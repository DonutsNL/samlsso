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

## 4. Reverse Proxy / Kubernetes Ingress HTTP 400 or ACS URL Mismatch (`ADR 0002`)
- **Symptom / Request**: User behind Nginx, Traefik, HAProxy, or Cloudflare experiences HTTP 400 Bad Request (`SessionCheckCookieListener`) or `The response was received at http://... instead of https://...`.
- **Architectural Rule**: Never override PHP cookie INI settings (`SameSite=None`) at runtime (`ADR 0002`).
- **How It Works & How to Respond**:
  - Instruct the administrator to enable **Requests Proxied** on the Transit tab (and inspect the Proxy Diagnostic popover icon) and map `HTTP_X_FORWARDED_PROTO` in GLPI's `config/local_define.php`:
    ```php
    define('GLPI_USE_SECURE_COOKIES', true);
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = 443;
    }
    ```

---

## 5. Local Account Linking Blocked on SAML Login (`ADR 0004`)
- **Symptom / Request**: User reports `"User account '...' uses local GLPI authentication and cannot be linked or accessed via SAML SSO"` or `"bound to a different authentication source"`.
- **Architectural Rule**: SAML assertions may never automatically hijack local GLPI database accounts (`authtype = Auth::DB_GLPI`) or the `glpi` super-admin account (`ADR 0004`).
- **How It Works & How to Respond**:
  - Explain that automatic email/username linking to local accounts is blocked to prevent Account Takeover (ATO). Existing users must either be provisioned via JIT or have their GLPI authentication source updated to External (`authtype = Auth::EXTERNAL`, `auths_id = <idpId>`) by an administrator before `sync_on_login` links them.

