# ADR 0001: Security-by-Design State Machine & Solicited-Only Authentication (Double-Pass Flow)

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
All SAML authentication, session lifecycle transitions, post-login redirects, and administrative session revocations are governed **exclusively** by the server-side, database-backed state machine (`LoginState`). **Direct unsolicited (IdP-initiated) authentication is never permitted.** Every assertion evaluated at `/front/acs/{idpId}` must correspond to a pre-registered `AuthnRequest` (`SAML_REQUEST_ID`) in `PHASE_SAML_ACS (2)`. Any unsolicited `SAMLResponse` arriving without a matching server-side request ID must always execute the **Double-Pass Solicited Flow** by initializing a state and redirecting the browser back to the Identity Provider (IdP) to complete a standard solicited round-trip.

---

## Architectural Invariants

### 1. Strict 9-Phase State Machine (`LoginState`)
Every visitor and authenticated SAML session is tracked through 9 explicit phases in `glpi_plugin_samlsso_loginstates`:

| Phase Constant | ID | Meaning & Preconditions | Exclusive Permitted Writer |
| :--- | :-: | :--- | :--- |
| `PHASE_INITIAL` | `1` | Initial visitor state (or unsolicited ACS arrival reset before IdP redirect). `SAML_REQUEST_ID` is empty. | `LoginState::createInitialState()` / `LoginState::evalGlpiAuth()` |
| `PHASE_SAML_ACS` | `2` | SP-initiated `AuthnRequest` dispatched (`SAML_REQUEST_ID` recorded, `SAML_RESPONSE_ID` empty, `LOGIN_DATETIME` refreshed). **Only rows in this phase may be evaluated by `Acs::assertSaml()`.** | `LoginFlow::performSamlIdpRequest()` **only** |
| `PHASE_SAML_AUTH` | `3` | Expected & cryptographically verified `SAMLResponse` accepted at ACS; unique `SAML_RESPONSE_ID` recorded. Permanently locks the assertion against replay. | `Acs::assertSaml()` **only** |
| `PHASE_GLPI_AUTH` | `4` | Authenticated session handed over to GLPI core (`$_SESSION['glpiname']` and `$_SESSION['valid_id']` active). | `LoginState::evalGlpiAuth()` |
| `PHASE_RESERVED` | `5` | Reserved for future use. | None |
| `PHASE_FORCE_LOG` | `6` | Administrator triggered Force Logoff & Disable User (`is_active = 0`). Next request in `LoginFlow::doAuth()` destroys the session and redirects to `/index.php?noAuto=1`. | `ConfigForm` (`forcelogoff` POST action) |
| `PHASE_TIMED_OUT` | `7` | Pending `PHASE_SAML_ACS (2)` exceeded `request_timeout`. Rejected at ACS with a user-facing message and redirect to `/index.php?noAuto=1`. | `LoginState::getPhase()` / `LoginState::expireStaleAcsRequests()` |
| `PHASE_LOGOFF` | `8` | Session terminated (local logout, SLO logout, or post-timeout/force-logoff cleanup). Excluded from active lookups. | `LoginFlow::doAuth()` / `Acs::handleRequestTimeout()` |
| `PHASE_ERROR` | `9` | Assertion validation, CSRF, IdP mismatch, or replay failure. Excluded from active lookups (`NOT PHASE IN (8, 9)`). | `LoginFlow::printError()` / `LoginFlow::PrintFatalLoginError()` |

```mermaid
stateDiagram-v2
    [*] --> PHASE_INITIAL_1 : New visitor / Unsolicited ACS hit (no matching SAML_REQUEST_ID)
    PHASE_INITIAL_1 --> PHASE_SAML_ACS_2 : LoginFlow::performSamlIdpRequest() registers SAML_REQUEST_ID & redirects to IdP
    PHASE_SAML_ACS_2 --> PHASE_SAML_AUTH_3 : Acs::assertSaml() validates expected InResponseTo & records unique SAML_RESPONSE_ID
    PHASE_SAML_ACS_2 --> PHASE_ERROR_9 : Validation / Replay / IdP / CSRF failure in ACS
    PHASE_SAML_ACS_2 --> PHASE_TIMED_OUT_7 : request_timeout exceeded
    PHASE_SAML_AUTH_3 --> PHASE_GLPI_AUTH_4 : Session::init() & redirect to GLPI
    PHASE_GLPI_AUTH_4 --> PHASE_FORCE_LOG_6 : Admin Force Logoff
    PHASE_GLPI_AUTH_4 --> PHASE_LOGOFF_8 : User Logout / SLO
    PHASE_FORCE_LOG_6 --> PHASE_LOGOFF_8 : LoginFlow::doAuth() cleans session
    PHASE_TIMED_OUT_7 --> PHASE_LOGOFF_8 : Acs::handleRequestTimeout() / LoginFlow::doAuth() cleans session & redirects to /index.php?noAuto=1
```

### 2. Solicited-Only Authentication & Mandatory Double-Pass Flow
1. When an IdP sends an unsolicited (IdP-initiated) `SAMLResponse` to `/front/acs/{idpId}` (either lacking an `InResponseTo` attribute or carrying an `InResponseTo` not registered in `glpi_plugin_samlsso_loginstates`), `LoginState::getState()` keeps the state in `PHASE_INITIAL (1)` with `SAML_REQUEST_ID = null` and marks `SAML_UNSOLICITED = '1'`.
2. `Acs::fetchRequestState()` intercepts any state with an empty `InResponseTo` or empty `getSamlRequestId()`, generates a unique session identifier if `session_id()` is empty, persists the initial state (`writeState()`), binds the route `idpId`, records `unsolicitedReauthRedirect` in the trace, and calls `$this->performSamlIdpRequest()`.
3. `performSamlIdpRequest()` dispatches a fresh SP-initiated `AuthnRequest` to the IdP (`PHASE_SAML_ACS (2)`). Because the user already holds an active session at the IdP, the IdP transparently returns a solicited `SAMLResponse` bound to the registered `SAML_REQUEST_ID`.
4. `Acs::assertSaml()` strictly requires a non-empty `$expectedRequestId = $this->state->getSamlRequestId()`, verifies `SubjectConfirmationData/@InResponseTo === $expectedRequestId`, and passes `$expectedRequestId` to `$this->samlResponse->isValid($expectedRequestId)`.

### 3. Replay Protection & Stateless Replay Isolation
1. Every accepted `SAMLResponse` has its unique assertion/response ID stored in `SAML_RESPONSE_ID` via `LoginState::setSamlResponseId()`, which verifies in real time that the database row is still in `PHASE_SAML_ACS (2)` with an empty `SAML_RESPONSE_ID` and that no other row in the database has ever used that `SAML_RESPONSE_ID` (`checkResponseIdUnique()`).
2. Replayed assertions or stateless ACS lookups with unknown/already-consumed `InResponseTo` values must **never** mutate, downgrade, or overwrite an existing `PHASE_SAML_AUTH (3)` or `PHASE_GLPI_AUTH (4)` session (`LoginState::getState()` and `LoginFlow::printError()` guard active authenticated rows).

### 4. Server-Side Redirect Authority & Anti-CSRF `RelayState`
1. Deep-link post-login redirect targets are captured exclusively on the server during `PHASE_INITIAL (1)` in `LoginState::REDIRECT` (`setRedirect()`).
2. `LoginState::getSafeRedirect()` validates that any redirect target is a relative, internal path starting with `/` (never `//`, `\`, or external schemes) and rejects auth/login entry points and static asset URLs (`isStaticAssetRedirect()`: `.js`, `.mjs`, `.css`, `.map`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.ico`, `.webp`, `.woff`, `.woff2`, `.ttf`, `.eot`, `.otf`).
3. The SAML `RelayState` parameter is reserved **exclusively** for the random per-flow anti-CSRF token (`$flowToken`) generated in `LoginFlow::performSamlIdpRequest()` and verified via `hash_equals()` in `Acs::init()`. `RelayState` is never used to transport redirect URLs.

### 5. Background AJAX Isolation & Force Logoff Enforcement
1. Background AJAX sub-requests (`Toolbox::isAjax()`) must never trigger SSO redirects or advance `PHASE_INITIAL` to `PHASE_SAML_ACS`.
2. Before skipping AJAX or excluded paths in `LoginFlow::doAuth()`, the plugin evaluates `PHASE_FORCE_LOG (6)` and `PHASE_TIMED_OUT (7)`, terminating revoked AJAX requests with HTTP 401 and redirecting revoked browser sessions to `/index.php?noAuto=1`.
3. `LOGIN_FLOW_TRACE` is stored as a `TEXT` column containing PHP-serialized arrays (`unserialize(..., ['allowed_classes' => false])`) with all values HTML-escaped via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** pass `null` to `$this->samlResponse->isValid()` or skip `InResponseTo` / `SubjectConfirmationData/@InResponseTo` verification in `Acs.php`.
- **NEVER** promote an unrecognized or unsolicited `SAMLResponse` directly to `PHASE_SAML_ACS (2)` in `LoginState::getState()` or `Acs::fetchRequestState()`.
- **NEVER** add a configuration toggle to "allow unsolicited / IdP-initiated logins without re-authentication".
- **NEVER** read post-login redirect URLs from `$_POST['RelayState']` or unvalidated client parameters on `/front/acs/{idpId}`.
- **NEVER** use raw string paths for ACS or SLO routes; always reference `SamlSsoController::ACS_ROUTE` and `SamlSsoController::SLO_ROUTE`.

---

## Issue Triage & Maintainer Directives
- **When a user or issue requests support for IdP-initiated / Unsolicited SAML login:**
  - **Do NOT alter `Acs.php` or `LoginState.php` to accept unsolicited assertions directly.**
  - Inform the reporter that direct unsolicited assertions are rejected by design to prevent Login CSRF and assertion injection, and explain that `samlsso` implements a **Double-Pass Solicited Flow**: when a user clicks the GLPI tile in their IdP portal (Okta, Entra ID, Google Workspace, etc.), the plugin automatically catches the unsolicited POST, initializes a tracked `LoginState`, and bounces the browser back to the IdP with a solicited `AuthnRequest`, completing the login seamlessly in a single transparent round-trip.

---

## Codebase & Automated Test Verification
- **Production Files**: `src/LoginState.php`, `src/LoginFlow.php`, `src/LoginFlow/Acs.php`, `src/Controller/SamlSsoController.php`, `src/Exclude.php`
- **Automated Tests**: `tests/AcsTest.php`, `tests/LoginStateTest.php`, `tests/LoginFlowTest.php`, `tests/ExcludeTest.php`, `tests/AdrComplianceTest.php`

