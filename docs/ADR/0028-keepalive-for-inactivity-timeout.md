# ADR 0028: Keepalive Ping for the Inactivity Timeout

## Status
Proposed

## Context
The inactivity timeout (`inactivity_timeout`, minutes, 0 disables it) logs out a GLPI session when the last activity stored in the login state (`lastClickTime`) is older than the configured timeout. The last activity is refreshed by `LoginState` on every full request that passes `LoginFlow::doAuth()`.

`LoginFlow::doAuth()` skips AJAX requests (ADR 0011), and the ticket editor sends no requests while the user types. A user who writes a ticket answer for longer than the timeout therefore appears inactive. The submit is the first full request after several minutes: `LoginState` marks the session `PHASE_TIMED_OUT`, `doAuth()` logs the user out and redirects to `index.php?noAuto=1` before GLPI processes the POST, and the typed text is lost (issue ISSUE_KEEPALIVE). This was reproduced with the 1.3.2-rc3 code: with a 5 minute timeout, a submit after 6 minutes of typing is logged out.

## Decision
1. Add the script `public/js/keepalive.js`. It listens to user input (`keydown`, `input`, `mousedown`, `touchstart` on the document and on TinyMCE editors, also when TinyMCE is loaded later) and pings the route `front/keepalive`. Without input no ping is sent, so idle sessions still time out.
2. The ping interval is half the smallest inactivity timeout of all active, non-deleted configurations, at most `Config::KEEPALIVE_MAX_INTERVAL` (60) seconds (`Config::getKeepaliveInterval()`). It is passed to the script through the meta tag `samlsso-keepalive-interval` (`add_header_tag` hook). The script pings immediately when the interval has passed and otherwise once at the end of the interval (leading and trailing throttle), so the server always receives activity well before the timeout expires.
3. Add the route `front/keepalive` to `SamlSsoController`, answering `204 No Content`. The route uses `Firewall::STRATEGY_AUTHENTICATED` explicitly, because the fallback for controller routes is `central_access`, which would reject simplified interface (helpdesk) users, and calls `Session::checkLoginUser()`.
4. The ping is sent without the `X-Requested-With` header. It is therefore handled by `doAuth()` like any page load: the inactivity timeout is evaluated first and the last activity is refreshed afterwards. A session that already timed out is still logged out and not revived by a ping. The state machine, its phases and ADR 0011 are not changed.
5. `setup.php` registers the script and the meta tag only for authenticated users and only if an active configuration has an inactivity timeout > 0 (`Config::getMinInactivityTimeout()`, cached per request).

## Alternatives
- **Count authenticated AJAX requests as activity**: would require loading the login state in the AJAX path that ADR 0011 deliberately skips. It also would not solve the reported case, because typing produces no requests, and periodic background requests (e.g. GLPI's notification polling) would keep idle sessions alive forever.
- **Ping on a fixed timer**: simple, but keeps every open browser tab alive and defeats the purpose of the inactivity timeout.
- **Fixed 60 second interval**: the first version of this change. Testing with a 1 minute timeout showed that the first ping could only be sent at the timeout boundary and arrived too late, and input shortly after a ping was not reported. Hence the interval derived from the timeout and the trailing ping.
- **Recommend a larger timeout or 0**: avoids the data loss but makes the inactivity timeout unusable for installations that need it.

## Consequences
- **Positive**:
  - Users writing long ticket answers are no longer logged out on submit and do not lose their text.
  - Idle sessions still time out after the configured timeout.
  - No changes to the state machine or to the AJAX handling of ADR 0011.
  - Nothing is loaded or requested when the inactivity timeout is disabled (default).
- **Negative**:
  - While a user is active, one additional request per interval (at most every 30 to 60 seconds) per browser tab, each refreshing the login state like a page load.
  - The effective timeout for an active user can be extended by up to one interval, because the trailing ping reports the input at the end of the interval.
  - Input in iframes other than TinyMCE editors is not detected.
