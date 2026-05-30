# ADR 0006: Install/Uninstall Teardown Refactoring

Fix for issue: https://github.com/DonutsNL/samlsso/issues/111

## Status
Accepted

## Context
The previous installation and uninstallation routines in `hook.php` dynamically scanned the `src/` directory using `scandir()` to discover class names and run their `install` and `uninstall` methods. This led to:
1. **Autoloader Pollution / Warnings**: The scan included `index.html`, which translated to class `GlpiPlugin\Samlsso\index.html`. Autoloading this file caused HTML content to be printed to the output buffer, potentially corrupting output streams/headers.
2. **Nondeterministic Table Setup/Teardown**: The filesystem index scan order is non-deterministic (filesystem-dependent), which can cause foreign key or installation dependency issues in some operating systems/filesystems.
3. **Empty Table Teardown Failure**: If dynamic class loading fails or a class check returns false, `CommonDBTM::getTable()` returns `''`. This meant `$migration->dropTable('')` was called, silently leaving tables (such as `glpi_plugin_samlsso_excludes`) in the database.
4. **Inefficient Migration Objects**: The dynamic loop instantiated a new `Migration` instance per class, violating the GLPI standard where a single shared `Migration` instance tracks the session.

## Decision
1. **Central Classes Constant**: Introduce a central `PLUGIN_SAMLSSO_CLASSES` constant array in `setup.php` that explicitly lists all database-backed and lifecycle-managed classes in dependency order.
2. **Eliminate scandir**: Remove directory scanning (`plugin_samlsso_getSrcClasses()`) completely.
3. **Shared Migration Instance**: Initialize and share a single `Migration` instance across all class installations and uninstallations.
4. **Deterministic Teardown**: Loop through the `PLUGIN_SAMLSSO_CLASSES` array during installation, and loop through `array_reverse(PLUGIN_SAMLSSO_CLASSES)` during uninstallation, ensuring a deterministic teardown.

## Consequences
- **Positive**: Foolproof installation and uninstallation, deterministic ordering, avoidance of autoloader issues on non-PHP files, and efficient database teardown where all tables are correctly deleted.
- **Negative**: Newly added database-backed classes must be manually added to the `PLUGIN_SAMLSSO_CLASSES` constant in `setup.php`.
