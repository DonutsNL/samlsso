# ADR 0006: Minimalist Tabler UI, Modular Twig Templates & On-Demand Diagnostic Popovers

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
The administrative interface must remain **clean, functional, and minimalist**, adhering strictly to GLPI's native Bootstrap / Tabler design language without marketing bloat or permanent inline diagnostic clutter. All configuration warnings, field errors, and environment diagnostic tables (`validateProxyEnvironment()`, `validateTimezoneEnvironment()`) are surfaced through `ConfigItem::ERRORS`, hidden inline on the form (`display: none !important`), and rendered on-demand inside sanitized HTML popovers (`SamlSsoPopoverManager`).

---

## Architectural Invariants

### 1. Modular Twig Template Architecture & External Stylesheet
1. `templates/configForm.html.twig` acts as the master skeleton and includes each of the 8 configuration tabs from `templates/configForm/`:
   - `general.html.twig`, `transit.html.twig`, `provider.html.twig`, `idp.html.twig`, `security.html.twig`, `claim_mapping.html.twig`, `scim.html.twig`, and `logging.html.twig` (plus `templates/configBackupRestore.html.twig` above the configuration list).
2. All CSS rules reside in the external stylesheet `public/css/samlSSO.css` (registered via `$PLUGIN_HOOKS['add_css']` in `setup.php`).
3. Every template variable and sub-key must be initialized and guarded (`is defined`) so templates render without error under GLPI 12 Twig `strict_variables` debug mode.
4. **Content Security Policy (CSP) Compliance**: All plugin templates and PHP UI classes must remain compatible with GLPI's nonce-based CSP (`script-src 'nonce-<random>'`, `'csp_compliant' => true` in `plugin_version_samlsso()`): bind UI interactions exclusively via `data-*` attributes and delegated event listeners inside Twig `<script>` blocks (or external JS assets), never emit inline `on*` HTML event handler attributes or `javascript:` URLs, and never echo raw `<script>` tags from PHP strings.
5. Country indicators in `logging.html.twig` use offline Unicode country flag emojis rather than external CDN image requests.
6. Major capabilities shipped in `Beta` (such as `scim.html.twig`) use a neutral `bg-secondary-lt` `Beta` badge on their tab and top notice and link directly (`target="_blank" rel="noopener noreferrer"`) to their GitHub Wiki documentation page.

### 2. Harmonized Popover Module (`SamlSsoPopoverManager`) & Inline Table Hiding
1. All field tooltips, warning indicators (`⚠️`), error indicators (`⭕`), and diagnostic tables are initialized and managed through `SamlSsoPopoverManager` in `templates/configForm.html.twig`.
2. **Prefix-Based Classification**: Instead of adding separate `warnings` keys to `ConfigItem` arrays, message severity is classified dynamically from the `errors` string prefix (`⚠️` for warnings, `⭕` / `❌` for errors, `✅` for informational success notices).
3. **HTML Diagnostic Tables in Popovers**:
   - `SamlSsoPopoverManager.init` configures `bootstrap.Popover` with `sanitize: true` and an extended `allowList` explicitly permitting `table`, `thead`, `tbody`, `tr`, `th`, and `td` (with `class`, `style`, `scope`, `colspan`, `rowspan` attributes) while stripping any `<script>` or XSS vectors.
   - `processNotificationHelper` extracts full table HTML (`$textEl.html().trim()`), sets `data-bs-html="true"` and `.samlsso-popover-table` on the trigger icon, and hides the inline container (`$textEl.addClass('samlsso-diagnostic-table-container').hide()`), reinforced by `.glpisaml .samlsso-diagnostic-table-container { display: none !important; }` in `public/css/samlSSO.css`.

### 3. Tab Warning Badge Alignment (`ConfigForm::getTabWarnings()`)
1. Every form field rendered in a child template under `templates/configForm/*.html.twig` must be mapped to its exact corresponding tab key in `ConfigForm::getTabWarnings()` (e.g., `REQUEST_TIMEOUT`, `INACTIVITY_TIMEOUT`, and `FORCE_AUTHN` under `security_warning`; `CONF_ICON_URL` and `LOGIN_HEADER_TEXT` under `general_warning`).
2. Informational success notices starting with `✅` (such as synchronized timezone diagnostics in debug mode) must never trigger a tab warning badge (`⚠️`).
3. Every navigation tab anchor in `templates/configForm.html.twig` must have a unique DOM `id` matching its child pane's `aria-labelledby` attribute.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** render diagnostic tables (`validateProxyEnvironment()`, `validateTimezoneEnvironment()`) visibly inline beneath form inputs or remove `.samlsso-diagnostic-table-container { display: none !important; }`.
- **NEVER** disable Bootstrap's popover sanitizer (`sanitize: false`) or strip table HTML with `.text()` before passing diagnostic tables to `SamlSsoPopoverManager`.
- **NEVER** add promotional taglines, marketing descriptions of internal safeguards ("Just There Features"), or high-saturation "Christmas tree" styling to administrative screens.
- **NEVER** map a field in `ConfigForm::getTabWarnings()` to a tab other than the child template that renders it.
- **NEVER** emit inline `on*` event handler attributes, `javascript:` URLs, or raw PHP `<script>` tags in templates or PHP classes (violating GLPI's nonce-based Content Security Policy).

---

## Issue Triage & Maintainer Directives
- **When a user or contributor proposes showing diagnostic tables inline on the form or adding promotional status banners:**
  - Decline inline diagnostic clutter and keep detailed tables inside on-demand popovers via `SamlSsoPopoverManager` so the configuration form remains compact and scannable.

---

## Codebase & Automated Test Verification
- **Production Files**: `src/Config/ConfigForm.php`, `src/Config/ConfigItem.php`, `templates/configForm.html.twig`, `templates/configForm/*.html.twig`, `public/css/samlSSO.css`
- **Automated Tests**: `tests/ConfigIntegrityTest.php`, `tests/Glpi12CompatTest.php`, `tests/AdrComplianceTest.php`
