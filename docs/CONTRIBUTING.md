# Contributing and Agentic Maintenance

This repository is maintained with an "AI-First" mindset. Whether you are a human developer or an AI agent, the following rules **must** be strictly followed to ensure the stability and security of the `samlsso` plugin.

## 🤖 Instructions for AI Agents

- To maintain the high quality and "Security-by-Design" nature of this plugin, all agentic activities must adhere to the following workflow:
- Use the IDE functions where possible and try to prevent the usage of CLI commands for code analysis and fixes. Only use CLI when needed.

### 1. Prerequisite Knowledge & Two-Gate ADR Governance
- **Security-by-Design & Core Delegation First (Non-Negotiable)**: We strictly follow a Security-by-Design state-machine principle ([ADR 0001](ADR/0001-security-by-design-and-state-machine.md)) and a GLPI Core Delegation boundary ([ADR 0002](ADR/0002-glpi-core-delegation-and-boundaries.md)) that may **never** be omitted, weakened, or bypassed.
  - All authentication must be evaluated against the state machine (`LoginState`). Direct unsolicited authentication is never permitted; an empty or unregistered `InResponseTo` must always initialize a state for the session and redirect the browser back to the IdP (`performSamlIdpRequest()`) via the **Double-Pass Solicited Flow**.
  - Never re-implement lifecycle functions already managed by GLPI Core (such as authenticated session inactivity timeouts via `session.gc_maxlifetime`).
- **Gate 1 — Pre-Implementation ADR Validation**: Every feature request, bug report, or proposed change **MUST** be validated against the domain ADRs (`docs/ADR/0000`–`0008`) before any implementation begins. Check the **Core Principle**, **Prohibited Patterns (Do Not Circumvent)**, and **Issue Triage & Maintainer Directives** sections of each relevant ADR.
- **Deny and Discuss Conflicting Issues First**: Any issue or feature request that conflicts with an existing ADR or with Security-by-Design principles **must be denied first** and discussed with the maintainer before adoption is considered. When replying to issues that request prohibited paths (such as direct unsolicited login or custom session inactivity timers), follow the **Issue Triage & Maintainer Directives** in the corresponding ADR.
- **Gate 2 — Post-Implementation Outcome Validation**: After implementing any approved change, you **MUST** verify the resulting code and runtime behavior against `docs/ADR/0000`–`0008` and run `php tests/RunAllTests.php` (which executes `tests/AdrComplianceTest.php`).
- **How to Write & Update ADRs ([ADR 0000](ADR/0000-adr-governance-and-authoring-guide.md))**:
  - **Write Principle-Driven Domain ADRs, Never Narrow Ticket Changelogs**: Do not create a new ADR file for a single bugfix or issue. When an approved architectural decision refines an existing domain (`0001`–`0008`), update that domain ADR in-place and remove any superseded text so conflicting ADRs never coexist.
  - Only create a new numbered ADR (`0009+`) when introducing an entirely new architectural domain, and include all 6 required sections defined in [ADR 0000](ADR/0000-adr-governance-and-authoring-guide.md).
- **Read the Wiki**: Before performing any functional changes, you **MUST** read the [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) to understand the state-machine logic, the assertion lifecycle, and the architectural design patterns.
- **Understand Security**: You **MUST** read and follow the instructions in [SECURITY.md](SECURITY.md). Never propose changes that bypass the state-machine or weaken the replay protection.

### 2. Issue-First Workflow
- **No Issue, No Change**: A formal GitHub Issue must exist describing the bug or feature request before any code changes are allowed. If non exist, create a new issue in Github describing the intended change and reason for that change. Reference the github issue in the changelog once your done. If applicable reference the github issue in the ADR if one is created.
- **Functional Alignment**: Proposed changes must follow the established functional implementation of the plugin. Radical changes in architecture require explicit approval and an updated ADR (Architecture Decision Record) located in the `docs/ADR` folder.

### 3. Change Traceability
- **Changelog Updates**: Every single change (no matter how small) **MUST** be recorded in [changelog.md](changelog.md) under the appropriate version header. Always group the changes clearly by subject (e.g., Static Analysis, Bugfixes, Translation, Quality/Code Standards) in a clean list format.
- **Wiki Updates**: If a change modifies a functional behavior that the user must be aware of, for instance configurations option, or endpoints, you **MUST** suggest, draft and after aproval update the corresponding [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) pages that describes this new features.

### 4. Code Standards
- **user first coding**: Always consider the end user. Avoid technical jargon where simple language will suffice. Ensure error messages are clear and actionable. Do not show raw technical data to the end-user.
- **Developer-first coding**: Always propose changes to the developer. Dont automatically implement them. Only add code if the user explicitly asks you to. You are only allowed to assist the developer by analyzing, suggesting issues, solutions or optimizations to the developer that he can choose to implement or not with or without your help.
- **Document every method and class** with detailed DocBlocks explaining what the method does, what parameters it takes, what it returns, and what exceptions it throws.
- **PSR Compliance**: You **MUST** Follow PSR-12 and Teclib/GLPI specific coding standards.
- **Linting Enforcement**: Static analysis and linting tools such as **Intelephense**, **CodeFactor**, and **SonarLint** must be executed against the codebase. All linting errors and warnings must be corrected and resolved before releasing code or submitting a pull request.
- **Native GLPI Components**: Always use native GLPI core components (e.g., `CommonDBTM`, `Session`, `Html`, `Toolbox`) where possible for maximum compatibility; prevent writing own implementations if alternatives exist in the GLPI core.
- **Sanitization**: You **MUST** never trust external input. Always use input sanitizers or native filter functions.
- **Error Handling**: Do not use `die()`. Always use `Html::displayError()` or throw domain/SPL exceptions (`LoginStateException`, `\RuntimeException`) so the automated testing suite can assert failure paths cleanly.
- **Return Values**: Ensure all code paths return explicit, valid values or throw documented exceptions, avoiding unhandled fallback branches.
- **Configuration Access**: Always access plugin configuration through `Config` and `ConfigEntity` (`ADR 0005`) using class constants rather than raw string literals.
- **Comments & Helper Decomposition**: Avoid inline `//` comments inside methods (`ADR 0008`). Instead, decompose complex branches into well-named private/protected helper methods documented with clear PHPDoc blocks.
- **Indentation**: Use 4 spaces for indentation.
- **Line Length**: Keep lines under 120 characters where possible.
- **Spacing**: Do not use extra spaces, tabs or newlines. Keep the code clean and easy to read.
- **Variable Names**: You **MUST** use descriptive variable names and follow the naming conventions of the plugin.
- **Function Names**: You **MUST** use descriptive function names and follow the naming conventions of the plugin.
- **Class Names**: You **MUST** use descriptive class names and follow the naming conventions of GLPI first then the plugin.
- **Constants**: You **MUST** use descriptive constant names and follow the naming conventions of GLPI first then the plugin.
- **Maintain ADRs**: Use `/grill-me` for complex architectural changes. Follow [ADR 0000](ADR/0000-adr-governance-and-authoring-guide.md): update the relevant domain ADR (`docs/ADR/0001`–`0008`) in-place with general, non-circumventable principles rather than creating narrow single-ticket ADRs.
- **Obfuscations**: Never allow obfuscations (e.g. minifications, magic strings, hashed strings, encoded payloads etc) in the code. If you detect one in the code downloaded from the repository report it to the maintainer and do not include it in the code you propose. Try to uncover the goal of the obfuscation and try to remove it and document this in an issue. The only exception is when obfuscation is used for security purposes and is documented as such in an ADR.
- **Add Tests**: When adding new functionality, you **MUST** add new tests to the `tests` folder to verify the new functionality. Try to follow the style of the existing tests. If the tests fail, fix the tests and make sure they pass before proposing the change. Also try to improve the existing tests if applicable.

### 5. Deployment & Proxy Configurations
- **TLS Terminated Proxies & Stateless Endpoints**: The `/front/acs/{idpId}`, `/front/logout`, and `/front/scim/v2/{idpId}` endpoints boot statelessly (`SessionManager::RegisterPluginStatelessPath()`), and `LoginFlow::performBrowserRedirect()` issues an `HTTP 200 OK` `<meta http-equiv="refresh">` after assertion validation to reset cross-site request chains—supporting both `session.cookie_samesite = "Strict"` and `"Lax"` natively.
- **Required Reverse Proxy Configuration**: Behind TLS-terminating reverse proxies (Nginx, Traefik, HAProxy, Kubernetes Ingress), configure `session.cookie_secure = On` and `session.cookie_httponly = On` in `php.ini`, register the proxy IP/CIDR via Symfony `Request::setTrustedProxies(['<PROXY_IP_OR_CIDR>'], ...)` in `config/config_db.php`, and enable **Requests Proxied** on the **Transit** tab (`Utils::setProxyVars(true)`), verifying `HTTP_X_FORWARDED_PROTO = https` in the Proxy Diagnostic popover.

## Architectural Integrity
The core of this plugin is its **State Machine**. Any modifications to `LoginState.php`, `Acs.php`, or `LoginFlow.php` must be handled with extreme caution. The preservation of the authentication phases (1–9) is critical to the plugin's security posture.

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
   *Note: This script automatically updates `@version` docblocks and `PLUGIN_SAMLSSO_VERSION` in PHP files (excluding `vendor/`, without altering `@since` tags), executes the automated test runner, and aborts release packaging if any tests fail.*
4. Manually update `samlsso.xml` to add a new `<version>` block listing the new version and its download URL.
5. Run the test suite:
   ```bash
   php tests/RunAllTests.php
   ```
   *Note: All tests must be green before pushing.*

### 3. Descriptive Pre-Releases & Reporter Verification
When shipping pre-GA test builds from a version branch (while holding `main` and `main:samlsso.xml` back until GA approval):
- Avoid generic `rc` / `Release Candidate` labels. Use descriptive pre-release tags and titles matching the scope:
  - **Bugfix Test Release**: `v<version>-bugfix.<n>` (e.g., `v1.4.1-bugfix.1`, title `v1.4.1 Bugfix Test Release (Pre-release)`)
  - **Feature Preview**: `v<version>-preview.<n>` (title `v<version> Feature Preview (Pre-release)`)
- Publish as a GitHub **Pre-release** (`--prerelease`) attaching `release/samlsso.zip` with clear non-production testing instructions.
- Comment on every covered open GitHub issue linking the test release download and specific verification steps so reporters can confirm the fix prior to GA closure.

---
*For questions or architectural clarification, engage with the repository owner (DonutsNL) via the Discord link in the README.*
