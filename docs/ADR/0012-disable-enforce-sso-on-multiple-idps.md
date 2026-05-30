# ADR 0012: Disable Enforce SSO on Multiple Active IDPs

## Status
Accepted

## Context
In GLPI plugins, it is common to display transient messages via the session queue (`Session::addMessageAfterRedirect()`) after a database record has been saved. 

However, in this plugin, we do not follow the standard GLPI behavior of utilizing session-redirect warnings for entity-level configuration conflicts. Instead, configuration validation feedback is surfaced inline directly within the configuration form. This ensures that the user is immediately aware of validation errors/warnings and can correct them without navigating away, and that the warnings do not disappear unexpectedly upon a subsequent redirect.

When multiple active SAML Identity Providers (IDPs) are configured and enabled (`is_active = 1`, `is_deleted = 0`), the single-sign-on (SSO) auto-redirect option (`enforce_sso`) cannot be active because it is impossible to auto-redirect the browser to multiple target providers simultaneously.

## Decision
We decided to enforce this rule within the entity validation layer (`ConfigEntity::validateAdvancedConfig()`). 

If more than one active configuration exists in the database, and the IDP configuration being loaded/saved has `enforce_sso` enabled, we:
1. Automatically set `enforce_sso` to `0` inside the fields data structure.
2. Set the `ConfigItem::ERRORS` field of the `enforce_sso` item to a translatable warning message: `⚠️ IDPs cannot be enforced if more than one is present in the IDP list.`

This corrective structure ensures that:
- The form displays the warning inline next to the "Enforce SSO" field.
- When the configuration is saved to the database (via `ConfigForm` invoking `getDBFields()`), the corrected `enforce_sso = 0` value is persisted automatically.

## Consequences
- **Positive**:
  - Validates and corrects configuration conflicts before they are saved to the database.
  - Keeps UX consistent by showing conflict feedback inline inside the form.
  - Avoids transient redirect-based session messages that can easily be missed or cleared.
- **Negative**:
  - Developers must remember to use the entity-level `validateAdvancedConfig()` validation layer for conflict resolutions rather than hooking into `CommonDBTM` save/redirect cycles.
