# samlSSO Plugin — Always-On Agent Constitution (Tier 1 Memory)

## 1. Core Operating Mode & Autonomous Behaviors
- **Developer-First Coding**: Propose analyses, root causes, and concrete code changes to the developer first. Only modify code when explicitly approved by the developer.
- **Issue-First Traceability**: Every change must map to a GitHub issue (`DonutsNL/samlsso`) and be recorded as a single concise bullet under the active version in `changelog.md`.
- **Autonomous Continuous Learning (Auto-`/learn`)**: Whenever the developer corrects an assumption, clarifies plugin intent, or resolves an environment quirk, **autonomously persist that rule** in the same turn into the appropriate memory file (`docs/ADR/0001`–`0008`, `rules/AGENTS.md`, `SKILL.md`, or `references/*.md`) without waiting for `/learn`.
- **Autonomous Design Alignment (Auto-`/grill-me`)**: When an issue has multiple design options or touches architectural boundaries, proactively present structured trade-offs and challenge flawed assumptions before writing code.
- **Autonomous Backlog Synchronization**: When analyzing any issue, check `skills/samlsso-development/references/deferred-backlog.md` to fold in relevant queued improvements touching the same files, and update `deferred-backlog.md` upon completion.

## 2. Two-Gate ADR Validation & Domain ADR Index (`docs/ADR/0000`–`0008`)
- **Gate 1 (Pre-Implementation)**: Validate every issue and proposed fix against `docs/ADR/0000`–`0008`. Deny and discuss any request that conflicts with an ADR.
- **Gate 2 (Post-Implementation)**: Validate all modified files against `docs/ADR/0000`–`0008` and run `php tests/RunAllTests.php` (includes `tests/AdrComplianceTest.php`).

| ADR | Domain & Non-Negotiable Core Principle |
| :--- | :--- |
| [0000](../../../docs/ADR/0000-adr-governance-and-authoring-guide.md) | **ADR Governance**: Amend domain ADRs (`0001`–`0008`) in-place; never write narrow single-ticket ADRs or leave superseded rules. |
| [0001](../../../docs/ADR/0001-security-by-design-and-state-machine.md) | **State Machine & Solicited-Only Auth**: 9-phase `LoginState`. **Never** allow direct unsolicited login; always use the **Double-Pass Solicited Flow** (`PHASE_INITIAL` -> `performSamlIdpRequest()` -> `PHASE_SAML_ACS` -> `Acs`). Server-side `REDIRECT` only; `RelayState` is strictly for `$flowToken`. |
| [0002](../../../docs/ADR/0002-glpi-core-delegation-and-boundaries.md) | **GLPI Core Delegation & Timeouts**: Never duplicate GLPI Core features. `request_timeout` covers `AuthnRequest` -> `ACS` only (drops expired onto `/index.php?noAuto=1`). Active session inactivity belongs 100% to GLPI (`session.gc_maxlifetime`), shown read-only in UI. Single PHP clock authority (`date('Y-m-d H:i:s')`). |
| [0003](../../../docs/ADR/0003-cryptography-certificates-and-secret-management.md) | **Cryptography & `php-saml` Config**: Honor configured `php-saml` `strict` & `security_*` flags in `Acs::setupSamlResponse()` while surfacing UI risk warnings when `strict` is off. Enforce `GLPIKey` encryption, `\r` PEM rejection, and NIST cert-generator floors (`RSA >= 2048`, `P-256/384/521`, `SHA-256+`). |
| [0004](../../../docs/ADR/0004-fail-closed-provisioning-and-access-control.md) | **Fail-Closed & JIT ATO Protection**: Reject `id <= 0`/inactive/deleted IdPs. Bind SAML users to `Auth::EXTERNAL` + `auths_id = $idpId`; never link `Auth::DB_GLPI` or `glpi` super-admin; strip protected fields on `sync_on_login`; require `hash_equals` bypass token. |
| [0005](../../../docs/ADR/0005-configuration-engine-and-caching.md) | **Config Engine & Caching**: 1:1 parity between `ConfigEntity` constants, SQL columns, `ConfigDefaultTpl`, and `ConfigItem` validators; request-level `$dbRowCache`. |
| [0006](../../../docs/ADR/0006-minimalist-ui-and-diagnostic-popovers.md) | **Minimalist UI & Popovers**: Native Tabler UI, no marketing bloat. Hide diagnostic tables inline (`display: none !important`) and render inside sanitized HTML popovers (`SamlSsoPopoverManager`). |
| [0007](../../../docs/ADR/0007-lifecycle-migrations-and-tooling.md) | **Lifecycle & Migrations**: Deterministic `PLUGIN_SAMLSSO_CLASSES`, mandatory `$migration->migrationOneTable($table)`, `SanityChecker::check()`, static literal `__()` strings. |
| [0008](../../../docs/ADR/0008-code-structure-and-testing-architecture.md) | **Code Structure & Testing**: Coarse-grained class layout (`const` -> public flow -> call-ordered helpers -> `install`/`uninstall`); process-isolated `php tests/RunAllTests.php`. |

## 3. Environment & Operational Rules
- **GitHub CLI (`gh`) & `git push`**: Always prefix network CLI commands with `HTTPS_PROXY= HTTP_PROXY= ALL_PROXY= https_proxy= http_proxy= all_proxy=` to bypass dead local `socks5h` proxy variables.
- **No `sudo` & No Direct Cache Clearing**: Never run `sudo` or clear the GLPI cache directly; ask the user if cache clearing is needed.
- **Release Branch Safety**: Work and push verified fixes on version branches (e.g. `1.3.2`). Never merge to `main` or update `samlsso.xml` on `main` until a release is officially approved.
