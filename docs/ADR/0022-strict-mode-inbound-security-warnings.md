# ADR 0022: Auto-Disable Inbound Security when Strict Mode is Disabled

## Status
Accepted (Amended by [ADR 0027](0027-security-audit-remediation.md) — runtime ACS evaluation in `Acs::setupSamlResponse()` always enforces `strict => true` and requires signed assertions or messages regardless of stored database flags)

## Context
When SAML strict mode is disabled, the underlying php-saml toolkit does not validate or enforce cryptography assertions or signatures on inbound SAML responses, even if settings such as "Require Signed Messages" are toggled to true in the UI. Under this scenario, users can get a false sense of security thinking these policies are being enforced.

Therefore, when strict mode is disabled, the corresponding inbound security elements (`security_wantmessagessigned`, `security_wantassertionssigned`, `security_wantassertionsencrypted`, and `security_wantnameid`) must be automatically disabled (forced to false).

## Decision
We choose to implement the auto-disable mechanism using the existing config entity validation logic.

### Design Details
Inside `ConfigEntity::validateAdvancedConfig()`, if the evaluated value of `strict` is false, we force the values of the four inbound security fields to `false`/`0` and assign a translatable warning message starting with `⚠️` to their `errors` field.

## Consequences

* **Positive**:
  * Prevents configuration misunderstandings by visually disabling non-enforced settings.
  * Preserves existing `ConfigItem` structure and maintains full backward compatibility with mapping formats and unit tests.
* **Negative**:
  * The fields are forced to false programmatically during verification, overriding any database values.
