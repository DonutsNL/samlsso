# ADR 0033: Align Configuration Form Tab Warnings with Template Field Placements

Fix for issue: https://github.com/DonutsNL/samlsso/issues/185

## Status
Accepted

## Context
1. **Misallocated Timeout Warnings**:
   In `ConfigForm::getTabWarnings()`, `ConfigEntity::REQUEST_TIMEOUT` and `ConfigEntity::INACTIVITY_TIMEOUT` were assigned to `$tabFields['general_warning']`. However, both fields are rendered exclusively within `templates/configForm/security.html.twig` under the **Security** tab. Consequently, when advisory warnings (such as PHP/Database timezone misalignment or session lifetime ceiling warnings) occurred, a warning badge (`⚠️`) was rendered on the **General** tab header instead of the **Security** tab header. Administrators inspecting the General tab were confronted with a warning badge with no erroneous fields visible on that tab.
2. **Missing Form Field Registrations**:
   `ConfigEntity::CONF_ICON_URL` and `ConfigEntity::LOGIN_HEADER_TEXT` (rendered in `general.html.twig`) and `ConfigEntity::FORCE_AUTHN` (rendered in `security.html.twig`) were omitted from `$tabFields`, preventing errors or warnings on these fields from surfacing in their respective tab headers.
3. **False Positive Warnings for Informational/Success Notices**:
   `getTabWarnings()` evaluated any non-empty `$fields[$field]['errors']` string as a warning (`⚠️`). When debug mode is active and clocks are synchronized, `validateTimezoneEnvironment()` outputs an HTML table with `✅ Timezone Alignment...`. Although purely an informational success confirmation, this non-empty string falsely triggered a tab warning badge.
4. **Duplicate Tab DOM ID**:
   In `templates/configForm.html.twig`, the navigation anchor for the Transit tab had duplicate `id="general-tab"`, causing a DOM ID collision and breaking the `aria-labelledby="transit-tab"` relationship defined in `transit.html.twig`.

## Decisions
1. **Reallocate Field Mappings in `ConfigForm::getTabWarnings()`**:
   - Moved `ConfigEntity::REQUEST_TIMEOUT` and `ConfigEntity::INACTIVITY_TIMEOUT` from `general_warning` to `security_warning`.
   - Added `ConfigEntity::CONF_ICON_URL` and `ConfigEntity::LOGIN_HEADER_TEXT` to `general_warning`.
   - Added `ConfigEntity::FORCE_AUTHN` to `security_warning`.
2. **Refine Warning Detection Logic**:
   - Explicitly check for `ConfigItem::INVALID` evaluation status.
   - Inspect error/notice strings for actual error/warning indicators (`⚠️`, `⭕`, `❌`) or non-empty error text that does not represent a green success notice (`✅`).
   - Retain X.509 certificate expiry/validity checks (`validTo`, `validFrom`).
3. **Correct Transit Tab Anchor ID**:
   - Updated `id="general-tab"` on the Transit tab anchor in `templates/configForm.html.twig` to `id="transit-tab"`.
4. **Automated Verification**:
   - Added `testTabWarningsFieldAlignment()` in `tests/ConfigIntegrityTest.php` covering field allocation alignment, timezone warning isolation to the Security tab, informational notice false-positive prevention, and template anchor ID uniqueness.

## Consequences
- **Positive**:
  - Tab warning badges accurately reflect the tab on which errors or advisory warnings reside.
  - Eliminates phantom warnings on the General tab.
  - Preserves accessibility and valid HTML DOM semantics across all configuration tabs.
- **Negative**:
  - Twig template cache must be refreshed (`php bin/console cache:clear`) for existing sessions to reload `configForm.html.twig`.
