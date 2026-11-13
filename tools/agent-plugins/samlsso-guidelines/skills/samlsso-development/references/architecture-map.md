# samlSSO Architecture, Call-Flow & Test Matrix

## 1. End-to-End Request Lifecycle

### A. Unauthenticated Request / Login Screen (`POST_INIT` Hook)
1. **`setup.php` -> `plugin_samlsso_evalAuth()` (`hook.php`)**:
   - Skips CLI (`isCommandLine()`) and excluded paths/user-agents (`Exclude::isExcluded()`).
   - Instantiates `LoginFlow` and calls `LoginFlow::doAuth()`.
2. **`LoginFlow::doAuth()` (`src/LoginFlow.php`)**:
   - Skips `/front/acs` (`SamlSsoController::ACS_ROUTE`) so the stateless POST callback is handled exclusively by `SamlSsoController::acs()` -> `Acs::init()`.
   - Loads `LoginState` (`new LoginState()`).
   - Evaluates administrative revocations (`PHASE_FORCE_LOG (6)`) and expired handshakes (`PHASE_TIMED_OUT (7)`) **before** AJAX or login-button checks (returning HTTP 401 on AJAX or redirecting to `/index.php?noAuto=1`).
   - Skips background AJAX requests (`Toolbox::isAjax()`).
   - Throttles stale ACS cleanup (`LoginState::expireStaleAcsRequests()`) every 60 seconds.
   - Captures deep-link target URLs server-side in `LoginState::setRedirect()` (validated via `getSafeRedirect()` and `isStaticAssetRedirect()`).
   - Handles SLO logout (`/front/logout` / `SamlSsoController::SLO_ROUTE`), emergency bypass (`interceptBypass()` with `hash_equals`), IdP button clicks (`$_POST[LoginFlow::POSTFIELD]` / `$_GET[LoginFlow::GETFIELD]`), email-domain discovery (`resolveIdpFromLoginForm()`), and single-IdP `enforce_sso` auto-redirect.
   - Dispatches SP-initiated `AuthnRequest` via `LoginFlow::performSamlIdpRequest()`, which refreshes `LOGIN_DATETIME`, generates per-flow CSRF token `$flowToken` (sent as `RelayState`), registers `SAML_REQUEST_ID`, sets `PHASE_SAML_ACS (2)`, and redirects the browser to the IdP.

### B. Assertion Consumer Service (`POST /plugins/samlsso/front/acs/{idpId}`)
1. **`SamlSsoController::acs()` (`src/Controller/SamlSsoController.php`) -> `Acs::init()` (`src/LoginFlow/Acs.php`)**:
   - Registered as stateless via `SessionManager::RegisterPluginStatelessPath` in `setup.php:146-147` (alongside `SLO_ROUTE`), causing GLPI Core's `SessionStart::onPostBoot()` to skip `Session::start()` and set `ini_set('session.use_cookies', '0')` so cross-site IdP callbacks never depend on browser cookies surviving the redirect.
   - Enforces HTTP POST method and rejects query-parameter `SAMLResponse`.
   - Validates target `ConfigEntity($idpId)` (`id > 0`, `isValid()`, `isActive()`, `!isDeleted()`).
   - Initializes `OneLogin\Saml2\Response` in `Acs::setupSamlResponse()` using `$this->configEntity->getPhpSamlConfig()`.
   - Calls `Acs::fetchRequestState()`:
     - Extracts `InResponseTo` from the XML root.
     - **Double-Pass Solicited Flow (`ADR 0001`)**: If `InResponseTo` is empty or `LoginState::getSamlRequestId()` is empty (unsolicited / IdP-initiated response), persists the `PHASE_INITIAL (1)` state (`SAML_UNSOLICITED = 1`, `unsolicitedReauthRedirect` trace) and calls `performSamlIdpRequest()` to bounce the browser through a tracked solicited `AuthnRequest` cycle.
   - Verifies state `idpId === $routeIdpId` and validates `RelayState` against `LoginState::getFlowToken()` via `hash_equals()`.
   - Calls `Acs::assertSaml()`:
     - If `getPhase() === PHASE_TIMED_OUT (7)`, calls `handleRequestTimeout()` (flash error message + redirect to `/index.php?noAuto=1`).
     - Requires `getPhase() === PHASE_SAML_ACS (2)` and non-empty `$expectedRequestId`.
     - Verifies `$this->samlResponse->isValid($expectedRequestId)`, `SubjectConfirmationData/@InResponseTo === $expectedRequestId`, and unique assertion/response IDs (`setSamlResponseId()`).
     - Transitions state to `PHASE_SAML_AUTH (3)`.
   - Calls `LoginFlow::performGlpiLogin()` -> `User::getOrCreateUser()`:
     - Binds user to `Auth::EXTERNAL` and `auths_id = $idpId`, blocks local `Auth::DB_GLPI` / `glpi` accounts, and runs JIT or `sync_on_login` (`RuleSamlCollection`).
     - Re-enables cookies (`ini_set('session.use_cookies', 1)`), destroys any transient session, mints a fresh stateful GLPI session (`Session::start()` + `Session::init($auth)` -> `PHASE_GLPI_AUTH (4)`), rebinds the `LoginState` row to the new `session_id()` (`$state->setSessionId()`), and calls `LoginFlow::performBrowserRedirect()` (`HTTP 200 OK` `<meta http-equiv="refresh">`) to reset the browser's cross-site request chain so `session.cookie_samesite = "Strict"` (and `"Lax"`) cookies work seamlessly.

### C. Per-IdP SCIM 2.0 Provisioning (`/plugins/samlsso/front/scim/v2/{idpId}/{subpath}`)
1. **`SamlSsoController::scim()` -> `ScimRouter::dispatch()` (`src/Scim/ScimRouter.php`)**:
   - Registered as stateless via `SessionManager::registerPluginStatelessPath` (`SamlSsoController::SCIM_ROUTE`) and bypassed in `Exclude::isExcluded()` and `LoginFlow::doAuth()`.
   - `ScimAuthenticator::authenticate()` enforces HTTPS (`$request->isSecure()`), active/valid/enabled `ConfigEntity($idpId)`, optional IPv4/IPv6 CIDR allowlist (`scim_allowed_ips` via `LoginState::resolveClientIp()`), `10/60s` failed-auth rate limiting, and constant-time `hash_equals()` Bearer verification (`db` SHA-256 digest or `env`/`define` >= 32 chars).
   - Dispatches RFC 7643/7644 discovery (`ScimDiscoveryService`), `/Users` (`ScimUserService`), and `/Groups` (`ScimGroupService`) using bounded local `ScimFilterParser` (max 32 tokens, depth 4) and `ScimPatchProcessor` (max 1 MiB, 100 operations).
   - `ScimAttributeMapper` translates standard SCIM Core + Enterprise 2.0 JSON independently of SAML `ClaimMap` and feeds `RuleSamlCollection` downstream (`scimDepartment`, `scimOrganization`, `scimDivision`, `scimCostCenter`, `scimEmployeeNumber`, `groups`).
   - Enforces `(Auth::EXTERNAL, $idpId)` + `ScimLink` (`glpi_plugin_samlsso_scimlinks`) ownership, `is_dynamic = 1` group memberships under `scim_group_policy` (`create_scoped` | `link_existing` | `disabled`), and immediate offboarding (`is_active = 0`, GLPI 12 `glpi_usertokens` / GLPI 11 `cookie_token` cleanup, and `LoginState::forceLogoffForUser()` -> `PHASE_FORCE_LOG`).

---

## 2. Production Source to Automated Test Suite Matrix

| Subsystem / Source File(s) | Primary Automated Test Suite(s) |
| :--- | :--- |
| `docs/ADR/0000`–`0008`, `CONTRIBUTING.md`, `SKILL.md` | `tests/AdrComplianceTest.php` |
| `src/LoginFlow/Acs.php` | `tests/AcsTest.php` |
| `src/LoginState.php` | `tests/LoginStateTest.php` |
| `src/LoginFlow.php` | `tests/FlowLogicTest.php`, `tests/RegressionTest.php` |
| `src/LoginFlow/User.php`, `src/RuleSaml*.php` | `tests/UserProvisioningTest.php` |
| `src/Scim/*.php`, `src/ScimLink.php` | `tests/ScimAuthTest.php`, `tests/ScimProtocolTest.php`, `tests/ScimProvisioningTest.php` |
| `tools/migrate_ldap_users.php` | `tests/LdapMigrationToolTest.php` |
| `src/Config.php`, `src/Config/ConfigEntity.php`, `src/Config/ConfigItem.php`, `src/Config/ConfigForm.php`, `templates/configForm*.twig` | `tests/ConfigIntegrityTest.php`, `tests/ConfigCachingTest.php`, `tests/CertValidationTest.php` |
| `src/Config/ClaimMapEntity.php`, `src/Config/ClaimMapItem.php`, `src/ObservedClaim.php` | `tests/ClaimMappingTest.php` |
| `src/Exclude.php` | `tests/ExcludeTest.php` |
| `src/CronTask.php` | `tests/CronTaskTest.php` |
| `src/Utility/GeoIPResolver.php`, `src/Utility/GeoIPUpdater.php` | `tests/GeoIPTest.php` |
| `src/Utility/SanityChecker.php` | `tests/SanityCheckerTest.php` |
| `src/compat.php`, `setup.php` | `tests/Glpi12CompatTest.php`, `tests/GlpiComplianceTest.php`, `tests/VersionAndCopyrightTest.php` |
| `hook.php` (`install` / `uninstall` / migrations) | `tests/LifecycleIntegrityTest.php`, `tests/DatabaseUpdateTest.php` |

