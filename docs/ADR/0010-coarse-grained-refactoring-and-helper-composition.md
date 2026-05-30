# ADR 0010: Coarse-Grained Refactoring and Helper Composition

## Status
Accepted

## Context
As codebases grow, large classes containing procedural or complex operations become hard to read and maintain. A common design response is to decompose classes by creating separate single-responsibility files (e.g. manager classes, validators, services). 

However, excessive decomposition can lead to "file bloat" and "abstraction leakage." For starting developers or new maintainers, having logic scattered across dozens of small files can make the execution flow feel like "magic" and increase the cognitive load required to trace operations.

## Decision
To maximize both readability and maintainability without creating unnecessary file bloat, the following standards are adopted for refactoring:

1. **Class-Internal Method Decomposition (Preferred)**:
   Instead of creating new classes/files, large or complex methods must first be decomposed into descriptive, single-responsibility `private` or `protected` helper methods within the same class.

2. **Coarse-Grained Helper Classes**:
   If a class grows too large (>800 lines) and extraction to a separate file is required, related functionalities must be grouped into a single, cohesive helper class rather than split into multiple fine-grained services.

3. **Strict Class Structure Layout**:
   To keep file navigation uniform and predictable across the plugin:
   - **Head of the Class**: All constant declarations (`const`) must reside immediately at the top of the class definition, before any property fields.
   - **Main/Public Flow**: Properties, constructor, and primary entry points should follow.
   - **Internal Methods Ordering**: Methods must be ordered down the class file (as best as possible) by:
     - **Execution/Call Flow**: Methods called earlier in the lifecycle or execution path should reside higher up than those called later.
     - **Usage Intensity**: Higher-intensity, frequently accessed, or more critical public methods must be positioned higher than low-intensity utility helpers.
   - **Bottom of the Class**: Database installation/uninstallation schema management hooks (`install` and `uninstall` methods) must always reside at the absolute bottom of the file.

4. **Visual Section Grouping**:
   Within a class, helper functions or specific segments (such as database persistence logic) should be organized into clearly labeled sections using comments (e.g. `// MARK: - Section Name` or similar header comments) to keep files highly navigatable.

## Consequences
- **Positive**:
  - Tracing execution flows is straightforward since related operations remain localized.
  - The total number of files in the project is kept low, making it easier for new coders to understand.
  - The public APIs of core classes remain clean and self-documenting.
- **Negative**:
  - Class files can become longer than they would be under strict micro-service decomposition.
