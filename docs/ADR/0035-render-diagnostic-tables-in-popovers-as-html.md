# ADR 0035: Render Diagnostic Tables in Popovers as HTML Instead of Inline on Form

Fix for issue: https://github.com/DonutsNL/samlsso/issues/187

## Status
Accepted

## Context
1. **Form Clutter vs. On-Demand Diagnostics**:
   In ADR 0034 / Issue #186, diagnostic tables (`validateProxyEnvironment()` on the Transit tab and `validateTimezoneEnvironment()` on the Security tab) were rendered inline directly beneath form controls. This permanently cluttered the form layout, whereas the intended UX is to display detailed diagnostics on-demand inside hover/click popovers attached to the field's indicator icon.
2. **Root Cause of Original Table Flattening**:
   The table had previously been lost from the popover due to three compounding factors:
   - `SamlSsoPopoverManager.init` configured `bootstrap.Popover` with `sanitize: true`. Bootstrap 5's default HTML sanitizer allowlist explicitly excludes `table`, `thead`, `tbody`, `tfoot`, `tr`, `th`, and `td`. Any table HTML passed to a popover was stripped by Bootstrap down to plain text.
   - Indicator icons were created with `data-bs-html="false"`.
   - Popover content was populated using jQuery `.text()`, stripping HTML markup before Bootstrap even processed it.

## Decisions
1. **HTML Popover Preservation with Sanitizer Allowlist (`SamlSsoPopoverManager.init`)**:
   - Configured `sanitize: true` in `SamlSsoPopoverManager.init` with an extended Bootstrap `allowList` that explicitly permits `table`, `thead`, `tbody`, `tr`, `th`, and `td` (with `class`, `style`, `scope`, `colspan`, `rowspan` attributes) so Bootstrap preserves plugin-generated diagnostic tables while continuing to strip any untrusted script/XSS vectors (see [ADR 0027](0027-security-audit-remediation.md)).
2. **Full HTML Table Transmission (`processNotificationHelper`)**:
   - For table elements (`isTable`), extract `$textEl.html().trim()` as popover content.
   - Configure trigger element with `data-bs-html="true"` and class `samlsso-popover-table`.
   - Handle GLPI native `_warning_icon` tooltip content divs by preserving `html()` if a table is present.
3. **Form Cleanliness**:
   - Always hide the raw inline container on the form (`$textEl.addClass('samlsso-diagnostic-table-container').hide()`).
   - Add `.glpisaml .samlsso-diagnostic-table-container { display: none !important; }` in `public/css/samlSSO.css` so diagnostic tables never appear inline on the form.
4. **Responsive Table Popover Styling (`public/css/samlSSO.css`)**:
   - Configured `.popover.samlsso-popover-table` and `.popover:has(table)` with `max-width: 560px !important`, scrollable bodies, and clear borders.
5. **Automated Verification (`tests/ConfigIntegrityTest.php`)**:
   - Extended `testDiagnosticTableIntegrityAndPopoverPreservation` to verify `sanitize: true` with the table `allowList`, HTML table passing, popover table CSS styling, and hidden inline containers.

## Consequences
- **Positive**:
  - The configuration form remains clean, functional, and minimalist without permanent inline table clutter.
  - Hovering or clicking the warning/info icon presents the full diagnostic table with status checkmarks/crosses and documentation links.
  - Tables cannot be mangled by Bootstrap sanitizers.
- **Negative**:
  - GLPI cache must be cleared (`php bin/console cache:clear`) for existing installations to load the updated Twig template and stylesheet.
