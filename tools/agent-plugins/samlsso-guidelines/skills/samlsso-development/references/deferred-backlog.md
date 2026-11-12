# samlSSO Persistent Backlog & Environment Memory

This file serves as persistent cross-session memory for:
1. **Queued Codebase Assessment Items** to fold in when we analyze or fix related GitHub issues.
2. **Completed Codebase Assessment Items** (for historical traceability).
3. **Learned Environment & Tooling Quirks**.

---

## 1. Queued Codebase Assessment Items (Fold Into Matching Issues)

When analyzing a reported GitHub issue, check whether it touches any of the files below and propose folding the corresponding improvement into the fix:

### Item A: Controller Firewall Strategy & Output Buffering (`src/Controller/SamlSsoController.php`)
- **Location**: `src/Controller/SamlSsoController.php:162-214`
- **Finding**:
  1. Administrative routes (`loginflow`, `exclude`, `__invoke`, `itemform`) currently carry `#[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]` (which is only needed on the public `acs` and `metadata` endpoints), relying solely on manual `Session::checkLoginUser()` calls inside the methods.
  2. `exclude()`, `__invoke()`, and `itemform()` echo legacy `Html::header()` / `Html::footer()` directly instead of wrapping them in `ob_start()` / `ob_get_clean()` before returning the Symfony `Response` (unlike `loginflow()` which already uses `ob_start()`).
- **Trigger**: Fold in whenever working on an issue touching `SamlSsoController.php` or routing/rendering.

### Item B: GeoIP Downloader cURL & Atomic Write Hardening (`src/Utility/GeoIPUpdater.php`)
- **Location**: `src/Utility/GeoIPUpdater.php`
- **Finding**:
  1. Add explicit HTTPS-only cURL options (`CURLOPT_PROTOCOLS => CURLPROTO_HTTPS`, `CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS`, `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, `CURLOPT_MAXREDIRS => 3`).
  2. Write downloaded binary data to a temporary file (`ip_to_country.bin.tmp`) and atomically `rename()` it into place after size/GZIP verification so concurrent readers in `GeoIPResolver` never read a half-written file.
- **Trigger**: Fold in whenever working on an issue touching GeoIP, `CronTask`, or `src/Utility/`.

### Item C: Unfinished v1.2.0 `LoginFlow` Admin Scaffolding (`src/LoginFlow/LoginFlow*.php`)
- **Location**: `src/LoginFlow/LoginFlowForm.php`, `src/LoginFlow/LoginFlowEntity.php`, `src/LoginFlow/LoginFlowItem.php` (~790 lines)
- **Finding**: Incomplete v1.2.0 scaffolding with placeholder strings (`TBD`) and stubbed save actions.
- **Trigger**: Discuss cleanup or completion when an issue touches `LoginFlowForm` or administrative menus.

---

## 2. Completed Codebase Assessment Items

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
