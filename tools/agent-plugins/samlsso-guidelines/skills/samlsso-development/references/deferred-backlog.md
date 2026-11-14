# samlSSO Persistent Backlog & Environment Memory

This file serves as persistent cross-session memory for:
1. **Queued Codebase Assessment Items** to fold in when we analyze or fix related GitHub issues.
2. **Completed Codebase Assessment Items** (for historical traceability).
3. **Learned Environment & Tooling Quirks**.

---

## 1. Queued Codebase Assessment Items (Fold Into Matching Issues)

When analyzing a reported GitHub issue, check whether it touches any of the files below and propose folding the corresponding improvement into the fix:

*(No open queued codebase assessment items currently remain.)*

---

## 2. Completed Codebase Assessment Items

- **Issue #193 (`1.4.0` GLPI Content Security Policy Readiness)**: Replaced all 21 inline `on*` event handler attributes across `logging.html.twig`, `provider.html.twig`, `claim_mapping.html.twig`, and `configForm.html.twig` with `data-*` attributes and delegated event listeners, extracted `ConfigForm::renderBackupRestoreCard()`'s PHP heredoc `<script>` into `templates/configBackupRestore.html.twig` using a Bootstrap 5 confirmation modal, declared `'csp_compliant' => true` in `plugin_version_samlsso()` (`setup.php`), and added automated CSP static enforcement to `ADR 0006` and `tests/AdrComplianceTest.php`.

- **Issue #199 (`1.4.0` Per-IdP SCIM 2.0 Provisioning & `LoginState` Legacy Column Cleanup — Items C & D)**: Shipped per-IdP stateless HTTPS-only SCIM 2.0 provisioning (`/front/scim/v2/{idpId}`, `ScimAuthenticator` with SHA-256 `db` / `env` / `define` `hash_equals()` Bearer tokens, CIDR allowlist, and `10/60s` rate limiting; bounded local `ScimFilterParser` and `ScimPatchProcessor` with zero external Composer dependencies; decoupled `ScimAttributeMapper` feeding `RuleSamlCollection`; `ScimUserService` & `ScimGroupService` enforcing `(Auth::EXTERNAL, $idpId)` + `ScimLink` isolation, 3-mode `scim_group_policy`, `is_dynamic = 1` group memberships, and immediate `PHASE_FORCE_LOG` + GLPI 11/12 remember-me token revocation; minimalist Tabler SCIM `Beta` tab), and dropped legacy `serverParams`, `loggedOff`, and `enforceLogoff` SQL columns in `LoginState::install()` (Item C).

- **Issue #189 (`1.3.2` & `1.4.0` Scoped Candidate Lookup, `AUTHS_ID_OFFSET = 1000000` Namespace Isolation & Console LDAP Migration Command)**: Replaced single-row `getFromDBByCrit()` / `getFromDBbyEmail()` calls in `User::getOrCreateUser()` with parameterized `User::findCandidateUsers()` to prevent `TooManyResultsException`, isolated SAML JIT and SCIM 2.0 `glpi_users.auths_id` bindings to `User::toAuthsId($idpId)` (`1,000,000 + $idpId`) so active `glpi_authldaps.id` directories never collide while `/acs/{id}` URLs remain unchanged, added one-time `(Auth::EXTERNAL, 0)` adoption stamping across `User` and `ScimUserService` plus idempotent legacy `auths_id` upgrade in `Config::install()`, and shipped `php bin/console plugins:samlsso:migrate_ldap_users` (`src/Console/MigrateLdapUsersCommand.php` / `src/Utility/LdapUserMigrator.php` / `tools/migrate_ldap_users.php`) with `0600` `GLPIKey` HMAC-signed `migrated_user_ids` registers, `--list-registers`, `--revert`, and source-only `glpi_authldaps` deactivation guard.

- **Issue #198**: Aligned `session.cookie_samesite` validation in `plugin_samlsso_check_prerequisites()` (`setup.php`) with GLPI Core's `SessionsSecurityConfiguration` (`['lax', 'strict', '']`) so `SameSite=Strict` no longer emits a false-positive warning during installation, and documented stateless ACS + `HTTP 200 OK` `<meta refresh>` `Strict` support across `references/*.md` and the GitHub Wiki.

- **Issue #196**: Removed `#[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]` and added `ob_start()` / `ob_get_clean()` output buffering on administrative routes (`exclude()`, `__invoke()`, `itemform()`) in `src/Controller/SamlSsoController.php` (Item A), and hardened `src/Utility/GeoIPUpdater.php` with HTTPS-only cURL options (`CURLOPT_PROTOCOLS`, `CURLOPT_REDIR_PROTOCOLS`, `CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST`, `CURLOPT_MAXREDIRS`) plus atomic `.tmp` + `rename()` binary compilation (Item B).

- **Issue #194**: Removed unfinished `LoginFlowEntity.php`, `LoginFlowForm.php`, and `LoginFlowItem.php` scaffolding, removed stale `FLOWFORM_*` and `/front/slo/` routes, decoupled `LoginFlow` from `CommonDBTM` (`LoginFlowCompat`), pruned dead PHP state/config constants (`ENFORCE_LOGOFF`, `SAML_RESPONSE`, `RICHVALUE`, `PLUGIN_SAMLSSO_WEBDIR`), relocated `update_geoip.php` to `tools/`, and excluded `docs/` + `templates/images/` from `tools/mkzip.sh` release archives.

- **Commit `35bfa1d`**: Canonical route constants (`SamlSsoController::ACS_ROUTE` / `SLO_ROUTE`), stateless replay isolation (`LoginState::getState()`, `LoginFlow::printError()`), `SubjectConfirmationData/@InResponseTo` validation, assertion ID uniqueness, and live `PHASE_SAML_ACS` verification in `setSamlResponseId()`.
- **Commit `d8a148e`**: Unified `samlsso_webdir(bool $relative = false)` in `setup.php`, consolidated `Exclude::ProcessExcludes()` into `Exclude::GetExcludeAction()` (fixing `ClientAgent` `if/else` bug), deduplicated `LoginFlow::recordErrorInState()`, delegated `LoginState::getRedirect()` to `getSafeRedirect()`, and removed global `updateUser()` alias from `hook.php`.
- **Commit `cbce3cd` (Issue #192)**: Removed plugin-level `inactivity_timeout` runtime enforcement (delegating session inactivity to GLPI core `session.gc_maxlifetime` and exposing it as a read-only field in Security tab), enforced PHP clock authority (`date('Y-m-d H:i:s')`) for `LOGIN_DATETIME`, and added `Acs::handleRequestTimeout()` to drop expired SAML requests onto `/index.php?noAuto=1`.
- **Commits `b951e14` & `3223112`**: Restructured all 36 legacy ADRs into `docs/ADR/0000`–`0008`, added `tests/AdrComplianceTest.php`, and removed hardcoded `strict => true` override in `Acs::setupSamlResponse()` so configured `php-saml` settings are honored while keeping unsolicited auth blocked.

---

## 3. Learned Environment & Tooling Quirks
- **IDE-Native Tools Over Shell CLI (`Allow` Fatigue)**: Use IDE file/search tools (`read_file`, `edit_file`, `write_file`) for all file inspection, searching, creation, and editing; reserve `run_command` strictly for `php tests/RunAllTests.php`, `./tools/mkzip.sh`, and `git`/`gh` CLI commands.
- **Dead Local Proxy (`socks5h`)**: The shell environment defines a dead `ALL_PROXY`/`HTTPS_PROXY` (`socks5h://...`) that breaks `gh` CLI and `git push`. Always prefix `gh` and `git push` commands with:
  ```bash
  HTTPS_PROXY= HTTP_PROXY= ALL_PROXY= https_proxy= http_proxy= all_proxy=
  ```
- **Single-LF EOF Formatter Quirk**: Background editor/formatter hooks can occasionally append a second trailing newline (`0a 0a`) to PHP/Twig files. Always check `git diff` before committing to keep clean single-LF EOFs.
- **GitHub Wiki Mermaid `sequenceDiagram` Lexer Quirk**: GitHub's Mermaid renderer fails with a syntax error if bare `+` characters appear in `sequenceDiagram` notes or message text (e.g., `authenticates user + MFA` is parsed as an arrow/activation token) or if statements lack line breaks. Always write `and` / `&` instead of `+` in sequence diagram text and put one statement per line.
- **`MockConfigEntity` Parity (`tests/TestHarness.php`)**: Whenever a constant is added to `src/Config/ConfigEntity.php`, also add it to `MockConfigEntity` in `tests/TestHarness.php`.
- **`AcsTest::testPhaseMismatchAfterRegistration()` Mock Phase Count**: `LoginState::$mockPhases` in `AcsTest` is consumed sequentially on each `$this->state->getPhase()` call inside `Acs::assertSaml()`. Avoid adding redundant `$this->state->getPhase()` calls in `Acs::assertSaml()`.
- **GLPI 11 vs. GLPI 12 Remember-Me Token Storage (`glpi_usertokens` vs. `cookie_token`)**: GLPI 12 dropped `glpi_users.cookie_token` and moved remember-me tokens to `glpi_usertokens` (`users_id`, `type = 'rememberme'`), whereas GLPI 11.0.x stores `cookie_token` on `glpi_users`. Any offboarding / session revocation flow (`ScimUserService::revokeUserSessions()`) must check `$DB->tableExists('glpi_usertokens')` first and fall back to `$DB->fieldExists('glpi_users', 'cookie_token', false)`.
- **GLPI Unsigned Foreign Keys (`migration:unsigned_keys`)**: All foreign keys referencing GLPI or plugin primary keys (`users_id`, `auths_id`, `entities_id`, `groups_id`) must use `INT UNSIGNED` (`fkey` in `Migration::addField()`) so GLPI's `bin/console migration:unsigned_keys` never flags signed-key warnings.
