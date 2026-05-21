# Security Policy

## Core Security Architecture

The `samlsso` plugin is designed with a **Security-by-Design** approach, moving beyond standard stateless SAML implementations. We use a database-backed state machine to ensure the integrity of every authentication attempt.

### 1. State-Machine Enforcement (Phase Control)
We track the lifecycle of a login through 4 primary phases:
- **Phase 1 (Initial)**: New visitor.
- **Phase 2 (SAML_ACS)**: Redirected to IdP, waiting for a response.
- **Phase 3 (SAML_AUTH)**: Response received, validating signature.
- **Phase 4 (GLPI_AUTH)**: Handing over to GLPI core.

**Why this matters:** This prevents "Unsolicited Assertions." An attacker cannot simply POST a SAML response to the ACS endpoint; it will be rejected unless a matching Request ID is currently in "Phase 2" in our database.

### 2. Replay Protection
Every `SAMLResponse` contains a unique ID. We store this ID in the `samlsso_loginstates` table. 
- If the same ID is presented twice, it is immediately blocked.
- This prevents "man-in-the-middle" or "browser-history" replay attacks.

### 3. XML Security & Signature Validation
We utilize the industry-standard `onelogin/php-saml` library to handle:
- **Signature Verification**: Ensuring the assertion was signed by your trusted IdP.
- **XXE Protection**: Preventing XML External Entity attacks during parsing.
- **Message Integrity**: Validating that the XML has not been tampered with.

---

## Actively Prevented "Hacks"

The plugin is hardened against the following common SAML vulnerabilities:

| Attack Type | Prevention Method |
| :--- | :--- |
| **Unsolicited Response** | State machine rejects any assertion without a corresponding `InResponseTo` ID in the database. |
| **Replay Attack** | Database check for unique `SAML_RESPONSE_ID`. |
| **XML Signature Wrapping** | Strict validation of XML structure via the OneLogin library. |
| **Race Conditions** | Database-level phase locks prevent parallel processing of the same authentication session. |
| **Open Redirect** | (Coming soon) Validation of the `redirect` parameter to ensure it is a local path. |

---

## How to Advance Your Security (Harden your Setup)

To achieve the highest level of security, we recommend the following configurations in your Identity Provider (IdP) and Plugin settings:

1.  **Enforce Signed Assertions**: Don't just sign the SAML Response; ensure the **Assertion** inside is also signed.
2.  **Use SHA-256 or Higher**: Ensure your IdP is configured to use at least RSA-SHA256 for signatures.
3.  **Strict NameID Matching**: Ensure the `NameID` provided by your IdP exactly matches a unique, immutable attribute in GLPI (like a UUID or Email).
4.  **Short Timeouts**: Set the `Clock Drift` and `Session Timeout` in your IdP to the minimum viable values (e.g., 2-3 minutes).
5.  **Disable Debug in Production**: Debug mode can leak technical state information in error screens. Keep it off unless troubleshooting.

## Reporting a Vulnerability

If you discover a security flaw, please do not open a public issue. 
PM vulnerabilities directly to the owner of this repository: **DonutsNL** 
We aim to acknowledge reports within 48 hours and provide a fix or mitigation plan as soon as possible.
