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
