# ADR 0002: LoginState Hardening and DB Compatibility

## Status
Proposed

## Context
The `LoginState` class is responsible for maintaining the state of a SAML authentication session. Several issues were identified:
1. **Invalid Method Call**: The code called `DBConnection::getDefaultDatabase()`, which does not exist in GLPI 10.0+.
2. **Open Redirect Risk**: The `redirect` parameter was stored and restored without validation, relying entirely on GLPI core for security.
3. **Debug Log Exposure**: SAML response metadata and state objects were being logged without redaction, potentially exposing sensitive session information.

## Decision
1. **DB Compatibility**: Replace the non-existent `DBConnection::getDefaultDatabase()` with `$DB->dbdefault`. This is the standard GLPI 10.0+ property for retrieving the active database name.
2. **Redirect Validation**: Implement a `getSafeRedirect()` method in `LoginState.php`. This method ensures that the `redirect` parameter is a relative path, preventing "Open Redirect" attacks where an attacker could redirect a user to a malicious external site.
3. **Log Redaction**: Implement a `getSafeStateForLogging(bool $debug)` method. By default, sensitive SAML data is redacted. If "Debug" is enabled in the IDP configuration, the full context is preserved. This balances security with troubleshooting needs.

## Consequences
- **Positive**: Improved compatibility with GLPI 10.0+, reduced risk of Open Redirect attacks, and enhanced data privacy in logs.
- **Negative**: Slight performance overhead for validation checks (negligible).
- **Neutral**: The plugin remains dependent on the global `$DB` object, which is standard for GLPI plugins.

## Technical Details

### Redirect Validation Logic
We will use a regex to ensure the redirect is a relative path and does not start with `//` or contains `://`.
```php
public function getSafeRedirect(): string {
    $redirect = $this->getRedirect();
    if (empty($redirect)) return '';
    
    // An 'Open Redirect' vulnerability occurs when an application redirects to an 
    // unvalidated user-provided URL. To prevent this, we only allow 'relative' 
    // paths (e.g., /front/ticket.php) and block 'absolute' URLs (e.g., http://evil.com) 
    // by ensuring the string does not start with // or contains ://.
    if (preg_match('/^(?!\/\/)(?!\w+:\/\/).+/', $redirect)) {
        return $redirect;
    }
    return '';
}
```

### Log Redaction
We will create a helper to sanitize the state array before it hits `var_export`.
