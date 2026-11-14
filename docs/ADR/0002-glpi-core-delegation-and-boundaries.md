# ADR 0002: GLPI Core Delegation, Protocol Boundaries & Timeout Separation of Duties

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
The `samlsso` plugin is strictly a **server-side SAML 2.0 authentication and session-bootstrap broker** for GLPI. **We never duplicate, override, or re-implement lifecycle mechanisms that GLPI Core or the PHP runtime already provide natively.** Once a user is authenticated and handed off via `Session::init($auth)` (`PHASE_GLPI_AUTH (4)`), GLPI Core owns the session lifecycle, idle expiration, cookie security, and localization.

---

## Architectural Invariants

### 1. SAML 2.0 Protocol Rigidity for the GLPI Monolith
1. GLPI is a server-side monolithic PHP application where sessions are managed on the backend. SAML 2.0 is used deliberately over OAuth 2.0 / OIDC because SAML's rigid XML schema, server-side assertion verification, and mandatory `InResponseTo` / assertion-ID replay protections match GLPI's monolithic architecture without exposing bearer tokens to browser-side JavaScript.

### 2. Strict Timeout Separation of Duties (`request_timeout` vs. GLPI Session Timeout)
Two distinct timeout domains exist and must never be conflated:
1. **SAML Handshake Timeout (`request_timeout` — Owned by `samlsso`)**:
   - Measures **only** the elapsed time between dispatching an `AuthnRequest` to the IdP (`PHASE_SAML_ACS (2)`) and receiving the corresponding `SAMLResponse` at `/front/acs/{idpId}`.
   - `LoginState::LOGIN_DATETIME` (`loginTime`) is initialized and refreshed using PHP's clock (`date('Y-m-d H:i:s')`) at the exact moment `setRequestId()`, `refreshLoginDateTime()`, or `setPhase(PHASE_SAML_ACS)` is executed.
   - If `time() - strtotime(loginTime) > request_timeout * 60` while in `PHASE_SAML_ACS`, the state transitions to `PHASE_TIMED_OUT (7)` (`staleTimeout` trace).
   - If a `SAMLResponse` arrives at `Acs::assertSaml()` for a `PHASE_TIMED_OUT` state, `Acs::handleRequestTimeout()` rejects the response, queues a clear user-facing error message via `Session::addMessageAfterRedirect()`, transitions the state to `PHASE_LOGOFF (8)`, and redirects the browser to `/index.php?noAuto=1`.
   - Subsequent explicit SSO login clicks (`POST` or `GET` IdP selection) from a timed-out unauthenticated session cleanly reset the state and start a new login attempt.
2. **Authenticated Session Inactivity Timeout (Owned Exclusively by GLPI Core & PHP)**:
   - Once a session reaches `PHASE_GLPI_AUTH (4)`, idle session expiration is governed 100% by PHP (`session.gc_maxlifetime`) and GLPI's native `session` automatic action (`CronTask::cronSession()`), which correctly accounts for GLPI's periodic background AJAX requests.
   - The `inactivity_timeout` field in `ConfigItem` and `templates/configForm/security.html.twig` is a **read-only (`readonly` / `disabled`) informational field** titled `GLPI SESSION TIMEOUT (MINUTES)` that displays `intdiv((int)ini_get('session.gc_maxlifetime'), 60)` and explains its origin in `php.ini` and GLPI Core.

### 3. Single Clock Authority (PHP Runtime Clock) & Native GLPI Presentation
1. **Internal State Persistence & Math**: All timestamps written to or evaluated in `glpi_plugin_samlsso_loginstates` (`loginTime`, `lastClickTime`) must be generated and compared exclusively using PHP's runtime clock (`date('Y-m-d H:i:s')`, `time()`, `strtotime()`). Never rely on MySQL's `CURRENT_TIMESTAMP` default or append `' UTC'` to strings parsed by `strtotime()`.
2. **UI Presentation**: All timestamps rendered in PHP views or Twig templates must use GLPI's native timezone-aware helpers (`Html::convDateTime()` in PHP or the `|formatted_datetime` filter in Twig).

### 4. Reverse Proxy HTTPS Termination, Stateless Endpoints & `SameSite=Strict` Support
1. The ACS (`/front/acs/{idpId}`), SLO (`/front/logout`), and SCIM (`/front/scim/v2/{idpId}`) routes are registered as stateless via `SessionManager::RegisterPluginStatelessPath()` (`session.use_cookies = 0`) so cross-origin IdP POST requests and Bearer API calls never depend on browser session cookies surviving inbound requests.
2. After `Acs` validates the assertion against `LoginState`, `LoginFlow::performGlpiLogin()` re-enables cookies (`session.use_cookies = 1`), mints a fresh GLPI session, and issues an `HTTP 200 OK` `<meta http-equiv="refresh">` (`performBrowserRedirect()`) to reset the cross-site request chain—allowing both `session.cookie_samesite = "Strict"` and `"Lax"` natively (`setup.php` prerequisites aligned with GLPI Core `['lax', 'strict', '']`).
3. The plugin never overrides `session.cookie_samesite`, `session.cookie_secure`, or PHP INI cookie directives at runtime. TLS-terminating reverse proxies must register trusted proxy IPs/CIDRs via Symfony `Request::setTrustedProxies(...)` in `config/config_db.php` alongside `session.cookie_secure = On` and the plugin's `proxied` toggle (`Utils::setProxyVars(true)`).

### 5. GLPI 11 & GLPI 12 Dual Compatibility (`src/compat.php`)
1. Intermediate compatibility classes in `src/compat.php` (`ConfigCompat`, `LoginFlowCompat`, `ExcludeCompat`, `RuleSamlCompat`, `RuleSamlCollectionCompat`) bridge PHP 8 property-type invariance between GLPI 11 (untyped parent properties) and GLPI 12 (typed parent properties).
2. Web URLs are resolved dynamically at call time via `samlsso_webdir(bool $relative = false)` in `setup.php` rather than boot-time constants.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** implement plugin-level session inactivity timers (`expireStaleGlpiSessions()`, `markSessionInactivityTimedOut()`, or `lastClickTime` expiration checks on `PHASE_GLPI_AUTH` rows) that compete with GLPI Core's session management.
- **NEVER** modify or monkey-patch GLPI Core files, session handlers, or PHP cookie INI settings (`SameSite=None`) at runtime.
- **NEVER** rely on database `CURRENT_TIMESTAMP` for timeout math or append hardcoded `' UTC'` to `strtotime()` calls.
- **NEVER** redeclare `$dohistory`, `$rightname`, or `$menu_option` directly on concrete subclasses that extend `src/compat.php` classes.

---

## Issue Triage & Maintainer Directives
- **When a user reports session timeout issues or asks for a per-IdP inactivity timeout:**
  - Verify whether the issue occurs during the **SAML handshake** (`PHASE_SAML_ACS` -> governed by `request_timeout`) or during an **active GLPI session** (`PHASE_GLPI_AUTH` -> governed by GLPI/PHP `session.gc_maxlifetime`).
  - Explain that active session timeouts are managed natively by GLPI Core (`php.ini` `session.gc_maxlifetime` and GLPI's `session` automatic action) so background GLPI AJAX polling works seamlessly, and point them to the read-only **GLPI Session Timeout (Minutes)** indicator on the Security tab.
- **When a user reports HTTP 400 Bad Request, `/index.php?error=3`, or ACS URL mismatch behind a reverse proxy, or asks about `SameSite=Strict`:**
  - Explain that stateless ACS + `<meta http-equiv="refresh">` natively supports both `session.cookie_samesite = "Strict"` and `"Lax"`, and direct them to configure `session.cookie_secure = On`, Symfony `Request::setTrustedProxies(...)` in `config/config_db.php`, and the Transit tab's **Requests Proxied** toggle (verifying `HTTP_X_FORWARDED_PROTO = https` in the Proxy Diagnostic popover).

---

## Codebase & Automated Test Verification
- **Production Files**: `src/LoginState.php`, `src/LoginFlow.php`, `src/LoginFlow/Acs.php`, `src/CronTask.php`, `src/Config/ConfigItem.php`, `src/compat.php`, `setup.php`
- **Automated Tests**: `tests/LoginStateTest.php`, `tests/AcsTest.php`, `tests/CronTaskTest.php`, `tests/ConfigIntegrityTest.php`, `tests/Glpi12CompatTest.php`, `tests/AdrComplianceTest.php`
