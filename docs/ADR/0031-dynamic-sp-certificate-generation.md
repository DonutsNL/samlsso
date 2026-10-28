# ADR 0031: Dynamic In-Place SP Certificate and Private Key Generation

Fix for issue: https://github.com/DonutsNL/samlsso/issues/183

## Status
Accepted

## Context
1. **Security Risk of Default Test Certificate**:
   The plugin historically bundles an example demo certificate and private key (`withlove.from.donuts.nl`) to assist initial configuration testing. In production environments, leaving this default demo certificate active offers zero cryptographic security, as the private key is publicly known and shared across all plugin installations.
2. **Administrator Burden**:
   Generating a compliant self-signed X.509 certificate and RSA-2048 private key manually via command-line OpenSSL requires multiple steps (creating CSRs, configuring SANs, exporting PEM blocks) and often causes administrators to procrastinate or omit replacing the demo certificate.
3. **In-Place Review Model**:
   When replacing the certificate, administrators need the ability to inspect the newly generated public certificate and private key in the configuration interface before committing the change to the database, ensuring no unexpected service disruption occurs.

## Decisions
1. **Cryptographic Generation Engine (`ConfigEntity::generateSelfSignedCertificate`)**:
   - Provide a static method `ConfigEntity::generateSelfSignedCertificate(?string $commonName = null, int $daysValid = 1095, int $keyBits = 2048): array|false`.
   - Utilizes PHP's native OpenSSL extension (`openssl_pkey_new`, `openssl_csr_new`, `openssl_csr_sign`, `openssl_x509_export`, `openssl_pkey_export`).
   - Automatically derives the certificate Common Name (CN) from GLPI's base URL host (`$CFG_GLPI['url_base']`), falling back to `glpi-saml-sp` if unavailable.
   - Signs with SHA-256 (`sha256WithRSAEncryption`) and 2048-bit RSA key length with a 3-year validity period (1095 days).
2. **Secure AJAX Endpoint (`ConfigForm::invokeForm`)**:
   - Add a dedicated handler for `action === 'generate_cert'`.
   - Requires HTTP POST method, rejecting GET requests with HTTP 405 Method Not Allowed.
   - Requires valid CSRF validation (`Session::validateCSRF`) with token preservation (`$preserve_token = true`) to prevent invalidating subsequent form submissions.
   - Enforces administrative update permissions (`Config::canUpdate() / Session::checkRight('config', UPDATE)`), returning HTTP 403 Forbidden for unauthorized requests.
   - Returns structured JSON `{ success: true, certificate: '...', private_key: '...', message: '...' }`.
3. **Contextual UI Banner and In-Place Population (`provider.html.twig`)**:
   - In `ConfigForm::showForm()`, detect whether the currently configured certificate is the test certificate (`withlove.from.donuts.nl`) and check if PHP OpenSSL is available.
   - Expose `is_test_certificate` and `openssl_available` variables to the Twig template.
   - When active, render a standard Tabler warning banner (`alert alert-warning`) with a `Generate Certificate & Key` button (`btn-warning btn-sm`).
   - On confirmation, issue an AJAX POST request, populate `textarea[name="sp_certificate"]`, automatically switch `select[name="sp_private_key_source"]` to `db`, populate `#sp_private_key_raw`, and transition the banner to a success alert (`alert alert-success`) prompting the administrator to review and click Save.
4. **Automated Verification**:
   - Comprehensive assertions added in `tests/ConfigIntegrityTest.php` covering OpenSSL certificate/key generation, modulus pair matching, template elements, and endpoint HTTP method guards.

## Consequences
- **Positive**:
  - Eliminates the security risk of administrators retaining the public demo certificate.
  - Streamlines certificate rotation to a single click directly inside the GLPI configuration UI.
  - In-place population allows full review and auditing prior to persistent database storage.
  - Fully compliant with GLPI's minimalist Tabler design and security standards.
- **Negative**:
  - Twig templates must be recompiled by clearing GLPI cache (`php bin/console cache:clear`) for existing installations to load the updated template markup.
