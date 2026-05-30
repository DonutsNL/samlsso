# ADR 0011: Skip SAML SSO Redirect on AJAX Requests

## Status
Accepted

## Context
When a user accesses GLPI in a clean session with an enforced Single Sign-On (SSO) configuration, background sub-requests (such as GLPI's translation fetch `/front/locale.php` or other AJAX callbacks) bootstrap GLPI and invoke the `POST_INIT` hook `plugin_samlsso_evalAuth()`.

Since the session is clean (initially in `PHASE_INITIAL`), these background AJAX/sub-requests execute `LoginFlow::doAuth()`, match the auto-redirect criteria, and execute a redirect by setting HTTP redirect headers (e.g. `Location: https://login.microsoftonline.com/...`) and updating the database session state phase to `PHASE_SAML_ACS` (phase 2).

Because these are AJAX/sub-requests, the browser's top-level document window does not navigate, and the redirect is ignored or fails. However, the database session state has now advanced to phase 2. When the main window's document request (e.g., `/index.php`) is processed, it loads the state and finds it is in phase 2 rather than `PHASE_INITIAL` or `PHASE_LOGOFF`. This bypasses the auto-redirection check, and the user is incorrectly presented with the standard login screen instead of being automatically redirected.

## Decision
We decided to skip the authentication and redirection logic in `LoginFlow::doAuth()` if the current request is an AJAX request. This is achieved by checking if `\Toolbox::isAjax()` evaluates to `true` (with a fallback logic for different environments).

## Consequences
- **Positive**:
  - Background sub-requests no longer advance the session phase or issue redirect headers.
  - The main page document request successfully triggers the top-level browser redirect to the IdP.
  - No session state corruption occurs when concurrent requests bootstrap GLPI in clean sessions.
- **Negative**:
  - None.
