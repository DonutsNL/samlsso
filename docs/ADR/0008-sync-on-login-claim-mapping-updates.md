# ADR 0008: Sync Mapped Claim Fields and Rerun Rules Engine on Login

## Status
Accepted

## Context
When users authenticate via SAML SSO, the Just-In-Time (JIT) provisioning mechanism is triggered only upon their first successful login to create the account and map their initial profiles/groups. However, attributes (such as email, display name, department, or group memberships) frequently change in the Identity Provider (IdP) over time. 

Previously, there was no option to keep existing GLPI users synchronized with their SAML attributes on subsequent logins, which resulted in stale user properties and outdated access control permissions unless manually corrected.

## Decision
To support dynamic and automated attribute updates, we decided to introduce a "Sync on Login" option:

1. **Database Schema Extension**: Added a `sync_on_login` tinyint column to the configuration table (`glpi_plugin_samlsso_configs`) to persist the setting per Identity Provider.
2. **Config Model & Form Integration**:
   - Added constant `ConfigEntity::SYNC_ON_LOGIN` and corresponding boolean validators in `ConfigItem`.
   - Exposed the configuration option as a togglable slider in the **Security** tab of the config form (`templates/configForm.html.twig`).
   - Integrated the field into the config backup, restore, and template default definitions to ensure backup/restore integrity.
3. **Provisioning Logic Update (`src/LoginFlow/User.php`)**:
   - Refactored the rules engine invocation into a helper method `runRulesEngine()`.
   - If a user already exists and `sync_on_login` is active for the IDP, the login flow updates their mapped user attributes from the SAML response and reruns the rules engine to synchronize profile/group memberships on every successful authentication.

## Consequences
- **Positive**:
  - Ensures user data and permissions in GLPI are always synchronized with the Identity Provider state.
  - Automates group membership updates (via the rules engine) based on the latest SAML claims.
- **Negative**:
  - Adds a database migration path and requires plugin reinstall or upgrade to apply the database schema.
  - Adds minor processing overhead during authentication for existing users, as claims are parsed and the rules engine is executed.
