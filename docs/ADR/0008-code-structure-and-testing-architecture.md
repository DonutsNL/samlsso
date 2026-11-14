# ADR 0008: Coarse-Grained Code Structure & Process-Isolated Testing Strategy

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
Codebase maintainability relies on **coarse-grained, execution-ordered class layouts** (avoiding microservice file fragmentation) and **100% automated verification** via a process-isolated integration test runner (`tests/RunAllTests.php`). No code change is complete unless it passes both behavioral flow tests and architectural ADR compliance tests.

---

## Architectural Invariants

### 1. Coarse-Grained Class Decomposition & Internal Ordering
1. **Class-Internal Decomposition First**: Complex methods are decomposed into descriptive `private` or `protected` helper methods within the same class rather than scattering logic across dozens of tiny single-method classes.
2. **Coarse-Grained Helper Classes**: Extraction to a dedicated helper class (`src/LoginFlow/*`, `src/Config/*`, `src/Scim/*`, `src/Utility/*`) occurs only when a cohesive domain exceeds ~800 lines or represents a distinct lifecycle stage.
3. **Predictable File Layout**:
   - **Top of Class**: All `public const` / `private const` declarations before any properties.
   - **Properties & Entry Points**: Class properties, constructor, and primary public entry points.
   - **Call-Flow Method Ordering**: Internal helper methods ordered top-to-bottom matching their runtime execution sequence and usage frequency, grouped by clear section headers (`// MARK: - Section`).
   - **Bottom of Class**: Database lifecycle methods (`install()` and `uninstall()`) always reside at the very bottom of the class.
4. **Defensive Coding & Static Analysis Compliance**:
   - All PHP files declare `strict_types=1` where applicable, omit closing `?>` tags, use 4-space indentation, and contain zero obfuscated payloads (`base64_decode` / `eval` / magic hashes).
   - Never use `die()` or `exit()` in testable library methods where `Html::displayError()` or `PluginException` is expected.
   - Initialize variables before `try/catch` or conditional blocks and wrap intentional conditional assignments in double parentheses (`if (($row = $iter->next()))`).

### 2. Process-Isolated Test Harness (`tests/RunAllTests.php`)
1. Every `*Test.php` suite in `tests/` is discovered and executed in its own isolated PHP child process by `tests/RunAllTests.php` to prevent class-redeclaration and mock-state collisions.
2. `tests/TestHarness.php` and `tests/Shims.php` provide centralized GLPI 12-aligned environment bootstrapping, output buffering (`ob_start()`), and database query inspection without requiring a live web server.
3. State-machine transitions and redirect outcomes are asserted deterministically via `LoginState::getLoginFlowTrace()` (`addLoginFlowTrace()`), `ConfigIntegrityTest`, and `AdrComplianceTest`. Passing tests emit a single concise `✅` line without raw data dumps; failing tests output the input, return value, and execution state (exception, exit, redirect, trace) for rapid diagnosis.
4. Release packaging (`./tools/mkzip.sh`) executes `php tests/RunAllTests.php` automatically and aborts packaging if any test suite fails.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** scatter a single sequential workflow across numerous tiny service classes when private helper methods in the owning class suffice.
- **NEVER** introduce obfuscated code, unescaped SQL string concatenation, or `die()` calls in production classes.
- **NEVER** weaken or delete a test assertion to make a failing test pass; fix the underlying code or update the test only when an approved ADR change explicitly alters the invariant.
- **NEVER** push commits or package a release without running `php tests/RunAllTests.php` with 0 failures.

---

## Issue Triage & Maintainer Directives
- **When reviewing any pull request or issue fix:**
  - Require a corresponding regression test in `tests/*Test.php` and verify that `php tests/RunAllTests.php` (including `AdrComplianceTest`) passes cleanly.

---

## Codebase & Automated Test Verification
- **Production Files**: `src/*.php`, `src/Config/*.php`, `src/LoginFlow/*.php`, `src/Scim/*.php`, `src/Controller/*.php`, `src/Utility/*.php`, `tools/mkzip.sh`
- **Automated Tests**: `tests/RunAllTests.php`, `tests/TestHarness.php`, `tests/Shims.php`, `tests/AdrComplianceTest.php`
