# ADR 0030: Dynamic Input Switching for SP Private Key Sources and Table Migration Execution

Fix for issue: https://github.com/DonutsNL/samlsso/issues/182

## Status
Accepted

## Context
1. **SP Private Key Source Usability**:
   samlSSO supports storing the Service Provider's private key in alternative secure locations outside the database:
   - Secure File Path (`file`)
   - Environment Variable (`env`)
   - PHP Define / Constant (`define`)
   - Database (`db`)

   Previously, `templates/configForm/provider.html.twig` unconditionally rendered a large multi-line PEM certificate textarea titled `SP PRIVATE KEY` with certificate formatting helper text. When an administrator selected an alternative source, they were forced to type single-line filesystem paths or variable names into the multi-line certificate textarea, creating significant UX confusion.

2. **Table Migration Execution in `Config::install()`**:
   When new configuration columns (`sp_private_key_source`, `force_authn`, `conf_icon_url`, `login_header_text`) were added to `Config::install()` via `$migration->addField(...)`, the execution call `$migration->migrationOneTable($table);` was omitted. GLPI's migration engine queues column definitions and only executes the `ALTER TABLE` DDL queries when `migrationOneTable()` is explicitly invoked. Consequently, upgrades on existing databases did not execute these schema updates, leading to missing database columns and blank/unlabeled form toggles (such as an empty slider for `force_authn`).

## Decisions
1. **Dynamic Input Switching in `provider.html.twig`**:
   - Provide a dedicated single-line text input (`fields.textField`) with dynamic contextual labeling and placeholders:
     - `file`: Label `SP Private Key File Path`, placeholder `/etc/glpi/saml.key`, helper explaining server filesystem permissions and docroot isolation.
     - `env`: Label `Environment Variable Name`, placeholder `SAML_SP_PRIVATE_KEY`, helper explaining server environment variable lookup.
     - `define`: Label `PHP Constant Name`, placeholder `SAML_SP_KEY_CONSTANT`, helper explaining defined PHP constants.
   - When an alternative source (`file`, `env`, `define`) is active, disable and hide the raw PEM certificate textarea, and display a subtle notice confirming database key storage is disabled.
   - When `db` is selected, enable and display the raw PEM certificate textarea and disable and hide the single-line input field.
   - Attach a client-side change listener on `select[name="sp_private_key_source"]` to handle dynamic transitions without requiring page reloads.
2. **Table Migration Execution**:
   - In `src/Config.php`, invoke `$migration->migrationOneTable($table);` immediately after registering column additions in `Config::install()`.
   - Add automated test assertions in `tests/DatabaseUpdateTest.php` to ensure `migrationOneTable()` is called for `glpi_plugin_samlsso_configs`.

## Consequences
- **Positive**:
  - Intuitive, modern configuration UX adhering to GLPI's Tabler design system without clutter or ambiguity.
  - Ensures accurate form submissions where only the relevant field (`sp_private_key`) is enabled and submitted.
  - Guarantees complete and reliable database schema migration for existing instances on upgrade.
- **Negative**:
  - Twig templates must be recompiled by clearing GLPI cache (`php bin/console cache:clear`) for existing installations to load updated template markup.
