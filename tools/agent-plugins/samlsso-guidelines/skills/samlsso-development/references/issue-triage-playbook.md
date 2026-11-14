# samlSSO GitHub Issue Triage Playbook

Use this playbook when analyzing reported GitHub issues or feature requests to ensure responses align with `docs/ADR/0001`–`0008`.

---

## 1. Unsolicited / IdP-Initiated Login Requests (`ADR 0001`)
- **Symptom / Request**: User asks to allow IdP-initiated (unsolicited) logins directly from Okta, Microsoft Entra ID (MyApps portal), or Google Workspace without an `InResponseTo` check, or reports an issue with unsolicited assertions.
- **Architectural Rule**: Direct authentication of unsolicited SAML responses is **never** permitted (`ADR 0001`).
- **How It Works & How to Respond**:
  - Explain that accepting unsolicited assertions directly exposes the ACS endpoint to Login CSRF, session fixation, and assertion replay attacks.
  - Explain the built-in **Double-Pass Solicited Flow** (`Acs::fetchRequestState()`): when a user clicks the GLPI tile in their IdP portal, `/front/acs/{idpId}` intercepts the unsolicited `SAMLResponse`, creates a tracked `PHASE_INITIAL (1)` state (`SAML_UNSOLICITED = 1`), and immediately redirects the browser back to the IdP via `performSamlIdpRequest()` (`PHASE_SAML_ACS (2)`). Because the user already has an active IdP session, the IdP transparently returns a solicited `SAMLResponse` with a valid `InResponseTo` ID.
  - If an IdP-initiated login fails, check whether the route `idpId` is valid/active or whether `request_timeout` expired—never relax `InResponseTo` validation.

---

## 2. Session Timeouts vs. SAML Request Timeouts (`ADR 0002`)
- **Symptom / Request**: User reports premature logouts, `PHASE_TIMED_OUT (99 / 7)` loops, or asks how `inactivity_timeout` interacts with GLPI's session timeout.
- **Architectural Rule**: We never duplicate GLPI Core's session management (`ADR 0002`).
- **How It Works & How to Respond**:
  1. **`request_timeout` (Plugin-Managed SAML Handshake Window)**:
     - Measured strictly using PHP's runtime clock (`date('Y-m-d H:i:s')`) between dispatching the `AuthnRequest` (`PHASE_SAML_ACS (2)`) and receiving the `SAMLResponse` at `/front/acs/{idpId}`.
     - If violated, `Acs::handleRequestTimeout()` rejects the response, displays *"Your SAML authentication request timed out. Please try logging in again."*, and drops the user onto `/index.php?noAuto=1`.
  2. **GLPI Session Timeout (GLPI Core-Managed Inactivity Window)**:
     - Once authenticated (`PHASE_GLPI_AUTH (4)`), idle session expiration is governed 100% by PHP (`session.gc_maxlifetime` in `php.ini`) and GLPI's `session` automatic action (`CronTask::cronSession()`).
     - The plugin's Security tab displays **GLPI Session Timeout (Minutes)** as a read-only informational field showing the active `session.gc_maxlifetime` value and its origin.

---

## 3. `php-saml` Strict Mode & Inbound Signature Toggles (`ADR 0003`)
- **Symptom / Request**: User asks why `Require Signed Assertions` / `Require Signed Messages` turn off when `Strict` is disabled, or wants to disable `strict` during testing.
- **Architectural Rule**: The plugin honors the configuration capabilities of `onelogin/php-saml` via `ConfigEntity::getPhpSamlConfig()` (`ADR 0003`), while keeping the `LoginState` state machine (`ADR 0001`) active at all times.
- **How It Works & How to Respond**:
  - In `onelogin/php-saml`, inbound security toggles (`wantMessagesSigned`, `wantAssertionsSigned`, `wantAssertionsEncrypted`, `wantNameId`) are only evaluated when `strict => true`.
  - When `strict` is disabled, `ConfigEntity::validateAdvancedConfig()` automatically toggles those four fields off and displays `⚠️ These fields are only enforced when strict mode is enabled. Your environment is at risk without strict mode.` so the UI accurately reflects `php-saml`'s runtime behavior.

---

## 4. Reverse Proxy / Cookie Settings & Stateless ACS (`ADR 0001` & `ADR 0002`)
- **Symptom / Request**: User behind Nginx, Traefik, HAProxy, or Docker experiences HTTP 400 / `"allow session cookies only in a secure context"`, `/index.php?error=3` (*Session Expired*), or `The response was received at http://... instead of https://...`, or asks whether `session.cookie_samesite = "Strict"` is supported.
- **Architectural Rule**: Both `session.cookie_samesite = "Strict"` and `"Lax"` are natively supported; never override PHP cookie INI settings (`SameSite=None`) at runtime (`ADR 0002`) and never blame `SameSite=Strict` for ACS cross-site `POST` failures.
- **How It Works & How to Respond**:
  - **Stateless ACS + `SameSite=Strict` Handoff**: `/front/acs/{id}` and `/front/logout` boot statelessly (`SessionManager::RegisterPluginStatelessPath()` -> `session.use_cookies = 0`), correlate state in `glpi_plugin_samlsso_loginstates` via `InResponseTo` (`SAML_REQUEST_ID`) while ignoring client `RelayState`, re-enable cookies in `LoginFlow::performGlpiLogin()` only after validation, and issue an `HTTP 200 OK` `<meta http-equiv="refresh">` (`performBrowserRedirect()`) to reset the cross-site request chain before entering GLPI.
  - **Proxy Split-Brain Fix**: Instruct the administrator to set `session.cookie_secure = On`, `session.cookie_httponly = On`, and `session.cookie_samesite = "Strict"` (or `"Lax"`), register the proxy IP/CIDR via Symfony `Request::setTrustedProxies(...)` in `config/config_db.php`, and enable **Requests Proxied** on the **Transit** tab (inspecting the Proxy Diagnostic popover icon to confirm `HTTP_X_FORWARDED_PROTO = https`).

---

## 5. Local or LDAP Account Linking Blocked on SAML Login (`ADR 0004`)
- **Symptom / Request**: User reports `"User account '...' uses local GLPI authentication and cannot be linked or accessed via SAML SSO"` or `"bound to a different authentication source"` (including `Auth::LDAP` accounts, e.g., Issue #189).
- **Architectural Rule**: SAML assertions may never automatically hijack local GLPI database accounts (`authtype = Auth::DB_GLPI`), the `glpi` super-admin account, or accounts belonging to other authentication authorities (`Auth::LDAP` or another IdP's `auths_id`) at runtime (`ADR 0004`). Runtime LDAP sharing/hijacking is intentionally not supported.
- **How It Works & How to Respond**:
  - Explain that runtime linking across `DB_GLPI`, `Auth::LDAP`, or `Auth::NOT_YET_AUTHENTIFIED (0)` is blocked by design to maintain strict single-authority ownership and prevent Account Takeover (ATO), and note that GLPI Core's UI Massive Action (*Change the authentication method*) cannot target SAML IdPs directly because `ajax/dropdownMassiveActionAuthMethods.php` hardcodes `AuthLDAP::dropdown()`.
  - Explain that SAML JIT and SCIM 2.0 isolate `glpi_users.auths_id` in `User::AUTHS_ID_OFFSET = 1,000,000` (`auths_id = 1,000,000 + <idpId>`, keeping `glpi_plugin_samlsso_configs.id` unchanged for `/acs/{id}` URLs while preventing any overlap with active `glpi_authldaps.id` directories), and allow **one-time adoption** of unbound `(Auth::EXTERNAL, 0)` rows (immediately stamping `auths_id = 1,000,000 + <idpId>`).
  - Direct administrators retiring LDAP to the non-destructive, reversible in-place GLPI console command (`php bin/console plugins:samlsso:migrate_ldap_users` / `tools/migrate_ldap_users.php` + GitHub Wiki manual — which preserves `glpi_users.id` and ticket/asset relations, deactivates only the retired source `glpi_authldaps` directory, and writes a `0600` `GLPIKey` HMAC-signed `--revert` snapshot), or to SAML JIT / per-IdP SCIM 2.0 push provisioning (`Auth::EXTERNAL`, `auths_id = 1,000,000 + <idpId>`).

---

## 6. Outbound SAML Flow Minimization & `RelayState` / Flow Tokens (`ADR 0001`, `#188`)
- **Symptom / Request**: Contributor suggests passing synthetic flow/CSRF tokens (`flow_token`, `samlsso_flow_token`) or deep-link return URLs in `RelayState` or extra `AuthnRequest`/`LogoutRequest` query parameters, or user hits `FlowTokenMismatch` on `1.3.2rc4`.
- **Architectural Rule**: Outbound `AuthnRequest` / `LogoutRequest` flows pass only `$CFG_GLPI['url_base'] ?? '/'` and `$parameters = []`; inbound `POST['RelayState']` is always ignored on `/front/acs/{idpId}`; all redirect and security enforcement remains strictly server-side in `glpi_plugin_samlsso_loginstates` (`ADR 0001`).
- **How It Works & How to Respond**:
  - Reject any re-introduction of `flow_token` / `samlsso_flow_token` or custom outbound `RelayState` return targets: SAML 2.0 `RelayState` is unencrypted and frequently overridden by IdPs (e.g., Entra ID in `#188`), and synthetic tokens add no security on a cookie-stateless ACS endpoint beyond `SAML_REQUEST_ID`, `SubjectConfirmationData/@InResponseTo`, and single-use `SAML_RESPONSE_ID`. Note that `1.3.2` was skipped in favor of `1.4.0`.

---

## 6. Outbound SAML Flow Minimization & `RelayState` / Flow Tokens (`ADR 0001`, `#188`)
- **Symptom / Request**: Contributor suggests passing synthetic flow/CSRF tokens (`flow_token`, `samlsso_flow_token`) or deep-link return URLs in `RelayState` or extra `AuthnRequest`/`LogoutRequest` query parameters, or user hits `FlowTokenMismatch` on `1.3.2rc4`.
- **Architectural Rule**: Outbound `AuthnRequest` / `LogoutRequest` flows pass only `$CFG_GLPI['url_base'] ?? '/'` and `$parameters = []`; inbound `POST['RelayState']` is always ignored on `/front/acs/{idpId}`; all redirect and security enforcement remains strictly server-side in `glpi_plugin_samlsso_loginstates` (`ADR 0001`).
- **How It Works & How to Respond**:
  - Reject any re-introduction of `flow_token` / `samlsso_flow_token` or custom outbound `RelayState` return targets: SAML 2.0 `RelayState` is unencrypted and frequently overridden by IdPs (e.g., Entra ID in `#188`), and synthetic tokens add no security on a cookie-stateless ACS endpoint beyond `SAML_REQUEST_ID`, `SubjectConfirmationData/@InResponseTo`, and single-use `SAML_RESPONSE_ID`. Note that `1.3.2` was skipped in favor of `1.4.0`.
