# ADR 0004: Certificate Sanitization and XML Compatibility

## Status
Accepted

## Context
The plugin allows users to provide X.509 certificates and private keys for SAML authentication. These strings are often copy-pasted from various sources (Windows/Linux/Web interfaces), leading to inconsistent line endings (CRLF `\r\n` vs. LF `\n`).

While PEM standards (RFC 1421/7468) technically permit CRLF, the SAML protocol embeds these certificates within XML documents. During XML Signature processing, the message undergoes **Canonicalization (C14N)**.

### The Problem
XML C14N normalization rules often convert `\r\n` to `\n`. If a certificate is stored with `\r` and the signature is calculated against the raw string, but the Identity Provider (IdP) normalizes the XML before verification, the resulting **Signature will be invalid**. This causes non-deterministic authentication failures that are extremely difficult to troubleshoot.

## Decision
We will enforce a **Strict Sanitization Policy** for all certificate and key input fields.
1.  The plugin will explicitly detect and reject any certificate string containing carriage return (`\r`) characters.
2.  Users will be prompted to provide "clean" certificates using only Unix-style line endings (`\n`) or no line endings at all (single-line base64).

## Consequences
- **Improved Reliability**: SAML signature validation becomes deterministic and environment-independent.
- **Easier Troubleshooting**: Prevents the "Signature Invalid" errors caused by hidden character normalization.
- **User Experience**: Users on Windows may need to "clean" their certificate strings before saving, but the plugin provides a clear error message explaining why this is necessary.
- **Security**: Reduces the risk of "Deceptive Simple" bypasses or header injection vulnerabilities related to SMTP/HTTP legacy handling of `\r`.
