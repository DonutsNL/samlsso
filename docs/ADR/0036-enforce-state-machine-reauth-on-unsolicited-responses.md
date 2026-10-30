# ADR 0036: Enforce State-Machine Re-Authentication on Unsolicited SAML Responses & RelayState CSRF Binding

Fix for issues: https://github.com/DonutsNL/samlsso/issues/147, https://github.com/DonutsNL/samlsso/issues/158, https://github.com/DonutsNL/samlsso/issues/180

## Status
Accepted (Amends ADR 0017 & ADR 0027)

## Context
1. **Unsolicited SAML Responses and State-Machine Authority ([ADR 0017](0017-security-by-design-architecture.md))**:
   - By design, `samlsso` never permits direct unsolicited SAML authentication. Every authentication attempt must be evaluated against the database-backed state machine (`LoginState`).
   - An earlier change for Issue #147 modified `Acs::fetchRequestState()` and `Acs::assertSaml()` to force unsolicited responses (empty `InResponseTo`) directly into `PHASE_SAML_ACS (2)` and pass `null` to `$this->samlResponse->isValid(null)`. In addition, `LoginState::getState()` promoted unrecognised `InResponseTo` values to `PHASE_SAML_ACS`.
   - Accepting assertions without a state-machine-registered `InResponseTo` bypassed Phase 2 (`PHASE_SAML_ACS`) and exposed the ACS endpoint to Login CSRF and unsolicited assertion injection (flagged in the security review as Finding #13 `login-csrf`).
2. **RelayState Binding vs. Post-Login Redirect Authority ([ADR 0002](0002-loginstate-hardening.md), [ADR 0016](0016-reverse-proxy-https-context-and-cookie-security.md))**:
   - In Issue #158, `LoginFlow::performSamlIdpRequest()` was changed to send the post-login redirect URL as the SAML `RelayState`, and `LoginState::setRedirect()` / `LoginFlow::performBrowserRedirect()` accepted `$_POST['RelayState']` from the unauthenticated ACS POST body as a redirect target.
   - However, deep-link redirects are already captured and persisted server-side in `glpi_plugin_samlsso_loginstates.redirect` during Phase 1 (`LoginFlow::doAuth()`) before redirecting to the IdP. Using `RelayState` for redirect URLs conflicted with using `RelayState` to carry the per-flow anti-CSRF token (`$flowToken`), and setting a `SameSite=None` cookie conflicted with [ADR 0016](0016-reverse-proxy-https-context-and-cookie-security.md) (stateless ACS operation without cookie overrides).
3. **Root-Element `LogoutResponse` Detection on ACS**:
   - `Acs::init()` previously checked `strpos($decoded, 'LogoutResponse') !== false` on the raw base64-decoded payload before XML parsing, which could falsely match an `AuthnResponse` containing that substring inside an attribute.

## Decisions
1. **Re-Authentication Redirect on Empty or Unregistered `InResponseTo` (`src/LoginFlow/Acs.php`, `src/LoginState.php`)**:
   - When a SAML response arrives at `Acs::fetchRequestState()` with an empty `InResponseTo` attribute or when `$this->state->getSamlRequestId()` is empty (no matching `AuthnRequest` registered in `glpi_plugin_samlsso_loginstates`), `Acs` creates/persists the initial `LoginState` record (`writeState()`), binds the route `idpId` (`setIdpId()`), marks the state as unsolicited (`setUnsolicited(true)`), records `unsolicitedReauthRedirect` in the login flow trace, and invokes `$this->performSamlIdpRequest()` to redirect the browser back to the IdP with a fresh SP-initiated `AuthnRequest`.
   - In `LoginState::getState()`, when no database row matches a non-empty `$samlInResponseTo`, the initial state records `SAML_UNSOLICITED => '1'` while keeping `PHASE => PHASE_INITIAL` rather than promoting to `PHASE_SAML_ACS`.
   - In `Acs::assertSaml()`, `$expectedRequestId = $this->state->getSamlRequestId()` is strictly required to be non-empty and is always passed to `$this->samlResponse->isValid($expectedRequestId)` (never `null`).
2. **Per-Flow Anti-CSRF Token in `RelayState` & Server-Side Redirect Authority (`src/LoginFlow.php`, `src/LoginState.php`, `src/LoginFlow/Acs.php`)**:
   - In `LoginFlow::performSamlIdpRequest()`, generate the random per-flow token (`$flowToken`), store it in `LoginState` (`setFlowToken()`), and pass `$flowToken` as the `RelayState` parameter to `$auth->login($flowToken, ...)` without setting any `SameSite=None` cookies.
   - In `Acs::init()`, verify the returned `RelayState` (or session flow token) against `$this->state->getFlowToken()` via `hash_equals()`.
   - Remove `$_POST['RelayState']` from `LoginState::setRedirect()` and `LoginFlow::performBrowserRedirect()` so post-login redirects rely exclusively on the server-side `LoginState` redirect captured in Phase 1.
3. **Strict Root-Element Check for `LogoutResponse` on ACS (`src/LoginFlow/Acs.php`)**:
   - Match `/^\s*<(?:[a-zA-Z0-9_-]+:)?LogoutResponse\b/` on the decoded XML payload so only genuine root `LogoutResponse` documents redirect to the SLO route.

## Consequences
- **Positive**:
  - Restores full compliance with [ADR 0017](0017-security-by-design-architecture.md), [ADR 0016](0016-reverse-proxy-https-context-and-cookie-security.md), and [ADR 0002](0002-loginstate-hardening.md).
  - Unsolicited assertions are never accepted directly; users arriving from an IdP portal are seamlessly redirected back through a state-machine-tracked SP-initiated `AuthnRequest` cycle.
  - Eliminates cookie dependencies on the stateless ACS endpoint while maintaining per-flow CSRF protection via `RelayState` and `InResponseTo`.
- **Negative**:
  - IdP-initiated logins perform one additional browser redirect round-trip to the IdP to establish a valid state-machine `AuthnRequest`.
