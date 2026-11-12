# ADR 0003: Cryptography, Certificate Hygiene & Secret Management

## Status
Accepted — Enforced Architectural Invariant

## Core Principle (Non-Negotiable)
Cryptographic verification of inbound SAML responses (`strict => true` and cryptographic signatures on messages or assertions), industry-standard key floors (RSA >= 2048 bits, NIST P-256/P-384/P-521, SHA-256+), PEM line-ending sanitization, and secret masking/encryption (`GLPIKey`) are **enforced unconditionally on the server** and may never be bypassed via configuration toggles or UI inputs.

---

## Architectural Invariants

### 1. Mandatory Runtime Strict Mode & Signature Verification
1. `Acs::setupSamlResponse()` unconditionally enforces `$phpSamlConfig['strict'] = true` at runtime during ACS evaluation.
2. At least one inbound signature requirement (`security['wantMessagesSigned']` or `security['wantAssertionsSigned']`) must be active (`true`); if both are stored as `false` in the database, `Acs::setupSamlResponse()` forces `$phpSamlConfig['security']['wantAssertionsSigned'] = true` so unsigned assertions are never accepted.
3. In `ConfigItem::validateInboundSecurity()`, if an administrator disables `strict` or disables both inbound signature flags in the UI, warning indicators (`⚠️`) are surfaced on `strict`, `security_want_messages_signed`, and `security_want_assertions_signed` to inform the administrator of the security requirement.

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
- **NEVER** allow `Acs::setupSamlResponse()` to evaluate a `SAMLResponse` with `strict => false` or with both `wantMessagesSigned` and `wantAssertionsSigned` set to `false`.
- **NEVER** store plaintext private keys in `glpi_plugin_samlsso_configs`, render a stored database private key into HTML, or include `sp_private_key` in backups, JSON exports, or error logs.
- **NEVER** permit RSA keys `< 2048` bits, weak curves, or SHA-1/MD5 digests in certificate generation or defaults.
- **NEVER** enable `LIBXML_NOENT`, `LIBXML_DTDLOAD`, or `LIBXML_DTDATTR` in any XML parsing call.

---

## Issue Triage & Maintainer Directives
- **When a user asks to disable signature validation or strict mode to work around an IdP error:**
  - Explain that disabling signature verification or strict mode exposes GLPI to trivial XML assertion forgery and account takeover. Guide them through fixing the underlying IdP EntityID, ACS URL, or X.509 certificate formatting mismatch using the configuration form's inline validation warnings.

---

## Codebase & Automated Test Verification
- **Production Files**: `src/LoginFlow/Acs.php`, `src/Config/ConfigEntity.php`, `src/Config/ConfigItem.php`, `src/Config/ConfigForm.php`, `src/Config/ConfigDefaultTpl.php`, `templates/configForm/provider.html.twig`
- **Automated Tests**: `tests/AcsTest.php`, `tests/ConfigIntegrityTest.php`, `tests/CertValidationTest.php`, `tests/AdrComplianceTest.php`

