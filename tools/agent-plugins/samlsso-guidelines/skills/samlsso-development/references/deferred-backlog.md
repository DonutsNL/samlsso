# samlSSO Persistent Backlog & Environment Memory

This file serves as persistent cross-session memory for:
1. **Queued Codebase Assessment Items** to fold in when we analyze or fix related GitHub issues.
2. **Completed Codebase Assessment Items** (for historical traceability).
3. **Learned Environment & Tooling Quirks**.

---

## 1. Queued Codebase Assessment Items (Fold Into Matching Issues)

When analyzing a reported GitHub issue, check whether it touches any of the files below and propose folding the corresponding improvement into the fix:

### Item C: Deferred `v1.4.0` SQL Column Cleanup (`src/LoginState.php`)
- **Location**: `src/LoginState.php` (`glpi_plugin_samlsso_states`)
- **Finding**: Dead PHP references for `serverParams`, `loggedOff`, and `enforceLogoff` were removed in `v1.3.2` (Issue #194), keeping the legacy nullable SQL columns untouched in the patch release. Drop `serverParams`, `loggedOff`, and `enforceLogoff` via `$migration->dropField()` in `v1.4.0`.
- **Trigger**: Execute when opening the `v1.4.0` minor release branch.

### Item D: Per-IdP SCIM 2.0 Provisioning (`v1.4.0` Minor Feature Branch)
- **Architectural Rule (Security-by-Design by Default — `ADR 0004`)**: Never hijack, share, or bind `Auth::LDAP` or any non-SAML `authtype` at runtime. Runtime login strictly requires `Auth::EXTERNAL (4)` + `auths_id = $idpId`. *(Note: Scoped `User::findCandidateUsers()` lookup hardening and `tools/migrate_ldap_users.php` were completed on `v1.3.2` under Issue #189).*
- **Per-IdP SCIM 2.0 Interface (`v1.4.0` Minor Feature Branch)**: Stateless HTTPS-only RFC 7643/7644 SCIM 2.0 endpoints (`/front/scim/v2/{idpId}/...`) authenticated via per-IdP Bearer token (`SHA-256` hex digest in DB or `env`/`define` via `hash_equals()`), implemented locally via bounded whitelist `ScimFilterParser` and `ScimPatchProcessor` without external Composer SCIM packages. Keep wire extraction decoupled from SAML `ClaimMap`, while **sharing 100% of `RuleSamlCollection`** downstream for deterministic `Profile`/`Entity` authorization. Separate group lifecycle concerns via a single `scim_group_policy` setting (`create_scoped` | `link_existing` | `disabled`), surface a soft `Beta` badge in the SCIM UI tab, and trigger immediate `is_active = 0` + `cookie_token = NULL` + `PHASE_FORCE_LOG (6)` revocation upon user offboarding.
- **Trigger**: Implement on the `v1.4.0` minor release branch.

---

## 2. Completed Codebase Assessment Items

- **Issue #189 (`1.3.2` Scoped Candidate Lookup & Reversible LDAP Migration Tool)**: Replaced single-row `getFromDBByCrit()` / `getFromDBbyEmail()` calls in `User::getOrCreateUser()` with parameterized `User::findCandidateUsers()` to prevent `TooManyResultsException` while failing closed on `Auth::DB_GLPI` / `'glpi'` / foreign `authtype` matches, and shipped `tools/migrate_ldap_users.php` with `0600` `GLPIKey` HMAC-signed `migrated_user_ids` registers, `--list-registers`, `--revert`, and active `glpi_authldaps` sync deactivation guard.

- **Issue #198**: Aligned `session.cookie_samesite` validation in `plugin_samlsso_check_prerequisites()` (`setup.php`) with GLPI Core's `SessionsSecurityConfiguration` (`['lax', 'strict', '']`) so `SameSite=Strict` no longer emits a false-positive warning during installation, and documented stateless ACS + `HTTP 200 OK` `<meta refresh>` `Strict` support across `references/*.md` and the GitHub Wiki.

- **Issue #196**: Removed `#[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]` and added `ob_start()` / `ob_get_clean()` output buffering on administrative routes (`exclude()`, `__invoke()`, `itemform()`) in `src/Controller/SamlSsoController.php` (Item A), and hardened `src/Utility/GeoIPUpdater.php` with HTTPS-only cURL options (`CURLOPT_PROTOCOLS`, `CURLOPT_REDIR_PROTOCOLS`, `CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST`, `CURLOPT_MAXREDIRS`) plus atomic `.tmp` + `rename()` binary compilation (Item B).

- **Issue #194**: Removed unfinished `LoginFlowEntity.php`, `LoginFlowForm.php`, and `LoginFlowItem.php` scaffolding, removed stale `FLOWFORM_*` and `/front/slo/` routes, decoupled `LoginFlow` from `CommonDBTM` (`LoginFlowCompat`), pruned dead PHP state/config constants (`ENFORCE_LOGOFF`, `SAML_RESPONSE`, `RICHVALUE`, `PLUGIN_SAMLSSO_WEBDIR`), relocated `update_geoip.php` to `tools/`, and excluded `docs/` + `templates/images/` from `tools/mkzip.sh` release archives.

- **Commit `35bfa1d`**: Canonical route constants (`SamlSsoController::ACS_ROUTE` / `SLO_ROUTE`), stateless replay isolation (`LoginState::getState()`, `LoginFlow::printError()`), `SubjectConfirmationData/@InResponseTo` validation, assertion ID uniqueness, and live `PHASE_SAML_ACS` verification in `setSamlResponseId()`.
- **Commit `d8a148e`**: Unified `samlsso_webdir(bool $relative = false)` in `setup.php`, consolidated `Exclude::ProcessExcludes()` into `Exclude::GetExcludeAction()` (fixing `ClientAgent` `if/else` bug), deduplicated `LoginFlow::recordErrorInState()`, delegated `LoginState::getRedirect()` to `getSafeRedirect()`, and removed global `updateUser()` alias from `hook.php`.
- **Commit `cbce3cd` (Issue #192)**: Removed plugin-level `inactivity_timeout` runtime enforcement (delegating session inactivity to GLPI core `session.gc_maxlifetime` and exposing it as a read-only field in Security tab), enforced PHP clock authority (`date('Y-m-d H:i:s')`) for `LOGIN_DATETIME`, and added `Acs::handleRequestTimeout()` to drop expired SAML requests onto `/index.php?noAuto=1`.
- **Commits `b951e14` & `3223112`**: Restructured all 36 legacy ADRs into `docs/ADR/0000`–`0008`, added `tests/AdrComplianceTest.php`, and removed hardcoded `strict => true` override in `Acs::setupSamlResponse()` so configured `php-saml` settings are honored while keeping unsolicited auth blocked.

---

## 3. Learned Environment & Tooling Quirks
- **Dead Local Proxy (`socks5h`)**: The shell environment defines a dead `ALL_PROXY`/`HTTPS_PROXY` (`socks5h://...`) that breaks `gh` CLI and `git push`. Always prefix `gh` and `git push` commands with:
  ```bash
  HTTPS_PROXY= HTTP_PROXY= ALL_PROXY= https_proxy= http_proxy= all_proxy=
  ```
- **`MockConfigEntity` Parity (`tests/TestHarness.php`)**: Whenever a constant is added to `src/Config/ConfigEntity.php`, also add it to `MockConfigEntity` in `tests/TestHarness.php`.
- **`AcsTest::testPhaseMismatchAfterRegistration()` Mock Phase Count**: `LoginState::$mockPhases` in `AcsTest` is consumed sequentially on each `$this->state->getPhase()` call inside `Acs::assertSaml()`. Avoid adding redundant `$this->state->getPhase()` calls in `Acs::assertSaml()`.

