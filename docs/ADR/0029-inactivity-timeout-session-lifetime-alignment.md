# ADR 0029: Inactivity Timeout Alignment with GLPI Core Session Lifetime

Fix for issue: https://github.com/DonutsNL/samlsso/issues/181

## Status
Accepted

## Context
In samlSSO, administrators can configure an `inactivity_timeout` (in minutes) to terminate sessions after a period of user inactivity. However, GLPI core manages session persistence and garbage collection via PHP's `session.gc_maxlifetime` setting and the `CronTask::cronSession()` automatic action. When an idle session exceeds `session.gc_maxlifetime`, GLPI core physically unlinks the session file in `GLPI_SESSION_DIR`.

If an administrator configures a samlSSO inactivity timeout that exceeds the server-side GLPI session lifetime (e.g., setting 120 minutes in samlSSO while PHP defaults to 24 minutes):
1. The user's session is purged by GLPI core after 24 minutes.
2. The user is logged out on their next request before samlSSO's 120-minute timer is ever evaluated.
3. This leads administrators to believe that samlSSO's inactivity timeout is malfunctioning or ignored, unaware that the underlying PHP session expired first.

## Decisions
1. In `ConfigItem::inactivity_timeout()`, inspect PHP's active session lifetime ceiling via `(int)ini_get('session.gc_maxlifetime')` and convert to minutes.
2. Dynamically display the active GLPI session lifetime in `ConfigItem::FORMEXPLAIN` so administrators understand the effective upper bound.
3. When a configured inactivity timeout exceeds the server session lifetime (`inactivity_timeout > session.gc_maxlifetime`), surface an advisory warning (`⚠️ Inactivity timeout (%d min) exceeds GLPI session lifetime (%d min)...`) in `ConfigItem::ERRORS`.
4. Keep the evaluation state as valid (`EVAL = VALID`) rather than a hard blocking error, accommodating environments utilizing custom Redis/Memcached session handlers with independent TTLs or administrators conducting phased configuration updates.

## Consequences
- **Positive**:
  - Eliminates administrator confusion regarding discrepancies between samlSSO inactivity timeouts and GLPI core session expiration.
  - Transparently surfaces the server's effective session ceiling directly in the configuration interface.
  - Fully backward-compatible; does not block configuration saves for custom or external session architectures.
- **Negative**:
  - Environments using external session stores that do not respect `session.gc_maxlifetime` will display the advisory warning based on PHP's default ini value.
