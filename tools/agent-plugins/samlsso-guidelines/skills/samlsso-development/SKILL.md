---
name: samlsso-development
description: Coding and architectural guidelines for maintaining the samlsso plugin. Use this skill whenever you write or modify PHP code, shims, tests, or documentation in this repository.
---

# SAMLSSO Development Guidelines

You MUST follow these rules when performing any changes in this repository.

## 🤝 Developer & User Alignment
- **Developer-First Coding**: Always propose changes to the developer. Do not automatically implement them unless the user explicitly asks you to. Your role is to assist the developer by analyzing, suggesting issues, solutions, or optimizations that they can choose to implement.
- **User-First Coding**: Always consider the end-user. Avoid technical jargon where simple language will suffice. Ensure error messages are clear, actionable, and do not expose raw technical data or stack traces.

## 🤖 Workflow & Preparation
- **Read the Wiki**: Before performing any functional changes, read the Project Wiki (https://github.com/DonutsNL/samlsso/wiki) to understand state-machine logic, the assertion lifecycle, and architectural design patterns.
- **Understand Security**: Never propose changes that bypass the state-machine or weaken replay protection.
- **Issue-First**: A formal GitHub Issue must exist before any code changes are allowed. If none exists, create a new issue on GitHub describing the intended change and reasoning, and reference it in the changelog and ADRs.
- **Change Traceability**: Record every single change (no matter how small) in `changelog.md` under the appropriate version header. Always group the changes clearly by subject (e.g., Static Analysis, Bugfixes, Translation, Quality/Code Standards) in a clean list format.
- **Wiki Updates**: If changing functional behavior, configuration, or endpoints, suggest updates to the corresponding Wiki pages.

## 💻 Code Standards & Cleanliness
- **DocBlocks**: Document every method and major logic block with detailed docblocks (description, `@param`, `@return`, `@throws`). Do not use inline comments (`//`); use DocBlocks instead.
- **PSR Compliance**: Follow PSR-12 coding standards.
- **No Closing PHP Tags**: PHP-only files must not end with a closing `?>` tag to avoid accidental whitespace emissions.
- **Indentation & Spacing**: Use 4 spaces for indentation. Avoid extra empty lines, spaces, or tabs.
- **Line Length**: Keep lines under 120 characters where possible.
- **Descriptive Naming**: Use descriptive names for variables, classes, functions, and constants. Follow GLPI naming conventions first, then plugin conventions.
- **Obfuscations**: Never allow obfuscations (e.g., minification, magic strings, hashed strings, or encoded payloads) in the code. If detected, report it immediately and attempt to uncover its purpose.

## 🔒 Security & GLPI Architecture
- **Native GLPI Components**: Use native GLPI core components (e.g. `CommonDBTM`, `Session`, `Html`, `Toolbox`) where possible.
- **Sanitization & Input Validation**: Never trust external input. Always use GLPI's `Sanitizer` or native filter functions.
- **Access Control (Rights)**: Every entry point (e.g., in `front/` or `ajax/`) must explicitly verify that the user has the required rights (e.g. using `Session::checkLoginUser()`).
- **i18n & Localization**: All user-facing strings must use GLPI translation helper functions like `__('string', PLUGIN_NAME)` for localization.
- **Error Handling**: Do not use `die()`. Always use `Html::displayError()` or throw a `PluginException`.
- **Return Values**: Ensure all code paths return a value. If a code path is theoretically unreachable, return an empty string or the appropriate default value to satisfy static analysis.
- **PluginContext**: Always use `PluginContext::get()` for global plugin configuration.
- **ADRs**: Document the rationale, alternatives, pros, and cons of your changes in an ADR under `ADRS/`.

## 🧪 Testing & Releases
- **Add Tests**: When adding new functionality, you MUST add new tests to the `tests/` folder. Ensure they pass successfully before proposing any change.
- **Running Tests**: Run the automated test runner from the root of the plugin directory:
  ```bash
  php tests/RunAllTests.php
  ```
- **Creating a Release**: To package a new release zip using `tools/mkzip.sh`:
  1. Update the version constant `PLUGIN_SAMLSSO_VERSION` in `setup.php`.
  2. Update the `OLDVERSION` and `NEWVERSION` variables in `tools/mkzip.sh` to match the version bump.
  3. Run the release packaging script (it will automatically run the automated test suite and abort on any failure):
     ```bash
     ./tools/mkzip.sh
     ```
  4. Manually update `samlsso.xml` to add a new `<version>` block listing the new version and its download URL.
  5. Run the test suite (`php tests/RunAllTests.php`) one final time to verify version and copyright alignments check out across all files.
