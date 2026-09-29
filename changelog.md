
**V1.3.2**
- Fixed issue https://github.com/DonutsNL/samlsso/issues/134 by adding "registration_number" to user mapping.
- Fixed issue https://github.com/DonutsNL/samlsso/issues/145 where custom GLPI_MARKETPLACE_DIR paths caused 404/installation regressions - suggested by @albertogalisteo.
- Fixed issue https://github.com/DonutsNL/samlsso/issues/141 with JIT rules test route opening missing plugin path - suggested by @KawanCostaNs.
- Fixed issue https://github.com/DonutsNL/samlsso/pull/144 to support GeneralizedTime X.509 certificate dates with validity dates beyond 2049 by @enricomv.
- Fixed issue https://github.com/DonutsNL/samlsso/pull/140 where LogoutResponse was incorrectly processed as AuthnResponse in Acs.php by @Siruv.
- Fix: Refactored database query and loop assignments inside conditional checks to resolve CodeFactor's "Assignment in condition" warnings.
- Fix: Refactored translation logging messages in User defaults synchronization to use static literal strings and `sprintf` to resolve CodeFactor's "Unnecessary string concatenation" warnings.
- Fix: Replaced raw `TODO` in base64 mock private key template to resolve CodeFactor's "Unresolved TODO comment" warnings.
- Fix: Fixed a loop-overwriting bug in `getTabWarnings` where warning status (⚠️) on configuration tab panels was cleared and overwritten by subsequent clean fields.
- Fix: Resolved a parsing condition in `sp_certificate` and `idp_certificate` where validly parsed certificates skipped passing expiration and default/example certificate warnings to the form fields' errors property.

**V1.3.1**
- Fix: Resolved TypeError: noMethod() expects string, int given on undefined or custom database configuration fields in ConfigItem and LoginFlowItem.
- Fix: Implemented case-insensitive email comparison during JIT user synchronization on login to prevent duplicate `glpi_useremails` insert errors. (Related issues: https://github.com/DonutsNL/samlsso/issues/126, https://github.com/DonutsNL/samlsso/issues/127)
- Feature: Added dynamic FontAwesome CSS parser to populate the LOGIN ICON selector with all available installed icons, sorted alphabetically.
- Fix: Removed '. UTC' from datetime conversions in LoginState to resolve timezone mismatches and premature timeouts on servers not configured to UTC. (Related issue https://github.com/DonutsNL/samlsso/issues/124)
- Feature: Added timezone validation warning popout to timeout configuration settings to detect and display PHP vs Database timezone mismatches.
- Feature: Added ADR 0023 documenting the timezone fix and validation for login states.
- Fix: Applied raw filter to Twig error helper variables passed to form fields to prevent HTML auto-escaping, restoring warning/error popovers and yellow background highlighting.
- Fix: Resolved database migration race condition where `loginFlowTrace` column failed to upgrade to `TEXT` if it did not exist prior to update. (Related issue https://github.com/DonutsNL/samlsso/issues/123)
- Fix: Ensured the `location` column in the login states table does not receive a `null` value when `parse_url()` fails on malformed or empty URIs by defaulting to `'UNKNOWN'`. (Related issue https://github.com/DonutsNL/samlsso/issues/123)
- Fix: Resolved inactive session timeout bugs by replacing invalid database column queries in cleanSessionSAML CronTask with class constants and protecting the TIMED_OUT phase in evalGlpiAuth.
- Feature: Automatically disable and display warnings on inbound security fields when strict mode is disabled.
- Architecture: Added ADR 0022 documenting the decision to auto-disable inbound security options when strict is off and use prefix-based dynamic stylinrsg for warnings.
- Redesigned the configuration form fields layout to use a modern two-line strategy (Labels and tooltips on line 1, input controls on line 2), preventing squishing and making better use of the available space.
- Implemented soft yellow/warning background styling (via `.form-field-warning-row` class) and borders for rows with active warnings.
- Refactored warnings across all form fields (including those inside General, Transit, and Security tabs) to render elegantly as warning tooltips next to labels, hiding the original text messages.
- Refactored monolithic configForm.html.twig into separate sub-templates for each tab panel inside templates/configForm/.
- Wrapped all remaining hardcoded user-facing fields in Twig templates with i18n translation functions and formatted them for easy localization.
- Fixed translation of Service Provider fields (Entity ID, MetaUrl, AcsUrl, SloUrl) in Twig configuration form templates.
- Architecture: Added ADR 0015 documenting the design, implementation, and environment requirements for the translation automation tool.
- UI: Added a translatable warning tooltip icon next to the "SYNC ON LOGIN" field explaining potential data leak incidents from IDP configuration mistakes. Also formatted the conditional warnings for "ENFORCED" and "STRICT" fields in the same clean hoverable style.
- UI: Restructured the Security tab layout using Bootstrap columns to eliminate empty whitespace and grouped the settings logically into four categories: Enforcement & Sessions, User Provisioning, Inbound Security (IdP), and Outbound Security (SP).
- UI: Restructured the General, Transit, SP, and IdP configuration tabs into consistent card-based logical subject groups with neat right-aligned inputs and responsive layouts.
- Feature: Added inactivity timeout configuration setting allowing administrators to forcefully log out inactive user sessions after a specified duration (default is 0, which disables the inactivity check).
- Feature: Implemented passive ACS request evaluation during user logins to automatically clean up and forcefully expire stale pending authentication states.



**V1.3.0**
- Fix: Removed 'after' statements from database field migrations to prevent update/installation errors due to non-existent columns. (Related issue https://github.com/DonutsNL/samlsso/issues/119)
- Feature: Added proactive GLPI cache reset during plugin installation/upgrade using CacheManager::resetAllCaches(). (Related issue https://github.com/DonutsNL/samlsso/issues/119)
- Architecture: Added ADR 0014 documenting the design decision to clear GLPI cache during installation/upgrade. (Related issue https://github.com/DonutsNL/samlsso/issues/119)
- Fix: Resolved a critical nested form structure conflict in the Twig template (`configForm.html.twig`) that incorrectly triggered `forcelogoff` actions when saving configuration updates.
- Fix: Prevented SSO login/redirection flow interception on active administrator sessions by skipping `doAuth()` and local login blocks if `Session::getLoginUserID()` is active.
- Fix: Ensured the multiple active IDPs warning message is always displayed on the Enforce SSO field in the configuration UI when more than one IDP is active.
- Fix: Reset database session phase to `PHASE_INITIAL` when GLPI session is expired, ensuring SSO enforcement redirects work correctly for returning users with lingering cookies. (Related issue https://github.com/DonutsNL/samlsso/issues/25)
- Feature: Added configurable SAML request timeout fallback to automatically expire stale authentication states after a set duration (default 15 minutes) - by @eduardomozart
- Security Fix: Addressed local login SSO enforcement bypass securely using server-side session variable checks instead of a spoofable Referer check - by @eduardomozart
- Merged PR 114: Added country code 'ZZ' fallback tooltip and image display - by @eduardomozart
- Merged PR 115: Replaced custom copy buttons with native is_copyable attribute - by @eduardomozart
- Merged PR 116: Enforced access control checks with Session::checkRight and added user avatars - by @eduardomozart
- Merged PR 117: Separated translation links and added PO/POT generator - by @eduardomozart
- Merged PR 118: Added user fields matching support in rule criteria - by @eduardomozart
- Implemented configurable SAML claim mapping for GLPI user fields with predefined presets (Entra ID, Okta, Keycloak) and observed claims tracking.
- Refactored installation/uninstallation routines using a central class registry and single migration instance.
- Hardened logout and Single Logout (SLO) confirmation rendering, local logout redirection paths, and auto-login loop prevention.
- Added automated test validation pre-checks to the release packaging script.
- Fixed static analysis warnings, type declarations, compliance checks, and translation domain issues across source files.
- Fixed issue https://github.com/DonutsNL/samlsso/issues/51 where user JIT creation bypassed the Identity Provider configuration.
- UI: Refactored the Backup & Restore section on the IDP list page into a collapsible panel triggered by a subtle outline toggle button, reducing visual weight while keeping the feature accessible.
- UI: Wrapped the "Template & View XML" tools on the Claim Mapping tab into a collapsible "Tools" panel, consistent with the Backup & Restore treatment.
- UI: Claim mappings are now ordered deterministically — enforced (system) mappings are always shown first, followed by manually added mappings in insertion order.
- Reliability: Replaced `::class` constants in the `PLUGIN_SAMLSSO_CLASSES` array in `setup.php` with fully-qualified string literals so the constant can be safely defined before the Composer autoloader is registered (i.e. when the plugin is disabled).
- Reliability: Added `include_once __DIR__ . '/vendor/autoload.php'` to `hook.php` so plugin classes are always resolvable during install/uninstall, even when `plugin_init_samlsso()` (which normally loads the autoloader) has not run.
- Reliability: Replaced `ClassName::getTable()` / `self::getTable()` calls inside `install()` and `uninstall()` lifecycle methods with `getTableForItemType(static::class)` across `Config`, `ClaimMap`, `ObservedClaim`, `Exclude`, and `LoginState`, using the GLPI-recommended function that performs pure string manipulation with no dependency on plugin state.
- Testing: Updated `LifecycleIntegrityTest.php` class parser to support both the legacy `::class` constant format and the new quoted string literal format in `PLUGIN_SAMLSSO_CLASSES`.
- Fix: Corrected signed `INT` foreign key columns (`configs_id` in `ObservedClaim` and `ClaimMap`; `idpId` in `LoginState`) to `INT UNSIGNED` to silence GLPI 11 deprecation warnings; added upgrade migrations to fix existing installations.
- Feature: Added `sync_on_login` configuration option to automatically update user claim mappings and rerun the rules engine on every successful user login.
- Refactored Claim Mappings to use class constants instead of raw strings for target fields and target types, improving consistency and maintainability.
- Refactored SAML claim resolution, defaults, and requirements validation logic out of `User.php` and into `ClaimMapEntity.php` helper methods.
- Decomposed large methods in `User.php`, `Acs.php`, and `LoginFlow.php` into descriptive private helper methods to improve readability.
- Standardized project-wide class layout and structure rules (constants at the top, chronological method ordering, and database schema hooks at the bottom) documented in ADR 0010.
- Cleaned up unused imports and normalized FQCN references across source files.
- Removed unused `SCHEMA_*` and `USERDATA` constants from `User.php` that are no longer referenced after the mapping refactor.
- Cleaned up unused and deprecated methods (`trackObservedClaim` in `ClaimMapEntity.php`; `setSamlResponseParams`, `setRequestParams`, and `isLoadedFromDb` in `LoginState.php`) detected by dead-code analysis.
- Feature: Added offline GeoIP originating country flag resolution. Client IPs are stored and matched using a high-performance binary search resolver (`GeoIPResolver`) on a compact local database (`ip_to_country.bin`). Flags are rendered as native browser emojis with full country hover tooltips in the session log UI.
- CLI Tool: Added `tools/update_geoip.php` to fetch DB-IP Lite country CSV databases and compile them into the optimized binary database format.
- Testing: Integrated `GeoIPTest.php` to verify offline database country matching and emoji flag conversions.
- Fix: Prevented SAML SSO auto-redirect logic from running on AJAX/sub-requests (e.g., translation fetch) to avoid session state phase corruption and ensure top-level pages correctly redirect to the IdP when SSO is enforced.
- Architecture: Added ADR 0011 documenting the decision to skip SAML SSO redirection on AJAX requests.
- Fix: Updated session log UI to render country flags using FlagCDN PNG images rather than native OS-dependent emoji characters, ensuring correct rendering on platforms like Windows.
- Fix: Automatically disable the SSO Enforce option (`enforce_sso`) via `ConfigEntity::validateAdvancedConfig()` when multiple active IDPs exist, and display a warning inline in the configuration form rather than as a redirect message.
- Architecture: Added ADR 0012 explaining the multi-IDP Enforce conflict resolution mechanism and the explicit design decision not to follow the GLPI standard redirect-based messages.
- Feature: Added administrative action to forcefully log off and disable users directly from the active sessions log table.
- Architecture: Added ADR 0013 documenting the design and security of the force logoff and disable user action.


**V1.2.7**
- Bugfix for regression bug in `acs.php:228` where method getErrors didnt exist https://github.com/DonutsNL/samlsso/issues/104

**V1.2.6**
- Security Fix: Resolved critical authentication bypass vulnerability in exclude path matching logic by parsing URL path component before matching.
- Security Fix: Implemented strict "Open Redirect" protection. The `redirect` parameter is now validated to allow only relative paths, preventing attackers from using GLPI as an open relay.
- Security Fix: Hardened error logs by implementing conditional redaction. Sensitive SAML data is now redacted by default unless "Debug" is explicitly enabled in the IDP configuration.
- Fix: Replaced non-existent `DBConnection::getDefaultDatabase()` with `$DB->dbdefault` for compatibility with GLPI 10.0+.
- Architecture: Added ADR 0002 documenting the rationale for LoginState hardening and DB compatibility fixes.
- Architecture: Added ADR 0003 documenting the new Integration Testing Strategy.
- Testing: Implemented a lightweight test harness in `tests/` with shims for GLPI core, allowing validation of core logic paths (Enforced redirect, Domain selection, Bypass) without a full GLPI environment.
- Documentation: Created `WIKI_UPDATES.md` and `PROPOSED_ISSUE_TESTABILITY.md` to guide future improvements.
- Improve generation scripts in tools directory to include more info.
- Improvement: Added index to logging table via installation/upgrade as suggested by @Neozlag
- Improvement: Reviewed and added completed es_AR translation
- Fix: Alter logout location preventing it from being processed to early
- Improvement: Added a warning about PHP memory_limit - by @eduardomozart
- Improvement: JIT handling: Remove default profiles only if profiles have been successfully assigned - by @eduardomozart
- Fix: Update User.php fix ENTITY_DEFAULT and PROFILE_DEFAULT variables undefined key warinings - by @eduardomozart
- Fix: Corrected constant name of entity for prepared not yet implemented reaply JIT function `src/LoginFlow/LoginFlowItem.php`:210 - by @eduardomozart
- Improvement: Check for numeric srtings too instead of pure int - by @tomas321. 

**V1.2.5 - BUGFIX RELEASE**
- Provide bugfix for critical bug https://github.com/DonutsNL/samlsso/issues/38.
- Fix incorrect plugin paths issue https://github.com/DonutsNL/samlsso/issues/41

**V1.2.4**
- Added webhook.php to excludes https://github.com/DonutsNL/samlsso/issues/32
- Added `.php` to excludes path in `Config.php` to make sure the 'add' button shows.
- Altered `getConfigIdByEmailDomain()` to handle multiple domains per IDP using comma seperated domain lists i.e. `domain1.com,domain1.nl,domain1.org`
- Altered the description of the userDomain field explaining the multiple domain option.
- Added basics in `performSamlSSO()` and `doAuth()` for Subject hinting, but this isnt supported by Entra. Maybe make this a configurable option in the future for IDPs that do support Subject hinting.
- Add acs exclusion in doAuth flow https://github.com/DonutsNL/samlsso/issues/29
- Remove excessive state inits in authFlow object.
- Fix issue in logout method causing warnings to be logged by Html object. https://github.com/DonutsNL/samlsso/issues/35
- Updated the credits in the Readme.md (long overdue sorry for that)
- Updated all the translation projects in https://app.transifex.com/quinquies/glpisaml/
- Added /api.php to excludes as requested by https://github.com/DonutsNL/samlsso/issues/36**



**V1.2.2**
- Corrected a router bug that caused the http header bag and response objects to be called with __toString()
- Added an additional Prerequisites check to validate the php cookie settings and block install if they are not correct (issue 13).
- Corrected small linting issues in the src files.
- Corrected the `login_name` field in the `LoginFlow.php` file to capture the login domains. (issue 16).
- Added `Config::getHideLoginFields()` if domain based idp selection is used.
- Added CSS to `LoginFlow::showLoginScreen()` to hide the password, source and rememeber fields when domain based auth is used and buttons are hidden.
- Implemented the `?bypass=1` getter to bypass the hidden fields and enforcement.
- Fixed the logo url being incorrect.
- Fixed redirects to browser sided redirects to make sure request chains are reset and not tainted.
- Added logic for the logout functionality.
- Added logout template.
- Fixed bypass logic making sure no loops occur.
- Fixed issue https://github.com/DonutsNL/samlsso/issues/27 with undefined tabs in twig.
- Fixed issue with state database not aligning state entries correctly after ACS redirect.
- Added logic for logout function https://github.com/DonutsNL/samlsso/issues/26
- Removed old, double init logic in state object.
- Fully Refined the state objects logic to prevent duplicate entries
- Implemented 'logout' catching mechanism that allows user to log out everywhere if desired.
- Added custom exceptions for saml State object not catched by design.
- Fix faulty Twig variable in configForm.
- Added logout URLs to controller
- Added logout URLs to config screen.


**V1.2.1**
- Added new bootstrap function to `setup.php` to register stateless paths.
- Disabled generic config tab untill fully implemented.
- Corrected typing issue in `LoginState.php:544` bool should be int.
- Corrected the authflow to seamlessly follow the GLPI auth.
- Added logic to reinitiate statefull redirect after stateless init at ACS.
- Removed deprecated CSRF_COMPLIANT hook.
- Fixed a few typing issues caused by enforcing strict mode in all PHP files.
- Bumped version to 1.2.1 to allow upgrade for those who tested with old crappy version.
- Updated the samlsso.xml and removed all old non compatible codeberg artifacts.
- Removed unsupported DisableCsrfCheck decorators from Controller routes.
- Refactored the `mkzip.sh` and added it to the tools directory.
- Upgraded onelogin/php-saml (4.2.0 => 4.3.0) to latest release
- Fixed branding and excludes in mkzip.sh
- Change to trigger git 

**v1.2.0**
- Updated the XMLseclibs to version 3.1.3
- Renaming plugin to samlSSO for better searchability
- Updated the credits
- `Config.php`:270 `getIsEnforced()` added `is_deleted` check to enforced query.
- Added `return ''; // Unreachable return but prevents PHP0405-no return linting error.` various places
- Fix non functional linting errors in `src/LoginFlow/User.php` paths without return value
- Fix constant and method naming in `hook.php`
- Fix constant and method naming in `Config/ConfigForm.php`
- Fix constant and method naming in `Config/ConfigEntity.php`
- Fix constant and method naming in `Config/ConfigItem.php`
- Renamed all the file headers
- Updated `samlsso.xml` with new name and repo.
- Added strict typechecking and corrected all typing issues `declare(strict_types=1);` 
-    @see: https://www.php.net/manual/en/language.types.declarations.php.

**v1.1.11**
- Removed the version validation from ConfigForm.php as its no longer used
- Added additional file logging for JIT operations to enable debugging for issues
- Optimized logrules for readability
- https://codeberg.org/QuinQuies/glpisaml/issues/111
- Added .pot generation script to tests folder
- Added fr_FR translations from https://app.transifex.com/quinquies/glpisaml/language/fr/
- Cleaned unused files and corrected file properties
- Added a fallback to use the nameId as email field if the email claim was set but didnt contain a valid emailadress.
- Added a not empty check to the emailadress claim and will now be ignored if the property was set with no actual value.

**v1.1.10**
- In preparation for 1.2.0
- https://codeberg.org/QuinQuies/glpisaml/issues/61
- https://codeberg.org/QuinQuies/glpisaml/issues/46
-  Added logic to automatically enforce saml configuration if there is only one configured with enforce enabled.
- Update template with compression enabled
- Added message with 'version' after install for saas validation purposes
- Upped minimal version: https://codeberg.org/QuinQuies/glpisaml/issues/65#issuecomment-2066465
- Upped the minimal required version in `setup.php` to GLPI 10.0.11 because plugin does not use deprecated `query()` but newer `doQuery()` instead.
- fixed warning in User.php file https://codeberg.org/QuinQuies/glpisaml/issues/71
- Removed unused 'use' inclusion in front/config.php https://codeberg.org/QuinQuies/glpisaml/issues/73
- Added gitignore to stop phpunit and deps from being send to the repository
- Updated `onelogin/php-saml` to latest version 4.2.0 @see https://github.com/SAML-Toolkits/php-saml/releases
- Changed `ConfigEntity.php:508` to add `?idpId=` to the ACS service URL send to the Idp for capture at ACS.
- Added wiki reference `https://codeberg.org/QuinQuies/glpisaml/wiki/ACS.php` in the acs error page to provide more information.
- Fully refactored `LoginFlow/Acs.php` and `/front/acs.php` to work arround the login cookie requirement.
- Fully refactored `src/LoginState.php` object to store and process additional fields samlRequestId, samlResponseId (InResponseTo), requestUnsolicited fields
- Refactored method LoginFlow::doAuth() for https://codeberg.org/QuinQuies/glpisaml/issues/45
- Refactored method LoginFlow::performSamlSSO for https://codeberg.org/QuinQuies/glpisaml/issues/45
- Added `tests/createPot.sh` to create a POT file from the php source using xgettext
- Added `locals/glpiSaml.pot` to allow users to translate and create localization files (PO/PM)
- Added https://app.transifex.com/quinquies/glpisaml/ project for public translations
- Started refactoring LoginFlow.php to include a LoginFlow configuration page.
- Fixed always enforced bug with only one idp configured and enforce off.
- Added loginFlow trace to the log idp page
- Removed extended location logging very problematic;
- Re-enabled the bypass option after removing no longer existing method;
- Extended update procedure to clean state table, and remove old cookies;
- Added locales for translations;
- Removed version check (causing timeouts if codeberg is offline)
- Removed hidden fields on enforce so enforce can be bypassed.

**v1.1.5**
- We found that the return value bool:false on the POST_INIT hook might break cron functionality in very nasty ways (removing user profiles after succesfull mail import for instance!) as a quick fix we now return null, making sure other components are not influenced by anything we did 'not' return to the calling plugin function. 

**v1.1.4**
- Aligned the menu icons and naming with TecLib's Oauth SSO Applications plugin in `src/Config.php`
- Altered `name` in `setup.php:122` to reflect plugin name correctly with value `Glpisaml`
- Altered `homepage` in `setup.php:125` to reflect correct GIT repository at `Codeberg.org`
- Altered menu name `src/RuleSaml.php` method `getTitle()` return value to  `JIT import rules`.
- Altered menu name `src/RuleSamlCollection.php` method `getTitle()` return value to `Jit import rules` 
- Altered JIT button name in `src/Config.php:142` to match the RuleCollection menu name `Jit import rules` 
- Added additional validation and warning to check if the example certificate `withlove.from.donuts.nl` is used in the configuration in `src/config/ConfigItem.php:599`.
- Added `dashboard.php` to the default excludes to prevent the plugin being called multiple times on dashboard load.
- Corrected spelling and typo's throughout the plugin files.
- Addressed issue https://codeberg.org/QuinQuies/glpisaml/issues/36
- Corrected and finished Excludes configuration. Excluded paths will now not be processed, but will be logged (for debugging purposes) in the `glpi_plugin_glpisaml_loginstates` table.
- Fixed https://codeberg.org/QuinQuies/glpisaml/issues/42
- Refactored IF statement in `loginFlow.php:138` to be more compact.
- Moved the `getUserInputFieldsFromSamlClaim` method from the `LoginFlow` class to `LoginFlow\User\` class.
- Simplified the `getUserInputFieldsFromSamlClaim` by only supporting the soap identity claims.
- Simplified the `getUserInputFieldsFromSamlClaim` by trusting the nameId validation of OneLogin and allowing all passed values.
- Made sure that `nameId` is now always mapped to `glpiUser->name` field
- Previous 2 changes now also explicitly allow you to use `samaccountname` as valid nameId
- Added `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/firstname` or `givenname` claim to be processed by userJit if provided
- Added `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname` claim to be processed by userJit if provided
- Added `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/mobilephone` claim to be processed by userJit if provided
- Added `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/telephonenumber` claim to be processed by userJit if provided
- Added `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/groups` to be passed to the rules engine (no match rule yet)
- Added `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/jobtitle` to be passed to the rules engine (no match rule yet)
- Added `user-fields->authtype = 4 (other)` to Jit Created users as discussed https://codeberg.org/QuinQuies/glpisaml/issues/41
- JIT wil now populate sync_date property
- Added location claims to the logic, they are currently not handled.
- Implemented the `enforced` option, enforcing automatic login if the user selected its IdP in a previous session using a `enforce_saml` cookie.
- Implemented the `?bypass=1` option to bypass the enforced login for troubleshooting.
- Enforce will now also `hide` the password,
- Users are now allowed to select the correct idp using a `?samlIdpId=ID` get parameter for Idp Initiated logins. 

**v1.1.3**
- Added logic to store the initial sessionId for reference in state table.
- Altered error messages in `/front/meta.php` to be more generic less helpful for added security
- Added method `getConfigIdByEmailDomain` to `src/config.php` to get IDP ID based on given CONF_DOMAIN
- Added Method `getConfigDomain` to `src/configEntity.php` to fetch the CONF_DOMAIN from the fetched entity used
  to evaluate if the button for the entity needs to be shown.
- Extended `doAuth` in `src/LoginFlow.php` to also evaluate username field in login screen and match it
  with idp configured userdomain. This allows a user to simply 'provide' its username and press login triggering
  a saml request if the domain in the username matches a given idp's userdomain configuration.
- Updated the loginbutton logic to not show on the login page if there are no buttons to show.
- Added a test `popover` in the config screen with the `copy meta url button` to see if that cleans 
  the configuration further and how that would look and feel. Considering to leave it and see if 
  and how ppl respond to it.
- Added logic to `generateForm` in `src\Config\ConfigForm.php` to detect if the login button will be hidden
- Added errorhelpers to `templates/configForm.html.twig` to warn users the login button will be hidden.
- Added errorhelpers to `templates/configForm.html.twig` to explain userdomain behavior if configured.
- Fixed issue https://codeberg.org/QuinQuies/glpisaml/issues/20
- Added saml cookies to help plugin correctly track session on redirect with session.cookie_samesite = strict.
- Added additional logic to `src/loginState.php` hardening the logic
- Added meta redirect to deal with session.cookie_samesite = strict after Saml Redirect back to GLPI
- Added additional explanations to config item in `src/Config/ConfigItem.php`
- Fixed issue https://codeberg.org/QuinQuies/glpisaml/issues/30
- Added `is_deleted = 0` filter in `src/Config.php` method `getLoginButtons`
- Fixed issue https://codeberg.org/QuinQuies/glpisaml/issues/31
- Implemented https://codeberg.org/QuinQuies/glpisaml/issues/14
- Added additional validations on certificate validation method in `src/Config/ConfigItem.php` method `parseX509Certificate` 
