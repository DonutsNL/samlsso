# ADR 0029: SAML Request Timeout Enforcement and Native GLPI Session Lifetime Delegation

## Status
Accepted

## Context
Two distinct timeout concepts exist during the lifecycle of a user session:
1. **SAML Request Timeout (`request_timeout`)**: The maximum allowed duration between dispatching an `AuthnRequest` to the Identity Provider (IdP) and receiving the corresponding `SAMLResponse` at the Assertion Consumer Service (`ACS`) endpoint.
2. **Session Inactivity Timeout**: The maximum duration an already-authenticated user can remain idle before their GLPI session expires.

Historically, two issues arose:
- If `loginTime` (`LoginState::LOGIN_DATETIME`) was recorded only when a visitor first opened the GLPI login page (`PHASE_INITIAL`) rather than when the `AuthnRequest` was issued (`PHASE_SAML_ACS`), users who left the login tab open before clicking SSO experienced premature `PHASE_TIMED_OUT` rejections at the ACS endpoint.
- Maintaining a separate plugin-level `inactivity_timeout` for authenticated sessions (`PHASE_GLPI_AUTH`) conflicted with GLPI core's native session lifecycle (`session.gc_maxlifetime` and `CronTask::cronSession()`) and GLPI's periodic background AJAX requests.

## Decisions
1. **Delegate Authenticated Session Inactivity to GLPI Core**:
   - Remove plugin-level runtime enforcement of `inactivity_timeout` from `LoginState`, `LoginFlow`, and `CronTask`. Once a user reaches `PHASE_GLPI_AUTH`, session persistence and idle expiration are governed solely by GLPI core and PHP (`session.gc_maxlifetime`).
   - Retain the timeout field in the Security configuration tab as a read-only informational field displaying GLPI's active session timeout (`intdiv((int)ini_get('session.gc_maxlifetime'), 60)` minutes) along with a clear explanation of where the setting originates (`php.ini` / GLPI session configuration).
2. **Measure `request_timeout` Strictly Between `AuthnRequest` Dispatch and `SAMLResponse` Receipt**:
   - Initialize `loginTime` (`LoginState::LOGIN_DATETIME`) using PHP's clock (`date('Y-m-d H:i:s')`) upon state creation and refresh it whenever an `AuthnRequest` is dispatched (`setRequestId()`, `refreshLoginDateTime()`, and `setPhase(LoginState::PHASE_SAML_ACS)`).
   - Evaluate `request_timeout` strictly against `PHASE_SAML_ACS` states (`time() - strtotime(loginTime) > request_timeout * 60`).
3. **Graceful Rejection of Timed-Out SAML Responses**:
   - When an incoming `SAMLResponse` at the ACS endpoint violates `request_timeout` (`PHASE_TIMED_OUT`), reject the assertion, record `staleTimeout` in `LOGIN_FLOW_TRACE`, queue a clear user-facing error message via `Session::addMessageAfterRedirect()`, transition the state to `PHASE_LOGOFF`, and redirect the user to the GLPI login page with `noAuto=1` (`/index.php?noAuto=1`) to prevent automatic redirection loops.
   - If an unauthenticated user initiates a new login request (`POST` or `GET` IdP selection) after an earlier `PHASE_SAML_ACS` attempt timed out, allow the new authentication request to proceed cleanly.

## Consequences
- **Positive**:
  - Eliminates false-positive ACS timeouts when a user sits on the GLPI login screen before clicking SSO.
  - Drops users who exceed `request_timeout` at the IdP cleanly onto `/index.php?noAuto=1` with a clear error message instead of a dead-end HTTP 400 error page or an infinite SSO redirect loop.
  - Eliminates conflicts between plugin inactivity timers, GLPI background AJAX polling, and PHP's native session garbage collection while keeping the active GLPI session timeout visible to administrators.
- **Negative**:
  - Per-IdP custom inactivity timeouts are no longer enforced separately from GLPI's global session lifetime.
