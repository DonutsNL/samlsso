# ADR 0032: Configurable Modal Dialog for SP Certificate and Key Generation

Fix for issue: https://github.com/DonutsNL/samlsso/issues/184

## Status
Accepted

## Context
1. **Administrative Customization Requirements**:
   While ADR 0031 introduced automated in-place generation of self-signed X.509 SP certificates and private keys, it used hardcoded parameters (GLPI host CN, 3-year validity, RSA-2048, SHA-256). In diverse enterprise deployments, administrators require direct control over:
   - Subject Common Name (CN) for custom domain aliases or specific naming standards.
   - Certificate validity periods (e.g., 1, 2, 3, 5, or 10 years).
   - Cryptographic algorithm type (RSA vs. ECDSA).
   - Cryptographic key lengths and elliptic curves.
   - Signature digest algorithms (SHA-256, SHA-384, SHA-512).
2. **Community Security Standards Enforcement**:
   Self-signed certificate generation must never permit weak or obsolete cryptographic parameters that fail to meet industry and community-accepted baselines (NIST SP 800-57, BSI TR-02102-1, CA/B Forum):
   - RSA key sizes must never fall below 2048 bits. Sub-standard sizes (such as 512 or 1024 bits) must be rejected both in the UI and on the server.
   - Elliptic curves must meet modern security standards (minimum 256 bits, NIST P-256 / prime256v1, P-384, P-521).
   - Legacy hash algorithms (MD5, SHA-1) must be strictly excluded.

## Decisions
1. **Interactive Bootstrap / Tabler Modal (`provider.html.twig`)**:
   - Implemented `#samlsso-cert-generator-modal` conforming strictly to GLPI's native Tabler design language.
   - Replaced immediate execution with an interactive dialog allowing administrators to configure:
     - Common Name (CN): pre-populated with GLPI host domain.
     - Cryptotype: RSA (SAML 2.0 standard) and ECDSA (Elliptic Curve).
     - Key Length / Curve: dynamic UI switching presenting RSA bit lengths (2048, 3072, 4096 bits) or NIST curves (P-256, P-384, P-521).
     - Validity Duration: 1, 2, 3, 5, or 10 years (defaulting to 3 years / 1095 days).
     - Signature Digest: SHA-256, SHA-384, or SHA-512.
   - Accessible via the default test certificate warning banner and via a header button (`Generate Keypair`) for subsequent key rotations.
2. **Server-Side Security Bounds & Validation (`ConfigEntity::generateSelfSignedCertificate`)**:
   - Added parameters `$commonName`, `$daysValid`, `$keyType`, `$keyBits`, `$curveName`, and `$digestAlg`.
   - Strictly enforces community cryptographic floors:
     - Rejects any RSA key length `< 2048` bits, returning `false`.
     - Restricts EC curves to approved NIST curves (`prime256v1`, `secp384r1`, `secp521r1`).
     - Restricts digest algorithms to `['sha256', 'sha384', 'sha512']`.
     - Clamps validity period between 30 and 3650 days.
3. **Endpoint Validation & In-Place Workflow (`ConfigForm::invokeForm`)**:
   - Added parameter parsing and server-side verification to `action=generate_cert`.
   - Validates that RSA keys meet or exceed 2048 bits, returning HTTP 400 Bad Request on violation.
   - Preserves the Option A workflow: populates `textarea[name="sp_certificate"]`, switches key source to `db`, and fills `#sp_private_key_raw` for administrative review before committing changes to the database.
4. **Automated Verification**:
   - Expanded `testGenerateCertMethodAndEndpoint()` in `tests/ConfigIntegrityTest.php` covering RSA (2048/3072), ECDSA (P-256), weak key rejection (< 2048 bits), modal markup controls, and endpoint checks.

## Consequences
- **Positive**:
  - Full administrator control over certificate parameters with zero compromise on cryptographic baselines.
  - Seamless in-place review flow preventing unintended service disruption.
  - Consistent and clean Tabler user experience without marketing bloat.
- **Negative**:
  - GLPI cache must be cleared (`php bin/console cache:clear`) for existing installations to load the updated Twig template.
