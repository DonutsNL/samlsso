# ADR 0027: Security Audit Remediation and Hardening for samlSSO 1.3.2

## Status
Accepted

## Context
A security review of samlSSO version 1.3.1 identified vulnerabilities across access control, SAML assertion processing, stored XSS in administrative logs, account takeover protections, secret management, error logging dumps, and request lifecycle management.

## Decisions

1. **Administrative Access Control**:
   - Enforce `Session::checkRight('config', UPDATE)` on configuration backup, restore, and force logoff actions in `src/Config/ConfigForm.php`.
   - Perform full `ConfigEntity` validation pass on imported backup records before database insertion in `restoreConfigEntry()`.

2. **SAML Assertion Validation and Strict Mode**:
   - Enforce `strict => true` in `Acs::setupSamlResponse()` and require that either `wantAssertionsSigned` or `wantMessagesSigned` is enabled for assertion verification.
   - Enforce IdP validation in `Acs::init()` to ensure IdP exists, is active, is not deleted, and is structurally valid.
   - Guard against cross-IdP assertion substitution in `Acs::assertSaml()` by verifying that the session state IdP ID matches the ACS route IdP ID.
   - Preserved demo and test keys in `ConfigDefaultTpl.php` with configuration validation warnings for administrator guidance.

3. **Output Encoding and Stored XSS Remediation**:
   - Sanitize login flow trace log entries in `src/LoginState.php` using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` before embedding in HTML output.
   - Escape proxy server headers in `src/Config/ConfigItem.php`.
   - Sanitize popover and help text helper variables in `templates/configForm.html.twig`.

4. **Account Takeover and Provisioning Guards**:
   - In `src/LoginFlow/User.php`, block SAML JIT provisioning from linking to or overwriting local GLPI accounts (`authtype = Auth::DB_GLPI`) or the default super-administrator account (`glpi`).
   - Strip protected system fields (`authtype`, `password`, `user_dn`, `auths_id`, `comment`, `is_active`) from the updated fields array during `syncUserFieldsOnLogin()`.

5. **Permission Alignment**:
   - Realign SAML JIT rule authorization right from `rule_import` to `rule_ldap` in `src/compat.php`, `src/RuleSaml.php`, and `src/RuleSamlCollection.php`.

6. **SP Private Key Security and Secret Masking**:
   - In `src/Config/ConfigEntity.php`, encrypt SP private keys using GLPI core `GLPIKey` before database persistence, and decrypt upon configuration retrieval.
   - Exclude `sp_private_key` from GLPI search options (`rawSearchOptions()`) and register it in `getUndisclosedFields()`.
   - Strip `sp_private_key` from backup tables and JSON exports in `src/Config.php` and `src/Config/ConfigForm.php`.

7. **Log Sanitization and Secret Leakage Prevention**:
   - In `src/Config/ClaimMapEntity.php`, replace raw `var_export($response, true)` calls with safe diagnostic summaries (Response ID, NameID, field name).
   - In `src/LoginFlow/Acs.php`, replace raw `$_SERVER` dumps with an allowlist of non-sensitive request attributes (`REQUEST_METHOD`, `REMOTE_ADDR`, `REQUEST_URI`, `HTTP_HOST`, `HTTP_USER_AGENT`).
   - In `src/LoginFlow.php`, redact `sp['privateKey']` from error dumps during `samlAuth` initialization.

8. **SSO Bypass Hardening**:
   - Harden `?bypass=1` in `src/LoginFlow.php` and `setup.php` to validate against an optional secret token (`PLUGIN_SAMLSSO_BYPASS_TOKEN` or `SAMLSSO_BYPASS_TOKEN`).
   - Audit-log authorized and unauthorized local login attempts, and clear bypass flags from the session namespace upon successful authentication.

9. **CSRF and Binding Protection**:
   - Enforce HTTP POST method and CSRF token validation on `forcelogoff` in `src/Config/ConfigForm.php`.
   - Enforce HTTP-POST binding on `src/LoginFlow/Acs.php` by rejecting GET requests and query-parameter responses.

10. **Session Lifecycle & Stale Cleanup Scalability**:
    - Reorder `LoginFlow::doAuth()` to evaluate `PHASE_FORCE_LOG` and `PHASE_TIMED_OUT` before AJAX and exclusion early returns, terminating AJAX requests with HTTP 401.
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
    - Fix exclude path matching in `tools/mkzip.sh`.

## Consequences
- **Positive**:
  - Resolves all 20 audit findings and quality items without breaking existing SAML flows.
  - Hardens cryptographic key management, authentication state transitions, and administrative actions.
  - Eliminates external network dependencies from the logging interface.
  - Complete automated test suite (19 test files) passes with full coverage.
- **Negative**:
  - Encrypted private keys require the GLPI installation key file (`glpi.key`) to remain intact across migrations.
