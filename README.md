# samlSSO
[![CodeFactor](https://www.codefactor.io/repository/github/donutsnl/samlsso/badge)](https://www.codefactor.io/repository/github/donutsnl/samlsso)
[![GLPI Compatibility](https://img.shields.io/badge/GLPI-%E2%89%A5%2011.0.0-green.svg)](https://github.com/DonutsNL/samlsso)
[![Tests Passing](https://img.shields.io/badge/tests-18%20suites%20passed-brightgreen.svg)](tests/RunAllTests.php)
[![GitHub release](https://img.shields.io/github/v/release/DonutsNL/samlsso.svg)](https://github.com/DonutsNL/samlsso/releases)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

This plugin is a full rewrite by Chris Gralike of Derrick Smith's initial PHPSAML plugin for GLPI. This plugin has evolved quite a bit since then and is fully redesigned and rewritten to be compatible with GLPI 11. It now supports multiple SAML Identity Providers (IdPs), implements advanced user right rules, Just-In-Time (JIT) provisioning, and more. 

The plugin is fully configurable from the GLPI UI and doesn't require any coding skills. It uses GLPI core components where possible for maximum compatibility and maintainability. It utilizes Composer for quick 3rd party library updates if security issues require it. It follows PSR best practices and, most importantly, is written with a "security-by-design-by-default" approach to help you visually identify security issues.

## Want to support my work?
- Star ⭐ my repo and contribute to my stargazer achievement. 
- Think my work earns me a cofee? Consider buying me one at https://www.buymeacoffee.com/donutsnl

## Key Capabilities & Features
* **Multiple SAML Identity Providers**: Configure and manage multiple IdPs simultaneously.
* **Just-In-Time (JIT) Provisioning**: Automatically create and update GLPI users dynamically on successful SAML login.
* **Flexible Claim Mapping**: Bind custom SAML assertion attributes and claims directly to GLPI user fields.
* **User Association & Authorization Rules**: Assign profiles, groups, and entities to users based on SAML claims.
* **Security Hardening**: Replay attack protection using assertion ID database tracking, strict time-window verification, and state-machine-backed login/logout flows.
* **Extensive Logging & Audit Trails**: Thorough logging of phase-by-phase login stages for security validation and SIEM monitoring.
* **Configuration Backup & Restore**: Built-in backup functionality to easily export and restore plugin configuration state.
* **Proxy Support**: Native compatibility with TLS-terminating proxies (Kubernetes Ingress, Traefik, HAProxy) using the "Requests Proxied" configuration setting.
* **Continuous Live Update Improvements**: Ongoing architectural updates ensuring seamless configuration adjustments and session synchronization.
* **Localizations**: Supports automated translation workflows to localize the interface for multiple languages.

## Feedback
I am very interested in your challenges and ideas. Want to contribute those? Look for issues with the label 'Public feedback wanted' or create a feedback issue yourself. I would love to engage with you!

## Current Focus
* Hardening the plugin security state machine
* Translation quality and localization coverage (https://app.transifex.com/quinquies/samlsso/)
* Usability optimization and remote logout handling
* SCIM provisioning capability research
* Enhancing the audit log state table for SIEM security monitoring

## Documentation
* Officially supported by Teclib: https://glpi-plugins.readthedocs.io/en/latest/saml/requirements.html
* Further documentation is available in the /docs/ folder and in the Project Wiki https://github.com/DonutsNL/samlsso/wiki.
* [Contributing & Agentic Maintenance](docs/CONTRIBUTING.md)
* [Architecture Decision Records (ADRs)](docs/ADR/)

> [!NOTE]
> *Admin Interface Images Notice*: The screenshots in the wiki and documentation will be renewed to reflect the latest version once updates to the configuration form are completed.

## Building Awareness About the OSS Funding Gap
- 4000+ downloads and counting.
- Hours spent maintaining and supporting glpisaml/samlsso for the past 3 years: +1000h and counting. Coffees received YTD: 29. Hourly compensation for efforts: €0.145.
- I am not in the begging-for-money business, but I do want to build some awareness about the OSS funding gap problem. Be honest about the benefits and consider supporting the OSS projects you are using and making money off of (like GLPI). Building quality software is time-consuming and expensive!

## Contribute, or learn to code yourself?
Join our Discord at: https://discord.gg/KyMdkqJcGz

If you have coding experience (or are learning to code) and want to add meaningful changes, please refer to our guidelines in [CONTRIBUTING.md](docs/CONTRIBUTING.md) to understand coding standards, testing, and pull request workflows.

# Credits
OSS depends on community effort! So honor where honours are due 🫶:
- Raul, @gambware, Koen, Marc-henri, Vijay Nayani, Fabio Grasso, for supporting  the OSS-community.
- @beeone42 (Sebastien BENOIT) for architecting the dual GLPI 11/12 property typing compatibility layer, call-time webdir helpers, and Twig strict mode fixes (#169, #167).
- @froberts0n (Frank Robertson, Synairgis) for adding registration_number to user claims mapping (#134, #157).
- @MikeDevresse, @eduardomozart, @Neozlag, @tomas321, @albertogalisteo, @KawanCostaNs, @enricomv, @Siruv for providing fixes and improvements to the codebase.
- @andreaPress for figuring out and sharing the docker config needed.
- @SpyK-01 for licensing and sharing the logo via https://elements.envato.com/letter-shield-gradient-colorful-logo-XZ7LYCM.
- @dollierp for adding a cleanup task
- Translations: @CTparental, Alan Lehoux (sp), Achraf Chico (fr), Eduardo Peres (us), Jonathan Ronquillo (sp), Achraf Oueldelferraga (fr), Joaquin Etchegaray (sp), Soporte Infrastructura (sp).
- Number of downloads so far: https://hanadigital.github.io/grev/?user=DonutsNL&repo=samlsso (not counting codeberg downloads +3K)

# Architecture Decision Records (ADRs)
Architectural decisions are structured and logged as ADRs inside the `docs/ADR/` folder.