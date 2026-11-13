# ADR 0004: Fail-Closed Configuration, JIT Provisioning & Access Control

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
Every Identity Provider lookup, Just-In-Time (JIT) user creation or synchronization decision, administrative action, and SSO bypass **must fail closed** whenever configuration validity, authentication source ownership, or user authorization cannot be positively verified. Template fallback configurations (`id = 0`) and unlinked local accounts may never be used to authenticate or provision a user.

---

## Architectural Invariants

### 1. Fail-Closed IdP Configuration Loading & Explicit Propagation
1. `ConfigEntity::getConfigById()` and `Acs::init()` / `Acs::assertSaml()` strictly verify that the target IdP exists in `glpi_plugin_samlsso_configs` (`id > 0`), is valid (`isValid()`), is active (`isActive()`), and is not soft-deleted (`!isDeleted()`).
2. `Acs::init()` and `Acs::assertSaml()` verify that the session state's `idpId` matches the ACS route's `idpId` to prevent cross-IdP assertion substitution.
3. The verified `ConfigEntity` instance is passed explicitly down the call chain (`Acs::init()` -> `performGlpiLogin()` -> `loadUser()` -> `getOrCreateUser()` -> `performJIT()`). The user-provisioning layer never guesses the IdP ID or falls back to `new ConfigEntity(0)` (`ConfigDefaultTpl`).

### 2. Account Takeover Prevention in JIT & `sync_on_login`
1. `User::getOrCreateUser()` and `User::performJIT()` bind every SAML-authenticated user to their explicit external authentication source (`authtype = \Auth::EXTERNAL` and `auths_id = $idpId`). Runtime binding or automatic hijacking of `Auth::LDAP` or any non-SAML authentication source is strictly prohibited; legacy LDAP-imported accounts are migrated offline via the non-destructive, reversible `tools/migrate_ldap_users.php` CLI utility (`0600` `GLPIKey` HMAC-signed user-ID register + active `glpi_authldaps` collision guard).
2. Candidate resolution (`User::findCandidateUsers()`) queries `glpi_users` and `glpi_useremails` directly via parameterized `$DB->request()` rather than single-row `CommonDBTM::getFromDBByCrit()` / `User::getFromDBbyEmail()` helpers so duplicate `name` or `email` rows across multiple IdPs never throw `TooManyResultsException`. If **any** candidate row is a local GLPI account (`authtype = \Auth::DB_GLPI` or `name = 'glpi'`), authentication fails closed immediately; otherwise only active, non-deleted rows bound to `(\Auth::EXTERNAL, $idpId)` are eligible, and fallback email matching requires `sync_on_login = 1`.
3. Disabled GLPI users (`is_active = 0`) are unconditionally blocked from logging in (`User::getOrCreateUser()`).
4. When `sync_on_login` is enabled on an IdP, `User::syncUserFieldsOnLogin()` updates mapped claim attributes and re-evaluates the SAML Rules Engine (`runRulesEngine()`), while stripping protected system columns (`User::AUTHTYPE`, `User::PASSWORD`, `User::PASSWORDN`, `User::COMMENT`, `user_dn`, `auths_id`, `password_md5`, `api_token`, `personal_token`) so SAML claims cannot escalate privileges or overwrite local credentials.

### 3. Multi-IdP `enforce_sso` Conflict Prevention
1. Auto-redirecting unauthenticated users (`enforce_sso = 1`) is only possible when exactly one active, non-deleted IdP configuration exists (`Config::getIsOnlyOneConfig()`).
2. If more than one active IdP exists, `ConfigEntity::validateAdvancedConfig()` automatically forces `enforce_sso = 0` and attaches an inline warning (`⚠️`) to the `enforce_sso` field so conflicting settings are corrected before database persistence.

### 4. Token-Guarded SSO Bypass & Administrative Access Control
1. The `?bypass=1` emergency local-login parameter fails closed unless `PLUGIN_SAMLSSO_BYPASS_TOKEN` or `SAMLSSO_BYPASS_TOKEN` is configured on the server and matched via constant-time `hash_equals()`. All authorized and unauthorized bypass attempts are recorded in GLPI's security audit log, and bypass session flags are cleared upon login.
2. Administrative state-changing actions in `ConfigForm` (configuration save, backup, restore, certificate generation, and `forcelogoff`) enforce `Session::checkRight('config', UPDATE)` and HTTP POST / CSRF validation. Restored backup rows pass full `ConfigEntity` validation before insertion.
3. SAML JIT rule management (`RuleSaml`, `RuleSamlCollection`, `src/compat.php`) enforces the `rule_ldap` authorization right.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** instantiate `new ConfigEntity(0)` or rely on `ConfigDefaultTpl` defaults during an authentication or JIT provisioning flow.
- **NEVER** link a SAML assertion by email or username to a local GLPI user (`authtype = \Auth::DB_GLPI`), the `glpi` super-admin account, an `Auth::LDAP` account at runtime, or a user bound to a different `auths_id`.
- **NEVER** clone duplicate `glpi_users` rows when migrating LDAP users to SAML; always re-bind existing rows in-place via `tools/migrate_ldap_users.php` with a signed reversible register and deactivated colliding `glpi_authldaps` sync.
- **NEVER** allow `syncUserFieldsOnLogin()` to modify `authtype`, `password`, `auths_id`, or `is_active`.
- **NEVER** allow `?bypass=1` to bypass enforced SSO without a verified server-side bypass token.

---

## Issue Triage & Maintainer Directives
- **When a user reports that an existing local GLPI account is not automatically linked on SAML login:**
  - Explain that automatic linking of local database accounts (`authtype = 1`) via email/username is blocked by design to prevent Account Takeover (ATO) via unverified or shared email claims. Instruct the administrator either to allow JIT to provision the external user or to set the user's authentication source in GLPI to SAML External before enabling `sync_on_login`.
- **When a user asks why `Enforce SSO` turns off automatically when adding a second IdP:**
  - Explain that browsers cannot automatically redirect to two Identity Providers simultaneously; when multiple active IdPs exist, users must either select their IdP on the login screen or match via Email Domain discovery (`conf_domain`).

---

## Codebase & Automated Test Verification
- **Production Files**: `src/LoginFlow/User.php`, `src/Config/ConfigEntity.php`, `src/Config/ConfigForm.php`, `src/Config.php`, `src/LoginFlow.php`, `src/RuleSaml.php`, `src/RuleSamlCollection.php`, `tools/migrate_ldap_users.php`
- **Automated Tests**: `tests/UserProvisioningTest.php`, `tests/LdapMigrationToolTest.php`, `tests/ConfigIntegrityTest.php`, `tests/FlowLogicTest.php`, `tests/AdrComplianceTest.php`
