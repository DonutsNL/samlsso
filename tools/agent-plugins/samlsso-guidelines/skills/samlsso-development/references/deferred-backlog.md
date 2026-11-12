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

---

## 2. Completed Codebase Assessment Items

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

