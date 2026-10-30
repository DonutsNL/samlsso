# ADR 0027: Security Audit Remediation and Hardening for samlSSO 1.3.2

Fix for issue: https://github.com/DonutsNL/samlsso/issues/180

## Status
Accepted

## Context
A security review of samlSSO version 1.3.1 identified vulnerabilities across access control, SAML assertion processing, stored XSS in administrative logs, account takeover protections, secret management, error logging dumps, and request lifecycle management (tracked in issue https://github.com/DonutsNL/samlsso/issues/180).

## Decisions

1. **Administrative Access Control**:
   - Enforce `Session::checkRight('config', UPDATE)` on configuration backup, restore, and force logoff actions in `src/Config/ConfigForm.php`.
   - Perform full `ConfigEntity` validation pass on imported backup records before database insertion in `restoreConfigEntry()`.

2. **SAML Assertion Validation and Strict Mode**:
   - Enforce `strict => true` in `Acs::setupSamlResponse()` and require that either `wantAssertionsSigned` or `wantMessagesSigned` is enabled for assertion verification.
   - Enforce IdP validation in `Acs::init()` and `Acs::assertSaml()` to ensure the IdP exists in the database (`ConfigEntity::getField(ConfigEntity::ID)` returns the numeric ID string rather than casting to boolean, and missing database IDs mark the entity invalid), is active, and is not deleted.
   - Guard against cross-IdP assertion substitution in both `Acs::init()` and `Acs::assertSaml()` by verifying that the session state IdP ID matches the ACS route IdP ID.
   - Ship `ConfigDefaultTpl.php` with empty `SP_CERTIFICATE`, `SP_KEY`, and `IDP_CERTIFICATE` fields, `STRICT => true`, and `SECURITY_WANTASSERTIONSSIGNED => true`, while providing an integrated OpenSSL self-signed certificate generator in the configuration UI.

3. **Output Encoding and Stored XSS Remediation**:
   - Sanitize login flow trace log entries directly on `$formattedParts[]` and `$trace .=` assignments in `src/LoginState.php` using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
   - Escape proxy server headers in `src/Config/ConfigItem.php`.
   - Enable the Bootstrap popover sanitizer (`sanitize: true`) with an explicit `allowList` permitting diagnostic table elements (`table`, `thead`, `tbody`, `tr`, `th`, `td`) and escape claim-mapping row attribute values via `escapeAttr()` in `templates/configForm.html.twig`.

4. **Account Takeover and Provisioning Guards**:
   - In `src/LoginFlow/User.php`, constrain fallback email lookup (`getFromDBbyEmail()`) in `getOrCreateUser()` behind the per-IdP `sync_on_login` opt-in setting and bind accounts to their authentication source (`authtype = Auth::EXTERNAL` and `auths_id = $idpId`).
   - Block SAML JIT provisioning from linking to or overwriting local GLPI accounts (`authtype = Auth::DB_GLPI`), the default super-administrator account (`glpi`), or accounts bound to a different authentication source or IdP.
   - Strip protected system fields (`authtype`, `password`, `user_dn`, `auths_id`, `comment`, `is_active`) from the updated fields array during `syncUserFieldsOnLogin()`.

5. **Permission Alignment**:
   - Realign SAML JIT rule authorization right from `rule_import` to `rule_ldap` in `src/compat.php`, `src/RuleSaml.php`, and `src/RuleSamlCollection.php`.

6. **SP Private Key Security and Secret Masking**:
   - In `src/Config/ConfigEntity.php`, `src/Config.php`, and `src/Config/ConfigForm.php`, encrypt SP private keys using GLPI core `GLPIKey` across all write paths (add, update, restore, and `Config::install()` migration for existing plaintext rows), and decrypt upon configuration retrieval in `getPhpSamlConfig()` and `resolvePrivateKey()`.
   - Blank the `sp_private_key` value in `ConfigForm::generateForm()` and `templates/configForm/provider.html.twig` when using database storage so the stored key is never rendered into HTML, while preserving the existing encrypted key in the database when the form is saved with the blank placeholder.
   - Exclude `sp_private_key` from GLPI search options (`rawSearchOptions()`) and register it in `getUndisclosedFields()`.
   - Strip `sp_private_key` from backup tables and JSON exports in `src/Config.php` and `src/Config/ConfigForm.php`.

7. **Log Sanitization and Secret Leakage Prevention**:
   - In `src/Config/ClaimMapEntity.php`, replace raw `var_export($response, true)` calls with safe diagnostic summaries (Response ID, NameID, field name).
   - In `src/LoginFlow/Acs.php`, replace raw `$_SERVER` dumps with an allowlist of non-sensitive request attributes (`REQUEST_METHOD`, `REMOTE_ADDR`, `REQUEST_URI`, `HTTP_HOST`, `HTTP_USER_AGENT`).
   - In `src/LoginFlow.php`, redact `sp['privateKey']` from error dumps during `samlAuth` initialization.

8. **SSO Bypass Hardening**:
   - Harden `?bypass=1` in `src/LoginFlow.php`, `setup.php`, and `src/Config.php` to fail closed unless `PLUGIN_SAMLSSO_BYPASS_TOKEN` or `SAMLSSO_BYPASS_TOKEN` is configured and matched via constant-time `hash_equals()`.
   - Audit-log authorized and unauthorized local login attempts, and clear bypass flags from the session namespace upon successful authentication.

9. **CSRF, Binding Protection, and State-Machine Re-Authentication**:
   - Enforce HTTP POST method and CSRF token validation on `forcelogoff` in `src/Config/ConfigForm.php`.
   - Enforce HTTP-POST binding on `src/LoginFlow/Acs.php` by rejecting GET requests and query-parameter responses, pass a per-flow anti-CSRF token as `RelayState` in `LoginFlow::performSamlIdpRequest()`, and validate it against the `LoginState` record via `hash_equals()` in `Acs::init()`.
   - Reject direct unsolicited authentication in `Acs::fetchRequestState()` and `Acs::assertSaml()`: when `InResponseTo` is empty or unregistered in `LoginState`, persist a new initial state and redirect the browser back to the IdP via `performSamlIdpRequest()` to re-authenticate through the state machine (see [ADR 0036](0036-enforce-state-machine-reauth-on-unsolicited-responses.md)).

10. **Session Lifecycle & Stale Cleanup Scalability**:
    - Reorder `LoginFlow::doAuth()` to run `LoginState::expireStaleGlpiSessions()` and evaluate `PHASE_FORCE_LOG` and `PHASE_TIMED_OUT` before AJAX and exclusion early returns, terminating AJAX requests with HTTP 401.
    - Convert stale session cleanup in `src/LoginState.php` from N+1 row-by-row SELECT/UPDATE loops to batch `$DB->update()` queries.

11. **Lifecycle Integrity**:
    - In `hook.php`, clean up SAML JIT rules from `glpi_rules`, `glpi_rulecriterias`, and `glpi_ruleactions` upon plugin uninstallation.

12. **Code Quality and Hygiene**:
    - Correct operator precedence bug in `src/LoginState.php:198` (`$sessionIterator->numrows() !== 1`).
    - Remove `LIBXML_NOENT`, `LIBXML_DTDLOAD`, and `LIBXML_DTDATTR` from `ConfigEntity::anonymizeXml()`, using `LIBXML_NONET | LIBXML_NOCDATA`.
    - Add `['allowed_classes' => false]` to all `unserialize()` calls in `src/LoginState.php`.
    - Check `$auth->getErrors()` before `Session::init($auth)` in `src/LoginFlow.php`.
    - Prefix global `updateUser()` hook to `plugin_samlsso_updateUser()`.
    - Replace external `flagcdn.com` image downloads in `templates/configForm/logging.html.twig` with offline Unicode country flag emoji.
    - Cache `SHOW COLUMNS FROM` metadata per database instance in `ConfigEntity::getFields()`, remove legacy `or die($DB->error())` calls, escape flash message emails and localize strings with `sprintf(__('...', PLUGIN_NAME), ...)`, and fix exclude path matching in `tools/mkzip.sh`.

## Consequences
- **Positive**:
  - Resolves all 20 audit findings and quality items without breaking existing SAML flows.
  - Hardens cryptographic key management, authentication state transitions, and administrative actions.
  - Eliminates external network dependencies from the logging interface.
  - Complete automated test suite (19 test files) passes with full coverage.
- **Negative**:
  - Encrypted private keys require the GLPI installation key file (`glpi.key`) to remain intact across migrations.
