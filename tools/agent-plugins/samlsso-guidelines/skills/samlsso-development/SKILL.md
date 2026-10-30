---
name: samlsso-development
description: Coding and architectural guidelines for maintaining the samlsso plugin. Use this skill whenever you write or modify PHP code, shims, tests, or documentation in this repository.
---

# SAMLSSO Development Guidelines

You MUST follow these rules when performing any changes in this repository.

## 🤝 Developer & User Alignment
- **Developer-First Coding**: Always propose changes to the developer. Do not automatically implement them unless the user explicitly asks you to. Your role is to assist the developer by analyzing, suggesting issues, solutions, or optimizations that they can choose to implement. Always validate the logic of developer reviews, adding to them or challenging them if flawed or if a better alternative exists.
- **User-First Coding**: Always consider the end-user. Avoid technical jargon where simple language will suffice. Ensure error messages are clear, actionable, and do not expose raw technical data or stack traces.
- **Security & Safe Coding Audits**: Always review implementations for strict compliance with safe coding standards to ensure that no logic or security bypass loops are created.

## 🤖 Workflow & Preparation
- **Security-by-Design First (Non-Negotiable)**: The Security-by-Design principle (`docs/ADR/0017-security-by-design-architecture.md`) always comes first and may **never** be omitted, weakened, or bypassed. All authentication must be evaluated strictly against the database-backed state machine (`LoginState`). Unsolicited SAML responses or responses with unregistered `InResponseTo` IDs must never authenticate directly; they must create a new initial state for the session and redirect the browser back to the IdP (`performSamlIdpRequest()`) to re-authenticate through the full state-machine lifecycle.
- **Validate Every Request Against Existing ADRs**: Before implementing any feature request, issue, or bug fix, you **MUST** read and validate the request against all existing ADRs in `docs/ADR/`.
- **Deny & Discuss Conflicting Issues First**: If a feature request or issue conflicts with any existing ADR or with Security-by-Design principles:
  1. **Deny / refuse direct implementation immediately** — never implement a workaround that bypasses the state machine, weakens validation, or contradicts an ADR.
  2. **Flag and discuss the conflict with the developer first**, citing the exact ADR(s) and security impact.
  3. **Mandatory ADR on Adoption**: Any approved change that modifies architecture or amends an existing decision **MUST** result in a new ADR in `docs/ADR/` documenting the decision, security analysis, and superseded/amended ADRs.
- **Read the Wiki**: Before performing any functional changes, read the Project Wiki (https://github.com/DonutsNL/samlsso/wiki) to understand state-machine logic, the assertion lifecycle, and architectural design patterns.
- **Understand Security**: Never propose changes that bypass the state-machine or weaken replay protection.
- **Issue-First**: A formal GitHub Issue must exist before any code changes are allowed. If none exists, create a new issue on GitHub describing the intended change and reasoning, and reference it in the changelog and ADRs.
- **Change Traceability**: Record every single change (no matter how small) in `changelog.md` under the appropriate version header. Always use a single, clean list of short, concise descriptions (e.g., `- Fixed issue https://github.com/DonutsNL/samlsso/issues/27 with undefined tabs in twig.`). Do not use categories, headers, or sub-lists within a version entry.
- **Wiki Updates**: If changing functional behavior, configuration, or endpoints, suggest updates to the corresponding Wiki pages.
- **Twig Template Cache**: The GLPI cache must be cleaned whenever Twig templates are changed (e.g., by executing `php bin/console cache:clear` from the GLPI root directory) to ensure updates are loaded successfully.


## 💻 Code Standards & Cleanliness
- **DocBlocks**: Document every method and major logic block with detailed docblocks (description, `@param`, `@return`, `@throws`). Do not use inline comments (`//`); use DocBlocks instead. Ensure all `@param` and `@return` types and parameter names are strictly aligned with the actual method signature and implementation. External types used in DocBlocks within namespaced files (like global classes such as `Migration`) must be explicitly imported via `use` statements or fully qualified (e.g., `\Migration`) to prevent undefined namespace type warnings.
- **Intentional Assignments in Conditions**: When intentionally assigning a variable inside a conditional statement (e.g. `if`, `elseif`), always wrap the assignment in an extra set of parentheses (e.g. `if (($var = val))`) to explicitly signal intent to static analyzers and resolve linter warnings.
- **Variable Initialization & Guarding**: Always explicitly initialize variables (e.g. `$auth = null;`, `$fields = [];`) at the start of a scope if they are assigned inside `try/catch` or conditional blocks, preventing "potentially undefined variable" static analysis warnings on subsequent uses.
- **Bash Command Substitution**: When executing commands inside bash scripts (e.g., in `tools/mkzip.sh`), always use the modern `$()` syntax (e.g., `$(pwd)`) instead of legacy backticks (e.g., `` `pwd` ``) to comply with modern shell standards and pass shellcheck rules.
- **Translation String Concatenation**: Translation helper functions (e.g. `__()`) must only be passed static, literal string constants to ensure they remain extractable by static parser tools. Never concatenate variables, function calls, or newline sequences (like `"\n"`) *inside* a translation function. Instead, concatenate variables outside the helper call or use `sprintf` with placeholders (e.g. `sprintf(__('Message: %s', PLUGIN_NAME), $var)`).
- **PSR Compliance**: Follow PSR-12 coding standards.
- **Linting Enforcement**: All code must pass static analysis and linting (Intelephense, SonarLint) with all errors corrected prior to proposing code, creating a release, or submitting a pull request.
- **No Closing PHP Tags**: PHP-only files must not end with a closing `?>` tag to avoid accidental whitespace emissions.
- **Indentation & Spacing**: Use 4 spaces for indentation. Avoid extra empty lines, spaces, or tabs.
- **Line Length**: Keep lines under 120 characters where possible.
- **Descriptive Naming**: Use descriptive names for variables, classes, functions, and constants. Follow GLPI naming conventions first, then plugin conventions.
- **Obfuscations**: Never allow obfuscations (e.g., minification, magic strings, hashed strings, or encoded payloads) in the code. If detected, report it immediately and attempt to uncover its purpose.
- **No Raw Strings for Config/Claim Mapping Fields**: Never use raw string literals when referencing configuration keys or claim mapping fields (e.g. use `ClaimMapItem::FIELD_EMAIL` instead of `'email'`). All fields and keys must be defined as class constants.
- **Form Layout & Element Consistency**: All tab panels and their constituent elements must follow a single, unified visual layout system. If an element style or behavior (such as toggles, inputs, warning/error row styles, warning tooltips, or info helper hovers) changes in one child template or tab, the change must be identically and consistently applied to all similar elements across all tabs. Info messages underneath fields should be wrapped in `.form-text-info-hover-box` to trigger only on field/row hover.

## 🔒 Security & GLPI Architecture
- **GLPI Core As-Is Rule**: Never modify or alter any class or method belonging to the GLPI core. Core files and methods must be considered "as-is". Always notify the developer immediately if any core security issues are found.
- **Separation of Duties & No Core Feature Manipulation**: We do not intervene, modify, or manipulate core GLPI features that the plugin utilizes. Core operations must remain untouched to respect a strict separation of duties.
- **Native GLPI Components**: Use native GLPI core components (e.g. `CommonDBTM`, `Session`, `Html`, `Toolbox`) where possible.
- **Sanitization & Input Validation**: Never trust external input. Always use GLPI's `Sanitizer` or native filter functions.
- **Access Control (Rights)**: Every entry point (e.g., in `front/` or `ajax/`) must explicitly verify that the user has the required rights (e.g. using `Session::checkLoginUser()`).
- **i18n & Localization**: All user-facing strings must use GLPI translation helper functions like `__('string', PLUGIN_NAME)` for localization.
- **Error Handling**: Do not use `die()`. Always use `Html::displayError()` or throw a `PluginException`.
- **Return Values**: Ensure all code paths return a value. If a code path is theoretically unreachable, return an empty string or the appropriate default value to satisfy static analysis.
- **PluginContext**: Always use `PluginContext::get()` for global plugin configuration.
- **ADRs**: Document the rationale, alternatives, pros, and cons of your changes in an ADR under `ADRS/`.
- **Never Use Sudo**: Never use `sudo` to bypass permission or security issues during development, testing, or operations (such as cache clearing). If a permission issue is encountered, ask the user/administrator to correct it instead of using elevated privileges.
- **Never Clear Cache**: Never attempt to clear the GLPI cache yourself. If the cache needs to be cleared (e.g. after changing Twig templates or locales), ask the user to clear it.

## 🧪 Testing & Releases
- **LoginFlow Test Coverage**: Every function included in the main `LoginFlow` class and its subclasses must be mapped to and covered by the automated test suite.
- **No Core Mocking**: We do not implement shim or pseudo functions that mimic GLPI core behaviors. Test environments must bootstrap the actual GLPI framework to execute real core behaviors rather than stubbing them out.
- **Add Tests**: When adding new functionality, you MUST add new tests to the `tests/` folder. Ensure they pass successfully before proposing any change.
- **Running Tests**: Run the automated test runner from the root of the plugin directory:
  ```bash
  php tests/RunAllTests.php
  ```
- **Creating a Release**: To package a new release zip using `tools/mkzip.sh`:
  1. **Controlled Releases**: Note that the `main` branch is actively monitored by the GLPI plugins directory website. Updating `samlsso.xml` or pushing tags to `main` will trigger a release immediately. Always perform build, packaging, and testing on dedicated version branches (e.g., `1.3.2`) and **do not** merge to `main` or publish the GitHub release until the version is officially approved and ready to go live.
  2. Update the version constant `PLUGIN_SAMLSSO_VERSION` in `setup.php`.
  3. Update the `OLDVERSION` and `NEWVERSION` variables in `tools/mkzip.sh` to match the version bump.
  4. Run the release packaging script (it will automatically run the automated test suite and abort on any failure):
     ```bash
     ./tools/mkzip.sh
     ```
  5. Manually update `samlsso.xml` to add a new `<version>` block listing the new version and its download URL.
  6. Run the test suite (`php tests/RunAllTests.php`) one final time to verify version and copyright alignments check out across all files.
