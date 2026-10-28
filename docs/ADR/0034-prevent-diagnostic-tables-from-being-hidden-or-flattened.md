# ADR 0034: Prevent Diagnostic Tables from Being Hidden or Flattened into Popovers

Fix for issue: https://github.com/DonutsNL/samlsso/issues/186

## Status
Accepted

## Context
1. **Loss of Diagnostic Tables**:
   In `templates/configForm.html.twig`, a blanket jQuery notification scanner introduced in ADR 0021 transformed all elements matching `.form-text` with error or warning styles into label popover tooltips.
   Certain configuration settings—notably **"REQUESTS PROXIED"** (`validateProxyEnvironment()`) on the Transit tab and **"REQUEST TIMEOUT" / "INACTIVITY TIMEOUT"** (`validateTimezoneEnvironment()`) on the Security tab—generate rich HTML tables containing structured parameters, expected values, detected values, status checkmarks/crosses, and MDN reference links.
2. **Tag Stripping & Information Flattening**:
   The scanner called `$warnText.text().trim()` on elements containing `<table>`, stripping all tags (`<table>`, `<tr>`, `<td>`, `<a>`, `<br>`) and flattening table headers and rows into an unformatted plain-text blob without spaces or column delimiters.
3. **Table Hiding**:
   The scanner called `$warnText.hide()`, completely removing the actual structured table from the DOM and leaving administrators with only a mangled text string in a tiny hover popover.

## Decisions
1. **Defensive Processing Function (`processNotificationHelper`)**:
   - Centralized all notification styling in `templates/configForm.html.twig` into a single helper function `processNotificationHelper($textEl, type)`.
   - Explicitly detect table presence (`$textEl.find('table').length > 0`).
   - If a table is present:
     - **Preserve Table Visibility**: Never call `.hide()`; assign `samlsso-diagnostic-table-container` with `display: block; width: 100%; flex: 1 1 100%;` so the diagnostic table is rendered in full width directly underneath row inputs.
     - **Extract Concise Summary for Popover**: Rather than flattening the entire table into unreadable text, extract the accompanying summary message (`.text-danger`, `.text-warning`, `.text-success`, or `.text-muted`) for the label icon popover.
   - If a table is not present (standard single-line text helper):
     - Retain standard behavior: display text in label popover and hide redundant inline element.
2. **CSS Layout Hardening (`public/css/samlSSO.css`)**:
   - Added `flex-wrap: wrap !important` to form-field row containers to allow full-width table containers to cleanly wrap beneath switches/inputs.
   - Added `.glpisaml .samlsso-diagnostic-table-container` rules ensuring responsive horizontal scrolling and proper white background.
3. **Automated Regression Prevention (`tests/ConfigIntegrityTest.php`)**:
   - Added `testDiagnosticTableIntegrityAndPopoverPreservation()` asserting:
     - The presence of `processNotificationHelper` and table guards in `templates/configForm.html.twig`.
     - That elements containing tables are guarded against `.hide()`.
     - That `public/css/samlSSO.css` enforces `flex-wrap: wrap` and `.samlsso-diagnostic-table-container`.
     - That `validateProxyEnvironment` and `validateTimezoneEnvironment` output valid HTML tables with standard columns and alert summary elements.

## Consequences
- **Positive**:
  - Diagnostic tables are permanently preserved and rendered cleanly inline on the configuration form.
  - Hover popovers provide a clean, concise single-sentence summary rather than flattened table text.
  - Architectural and automated test guards prevent future UI refactoring from re-introducing this regression.
- **Negative**:
  - GLPI cache must be cleared (`php bin/console cache:clear`) for existing installations to load the updated Twig template and stylesheet.
