# ADR 0013: Force Logoff and Disable User Action

## Status
Accepted

## Context
Administrators need a way to immediately revoke access for active sessions and prevent the corresponding users from logging back into GLPI, even if the Identity Provider (IdP) still considers their session authenticated. 

However, since standard SAML SSO flows automatically log users back in if an active IdP session exists, simply logging the user out locally is insufficient. The user must also be disabled in GLPI. Furthermore, performing this administrative action carries a risk of Denial of Service (DoS) if unauthorized actors could trigger it.

## Decision
We decided to implement a dual-action mechanism consisting of:
1. **User Disabling**: Set `is_active = 0` on the GLPI user record. When a login attempt is made, `User::getOrCreateUser()` checks this field and blocks the login.
2. **Session Force Logoff**: Transition the database session phase to `LoginState::PHASE_FORCE_LOG` (6).
3. **Session Invalidation**: In `LoginFlow::doAuth()`, if the current session's phase is `PHASE_FORCE_LOG`, we perform a local logout using `Session::cleanOnLogout()`, transition the phase to `PHASE_LOGOFF` (8), add a flash message notifying the user they were forcefully logged out by an administrator, and redirect the user to `/index.php?noAuto=1` to break any automatic login loop.

To mitigate security risks and prevent Denial of Service:
- Access to the action is strictly guarded with `$config->canUpdate()` checks.
- State-changing requests are processed via POST, protected by GLPI's CSRF token mechanism.

## Consequences
- **Positive**:
  - Securely revokes access and prevents automatic re-login.
  - Mitigates DoS and CSRF risks by utilizing POST requests, access right verification, and CSRF tokens.
  - Informs the forcefully logged-out user with a clear flash message.
- **Negative**:
  - Requires updating the active sessions table to include a form for CSRF verification per action button.
