# ADR 0007: Strict SSO Configuration Validation during JIT User Creation

## Status
Accepted

## Context
During Just-In-Time (JIT) user creation, the plugin is responsible for dynamically provisioning a GLPI user account and mapping their profile and group rights using attributes received from the Identity Provider (IdP). To do this securely, the plugin must inspect the IdP's configuration (stored as a `ConfigEntity`) to:
1. Verify if JIT provisioning is enabled for this specific provider (`USER_JIT` config field).
2. Fetch the target default profiles or JIT rules associated with the provider.

Previously, `User::performJIT()` instantiated a new `Loginstate` using the current session ID to retrieve the active `idpId`. However:
* In standard SAML redirection flows (especially with `SameSite=Strict` cookies or cross-site POST requests), the browser's session ID can be transient or reset.
* If the login state look-up failed, the IDP ID defaulted to `0` or was missing.
* Instantiating `new ConfigEntity(0)` fell back to loading configuration defaults from the template `ConfigDefaultTpl.php`.
* Because the template defaults set `USER_JIT => true`, a user would be JIT-created and logged in, completely bypassing the actual IDP's configuration (which may have had JIT disabled).

This represents a classic fallback vulnerability where an inconsistent application state (e.g. failing to resolve the IDP ID) results in a "fail-open" scenario where security checks are skipped.

## Decision
To secure the user JIT creation process and eliminate potential bypass loopholes, we will apply the following strict design constraints:

1. **Explicit Config Propagation**: Do not guess or perform stateless look-ups for the active configuration inside the user-provisioning layer. The fully loaded and validated `ConfigEntity` must be explicitly passed down from the ACS controller (`Acs::init()` -> `performGlpiLogin()` -> `loadUser()` -> `getOrCreateUser()` -> `performJIT()`).
2. **Fail-Closed Policy**: If the configuration is missing, invalid, or disabled, the authentication flow must immediately terminate and present a fatal error.
3. **Database Origin Verification**: We must explicitly assert that the loaded configuration is sourced from the database and not a template fallback. This is done by checking that the configuration ID (`ConfigEntity::ID`) is defined and greater than zero.
4. **Validation Enforcements**: We must strictly check that:
   * The configuration is valid (`$configEntity->isValid()`).
   * The configuration is active (`$configEntity->isActive()`).
   * The configuration has JIT enabled (`$configEntity->getField(ConfigEntity::USER_JIT)`).
   If any of these assertions fail, the JIT process must trigger a fatal login error (`LoginFlow::PrintFatalLoginError(...)`) and abort.

## Consequences
- **Positive**:
  * Eliminates the template fallback vulnerability, ensuring that JIT checks cannot be bypassed.
  * Guarantees a "fail-closed" behavior where database failures or session ID mismatch errors result in a clean rejection rather than an unauthenticated login.
  * Improves performance by avoiding redundant database queries and object instantiations during JIT.
- **Negative**:
  * The method signatures for `loadUser`, `getOrCreateUser`, and `performJIT` must be modified to propagate the `ConfigEntity` object.
  * Unit tests must mock and supply the `ConfigEntity` object to avoid failures.
