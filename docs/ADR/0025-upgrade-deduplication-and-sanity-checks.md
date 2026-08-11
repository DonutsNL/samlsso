# ADR 0025: Upgrade Deduplication and Sanity Checks

## Status
Accepted

## Context
When users upgrade the samlsso plugin from legacy versions (e.g. from the older namespace `GlpiPlugin\Glpisaml` to `GlpiPlugin\Samlsso`), duplicate and legacy CronTask actions often remain registered in the GLPI database (`glpi_crontasks` table) under obsolete class names (Issue #149). This results in duplicate task executions or silent errors when the old namespace fails to resolve.

Additionally, to ensure stability after upgrading or installing the plugin, administrators need a reliable way to verify that the deployed files, translation assets, database tables, and key schema columns are consistent and intact.

## Decision
1. **Deduplication on Install/Upgrade**: Implement a deduplication routine in `CronTask::install()`. This scans the `glpi_crontasks` table for existing tasks matching `cleanSessionSAML` or `updateGeoIP` under either the new namespace (`GlpiPlugin\Samlsso\CronTask`) or the old legacy namespace (`GlpiPlugin\Glpisaml\CronTask`). It migrates the oldest/legacy entry to the new namespace (preserving schedule settings) and purges all other duplicate tasks.
2. **Post-Upgrade Sanity Checker**: Introduce a `SanityChecker` utility class at `src/Utility/SanityChecker.php` that audits core file availability, locales/compiled translation assets, expected database tables, and critical column definitions (e.g. `configs_id` unsigned keys, `phase`, etc.).
3. **Auto-Deactivation on Inconsistency**: Hook `SanityChecker::check()` into the `plugin_samlsso_install()` sequence in `hook.php`. If the check fails, the installer emits descriptive toast notifications to the administrator, calls `Plugin::deactivate()` to deactivate the plugin immediately to protect the application, and aborts installation by returning `false`.
4. **Environment Shims**: Update the test harness and testing shims (`Shims.php` and `TestHarness.php`) to support `Plugin::deactivate()`, `CommonDBTM::getFromDBByCrit()`, GLPI core constants (`GLPI_SYSTEM_CRON`), and migration operations (`changeField()`, `addKey()`) to ensure the entire test suite executes correctly under simulation.

## Consequences
- **Positive**:
  - Automatically cleans up legacy and duplicate DB CronTask registrations during upgrades, ensuring only one active task runs.
  - Instantly prevents GLPI from executing or crashing on corrupt/inconsistent plugin installations by disabling them immediately.
  - Elevates security-by-design by verifying table structures and column types on plugin activation.
- **Negative**:
  - Requires maintaining shim methods in test files to mirror GLPI core database migrations and plugin deactivation routines.
