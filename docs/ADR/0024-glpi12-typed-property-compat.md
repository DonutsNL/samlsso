# ADR 0024: GLPI 12 Typed-Property Compatibility Layer

## Status
Accepted

## Context
GLPI 12 introduced PHP type declarations on several public core properties that the samlSSO plugin redeclares in its own classes:

- `CommonGLPI::$rightname` and its subclasses became `public static string $rightname`;
- `CommonDBTM::$dohistory` became `public bool $dohistory`;
- `RuleCollection::$menu_option` became `public string $menu_option`.

PHP enforces property **type invariance** across a class hierarchy: a child class may redeclare a parent property only with an identical type. The plugin historically redeclared these properties **untyped** (to set `Config::$rightname = 'config'`, `$dohistory = true`, `RuleSamlCollection::$menu_option = 'Saml'`, and so on). On GLPI 12 those untyped redeclarations are a fatal compile error (`Type of ... must not be defined`), while simply removing the redeclarations would be a fatal error on GLPI 11 (untyped parent, so no value could be assigned). A **single** declaration can therefore never satisfy both cores at the same time, and the plugin could not be installed on GLPI 12 at all (issue https://github.com/DonutsNL/samlsso/issues/167).

GLPI 11 and GLPI 12 cannot be supported by editing the concrete classes in place, because the concrete class is compiled identically under both cores. The version has to be resolved at load time, before the concrete class is declared.

## Decision
Introduce `src/compat.php`, a single compatibility module that:

1. Declares a gate helper `GlpiPlugin\Samlsso\Compat\samlsso_glpi12(): bool`, which returns `true` when `GLPI_VERSION` is defined and `>= 12.0.0` (`GLPI_VERSION` is defined by the core bootstrap long before any plugin class loads).
2. Branches on that helper and declares, **once per GLPI major**, one abstract intermediate class per concrete plugin class, each carrying exactly the parent's property shape for that major:
   - GLPI 12 branch → typed properties (`public static string $rightname`, `public bool $dohistory`, `public string $menu_option`);
   - GLPI 11 branch (and the untyped unit-test doubles) → untyped properties.
3. Each intermediate is `abstract`, is namespaced under `GlpiPlugin\Samlsso\Compat`, and is required with `require_once` by its concrete class before that class extends it. `RuleSamlCollectionCompat` is additionally guarded with `class_exists('RuleCollection')` because the standalone unit-test harness does not always provide that core parent.

The concrete plugin classes (`Config`, `Exclude`, `LoginFlow`, `RuleSaml`, `RuleSamlCollection`) then extend their `*Compat` intermediate and redeclare **nothing** that collides with the core. `src/compat.php` is idempotent (function and class guards allow any number of inclusions) and contains no obfuscation, no `eval`, and no core patch — it only selects a declaration per major version. This decision is scoped to class loading and is orthogonal to the reverse-proxy HTTPS/cookie handling, which remains governed by ADR 0016.

## Consequences
- **Positive**:
  - The plugin loads and runs on GLPI 12 (typed cores) **and** GLPI 11 (untyped cores) from a single source tree, with identical behaviour on GLPI 11 versus the previous `main`.
  - No GLPI core file is patched and no hook contract changes; everything stays plugin-only, which keeps the plugin installable from the marketplace and safe across core upgrades.
  - The pattern is explicit and documented: a maintainer reading one concrete class sees it extends a version-typed `*Compat` base, and `compat.php` centralises the only place where GLPI major differences in property typing live.
  - The unit-test doubles (untyped) resolve to the GLPI 11 branch, so the standalone test harness keeps working without real GLPI classes.
- **Negative**:
  - A new indirection layer: every property that changes typing in a future GLPI major must be mirrored in `compat.php` (one typed and one untyped declaration), which is a maintenance point to remember when bumping `PLUGIN_SAMLSSO_MAX_GLPI`.
  - One abstract intermediate **per** concrete class is mandatory (static properties such as `$rightname` are not shared across siblings without redeclaration), so the module lists one intermediate per class rather than a single shared base.

## Alternatives considered
1. **A separate GLPI 12 fork/branch of the plugin.** Rejected: it doubles maintenance for the whole plugin and fragments issues and translations for what is, at this stage, a typing-only difference.
2. **Inline conditional `class ... extends ...` selection inside each concrete file.** Rejected: PHP compiles class declarations eagerly; choosing a base class per major from inside the concrete file either duplicates the whole class body per branch or still needs a pre-declared intermediate, and it scatters version logic across every file instead of one auditable module.
3. **A runtime shim that mutates the core classes.** Rejected: patching or monkey-patching core is a GLPI core modification, contrary to the plugin-only rule and fragile across core releases.
4. **Drop the redeclarations and rely on core defaults.** Rejected: on GLPI 11 the parents are untyped and the plugin needs its own values (`config` rights, `$dohistory = true`, `menu_option = 'Saml'`); removing them loses required behaviour on GLPI 11.
