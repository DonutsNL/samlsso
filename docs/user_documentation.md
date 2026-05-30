# SAML SSO Plugin for GLPI - User & Administrator Documentation

This document provides a comprehensive guide for installing, configuring, and troubleshooting the SAML SSO plugin for GLPI.

---

## 1. Overview
The SAML SSO plugin allows GLPI to delegate authentication to external SAML 2.0 Identity Providers (IdPs) such as Microsoft Entra ID (Azure AD), Okta, Keycloak, or Ping Identity. It supports:
* Just-In-Time (JIT) user provisioning.
* Dynamic claim mapping (profile and group assignments).
* Automated attribute updates upon subsequent logins ("Sync on Login").
* Strict cryptographic signature verification and response assertions.

---

## 2. Installation & Upgrades

### Fresh Installation
1. Extract the plugin ZIP into your GLPI `plugins/samlsso` directory.
2. Navigate to **Setup > Plugins** in GLPI.
3. Click **Install**, then click **Activate**.

### Upgrading the Plugin
When upgrading the plugin:
1. Replace the plugin files under `plugins/samlsso`.
2. Navigate to **Setup > Plugins** and click **Upgrade**.
3. Clear the Twig cache via the GLPI CLI to ensure the UI updates render properly:
   ```bash
   php bin/console cache:clear
   ```

---

## 3. Configuration Form Tabs

### General Tab
* **Name**: Friendly name for this IdP configuration.
* **Userdomain**: If populated (e.g., `company.com`), the login page will hide the direct SSO button. Instead, users trigger SSO by typing their username/email format (`user@company.com`) into the main GLPI login form, and the password field will be bypassed.
* **Active**: Enable or disable authentication through this IdP.
* **Debug Mode**: Logs raw SAML transactions and assertion errors into GLPI logs. **Must be enabled** to view XML payloads or ACS traces in the Logging tab.

### Transit Tab
* **Compress Requests**: Compresses outbound SAML AuthN requests.
* **Compress Responses**: Expects compressed inbound responses.
* **Proxied**: Enable if GLPI is behind a reverse proxy (interprets `X-Forwarded-Proto` and `X-Forwarded-For`).
* **XML Validation**: Validates incoming XML structures against the SAML 2.0 schema.
* **Validate Destination**: Relaxes destination checks (useful if the external URL mismatches the internal proxy URL).
* **Lowercase URL Encoding**: Enforces lowercase URL encoding for compatibility.

### Service Provider (SP) Tab
This tab displays target URLs to register in your IdP and configures the SP keys:
* **Entity ID**: The identifier for GLPI in the IdP (typically `https://your-glpi-url/`).
* **ACS URL**: The callback endpoint (Assertion Consumer Service) where the IdP sends assertions.
* **Metadata URL**: Exposes GLPI's public SP configuration (requires Debug Mode active).
* **SP Certificate / Private Key**: Used to sign requests and decrypt name IDs. Generate a standard self-signed certificate pair for GLPI.
* **SP NameID Format**: Format requested for the user identifier (e.g., `Email Address`, `Persistent`, `Transient`).

### Identity Provider (IdP) Tab
Configures the connection to your IdP:
* **IdP Entity ID**: Found in the IdP metadata.
* **Single Sign-On URL**: The login URL where users are redirected.
* **Single Logout URL**: The logout URL.
* **IdP Certificate**: The public certificate of the IdP (used to verify SAML response signatures).
* **Requested AuthN Context**: Enforce specific authentication context classes (e.g., `PasswordProtectedTransport`).

### Security Tab
Controls the security settings of the SSO flow:
* **Enforced**: Bypasses the local login screen completely and redirects directly to SSO.
* **Strict**: Validates signatures, expiration times, issuers, and certificates strictly.
* **JIT User Creation**: Creates new users dynamically in GLPI when they authenticate via SSO.
* **Sync on Login**: Updates existing GLPI users' mapped fields and reruns the GLPI rules engine on every login using incoming SAML assertions.
* **Encrypt NameID**: Instructs the IdP to encrypt the NameID identifier.
* **Sign AuthN Request**: Signs outbound login requests.
* **Sign Logout Request / Response**: Signs logout requests and responses.
* **Require Signed Messages**: Instructs GLPI to reject any incoming SAML response messages from the IdP that are not cryptographically signed.
* **Require Signed Assertions**: Instructs GLPI to reject any individual SAML assertions inside the message payload that are not signed.
* **Require Encrypted Assertions**: Tells GLPI to expect assertions to be encrypted (GLPI decrypts them using the Service Provider's private key).
* **Sign SP Metadata**: Instructs GLPI to sign the generated Service Provider metadata XML payload using the SP's certificate.
* **Require NameID**: Instructs GLPI to strictly require the presence of the NameID attribute in SAML assertions.

### Claim Mapping Tab
Maps attributes in the incoming SAML assertion to GLPI user properties:
* **Target Type**:
  - `User Field`: Directly updates user profile properties (e.g., Email, First Name, Last Name).
  - `Rule Field`: Maps attributes to fields evaluated by the GLPI Rules Engine to dynamically assign profiles, entities, or groups.
* **SAML Response Claim Key**: The exact claim attribute name from the IdP (e.g., `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` or `groups`).

### Logging Tab
Displays a history of authentication attempts:
* Tracks session IDs, usernames, authentication phases, outcomes (success/failure), and traces.
* **Note**: Logging entries require Debug Mode to capture response details.

---

## 4. Dangerous Configuration Options

> [!WARNING]
> Disabling security protections can expose your GLPI instance to critical vulnerabilities (e.g., account takeover, credential harvesting).

* **Disabling Strict Mode (`Strict = No`)**:
  * **Risk**: Allows unsigned assertions, expired sessions, and unvalidated issuers. Attackers can forge assertions to log in as any user.
  * **Mitigation**: **Always keep Strict enabled in production.**
* **Disabling Destination Validation (`Validate Destination = No`)**:
  * **Risk**: Disables cross-checks on the assertion recipient.
  * **Mitigation**: Only disable when debugging proxy rewriting configurations.
* **Permissive JIT Creation (`JIT User Creation = Yes` with no Rules)**:
  * **Risk**: Anyone who can authenticate against the IdP can gain access to GLPI.
  * **Mitigation**: Use JIT Rule mappings to restrict access based on IdP groups or specific attributes.

---

## 5. Major Identity Provider Scenarios

### Microsoft Entra ID (Azure AD)
1. **App Registration**: Create an Enterprise Application, choosing **SAML-based Sign-on**.
2. **Identifier (Entity ID)**: Copy from GLPI **SP Tab** (e.g., `https://your-glpi/`).
3. **Reply URL (ACS URL)**: Copy from GLPI **SP Tab** (e.g., `https://your-glpi/plugins/samlsso/front/acs.php/1`).
4. **Attributes & Claims**:
   - Unique User Identifier: `user.userprincipalname`.
   - Add a Group Claim if dynamic profile assignment is desired.
5. **Entra ID Documentation**: Refer to [Microsoft Entra SAML documentation](https://learn.microsoft.com/en-us/entra/identity-platform/active-directory-saml-claims-customization).

### Okta
1. **Create App Integration**: Choose **SAML 2.0**.
2. **Single Sign-On URL**: Enter the GLPI ACS URL.
3. **Audience URI (SP Entity ID)**: Enter the GLPI SP Entity ID.
4. **Attribute Statements**: Map `email` to `user.email`, `firstName` to `user.firstName`, and `lastName` to `user.lastName`.
5. **Group Attribute Statements**: Add a group claim (e.g., name `groups`, filter matches regex `.*`).
6. **Okta Documentation**: Refer to [Okta SAML application guide](https://developer.okta.com/docs/concepts/saml/).

### Keycloak
1. **Create Client**: Choose Client Protocol **saml**.
2. **Client ID**: Enter the GLPI SP Entity ID.
3. **Master SAML Processing URL**: Enter the GLPI ACS URL.
4. **Fine-Grained SAML Endpoint Configuration**:
   - Ensure assertions are signed (**Sign Assertions = ON**).
5. **Keycloak Documentation**: Refer to [Keycloak SAML client guide](https://www.keycloak.org/docs/latest/server_admin/#_saml).

---

## 6. Troubleshooting & Common Error Conditions

| Error / Message | Cause | Resolution |
| --- | --- | --- |
| `SAML Response replay protection` | The same assertion token was sent twice. | Ensure the browser is not submitting form data twice. |
| `SP private key does not match certificate modulus` | The configured SP key pair is mismatched. | Generate a matching X509 certificate and private key. |
| `This claim key has not been observed yet` | Claim Mapping contains keys not present in SAML. | Perform a successful login to capture claims, check spelling. |
| `Strict is disabled, validate xml setting will do nothing` | XML validation depends on Strict mode. | Enable Strict mode under the Security tab. |
| `Invalid XML response` | Malformed XML payload returned from IdP. | Verify IdP single-sign-on URL and certificate formatting. |
| `Destination validation failed` | Reverse proxy URL mismatches target URL. | Configure the `Proxied` toggle, or relax validation if safe. |
