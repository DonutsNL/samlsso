# ADR 0003: Cryptography, Certificate Hygiene & Secret Management

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
The plugin honors the configuration capabilities of `onelogin/php-saml` (`strict` and `security_*` flags) as configured by the administrator via `ConfigEntity::getPhpSamlConfig()`, shipping with secure defaults (`strict => true`, `security_wantassertionssigned => true`) and surfacing clear UI risk warnings whenever `strict` mode or certificate requirements are disabled or unmet. At the same time, modern key floors for generated certificates (RSA >= 2048 bits, NIST P-256/P-384/P-521, SHA-256+), PEM line-ending sanitization, and secret masking/encryption (`GLPIKey`) are enforced on the server, and disabling `strict` in `php-saml` **never** disables the plugin's own Solicited-Only State Machine ([ADR 0001](0001-security-by-design-and-state-machine.md)).

---

## Architectural Invariants

### 1. `php-saml` Strict Mode Configuration & Risk Warnings
1. `Acs::setupSamlResponse()` passes `$this->configEntity->getPhpSamlConfig()` directly to `OneLogin\Saml2\Settings` without silently overriding `strict` or `security_*` flags at runtime, respecting the configuration capabilities offered by `php-saml`.
2. Because `onelogin/php-saml` only enforces inbound security flags (`security_wantmessagessigned`, `security_wantassertionssigned`, `security_wantassertionsencrypted`, `security_wantnameid`) when `strict` is enabled, `ConfigEntity::validateAdvancedConfig()` automatically toggles those four inbound fields to `false` when `strict` is disabled and attaches the warning: `⚠️ These fields are only enforced when strict mode is enabled. Your environment is at risk without strict mode.`
3. Similarly, if the Service Provider certificate or private key is missing or invalid (`$disable = true` in `validateAdvancedConfig()`), SP-outbound signing/encryption options (`security_nameidencrypted`, `security_authnrequestssigned`, `security_logoutrequestsigned`, `security_logoutresponsesigned`) are automatically toggled to `false` with an inline warning (`⚠️ Will be defaulted to "No" because the provided SP certificate does not look valid!`).
4. Disabling `strict` in `php-saml` only relaxes `OneLogin\Saml2\Response` strict checks; it **never** bypasses `samlsso`'s database state machine, `InResponseTo` / `SubjectConfirmationData` binding, or the Double-Pass Solicited Flow ([ADR 0001](0001-security-by-design-and-state-machine.md)).

### 2. PEM Certificate Sanitization & XML C14N Compatibility
1. XML Canonicalization (C14N) normalizes `\r\n` to `\n` during XML Signature verification, causing signatures computed over certificates containing carriage returns (`\r`) to fail unpredictably across Identity Providers.
2. `ConfigItem` validators for `sp_certificate`, `sp_private_key`, and `idp_certificate` strictly reject any PEM string containing `\r` characters and require clean Unix line endings (`\n`) or single-line base64 payloads.
3. `ConfigDefaultTpl.php` ships with empty `SP_CERTIFICATE`, `SP_KEY`, and `IDP_CERTIFICATE` values, `STRICT => true`, and `SECURITY_WANTASSERTIONSSIGNED => true`.

### 3. SP Private Key Sources, Encryption at Rest & Secret Masking
1. Service Provider private keys support four storage sources via `sp_private_key_source`:
   - `db`: Encrypted at rest using GLPI's core `GLPIKey` (`(new \GLPIKey())->encrypt()` / `decrypt()`) across all write and migration paths (`ConfigEntity`, `Config`, `ConfigForm`, `Config::install()`).
   - `file`: Single-line path outside the web document root.
   - `env`: Server environment variable name.
   - `define`: PHP constant name.
2. In `provider.html.twig`, selecting `file`, `env`, or `define` dynamically switches the input from the multi-line PEM textarea to a contextual single-line input and disables/hides the unused control.
3. When `sp_private_key_source === 'db'`, the stored private key is blanked before rendering the form (`ConfigForm::generateForm()`), listed in `getUndisclosedFields()`, excluded from `rawSearchOptions()`, stripped from JSON exports and configuration backup tables, and preserved in the database when the form is saved with an empty key placeholder.
4. Error logs and diagnostic traces (`LoginFlow`, `Acs`, `ClaimMapEntity`) redact `sp['privateKey']`, never dump raw `$_SERVER` arrays (using a strict 5-header allowlist: `REQUEST_METHOD`, `REMOTE_ADDR`, `REQUEST_URI`, `HTTP_HOST`, `HTTP_USER_AGENT`), and never pass `LIBXML_NOENT` or `LIBXML_DTDLOAD` to XML parsers (`LIBXML_NONET | LIBXML_NOCDATA` only).

### 4. Configurable SP Self-Signed Certificate Generator & Cryptographic Floors
1. `ConfigEntity::generateSelfSignedCertificate()` and `ConfigForm::invokeForm()` (`action=generate_cert`) allow administrators to generate X.509 keypairs in-place via `#samlsso-cert-generator-modal` (`provider.html.twig`) for review prior to saving.
2. Server-side validation strictly enforces NIST SP 800-57 / CA-B Forum floors:
   - RSA keys `< 2048` bits are rejected (`return false` / HTTP 400). Supported RSA sizes: `2048`, `3072`, `4096`.
   - ECDSA curves are restricted to `prime256v1` (P-256), `secp384r1` (P-384), and `secp521r1` (P-521).
   - Digest algorithms are restricted to `sha256`, `sha384`, and `sha512` (MD5 and SHA-1 are forbidden).
   - Validity duration is clamped between `30` and `3650` days.

---

## Prohibited Patterns (Do Not Circumvent)
- **NEVER** hardcode overrides in `Acs::setupSamlResponse()` (such as forcing `$phpSamlConfig['strict'] = true`) that silently ignore the administrator's saved `ConfigEntity::getPhpSamlConfig()` settings.
- **NEVER** allow disabling `strict` mode to bypass the Solicited-Only State Machine or permit direct unsolicited authentication ([ADR 0001](0001-security-by-design-and-state-machine.md)).
- **NEVER** store plaintext private keys in `glpi_plugin_samlsso_configs`, render a stored database private key into HTML, or include `sp_private_key` in backups, JSON exports, or error logs.
- **NEVER** permit RSA keys `< 2048` bits, weak curves, or SHA-1/MD5 digests in certificate generation or defaults.
- **NEVER** enable `LIBXML_NOENT`, `LIBXML_DTDLOAD`, or `LIBXML_DTDATTR` in any XML parsing call.

---

## Issue Triage & Maintainer Directives
- **When a user disables `strict` mode and asks why `Require Signed Assertions` or `Require Signed Messages` automatically toggles off:**
  - Explain that in the underlying `onelogin/php-saml` library, those inbound security checks are only evaluated when `strict` mode is active. The plugin reflects this accurately in the UI and displays a warning (`⚠️`) so the administrator is never misled into thinking inbound checks are active while `strict` is off.

---

## Codebase & Automated Test Verification
- **Production Files**: `src/LoginFlow/Acs.php`, `src/Config/ConfigEntity.php`, `src/Config/ConfigItem.php`, `src/Config/ConfigForm.php`, `src/Config/ConfigDefaultTpl.php`, `templates/configForm/provider.html.twig`
- **Automated Tests**: `tests/AcsTest.php`, `tests/ConfigIntegrityTest.php`, `tests/CertValidationTest.php`, `tests/AdrComplianceTest.php`

