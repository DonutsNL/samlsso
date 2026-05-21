# ADR 0005: Configuration Entity Implementation

## Status
Accepted

## Context
The samlSSO plugin requires a robust, type-safe, and verifiable way to manage its configuration. Historically, GLPI plugins often use simple associative arrays for configuration, which are difficult to validate and test. We need a system that:
1.  Enforces a strict schema aligned with the database.
2.  Provides granular validation for each configuration field.
3.  Supports behavioral testing (e.g., verifying SQL generation).
4.  Is scannable for "dead" or unreferenced configuration fields.

## Decision
We implement a two-tier configuration system consisting of `ConfigEntity` and `ConfigItem`.

### 1. ConfigEntity (State & Accessors)
The `ConfigEntity` class represents a single configuration instance (a database row or a template).
-   **Constants**: Defines public constants for every database field (e.g., `ConfigEntity::ID`, `ConfigEntity::NAME`). This provides a single source of truth for field names.
-   **State Management**: Holds the actual configuration values in a private `$fields` array.
-   **Aliasing**: In testing environments, `ConfigEntity` can be aliased to a `MockConfigEntity` to decouple logic from the database.

### 2. ConfigItem (Validation & Enrichment)
The `ConfigItem` class serves as the base class for `ConfigEntity` and contains the validation logic.
-   **Method-Based Validation**: For every field defined in `ConfigEntity`, `ConfigItem` provides a protected method with the **exact same name** (e.g., `id()`, `name()`, `conf_domain()`).
-   **Standardized Return**: Each validation method returns an array containing:
    -   `VALUE`: The normalized value.
    -   `EVAL`: Whether the value is `VALID` or `INVALID`.
    -   `ERRORS`: Translatable error messages if applicable.
    -   `FORMEXPLAIN`/`FORMTITLE`: UI-related metadata.
-   **Reflection-Friendly**: This naming convention allows automated tests to use Reflection to verify that every field has a corresponding validator.

### 3. Verification Strategy
The integrity of this implementation is enforced by `ConfigIntegrityTest.php`, which:
-   Executes the actual `install()` logic to capture the generated SQL.
-   Verifies that every constant in `ConfigEntity` exists in the SQL schema.
-   Verifies that every constant has a corresponding validator method in `ConfigItem`.
-   Verifies that the SQL datatypes (TINYINT, TEXT, etc.) are "sensible" for the field's purpose.

## Consequences
-   **Pros**:
    -   High structural integrity: Schema, constants, and validators are always in sync.
    -   Excellent testability: Mocks can precisely simulate any configuration state.
    -   Clear separation of concerns: `ConfigEntity` manages *what* the config is, `ConfigItem` manages *how* it's validated.
-   **Cons**:
    -   Initial boilerplate: Every new field requires a constant and a validator method.
    -   Reflection overhead: Validation and integrity checks rely on PHP reflection.
