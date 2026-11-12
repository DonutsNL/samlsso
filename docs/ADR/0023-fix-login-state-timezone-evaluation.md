# ADR 0023: Fix Login State Timezone Evaluation

## Status
Accepted

## Context
In ADR 0009, we established that database timestamps should be managed timezone-agnostically. Previously, the plugin evaluated session timeouts by appending `' UTC'` to database datetime values (e.g., `loginTime` and `lastClickTime`) before parsing them with PHP's `strtotime()`. 

However, this assumed that the database connection session timezone is always set to UTC. In environments where GLPI's timezone support (`$DB->use_timezones`) is disabled or configured to use the local database server timezone, querying timestamp columns returns local datetime strings rather than UTC strings. Appending `' UTC'` to these local datetime strings shifts the parsed timestamp value by the local timezone offset, making fresh sessions appear several hours old and triggering immediate session timeouts.

## Decision
1. Remove the hardcoded `' UTC'` suffix from all `strtotime()` parsing calls on database timestamp fields in `LoginState.php`.
2. Implement a proactive environment timezone validation helper (`validateTimezoneEnvironment()`) on the `request_timeout` configuration setting in `ConfigItem.php`. If a mismatch between PHP's current clock/timezone and MySQL connection current clock/timezone (drift > 15s) is detected, render a detailed warnings table inside the configuration UI displaying both current times, active timezones, and diagnostic guidance.

GLPI ensures that both the PHP runtime timezone and the database connection timezone are synchronized (either both set to the user's localized timezone on boot when `$DB->use_timezones` is active, or both left at the default system/server configuration when inactive). By using raw `strtotime()` on the database output without forcing a UTC suffix, PHP correctly parses the timestamp string according to its current timezone, producing the correct Unix epoch value under all configurations.

## Consequences
- **Positive**:
  - Fixes login timeout issues on GLPI installations running with non-UTC server timezones or with timezone support disabled.
  - Ensures accurate timeout checks (both for request timeouts and inactivity timeouts) across diverse server configurations.
  - Alerts administrators directly on the timeout configuration fields when PHP and database timezone configurations are out of sync, preventing silent failures.
  - Maintains compatibility with both standard GLPI installations and customized timezone setups.
- **Negative**:
  - Adds a small processing overhead of executing a timestamp/timezone query when validation settings are loaded.
