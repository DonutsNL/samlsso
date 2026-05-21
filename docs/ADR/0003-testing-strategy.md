# ADR 0003: Testing Strategy

## Status
Accepted

## Context
The GLPI plugin ecosystem is highly dependent on a global state (e.g., `$DB`, `$CFG_GLPI`, `Session`) and hard-coded environment checks (e.g., `PHP_SAPI`). This makes standard unit testing extremely difficult without a full GLPI installation and database. 

To harden the `samlsso` plugin, we need a way to validate complex authentication flows, configuration logic, and security checks (like Open Redirect prevention) in a lightweight, automated fashion.

## Decision
We will implement a **Lightweight Integration Test Harness** that shims the necessary GLPI core dependencies and executes tests in a controlled CLI environment.

### Key Considerations & Implementation Details:

1.  **Centralized Shimming**: All global GLPI dependencies (e.g., `__()`, `Session`, `Html`, `Toolbox`, `Plugin`, `CommonDBTM`) are centralized in `tests/TestHarness.php`. This ensures consistency across all test suites and resolves "undefined function/class" errors in namespaced production code.
2.  **CLI Environment Bypassing**: The plugin uses `isCommandLine()` to exit early in CLI. We shim this function to return `false` during tests and use the global variable `$GLPI_IS_COMMAND_LINE = false` to allow "web-only" logic to execute in a terminal.
3.  **Output Buffering**: To prevent `header()` and `setcookie()` calls from throwing "headers already sent" warnings, the `TestHarness` utilizes `ob_start()`. The buffer is explicitly flushed using `ob_end_flush()` in the destructor (or manually) to ensure test results are visible in the console.
4.  **Property Visibility for Mocking**: To enable deep inspection of internal state (like the `trace` in `Loginstate`), we have transitioned key properties in production classes from `private` to `protected`. This allows test doubles (mocks) to access and validate the internal state of the plugin during execution.
5.  **Process Isolation**: To avoid "Cannot redeclare class" errors (caused by multiple test suites loading the same shims), we utilize a centralized runner (**`RunAllTests.php`**). This runner executes each `*Test.php` file in its own separate PHP process.
6.  **Trace-Based Validation**: Instead of attempting to "catch" redirects (which use `exit`), we leverage the `LoginState::addLoginFlowTrace()` mechanism. By inspecting the trace, we verify that the plugin reached the correct logical endpoint.
7.  **Namespace Resolution**: All global function calls in namespaced test suites (like OpenSSL functions) are prefixed with a backslash (`\`) to ensure correct resolution without polluting the test namespace.

## Consequences
- **Positive**: 
    - Enables automated validation of complex configuration and security scenarios.
    - Zero dependency on a live GLPI database or web server.
    - Fast, modular, and extendable testing suite.
- **Negative**:
    - The harness requires maintenance as the GLPI core API evolves.
    - Does not fully test the final `exit()` call, only the logic leading up to it.
- **Neutral**:
    - Future refactoring to replace `exit()` with exceptions will allow even cleaner teardowns.

## Technical Implementation
The suite is discovery-driven and executed via the centralized runner:
```bash
php tests/RunAllTests.php
```
This discovery-based approach allows for adding new tests simply by creating a file ending in `Test.php` in the `tests/` directory.
