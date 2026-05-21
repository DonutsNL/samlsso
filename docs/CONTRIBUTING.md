# Contributing and Agentic Maintenance

This repository is maintained with an "AI-First" mindset. Whether you are a human developer or an AI agent, the following rules **must** be strictly followed to ensure the stability and security of the `samlsso` plugin.

## 🤖 Instructions for AI Agents

- To maintain the high quality and "Security-by-Design" nature of this plugin, all agentic activities must adhere to the following workflow:
- Use the IDE functions where possible and try to prevent the usage of CLI commands for code analysis and fixes. Only use CLI when needed.

### 1. Prerequisite Knowledge
- **Read the Wiki**: Before performing any functional changes, you **MUST** read the [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) to understand the state-machine logic, the assertion lifecycle, and the architectural design patterns.
- **Understand Security**: You **MUST** read and follow the instructions in [SECURITY.md](SECURITY.md). Never propose changes that bypass the state-machine or weaken the replay protection.

### 2. Issue-First Workflow
- **No Issue, No Change**: A formal GitHub Issue must exist describing the bug or feature request before any code changes are allowed.
- **Functional Alignment**: Proposed changes must follow the established functional implementation of the plugin. Radical changes in architecture require explicit approval and an updated ADR (Architecture Decision Record).

### 3. Change Traceability
- **Changelog Updates**: Every single change (no matter how small) **MUST** be recorded in [changelog.md](changelog.md) under the appropriate version/date.
- **Wiki Updates**: If a change modifies a functional behavior, configuration option, or endpoint, you **MUST** update the corresponding [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) pages to reflect the new state.

### 4. Code Standards
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

## Architectural Integrity
The core of this plugin is its **State Machine**. Any modifications to `LoginState.php`, `Acs.php`, or `LoginFlow.php` must be handled with extreme caution. The preservation of the authentication phases (1-8) is critical to the plugin's security posture.

---
*For questions or architectural clarification, engage with the repository owner (DonutsNL) via the Discord link in the README.*
