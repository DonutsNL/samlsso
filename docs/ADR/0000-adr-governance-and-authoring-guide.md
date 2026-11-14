# ADR 0000: ADR Governance, Validation Workflow, and Authoring Guide

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
Architecture Decision Records (`docs/ADR/`) are the **authoritative architectural constitution** of the `samlsso` plugin. They must capture **general, domain-level engineering principles and hard boundaries**—never narrow single-ticket changelogs—so that neither human developers nor AI agents can circumvent core invariants by inventing alternative code paths.

---

## 1. Why We Restructured the ADRs (Avoiding the "Narrow ADR Trap")

Historically, creating a separate ADR for every individual bugfix (`0001` through `0036`) caused three systemic problems for AI-assisted maintenance:
1. **Narrow Symptom Descriptions Permitted Workarounds**: When an ADR only described a single edge case (e.g., a specific `InResponseTo` check or a specific timeout calculation), AI agents treated anything outside that narrow symptom as fair game—introducing alternative paths for unsolicited logins or duplicating GLPI core session timeouts.
2. **Contradictory Sequential ADRs**: Leaving older, superseded ADRs alongside newer ones (such as `0034` saying *"never hide inline diagnostic tables"* next to `0035` saying *"always hide inline diagnostic tables"*) caused agents reading `docs/ADR/` to receive conflicting instructions.
3. **Context Fragmentation**: Scattering rules for a single subsystem (like `LoginState` or `ConfigForm`) across 10+ small files prevented holistic validation before and after code changes.

To prevent this permanently, all architectural decisions are organized into **8 cohesive Domain ADRs (`0001`–`0008`)** governed by this guide (`0000`).

---

## 2. Mandatory Pre-Implementation & Post-Outcome Validation Workflow

Every contributor and AI agent **MUST** execute the following two-gate validation loop on every issue, feature request, bug report, or pull request:

### Gate 1: Pre-Implementation ADR Validation (Before Writing Any Code)
1. **Read the Relevant Domain ADRs (`0001`–`0008`)**: Identify which architectural domains the request touches and check the **Core Principle**, **Prohibited Patterns**, and **Issue Triage & Maintainer Directives** sections.
2. **Deny & Discuss Conflicting Requests Immediately**:
   - If a user issue, feature request, or proposed fix conflicts with any ADR (for example: requesting direct IdP-initiated / unsolicited authentication without the double-pass solicited flow, requesting a plugin-managed session inactivity timer, or weakening signature/state-machine checks):
     1. **Halt implementation immediately.** Never write a workaround or flag that bypasses the ADR.
     2. **Flag the conflict to the maintainer**, citing the specific ADR number and section.
     3. **Follow the ADR's Issue Triage Directive** when responding to the issue reporter (e.g., explaining how the existing architecture already solves their use case safely, such as the double-pass solicited flow).

### Gate 2: Post-Implementation Outcome Validation (Before Proposing or Committing)
1. **Verify Outcome Against ADR Invariants**: Inspect all modified files against the **Architectural Invariants** and **Prohibited Patterns** of `0001`–`0008` to confirm no unintended side paths, fallback defaults, or core duplications were introduced.
2. **Run Automated ADR & Integration Verification**:
   ```bash
   php tests/RunAllTests.php
   ```
   This executes `tests/AdrComplianceTest.php` alongside all unit and integration suites to verify both ADR documentation hygiene and codebase compliance against the ADR invariants.

---

## 3. How to Write and Maintain ADRs

Whenever an approved architectural change is adopted after maintainer discussion, follow these strict authoring rules:

### Rule 1: Amend Domain ADRs In-Place — Do Not Spawn Ticket Changelogs
- **Do NOT create a new ADR file for a bugfix, UI tweak, or single GitHub issue.** Detailed per-issue history belongs in `changelog.md` and git commit messages.
- If an approved change refines or extends an existing domain (`0001`–`0008`), **update that domain ADR in-place** and remove any obsolete text so the document remains a single, current truth.
- Only create a new numbered ADR (`0009+`) if the plugin introduces an **entirely new architectural domain** not covered by `0001`–`0008`.

### Rule 2: Never Leave Superseded or "Proposed" Files in `docs/ADR/`
- Every file in `docs/ADR/` must have `## Status: Accepted — Enforced Architectural Invariant`.
- Draft plans belong in `docs/superpowers/plans/`, never in `docs/ADR/`.
- If an architectural decision is replaced, **delete or overwrite the old rule immediately** so two conflicting rules never coexist in `docs/ADR/`.

### Rule 3: Mandatory Structure for Every ADR
Every ADR (`0001`–`0008`) **MUST** contain these six exact sections (enforced automatically by `tests/AdrComplianceTest.php`):
1. `## Status`: Must be `Accepted — Enforced Architectural Invariant`.
2. `## Core Principle (Non-Negotiable)`: A concise, general statement of the architectural law that applies across the entire plugin.
3. `## Architectural Invariants`: Concrete, numbered rules governing classes, state transitions, data structures, and boundaries.
4. `## Prohibited Patterns (Do Not Circumvent)`: Explicit list of forbidden shortcuts, anti-patterns, and bypasses that agents and developers must never introduce.
5. `## Issue Triage & Maintainer Directives`: Standard guidance on how to answer GitHub issues or feature requests that challenge or misunderstand this principle.
6. `## Codebase & Automated Test Verification`: Mapping of production files and automated test suites (`tests/*Test.php`) that enforce the ADR.

---

## 4. Index of Restructured Domain ADRs & Legacy Consolidation Map

| Domain ADR | Architectural Scope | Consolidated Legacy ADRs (`0001`–`0036`) |
| :--- | :--- | :--- |
| [ADR 0001](0001-security-by-design-and-state-machine.md) | **Security-by-Design State Machine & Solicited-Only Authentication (Double-Pass Flow)** | `0002`, `0011`, `0013`, `0017`, `0020`, `0028`, `0036` |
| [ADR 0002](0002-glpi-core-delegation-and-boundaries.md) | **GLPI Core Delegation, Protocol Boundaries & Timeout Separation of Duties** | `0009`, `0016`, `0018`, `0023`, `0026`, `0029` |
| [ADR 0003](0003-cryptography-certificates-and-secret-management.md) | **Cryptography, Certificate Hygiene & Secret Management** | `0004`, `0022`, `0027` *(Crypto/Secrets)*, `0030`, `0031`, `0032` |
| [ADR 0004](0004-fail-closed-provisioning-and-access-control.md) | **Fail-Closed Configuration, JIT Provisioning & Access Control** | `0007`, `0008`, `0012`, `0027` *(Access/JIT/Bypass)* |
| [ADR 0005](0005-configuration-engine-and-caching.md) | **Two-Tier Configuration Engine (`ConfigEntity` / `ConfigItem`) & Request Caching** | `0001`, `0005`, `0024` |
| [ADR 0006](0006-minimalist-ui-and-diagnostic-popovers.md) | **Minimalist Tabler UI, Modular Twig Templates & On-Demand Diagnostic Popovers** | `0019`, `0021`, `0033`, `0034`, `0035` |
| [ADR 0007](0007-lifecycle-migrations-and-tooling.md) | **Deterministic Install/Upgrade Lifecycle, Sanity Checks & Translation Tooling** | `0006`, `0014`, `0015`, `0025`, `0030` *(Migrations)* |
| [ADR 0008](0008-code-structure-and-testing-architecture.md) | **Coarse-Grained Code Structure & Process-Isolated Testing Strategy** | `0003`, `0010` |

