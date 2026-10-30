# ADR 0017: Security-by-Design Architecture & Threat Mitigation

## Status
Accepted

## Context
Standard SAML Service Provider (SP) implementations are often stateless, validating SAML Responses dynamically from browser-submitted inputs. This design exposes authentication integrations to several classes of attacks, including Unsolicited Response injection, Replay attacks, and race conditions. For a core infrastructure application like GLPI, authentication stability and security are paramount.

## Decision
We decided to enforce a stateful, database-backed state-machine architecture for the authentication lifecycle of the `samlsso` plugin. Instead of trusting assertions dynamically, we strictly bind authentication attempts to verified database state transitions.

### 1. State-Machine Enforcement (Phase Control & Allowed Transitions)
We track the complete lifecycle of a session through 9 explicit database phases defined in `LoginState`:

| Phase Constant | ID | UI / Log Label | Meaning & Entry Preconditions | Only Permitted Writer |
| :--- | :-: | :--- | :--- | :--- |
| `PHASE_INITIAL` | `1` | Initial | New visitor session created (or unsolicited call reset to initial before IdP redirect). `SAML_REQUEST_ID` is empty. | `LoginState::createInitialState()` / `LoginState::evalGlpiAuth()` |
| `PHASE_SAML_ACS` | `2` | SAML ACS Expected | SP-initiated `AuthnRequest` sent to IdP (`SAML_REQUEST_ID` registered, `SAML_RESPONSE_ID` empty), waiting for IdP callback at `/front/acs/{idpId}`. **Only sessions in this state with a matching `SAML_REQUEST_ID` may be evaluated by `Acs::assertSaml()`.** | `LoginFlow::performSamlIdpRequest()` **only** (never `Acs` or `LoginState` directly) |
| `PHASE_SAML_AUTH` | `3` | SAML Authed | Validated & expected `SAMLResponse` accepted at ACS; `SAML_RESPONSE_ID` recorded. Blocks any re-evaluation or replay of the assertion at ACS. | `Acs::assertSaml()` **only** (after `isValid($expectedRequestId)` and `checkResponseIdUnique()` succeed) |
| `PHASE_GLPI_AUTH` | `4` | GLPI Authed | Handed over to GLPI core session (`$_SESSION['glpiname']` and `$_SESSION['valid_id']` active). | `LoginState::evalGlpiAuth()` |
| `PHASE_RESERVED` | `5` | Reserved | Reserved for future use. | N/A |
| `PHASE_FORCE_LOG` | `6` | Forced Logoff | Administrator triggered Force Logoff & Disable User ([ADR 0013](0013-force-logoff-and-disable-user-action.md)). Next request in `LoginFlow::doAuth()` destroys session and transitions to `PHASE_LOGOFF (8)`. | `ConfigForm` (`forcelogoff` POST action) |
| `PHASE_TIMED_OUT` | `7` | Timed Out | Pending `PHASE_SAML_ACS (2)` exceeded `request_timeout` or active `PHASE_GLPI_AUTH (4)` exceeded `inactivity_timeout`. Rejected by `Acs::assertSaml()` and terminated by `LoginFlow::doAuth()`. | `LoginState::getState()`, `LoginState::expireStaleAcsRequests()`, `LoginState::expireStaleGlpiSessions()`, `CronTask` |
| `PHASE_LOGOFF` | `8` | Logged Out | Session terminated (local logout, SLO logout, or after forced/timed-out cleanup). Excluded from active state lookups. | `LoginFlow::doAuth()` |
| `PHASE_ERROR` | `9` | Error | Assertion validation or login flow failed. Excluded from active state lookups (`NOT PHASE IN (8, 9)`), permanently locking the failed state row against reuse. | `LoginFlow::printError()` / `LoginFlow::PrintFatalLoginError()` |

#### State-Machine Flow Diagram

```mermaid
stateDiagram-v2
    [*] --> PHASE_INITIAL_1 : New visitor / Unsolicited ACS hit (no matching SAML_REQUEST_ID)
    PHASE_INITIAL_1 --> PHASE_SAML_ACS_2 : LoginFlow::performSamlIdpRequest() registers SAML_REQUEST_ID & redirects to IdP
    PHASE_SAML_ACS_2 --> PHASE_SAML_AUTH_3 : Acs::assertSaml() validates expected InResponseTo & records unique SAML_RESPONSE_ID
    PHASE_SAML_ACS_2 --> PHASE_ERROR_9 : Validation / Replay / IdP / CSRF failure in ACS
    PHASE_SAML_ACS_2 --> PHASE_TIMED_OUT_7 : request_timeout exceeded
    PHASE_SAML_AUTH_3 --> PHASE_GLPI_AUTH_4 : Session::init() & redirect to GLPI
    PHASE_GLPI_AUTH_4 --> PHASE_FORCE_LOG_6 : Admin Force Logoff
    PHASE_GLPI_AUTH_4 --> PHASE_TIMED_OUT_7 : inactivity_timeout exceeded
    PHASE_GLPI_AUTH_4 --> PHASE_LOGOFF_8 : User Logout / SLO
    PHASE_FORCE_LOG_6 --> PHASE_LOGOFF_8 : LoginFlow::doAuth() cleans session
    PHASE_TIMED_OUT_7 --> PHASE_LOGOFF_8 : LoginFlow::doAuth() cleans session
```

#### Non-Negotiable State-Machine Invariants (Testable & Verifiable)
1. **No Direct Unsolicited Authentication ([ADR 0036](0036-enforce-state-machine-reauth-on-unsolicited-responses.md)):**
   - When an incoming `SAMLResponse` at `/front/acs/{idpId}` has an empty `InResponseTo` or no matching `SAML_REQUEST_ID` in `glpi_plugin_samlsso_loginstates`, `LoginState` initializes in `PHASE_INITIAL (1)` (`SAML_REQUEST_ID = null`).
   - Because it does not meet the conditions for ACS assertion evaluation, `Acs::fetchRequestState()` must **never** promote it directly to `PHASE_SAML_ACS (2)` for inline validation or pass `null` to `isValid()`. Instead, it persists the initial state (`writeState()`), flags `SAML_UNSOLICITED = 1`, binds `idpId`, and calls `LoginFlow::performSamlIdpRequest()` to register a fresh `SAML_REQUEST_ID` and redirect the browser to the IdP so the IdP returns to ACS via the standard solicited flow.
2. **Single-Evaluation Lock at ACS (`PHASE_SAML_ACS (2)` → `PHASE_SAML_AUTH (3)` or `PHASE_ERROR (9)`):**
   - `Acs::assertSaml()` may only evaluate a state row whose phase is `PHASE_SAML_ACS (2)` (awaiting ACS), whose `SAML_REQUEST_ID` is non-empty and matches `InResponseTo`, and whose `SAML_RESPONSE_ID` is empty.
   - Immediately upon evaluation, the state transitions to `PHASE_SAML_AUTH (3)` (if valid) or `PHASE_ERROR (9)` (if invalid), preventing any second evaluation or replay of the same state or `SAMLResponse`.

### 2. Replay Protection
Every `SAMLResponse` contains a unique ID. We store this ID in the `samlsso_loginstates` table.
* If a response ID is presented more than once, it is blocked immediately.
* This mitigates "man-in-the-middle" and "browser-history" replay attacks.

### 3. XML Security & Signature Validation
The plugin utilizes the industry-standard `onelogin/php-saml` library to handle:
* **Signature Verification**: Ensuring the assertion is signed by the trusted IdP.
* **XXE Protection**: Preventing XML External Entity attacks during parsing.
* **Message Integrity**: Validating that the XML has not been tampered with.

---

## Hardened Threat Mitigation Matrix

| Attack Type | Prevention Method |
| :--- | :--- |
| **Unsolicited Response** | State machine rejects any assertion without a corresponding `InResponseTo` ID in the database. |
| **Replay Attack** | Database check for unique `SAML_RESPONSE_ID`. |
| **XML Signature Wrapping** | Strict validation of XML structure via the OneLogin library. |
| **Race Conditions** | Database-level phase locks prevent parallel processing of the same authentication session. |
| **Open Redirect** | Validation of the `redirect` parameter to ensure it maps exclusively to a local path. |

---

## Implementation Guidelines (Harden your Setup)
To achieve the highest level of security, the following parameters are expected:
1. **Enforce Signed Assertions**: Ensure that both the SAML Response and the Assertion inside are signed.
2. **SHA-256 or Higher**: Configure the IdP to use at least RSA-SHA256 for signatures.
3. **Strict NameID Matching**: Map the `NameID` to a unique, immutable GLPI attribute (like email or UUID).
4. **Short Timeouts**: Set clock drift and session timeout to the minimum viable values (2-3 minutes).
5. **Disable Debug in Production**: Keep debug logging disabled in production to prevent technical details leakage.

---

## Testing & Core Integrity Requirements
To ensure code quality and prevent security regression:
* **Test Suite Coverage**: Every function included in the main `LoginFlow` class and its subclasses must be mapped to and covered by the automated test suite.
* **No Core Mocking / Pseudo Functions**: We do not implement shim or pseudo functions that mimic GLPI core behaviors. Test environments must bootstrap the actual GLPI framework to execute real core behaviors rather than stubbing them out.
* **No Core Feature Manipulation**: We do not intervene, modify, or manipulate core GLPI features that the plugin utilizes. Core operations must remain untouched to respect a strict separation of duties between the authentication plugin and the main GLPI platform.

## Consequences
* **Positive**:
  - Out-of-the-box protection against unsolicited logins, token replays, and race conditions.
  - Transparent state tracking allows for security log auditing and SIEM integration.
  - Strict testing guidelines guarantee that any functional changes to login flows undergo regression checks.
  - Safe maintenance boundaries: no risk of breaking core GLPI security hooks due to dynamic code injection.
* **Negative**:
  - Requires database write capability during login initiation (storing the state), introducing small database overhead.
  - Developers must spend additional effort writing full-coverage integration tests for any edits to the `LoginFlow` routines.
