---
name: samlsso-development
description: Coding, architectural, issue-triage, and self-learning guidelines for maintaining the samlsso GLPI plugin. Activate whenever analyzing issues or writing/modifying PHP, Twig, CSS, tests, or documentation in this repository.
---

# SAMLSSO Development & 3-Tier Memory Skill

You MUST follow these guidelines exclusively when analyzing issues or performing changes in `plugins/samlsso`. Never mix or import concepts, files, ADRs, or open-tab context from any sibling plugin installed side-by-side in `plugins/`—they share nothing with `samlsso` beyond GLPI Core.

## 🧠 1. Progressive Disclosure Memory Map (Read On-Demand)
To operate with maximum speed and minimum token overhead, consult these Tier 3 reference files in `references/` instead of grepping the codebase from scratch:
- **[references/architecture-map.md](references/architecture-map.md)**: Complete request call-flow (`POST_INIT` -> `LoginFlow` -> `Acs` -> `LoginState` phases 1–9 -> `User`), database table schemas, and 1:1 `src/` to `tests/*Test.php` mapping.
- **[references/issue-triage-playbook.md](references/issue-triage-playbook.md)**: Standard decision trees and responses for recurring GitHub issues (unsolicited/IdP-initiated login, session vs. request timeouts, `strict` mode behavior, reverse proxy HTTP 400, local account linking).
- **[references/deferred-backlog.md](references/deferred-backlog.md)**: Persistent cross-session backlog of queued codebase improvements and environment quirks. **Always check this file when analyzing a new issue** to see if a queued fix touches the same files.

## 🤖 2. Autonomous Workflows & Two-Gate ADR Governance
Execute these workflows automatically without waiting for the user to type `/learn` or `/grill-me`:
1. **Gate 1 — Pre-Implementation ADR Validation**:
   - Before writing any code, validate the issue and proposed fix against `docs/ADR/0000`–`0008`. Deny and discuss any conflicting request first.
2. **Gate 2 — Post-Implementation Outcome Validation**:
   - After implementing an approved change, validate all modified files against `docs/ADR/0000`–`0008` and execute `php tests/RunAllTests.php` (including `tests/AdrComplianceTest.php`).
3. **Auto-`/learn` (Continuous Memory Persistence)**:
   - Whenever the developer corrects an assumption, clarifies plugin intent (e.g., honoring `php-saml` `strict` configuration while keeping unsolicited flows blocked), or resolves an environment quirk, immediately update the appropriate file (`docs/ADR/0001`–`0008`, `rules/AGENTS.md`, or `references/*.md`) in the same turn. Do not persist transient local-dev observations explicitly dismissed by the developer.
4. **Auto-`/grill-me` (Fact-Checked Design Alignment)**:
   - Never blindly adopt casual hypotheses if a cleaner native GLPI Core primitive or bounded local design exists. Verify remarks against GLPI Core source (`/var/www/glpi-dev_quinquies_nl/src/`), RFC specs, and Composer package maintenance before writing ADRs, plans, Wiki pages, or code, and align on structured trade-offs first.
5. **Auto-Backlog Check & Sync**:
   - Before proposing a fix for a GitHub issue, check `references/deferred-backlog.md`. If a queued backlog item touches the same class or workflow, propose folding it into the fix. Once committed, remove or mark it completed in `references/deferred-backlog.md`.

## 💻 3. Code Standards, Tooling & Documentation Hygiene
- **IDE-Native File Tools First**: Use IDE file/search tools (`read_file`, `edit_file`, `write_file`) for reading, searching, creating, and modifying files rather than shell commands so the developer is not prompted with unnecessary `Allow` dialogs. Check `git diff` before committing to guard against duplicate trailing newlines (`\n\n`) at EOF.
- **DocBlocks Only (No Inline Comments)**: Document every method and major logic block with DocBlocks (`@param`, `@return`, `@throws`). Do not use `//` inline comments inside method bodies; use `/** ... */` block comments. Explicitly import (`use`) or fully qualify (`\Migration`, `\Throwable`) global classes in namespaced files.
- **Defensive Static Analysis Hygiene (`ADR 0008`)**:
  - Assign variables **outside** `if`/`elseif` conditions (`$row = $iter->next(); if ($row) {`) rather than inside conditions; avoid unnecessary string concatenation.
  - Initialize variables (`$auth = null;`, `$fields = [];`) at the start of a scope before `try/catch` or conditional branches.
  - Keep methods cohesive (target cyclomatic complexity `< 15`) and extract shared helpers instead of duplicating blocks; keep CSS Stylelint-clean (no duplicate selectors or redundant 4-value box shorthands).
  - Ensure all code paths return a value; use `Html::displayError()` or throw `PluginException` instead of `die()`.
  - Omit closing `?>` tags in PHP files; use 4-space indentation and keep lines under 120 characters where possible.
- **Translation Strings (`__()`)**: Pass **only static literal string constants** to `__()`. Never concatenate variables or `\n` inside `__()`; use `sprintf(__('Message: %s', PLUGIN_NAME), $var)`.
- **Constants Over Raw Strings (`ADR 0008`)**: Define and reference domain class constants (`ConfigEntity::*`, `ConfigItem::*`, `ClaimMap::*`, `ClaimMapItem::*`, `ObservedClaim::*`, `LoginState::*`, `User::*`, `ScimLink::*`, `Exclude::*`, and `SamlSsoController::ACS_ROUTE` / `SLO_ROUTE` / `SCIM_ROUTE`) for all DB columns, GLPI user/group/profile fields, rule criteria/action keys, and routes instead of hardcoding raw string literals. Mirror any added/updated `public const` declarations on corresponding mocks in `tests/TestHarness.php`.
- **GLPI 11 & 12 Dual Compatibility & Schema Types**:
  - Concrete classes (`Config`, `Exclude`, `LoginFlow`, `RuleSaml`, `RuleSamlCollection`) extend their intermediate class in `src/compat.php` and must never redeclare `$dohistory`, `$rightname`, or `$menu_option`.
  - Declare all foreign-key columns (`users_id`, `auths_id`, `entities_id`, `groups_id`) as `INT UNSIGNED` (`fkey`) to satisfy `migration:unsigned_keys`.
  - Resolve web paths at call time via `samlsso_webdir(bool $relative = false)` and guard Twig variables with `is defined` for GLPI 12 `strict_variables` compatibility.
- **Form, Popover & Wiki Consistency (`ADR 0006`)**:
  - Follow GLPI's native Tabler UI without marketing bloat or redundant parenthetical constraint hints.
  - Major `Beta` subsystems display a subtle `bg-secondary-lt` `Beta` badge and link directly (`target="_blank" rel="noopener noreferrer"`) to their GitHub Wiki page in the top notice; CLI tools in `tools/*.php` include their Wiki manual URL in `--help` output.
  - In GitHub Wiki Mermaid `sequenceDiagram` blocks, put every statement on its own line and never use bare `+` characters in message/note text (which breaks GitHub's Mermaid lexer).
  - Surface field warnings/errors and diagnostic tables via `ConfigItem::ERRORS`, hidden inline (`.samlsso-diagnostic-table-container { display: none !important; }`) and rendered on demand inside `SamlSsoPopoverManager`.

## 🧪 4. Verification & Release Packaging
1. **Run All Tests**:
   ```bash
   php tests/RunAllTests.php
   ```
   All 24 test suites (including `AdrComplianceTest.php` and `ConfigIntegrityTest.php`) must pass before proposing or pushing any commit. Keep passing test output concise (single `✅` line per test, no raw data dumps); print input, return value, and execution state (exception/redirect/trace) only on failure.
2. **Release Packaging (`./tools/mkzip.sh`) & Pre-Release Workflow**:
   - Update `PLUGIN_SAMLSSO_VERSION` in `setup.php` and `OLDVERSION` / `NEWVERSION` in `tools/mkzip.sh` when bumping versions (`mkzip.sh` updates `@version` docblocks and `PLUGIN_SAMLSSO_VERSION` cleanly without touching historical `@since` tags).
   - Run `./tools/mkzip.sh` (which executes `RunAllTests.php` and builds `release/samlsso.zip`).
   - For pre-release testing builds on a version branch (`main` and `main:samlsso.xml` untouched until GA), avoid generic `rc` / `Release Candidate` labels: publish a GitHub `--prerelease` with descriptive naming (`v<version>-bugfix.<n>` / `v<version> Bugfix Test Release (Pre-release)` for patch fixes, or `v<version>-preview.<n>` / `v<version> Feature Preview (Pre-release)` for feature releases), attach `release/samlsso.zip`, and notify reporters on the relevant open GitHub issues with clear verification steps.
   - Push verified commits to `origin/<branch>` with proxy variables unset (`HTTPS_PROXY= HTTP_PROXY= ALL_PROXY= git push`).
