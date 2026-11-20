# samlSSO

[![CodeFactor](https://www.codefactor.io/repository/github/donutsnl/samlsso/badge)](https://www.codefactor.io/repository/github/donutsnl/samlsso)
[![GLPI Compatibility](https://img.shields.io/badge/GLPI-11%20%7C%2012-green.svg)](https://github.com/DonutsNL/samlsso)
[![Tests Passing](https://img.shields.io/badge/tests-24%20suites%20passed-brightgreen.svg)](tests/RunAllTests.php)
[![GitHub release](https://img.shields.io/github/v/release/DonutsNL/samlsso.svg)](https://github.com/DonutsNL/samlsso/releases)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> [!NOTE]
> **`v1.4.0` Released** — Full **GLPI 11 & 12** compatibility, **GLPI CSP readiness**, **LDAP-to-SAML CLI user migration** (`plugins:samlsso:migrate_ldap_users`), and **Per-IdP SCIM 2.0 Provisioning (`Beta`)**.
> Upgrading from `1.3.x`? Review the **[`v1.4.0` Release Notes](https://github.com/DonutsNL/samlsso/releases/tag/v1.4.0)** for upgrade instructions and security breaking changes (external `auths_id` namespace isolation, token-guarded `?bypass=1`, encrypted SP keys, and GLPI Core session timeout delegation).

---

## Overview

`samlSSO` is a SAML 2.0 Single Sign-On (SSO) plugin for **GLPI 11 and 12**, originally rewritten by Chris Gralike from Derrick Smith's `PHPSAML` plugin. It integrates natively with GLPI Core components, uses Composer (`onelogin/php-saml`) for maintainability, follows PSR coding standards, and enforces a database-backed 9-phase authentication state machine ([ADR 0001](docs/ADR/0001-security-by-design-and-state-machine.md)).

### Requirements

| Component | Requirement |
| :--- | :--- |
| **GLPI** | `11.0.0` – `12.9.99` |
| **PHP** | `>= 8.0` with `simplexml` and `openssl` extensions |
| **Transport** | HTTPS (strongly recommended for SAML SSO; strictly required for SCIM 2.0) |
| **Sessions** | `session.cookie_httponly = On`; `SameSite=Lax` or `Strict` supported natively via stateless ACS |

---

## Key Capabilities & Features

### Authentication & Identity Providers
- **Multiple SAML Identity Providers**: Configure and operate multiple IdPs concurrently (Microsoft Entra ID, Okta, Keycloak, Ping, Authentik, Google Workspace, JumpCloud, WatchGuard AuthPoint, and generic SAML 2.0 IdPs).
- **Double-Pass Solicited Authentication**: Enforces `InResponseTo` and unique `SAMLResponse` ID replay tracking through `LoginState`, handling IdP-initiated clicks via transparent solicited re-authentication.
- **Exclusions & Token-Guarded Bypass**: Configure exclusions and optional cryptographic `?bypass=1` escape hatches for emergency local administration.

### Provisioning, Directory Migration & Rules
- **Just-In-Time (JIT) Provisioning & Claim Mapping**: Dynamically create and synchronize GLPI user accounts on login using SAML attributes mapped to GLPI user fields.
- **GLPI Rule Engine Integration**: Assign profiles, entities, and groups via native `RuleSaml` authorization rules.
- **SCIM 2.0 Provisioning (`Beta`)**: Per-IdP stateless HTTPS Bearer endpoint (`/front/scim/v2/{idpId}`) supporting user create/update/deactivate/offboarding, filter queries, PATCH operations, and scoped/existing group membership policies.
- **LDAP-to-SAML User Migration (`bin/console`)**: Migrate existing LDAP-bound accounts safely in-place (`plugins:samlsso:migrate_ldap_users`) with HMAC-signed revert registers.

### Operations, Administration & Security
- **Audit Trails & Diagnostics**: Phase-by-phase login trace logging and on-demand sanitized diagnostic popovers for TLS reverse proxies (`X-Forwarded-*`) and clock synchronization.
- **SP Certificate Management & Encrypted Secrets**: Built-in OpenSSL certificate/key generator (`RSA >= 2048` / ECDSA `P-256`–`P-521`), file/env/define key source support, and `GLPIKey` encryption at rest.
- **Configuration Backup & Restore**: Export and validate/restore full IdP, claim map, and exclusion configurations directly from the UI.
- **Content Security Policy (CSP) Ready**: Compliant with GLPI nonce-based CSP (`'csp_compliant' => true`).
- **Localizations**: Automated localization pipeline supporting 21 languages via [Transifex](https://app.transifex.com/quinquies/samlsso/).

---

## Documentation & Project Wiki

Comprehensive setup manuals, IdP walkthroughs, and troubleshooting playbooks live in the **[Project Wiki](https://github.com/DonutsNL/samlsso/wiki)** and local `/docs` directory:

| Topic | Resource |
| :--- | :--- |
| **Wiki Home & Overview** | [Project Wiki](https://github.com/DonutsNL/samlsso/wiki) |
| **Installation, Upgrade & Cookies** | [Installation and Lifecycle](https://github.com/DonutsNL/samlsso/wiki/Installation-and-Lifecycle) · [Teclib Official Docs](https://glpi-plugins.readthedocs.io/en/latest/saml/requirements.html) |
| **Configuration UI Reference** | [Tab-by-Tab Configuration Reference](https://github.com/DonutsNL/samlsso/wiki/Tab-by-Tab-Configuration-Reference) |
| **Identity Provider Guides** | [Entra ID & Okta](https://github.com/DonutsNL/samlsso/wiki/Configure-Entra-ID-and-Okta) · [Keycloak](https://github.com/DonutsNL/samlsso/wiki/Configure-Keycloak) · [Ping / Authentik / OneLogin / JumpCloud](https://github.com/DonutsNL/samlsso/wiki/Configure-Ping-Authentik-OneLogin-and-JumpCloud) · [WatchGuard AuthPoint](https://github.com/DonutsNL/samlsso/wiki/Configure-Watchguard-AuthPoint) |
| **Provisioning & Migrations** | [Claim Mapping & JIT Rules](https://github.com/DonutsNL/samlsso/wiki/Claim-Mapping-and-JIT-Rules) · [Configure SCIM Provisioning (`Beta`)](https://github.com/DonutsNL/samlsso/wiki/Configure-SCIM-Provisioning) · [Migrating Users from LDAP to SAML](https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML) |
| **Security, Bypass & SLO** | [Login Scenarios & Bypass](https://github.com/DonutsNL/samlsso/wiki/Login-Scenarios-and-Bypass) · [Exclusions, Logout & Cron](https://github.com/DonutsNL/samlsso/wiki/Exclusions-Logout-and-Cron) · [Timeouts, Clocks & Secrets](https://github.com/DonutsNL/samlsso/wiki/Timeouts-Clocks-and-Secrets) · [Unsolicited Login Flows](https://github.com/DonutsNL/samlsso/wiki/Unsollicited-%E2%80%90-IdP-initiated-login-flows) |
| **Troubleshooting & Containers** | [Troubleshooting Playbook](https://github.com/DonutsNL/samlsso/wiki/Troubleshooting-Playbook) · [Docker Setup Guide](https://github.com/DonutsNL/samlsso/wiki/Enable-samlSSO-inside-Docker-containers) |
| **Architecture & Contribution** | [Architecture Decision Records (`docs/ADR/`)](docs/ADR/) · [Contributing & Agentic Maintenance](docs/CONTRIBUTING.md) · [Changelog](changelog.md) |

> [!NOTE]
> *Admin Interface Images Notice*: The screenshots in the wiki and documentation will be renewed to reflect the latest version once updates to the configuration form are completed.

---

## Feedback, Roadmap & Contributing

### Feedback Wanted
Share challenges, deployment quirks, or SCIM `Beta` feedback via [GitHub Issues](https://github.com/DonutsNL/samlsso/issues) (check issues labeled `Public feedback wanted`) or join the community [Discord](https://discord.gg/KyMdkqJcGz).

### Current Focus
- Gathering real-world feedback on **SCIM 2.0 Provisioning (`Beta`)** and **GLPI 12** deployments
- Hardening the plugin security state machine and introducing environment-agnostic smoke testing ([#202](https://github.com/DonutsNL/samlsso/issues/202))
- Translation quality and localization coverage ([Transifex](https://app.transifex.com/quinquies/samlsso/))
- Usability optimization and remote logout (SLO) handling
- Enhancing the audit log state table for SIEM security monitoring

### Contributing & Testing
Review [CONTRIBUTING.md](docs/CONTRIBUTING.md) and [ADR 0000–0008](docs/ADR/) before submitting changes, and run the full automated test suite:

```bash
php tests/RunAllTests.php
```

---

## Want to Support This Project?
- Star ⭐ the repository to help visibility and project growth.
- Think this work earned a coffee? You can contribute at https://www.buymeacoffee.com/donutsnl.

### Building Awareness About the OSS Funding Gap
- 4000+ downloads and counting.
- Hours spent maintaining and supporting `glpisaml`/`samlsso` for the past 3 years: +1000h and counting. Coffees received YTD: 29. Hourly compensation for efforts: €0.145.
- This is not about begging for money — it is about awareness around the OSS sustainability gap. If your organization relies on open-source software commercially (including GLPI and this plugin), please consider supporting the maintainers behind it.

---

## Credits

OSS depends on community effort — thank you to everyone who has contributed🫶:
- Raul, `@gambware`, Koen, Marc-henri, Vijay Nayani, and Fabio Grasso for supporting the OSS community.
- `@beeone42` (Sebastien BENOIT) for architecting the dual GLPI 11/12 property typing compatibility layer, call-time webdir helpers, and Twig strict mode fixes (`#169`, `#167`).
- `@froberts0n` (Frank Robertson, Synairgis) for adding `registration_number` to user claims mapping (`#134`, `#157`).
- `@MikeDevresse`, `@eduardomozart`, `@Neozlag`, `@tomas321`, `@albertogalisteo`, `@KawanCostaNs`, `@enricomv`, and `@Siruv` for fixes and codebase improvements.
- `@andreaPress` for figuring out and sharing the Docker configuration needed.
- `@SpyK-01` for licensing and sharing the logo via https://elements.envato.com/letter-shield-gradient-colorful-logo-XZ7LYCM.
- `@dollierp` for adding the cleanup cron task.
- Translations: `@CTparental`, Alan Lehoux (`es`), Achraf Chico (`fr`), Eduardo Peres (`us`), Jonathan Ronquillo (`es`), Achraf Oueldelferraga (`fr`), Joaquin Etchegaray (`es`), Soporte Infrastructura (`es`).
- Download statistics: https://hanadigital.github.io/grev/?user=DonutsNL&repo=samlsso (excluding +3K historical Codeberg downloads).

---

## License

Licensed under the [GNU Affero General Public License v3 (AGPL-3.0) / GPLv3+](LICENSE). Architectural decisions are tracked under [`docs/ADR/`](docs/ADR/).
