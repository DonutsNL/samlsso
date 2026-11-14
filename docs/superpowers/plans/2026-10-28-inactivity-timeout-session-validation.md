# Inactivity Timeout Alignment with GLPI Core Session Lifetime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide transparent alignment between samlSSO's `inactivity_timeout` and GLPI core's PHP session lifetime (`session.gc_maxlifetime`), warning administrators and enforcing the ceiling so samlSSO inactivity timeouts never exceed server-side session persistence.

**Architecture:** Inspect PHP's runtime session garbage collection lifetime (`ini_get('session.gc_maxlifetime')`) inside `ConfigItem::inactivity_timeout()`. Dynamically expose the effective GLPI core session ceiling in the field explanation text and surface an advisory warning or boundary validation when `inactivity_timeout > (session.gc_maxlifetime / 60)`. Document decisions in ADR 0029 and link with a formal GitHub issue.

**Tech Stack:** PHP 8.x, GLPI Core Configuration, Twig, PHPUnit / Custom Test Harness.

---

### Task 1: Create Formal GitHub Issue for Issue-First Traceability

**Files:**
- None (GitHub API)

- [ ] **Step 1: Create GitHub issue on DonutsNL/samlsso**
Create a formal issue describing the need to align samlSSO's inactivity timeout with GLPI core's session lifetime ceiling (`session.gc_maxlifetime`) to avoid silent server-side session purges before samlSSO timeouts are reached.

- [ ] **Step 2: Record issue number for ADR and Changelog**
Save the resulting GitHub issue number (e.g., #181).

---

### Task 2: Add Failing Unit Tests for Inactivity Timeout Session Ceiling

**Files:**
- Modify: `tests/ConfigIntegrityTest.php`

- [ ] **Step 1: Write test cases for inactivity timeout boundary conditions**
Add a test method `testInactivityTimeoutSessionCeilingValidation()` in `tests/ConfigIntegrityTest.php` testing:
1. Valid positive timeout less than or equal to core session lifetime.
2. Disabled timeout (`0`) which should always be valid without warning.
3. Timeout exceeding core session lifetime, asserting that `errors` contains a clear warning/notice regarding GLPI session lifetime (`session.gc_maxlifetime`).
4. Invalid non-numeric or negative input.
5. Dynamic explanation text in `formexplain` mentioning the active GLPI session lifetime in minutes.

- [ ] **Step 2: Run test suite to verify test failure**
Run: `php tests/RunAllTests.php`
Expected: FAIL due to missing session ceiling check in `ConfigItem::inactivity_timeout()`.

---

### Task 3: Implement Session Lifetime Check in ConfigItem.php

**Files:**
- Modify: `src/Config/ConfigItem.php:995-1025`

- [ ] **Step 1: Implement session lifetime calculation and advisory warning**
In `ConfigItem::inactivity_timeout(mixed $var)`:
1. Retrieve core session lifetime:
   ```php
   $coreLifetimeSec = (int)ini_get('session.gc_maxlifetime');
   $coreLifetimeMin = ($coreLifetimeSec > 0) ? intdiv($coreLifetimeSec, 60) : 0;
   ```
2. Build dynamic `ConfigItem::FORMEXPLAIN`:
   If `$coreLifetimeMin > 0`:
   Include reference to the current GLPI session ceiling.
3. If `$error === false` and `$var > 0` and `$coreLifetimeMin > 0` and `(int)$var > $coreLifetimeMin`:
   Set advisory warning in `ConfigItem::ERRORS`:
   ```php
   $warning = sprintf(
       __('⚠️ Inactivity timeout (%d min) exceeds GLPI session lifetime (%d min). Sessions will be terminated by the server after %d minutes before this timeout is reached.', PLUGIN_NAME),
       (int)$var,
       $coreLifetimeMin,
       $coreLifetimeMin
   );
   ```
4. Retain existing timezone environment checks.

- [ ] **Step 2: Run test suite to verify test passes**
Run: `php tests/RunAllTests.php`
Expected: ALL TESTS PASSED!

---

### Task 4: Documentation and Architecture Decision Record

**Files:**
- Create: `docs/ADR/0029-inactivity-timeout-session-lifetime-alignment.md`
- Modify: `changelog.md`

- [ ] **Step 1: Create ADR 0029**
Document the rationale, consequences, and design tradeoffs of evaluating samlSSO inactivity timeout against GLPI core session lifetime (`session.gc_maxlifetime`), referencing the GitHub issue created in Task 1.

- [ ] **Step 2: Update changelog.md**
Add an entry under `**V1.3.2**` referencing the GitHub issue and ADR 0029.

---

### Task 5: Verification & Remote Synchronization

**Files:**
- Git repository

- [ ] **Step 1: Run full test runner**
Run: `php tests/RunAllTests.php`
Ensure all 19 test files pass.

- [ ] **Step 2: Commit changes**
Commit all changes with descriptive commit messages referencing the GitHub issue.

- [ ] **Step 3: Push to remote origin/1.3.2**
Push commits promptly to `origin/1.3.2` as required by workspace rules.
