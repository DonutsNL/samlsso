# ADR 0007: Deterministic Install/Upgrade Lifecycle, Sanity Checks & Translation Tooling

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
Plugin installation, database schema migrations, upgrades, uninstallation, cache resets, and localization compilation must follow a **deterministic, idempotent, and self-verifying lifecycle**. Corrupt or incomplete installations must fail safe by automatically deactivating the plugin rather than crashing GLPI.

---

## Architectural Invariants

### 1. Deterministic Class Lifecycle (`PLUGIN_SAMLSSO_CLASSES`) & Shared Migration
1. All database-backed and lifecycle-managed classes are explicitly listed in dependency order in the `PLUGIN_SAMLSSO_CLASSES` constant in `setup.php`. Dynamic directory scanning (`scandir()`) is forbidden.
2. `plugin_samlsso_install()` iterates forward through `PLUGIN_SAMLSSO_CLASSES` using a single shared `\Migration` instance, and `plugin_samlsso_uninstall()` iterates through `array_reverse(PLUGIN_SAMLSSO_CLASSES)` so dependent tables and rules are torn down deterministically.
3. Every `install()` method that queues schema updates via `$migration->addField()`, `$migration->changeField()`, or `$migration->addKey()` **must** invoke `$migration->migrationOneTable($table)` before returning so queued DDL statements execute immediately on existing installations, and all foreign-key columns referencing GLPI or plugin primary keys (`users_id`, `auths_id`, `entities_id`, `groups_id`) must use `INT UNSIGNED` (`fkey`) to satisfy GLPI's `migration:unsigned_keys` check.
4. Uninstalling the plugin cleans up all SAML JIT rules from `glpi_rules`, `glpi_rulecriterias`, and `glpi_ruleactions`.

### 2. Upgrade Deduplication & Post-Install `SanityChecker`
1. `CronTask::install()` deduplicates any legacy (`GlpiPlugin\Glpisaml\CronTask`) or duplicate (`GlpiPlugin\Samlsso\CronTask`) registrations for `cleanSessionSAML` and `updateGeoIP`, migrating the oldest record to preserve administrator schedules and purging duplicates.
2. At the end of `plugin_samlsso_install()`, `SanityChecker::check()` verifies required source files, compiled translation `.mo` assets, database tables, and critical column definitions. If any check fails, the installer displays actionable error messages, deactivates the plugin via `$plugin->unactivate($id)`, and returns `false`.
3. During install and update, `hook.php` proactively resets PHP OPcache (`opcache_reset()`) and clears stale compiled templates where permitted so updated code and templates take effect immediately.

### 3. Localization Standards & Automated Translation Pipeline
1. Every user-facing string in PHP and Twig must use GLPI's translation helper `__('Literal string', PLUGIN_NAME)` with **static literal string constants only**—never concatenating variables or `\n` sequences inside `__()`. Dynamic values must be injected via `sprintf(__('Message: %s', PLUGIN_NAME), $value)`.
2. Translation catalogs (`locales/*.po` and compiled `locales/*.mo`) are maintained via `tools/generatePot.sh` and `tools/translation/update_translations.py`.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** use `scandir()` to discover classes during `install()` or `uninstall()`.
- **NEVER** call `$migration->addField()` without calling `$migration->migrationOneTable($table)` in the same `install()` method.
- **NEVER** call non-existent `$plugin->deactivate()`; always use GLPI's native `$plugin->unactivate($id)`.
- **NEVER** pass variable concatenations or non-literal strings inside `__()`.

---

## Issue Triage & Maintainer Directives
- **When a user reports missing columns or blank toggles after upgrading the plugin:**
  - Verify that the column is registered in `Config::install()` (or the relevant entity's `install()` method), that `$migration->migrationOneTable($table)` is called, and instruct the user to run plugin upgrade/re-enable and clear the GLPI cache (`php bin/console cache:clear`).

---

## Codebase & Automated Test Verification
- **Production Files**: `setup.php`, `hook.php`, `src/Config.php`, `src/CronTask.php`, `src/Utility/SanityChecker.php`, `tools/generatePot.sh`, `tools/mkzip.sh`
- **Automated Tests**: `tests/LifecycleIntegrityTest.php`, `tests/DatabaseUpdateTest.php`, `tests/CronTaskTest.php`, `tests/SanityCheckerTest.php`, `tests/AdrComplianceTest.php`
