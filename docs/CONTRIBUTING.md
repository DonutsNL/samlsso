# Contributing and Agentic Maintenance

This repository is maintained with an "AI-First" mindset. Whether you are a human developer or an AI agent, the following rules **must** be strictly followed to ensure the stability and security of the `samlsso` plugin.

## 🤖 Instructions for AI Agents

- To maintain the high quality and "Security-by-Design" nature of this plugin, all agentic activities must adhere to the following workflow:
- Use the IDE functions where possible and try to prevent the usage of CLI commands for code analysis and fixes. Only use CLI when needed.

### 1. Prerequisite Knowledge
- **Read the Wiki**: Before performing any functional changes, you **MUST** read the [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) to understand the state-machine logic, the assertion lifecycle, and the architectural design patterns.
- **Understand Security**: You **MUST** read and follow the instructions in [SECURITY.md](SECURITY.md). Never propose changes that bypass the state-machine or weaken the replay protection.

### 2. Issue-First Workflow
- **No Issue, No Change**: A formal GitHub Issue must exist describing the bug or feature request before any code changes are allowed. If non exist, create a new issue in Github describing the intended change and reason for that change. Reference the github issue in the changelog once your done. If applicable reference the github issue in the ADR if one is created.
- **Functional Alignment**: Proposed changes must follow the established functional implementation of the plugin. Radical changes in architecture require explicit approval and an updated ADR (Architecture Decision Record) located in the `docs/ADR` folder.

### 3. Change Traceability
- **Changelog Updates**: Every single change (no matter how small) **MUST** be recorded in [changelog.md](changelog.md) under the appropriate version header. Always group the changes clearly by subject (e.g., Static Analysis, Bugfixes, Translation, Quality/Code Standards) in a clean list format.
- **Wiki Updates**: If a change modifies a functional behavior that the user must be aware of, for instance configurations option, or endpoints, you **MUST** suggest an update for the corresponding [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) pages that describes this new features.

### 4. Code Standards
- **user first coding**: Always consider the end user. Avoid technical jargon where simple language will suffice. Ensure error messages are clear and actionable. Do not show raw technical data to the end-user.
- **Developer-first coding**: Always propose changes to the developer. Dont automatically implement them. Only add code if the user explicitly asks you to. You are only allowed to assist the developer by analyzing, suggesting issues, solutions or optimizations to the developer that he can choose to implement or not with or without your help.
- **Document every method and major logic block** with detailed docblocks explaining what the method does, what parameters it takes, what it returns, and what exceptions it throws. Also add comments to major logic blocks explaining why the code is there and what it does.
- **PSR Compliance**: Follow PSR-12 coding standards.
- **Native GLPI Components**: Always use native GLPI core components (e.g., `CommonDBTM`, `Session`, `Html`, `Toolbox`) where possible for maximum compatibility.
- **Sanitization**: Never trust external input. Always use GLPI's `Sanitizer` or native filter functions.
- **Error Handling**: Do not use `die()`. Always use `Html::displayError()` or throw a PluginException.
- **Return Values**: Ensure all code paths return a value. If a code path is theoretically unreachable, return an empty string or the appropriate default value to satisfy static analysis.
- **PluginContext**: Always use `PluginContext::get()` for global plugin configuration instead of directly accessing `Config` or static methods.
- **Inline Comments**: Do not use inline comments. Use DocBlocks instead and make sure every line of code is commented on why it is there and what it does and is easy to understand.
- **Indentation**: Use 4 spaces for indentation.
- **Line Length**: Keep lines under 120 characters where possible.
- **Spacing**: Do not use extra spaces, tabs or newlines. Keep the code clean and easy to read.
- **Variable Names**: Use descriptive variable names and follow the naming conventions of the plugin.
- **Function Names**: Use descriptive function names and follow the naming conventions of the plugin.
- **Class Names**: Use descriptive class names and follow the naming conventions of GLPI first then the plugin.
- **Constants**: Use descriptive constant names and follow the naming conventions of GLPI first then the plugin.
- **Add ADRs**: Take note of and provide the rationale, consequences, alternatives, pros, and cons of your changes in an ADR (Architecture Decision Record) in the [ ADRS folder.](ADRS/0001-authentication-system.md)
- **Obfuscations**: Never allow obfuscations (e.g. minifications, magic strings, hashed strings, encoded payloads etc) in the code. If you detect one in the code downloaded from the repository report it to the maintainer and do not include it in the code you propose. Try to uncover the goal of the obfuscation and try to remove it and document this in an issue. The only exception is when obfuscation is used for security purposes and is documented as such in an ADR.
- **Add Tests**: When adding new functionality, you **MUST** add new tests to the `tests` folder to verify the new functionality. Try to follow the style of the existing tests. If the tests fail, fix the tests and make sure they pass before proposing the change. Also try to improve the existing tests if applicable.

## Architectural Integrity
The core of this plugin is its **State Machine**. Any modifications to `LoginState.php`, `Acs.php`, or `LoginFlow.php` must be handled with extreme caution. The preservation of the authentication phases (1-8) is critical to the plugin's security posture.

## 🔧 Local Agent Skill Setup

This repository contains a custom agent skill package (`tools/agent-plugins/samlsso-guidelines`) designed to automatically load these maintenance guidelines into agentic AI coding assistants (like Gemini/Antigravity) at the start of a session.

To enable this for your local agent:
1. Create a symbolic link from the repository plugin folder to your local agent configuration folder:
   ```bash
   ln -s /path/to/your/workspace/plugins/samlsso/tools/agent-plugins/samlsso-guidelines ~/.gemini/config/plugins/samlsso-guidelines
   ```
2. When starting a new agent chat, the assistant will register `samlsso-development` as an available skill and automatically inspect these guidelines.

## 🧪 Testing & Releases

### 1. Running Tests
Execute the entire automated test runner from the root of the plugin directory to verify that all code logic, XML schemas, and versions are correctly aligned:
```bash
php tests/RunAllTests.php
```

### 2. Creating a Release
To package a new release zip using `tools/mkzip.sh`:
1. Update the version constant `PLUGIN_SAMLSSO_VERSION` in `setup.php` (e.g. to `'1.2.7'`).
2. Update the `OLDVERSION` and `NEWVERSION` variables in `tools/mkzip.sh` to match the version bump (e.g. `OLDVERSION='1.2.6'` and `NEWVERSION='1.2.7'`).
3. Run the release packaging script:
   ```bash
   ./tools/mkzip.sh
   ```
   *Note: This script automatically bumps version docstrings in all PHP files (excluding `vendor/`), executes the automated test runner, and aborts the release packaging if any tests fail.*
4. Manually update `samlsso.xml` to add a new `<version>` block listing the new version and its download URL.
5. Run the test suite:
   ```bash
   php tests/RunAllTests.php
   ```
   *Note: All tests must be green before pushing.*

---
*For questions or architectural clarification, engage with the repository owner (DonutsNL) via the Discord link in the README.*
