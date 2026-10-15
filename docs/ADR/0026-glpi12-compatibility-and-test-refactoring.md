# ADR 0026: GLPI 12 Compatibility, Property-Type Invariance, and Test Suite Mock Refactoring

## Status
Accepted

## Context
GLPI core updated to version 12.0.0. This major release introduced several breaking API removals, property typing changes, and lifecycle alterations:
1. **PHP 8 Property Type Invariance in Inheritance**: GLPI 12 added native PHP types to core properties (`CommonDBTM::$dohistory: bool`, `CommonGLPI::$rightname: string`, `RuleCollection::$menu_option: string`). In PHP 8+, property types cannot be added in subclasses over untyped parent properties (GLPI 11), nor can types be omitted over typed parent properties (GLPI 12). A single subclass definition redeclaring these properties causes a fatal compile-time type-invariance error on one of the two cores.
2. **Boot-Time Configuration Timing**: In GLPI 12, plugins are booted during `PostBootListener` before database configuration values (such as `url_base`) are loaded. Evaluating `PLUGIN_SAMLSSO_WEBDIR` at boot-time produces relative URLs (`/plugins/samlsso/`), causing OneLogin/php-saml strict mode to reject ACS and SLO endpoints (`sp_acs_url_invalid`).
3. **Removed `Plugin::getWebDir()`**: plugins are now uniformly served under `/plugins/{plugin_key}/`.
4. **Removed `Glpi\Toolbox\Sanitizer`**: GLPI 12 removed `Sanitizer`, using prepared statements and parameter bindings in DBAL, while GLPI 11 still requires sanitization before `add()` and `update()`.
5. **Strict property typing on `Auth::$auth_succeded`**: `src/Auth.php` now strictly types `public bool $auth_succeded = false;`. Assigning an object (`$this->auth_succeded = $this->user;`) triggers a fatal `TypeError` in PHP 8.3+.
6. **Removed global `QueryExpression`**: moved to `Glpi\DBAL\QueryExpression`.
7. **Removed token-based CSRF protection**: `Session::getNewCSRFToken()`, `Session::validateCSRF()`, and form CSRF token fields were removed in favor of browser-native `Sec-Fetch-Site`/`Origin` headers (`CheckCsrfListener`).
8. **Non-existent method `Plugin::deactivate()`**: GLPI native method is `$plugin->unactivate($id)`.
9. **Twig Debug Strict Variables**: GLPI 12 debug mode (`kernel.debug=true`) configures Twig with `strict_variables`, throwing 500 exceptions on uninitialized subkeys and missing template variables.

Furthermore, the existing test suite (`tests/Shims.php` and `tests/TestHarness.php`) contained outdated mocks that hardcoded `GLPI_VERSION` to `10.0.12` and stubbed removed/non-existent core classes and methods, masking breaking changes. In addition, `ConfigIntegrityTest.php` attempted to write a temporary test key to `/var/www/saml_test_key.key`, failing with permission denied under non-root users.

## Decision
1. **Inheritance Compatibility Layer (`src/compat.php`)** (suggested by @sbenoit in PR #169):
   - Implement intermediate abstract classes (`ConfigCompat`, `LoginFlowCompat`, `ExcludeCompat`, `RuleSamlCompat`, `RuleSamlCollectionCompat`) conditionally defined based on `samlsso_glpi12()`.
   - Concrete plugin classes extend their intermediate class and omit colliding property redeclarations, enabling full dual-support for GLPI 11 and GLPI 12.
2. **Call-Time Dynamic Web Directory Resolution**:
   - Provide `samlsso_plugin_web_dir()` with fallback to `Plugin::getWebDir()` on GLPI 11 and `root_doc . '/plugins/samlsso'` on GLPI 12.
   - Provide `samlsso_webdir()` to evaluate `$CFG_GLPI['url_base']` dynamically at call time, preventing relative URL generation in OneLogin strict mode.
3. **Dual-Version User Sanitization**:
   - Implement `User::sanitizeInput()` to conditionally invoke `Glpi\Toolbox\Sanitizer::sanitize()` when available (GLPI 11) while passing raw parameters on GLPI 12.
4. **GLPI 12 Production Code Compatibility**:
   - In `setup.php`: Update `PLUGIN_SAMLSSO_MAX_GLPI` to `'12.9.99'`.
   - In `src/LoginFlow/Auth.php`: Assign a strict boolean (`($this->user !== null && $this->user !== false)`) to `$this->auth_succeded`.
   - In `src/CronTask.php`: Use `Glpi\DBAL\QueryExpression` with a fallback alias for older versions.
   - In `src/Config/ConfigForm.php` & `src/LoginFlow/LoginFlowForm.php` & `templates/configForm.html.twig`: Safely guard `_glpi_csrf_token` and `Session::getNewCSRFToken()` calls, normalize optional field sub-keys for Twig strict mode, and provide missing context variables (`currentUserId`, `currentUsername`).
   - In `templates/configForm/idp.html.twig`: Remove bare `~ helper|raw` concatenation and guard `idp_certificate.validate` subkeys.
   - In `src/Config.php`: Filter `getHideLoginFields()` by active, non-deleted configurations to prevent login lockout.
   - In `hook.php`: Replace `$pluginObj->deactivate('samlsso')` with native `$pluginObj->unactivate($id)`.
5. **Test Suite & Mock Refactoring**:
   - In `tests/Shims.php`: Update `GLPI_VERSION` to `'12.0.0'`. Align `CommonDBTM::getTable` signature with core.
   - Remove obsolete mocks (`Plugin::getWebDir()`, `Glpi\Toolbox\Sanitizer`) to ensure tests fail immediately if removed APIs are called.
   - Update `Plugin` mock in `Shims.php` to provide native `unactivate()` and `getFromDBByCrit()`.
   - Map `Glpi\DBAL\QueryExpression` in `Shims.php`.
   - Add `tests/Glpi12CompatTest.php` to validate compatibility layer wiring, webdir helpers, and anti-obfuscation rules.
   - In `tests/ConfigIntegrityTest.php`: Write temporary test key files to `sys_get_temp_dir()` instead of `/var/www/`.

## Consequences
- **Positive**:
  - Restores full operational compatibility with both GLPI 11 and GLPI 12.0.0 on PHP 8.2 and 8.3+.
  - Fully eliminates fatal property type invariance errors and Twig strict mode exceptions.
  - Aligns the test suite with GLPI 12 core architecture and removes outdated mocks.
  - All 18 automated test suites pass cleanly.
- **Negative**:
  - Intermediate class layer must be maintained when adding new core-extended classes.
