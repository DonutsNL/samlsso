# ADR 0005: Two-Tier Configuration Engine (`ConfigEntity` / `ConfigItem`) & Request Caching

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
All Identity Provider configuration settings are governed by a strict **two-tier architecture** (`ConfigEntity` for schema constants and state accessors; `ConfigItem` for reflection-verified field validation and UI metadata) paired with **request-level in-memory caching**. Raw string literals for configuration keys or claim mapping fields are forbidden, and every database column must have a 1:1 constant and validator method verified by reflection.

---

## Architectural Invariants

### 1. Two-Tier Configuration Model (`ConfigEntity` & `ConfigItem`)
1. **`ConfigEntity` (State & Constants)**:
   - Defines public class constants for every column in `glpi_plugin_samlsso_configs` (e.g., `ConfigEntity::ID`, `ConfigEntity::REQUEST_TIMEOUT`).
   - Holds the normalized field structure (`$fields`) and handles database read/write serialization (`xml_config` and top-level columns).
2. **`ConfigItem` (Validation & UI Metadata)**:
   - Serves as the base class for `ConfigEntity`.
   - For every field constant defined in `ConfigEntity`, `ConfigItem` implements a `protected` validator method with the **exact same name** (e.g., `request_timeout()`, `inactivity_timeout()`, `strict()`).
   - Every validator returns a standardized structure (`VALUE`, `EVAL` (`VALID` / `INVALID`), `ERRORS`, `FORMTITLE`, `FORMEXPLAIN`, `VALIDATE`, `RICHVALUE`).
3. **`ClaimMapItem` & `ClaimMapEntity`**:
   - Claim mapping fields follow the same constant-driven pattern (`ClaimMapItem::FIELD_*`), forbidding raw string keys.

### 2. Request-Level In-Memory Caching
1. Because GLPI hooks (`POST_INIT`, login page rendering, form evaluations) query SAML configuration repeatedly within a single HTTP request, both `ConfigEntity` and `Config` maintain request-scoped static caches:
   - `ConfigEntity::$dbRowCache` caches database rows by ID so repeated `new ConfigEntity($id)` instantiations execute at most 1 `SELECT` query per ID per HTTP request, staying synchronized on `updateXmlStructure()`.
   - `Config` caches static query results (`$isEnforcedCache`, `$isOnlyOneConfigCache`, `$configIdByEmailDomainCache`, `$hideLoginFieldsCache`).
   - `ConfigEntity::getFields()` caches `SHOW COLUMNS FROM` metadata per database instance.
2. Both `Config::clearCache()` and `ConfigEntity::clearCache()` are provided so unit tests can reset state cleanly between test cases.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** add a column to `glpi_plugin_samlsso_configs` without adding its matching constant in `ConfigEntity`, default in `ConfigDefaultTpl`, and validator method in `ConfigItem`.
- **NEVER** reference configuration keys or claim-mapping fields using raw string literals when a `ConfigEntity::*` or `ClaimMapItem::*` constant exists.
- **NEVER** bypass `ConfigEntity::$dbRowCache` with uncached `SELECT` loops during request initialization.

---

## Issue Triage & Maintainer Directives
- **When adding or modifying any configuration option:**
  - Validate the new field across all four layers (`Config::install()`, `ConfigEntity` constant, `ConfigDefaultTpl`, `ConfigItem` validator, and `ConfigForm::getTabWarnings()`) and run `tests/ConfigIntegrityTest.php` and `tests/ConfigCachingTest.php`.

---

## Codebase & Automated Test Verification
- **Production Files**: `src/Config/ConfigEntity.php`, `src/Config/ConfigItem.php`, `src/Config/ConfigDefaultTpl.php`, `src/Config/ClaimMapItem.php`, `src/Config/ClaimMapEntity.php`, `src/Config.php`
- **Automated Tests**: `tests/ConfigIntegrityTest.php`, `tests/ConfigCachingTest.php`, `tests/AdrComplianceTest.php`
