---
name: samlsso-development
description: Coding, architectural, issue-triage, and self-learning guidelines for maintaining the samlsso GLPI plugin. Activate whenever analyzing issues or writing/modifying PHP, Twig, CSS, tests, or documentation in this repository.
---

# SAMLSSO Development & 3-Tier Memory Skill

You MUST follow these guidelines when analyzing issues or performing changes in `plugins/samlsso`.

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
   - Whenever the developer corrects an assumption, clarifies plugin intent (e.g., honoring `php-saml` `strict` configuration while keeping unsolicited flows blocked), or resolves an environment quirk, immediately update the appropriate file (`docs/ADR/0001`–`0008`, `rules/AGENTS.md`, or `references/*.md`) in the same turn.
2. **Auto-`/grill-me` (Proactive Design Alignment)**:
   - When an issue has architectural ambiguity, multiple implementation paths, or tension with `docs/ADR/0001`–`0008`, present a concise comparison of options and trade-offs and align with the developer before writing code.
3. **Auto-Backlog Check & Sync**:
   - Before proposing a fix for a GitHub issue, check `references/deferred-backlog.md`. If a queued backlog item touches the same class or workflow, propose folding it into the fix. Once committed, remove or mark it completed in `references/deferred-backlog.md`.

## 💻 3. Code Standards & Cleanliness
- **DocBlocks Only (No Inline Comments)**: Document every method and major logic block with DocBlocks (`@param`, `@return`, `@throws`). Do not use `//` inline comments inside method bodies; use `/** ... */` block comments. Explicitly import (`use`) or fully qualify (`\Migration`, `\Throwable`) global classes in namespaced files.
- **Defensive Static Analysis Hygiene**:
  - Wrap intentional conditional assignments in double parentheses: `if (($row = $iterator->next()))`.
  - Initialize variables (`$auth = null;`, `$fields = [];`) at the start of a scope before `try/catch` or conditional branches.
  - Ensure all code paths return a value; use `Html::displayError()` or throw `PluginException` instead of `die()`.
  - Omit closing `?>` tags in PHP files; use 4-space indentation and keep lines under 120 characters where possible.
- **Translation Strings (`__()`)**: Pass **only static literal string constants** to `__()`. Never concatenate variables or `\n` inside `__()`; use `sprintf(__('Message: %s', PLUGIN_NAME), $var)`.
- **Constants Over Raw Strings**: Always use `ConfigEntity::*`, `ConfigItem::*`, `ClaimMapItem::FIELD_*`, `LoginState::*`, and `SamlSsoController::ACS_ROUTE` / `SLO_ROUTE` constants instead of raw string literals.
- **GLPI 11 & 12 Dual Compatibility**:
  - Concrete classes (`Config`, `Exclude`, `LoginFlow`, `RuleSaml`, `RuleSamlCollection`) extend their intermediate class in `src/compat.php` and must never redeclare `$dohistory`, `$rightname`, or `$menu_option`.
  - Resolve web paths at call time via `samlsso_webdir(bool $relative = false)`.
  - Guard Twig variables with `is defined` for GLPI 12 `strict_variables` compatibility.
- **Form & Popover Consistency (`ADR 0006`)**:
  - Follow GLPI's native Tabler UI without marketing bloat.
  - Surface field warnings/errors and diagnostic tables via `ConfigItem::ERRORS`, hidden inline (`.samlsso-diagnostic-table-container { display: none !important; }`) and rendered on demand inside `SamlSsoPopoverManager`.

## 🧪 4. Verification & Release Packaging
1. **Run All Tests**:
   ```bash
   php tests/RunAllTests.php
   ```
   All 20 test suites (including `AdrComplianceTest.php` and `ConfigIntegrityTest.php`) must pass before proposing or pushing any commit.
2. **Release Packaging (`./tools/mkzip.sh`)**:
   - Update `PLUGIN_SAMLSSO_VERSION` in `setup.php` and `OLDVERSION` / `NEWVERSION` in `tools/mkzip.sh` when bumping versions.
   - Run `./tools/mkzip.sh` (which runs `RunAllTests.php` and builds `release/samlsso.zip`).
   - Push verified commits to `origin/<branch>` with proxy variables unset (`HTTPS_PROXY= HTTP_PROXY= ALL_PROXY= git push`).
