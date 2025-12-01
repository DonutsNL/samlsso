# samlSSO
This plugin is a full rewrite by Chris Gralike of Derrick Smith's initial PHPSAML plugin for GLPI. This plugin is redesigned and rewritten to be compatible with GLPI10+, Support multiple saml idp's, implement user right rules and more. It allows you to configure everything from the GLPI UI and dont require coding skills. It uses GLPI core components where possible for maximum compatibility and maintainability. It implements composer for quick 3rd party library updates if security issue's requires it. It follows the PSR best-practices where possible.

# Status
PRODUCTION RELEASE

# Current Focus
* Adding functionality
* hardening the plugin
* Translations : https://app.transifex.com/quinquies/glpisaml/

# Support
Want to support my work?
- Star ⭐ my repo and contribute to my stargazer achievement. 
- Want to do more, I just love coffee: https://www.buymeacoffee.com/donutsnl
- Consider to donate codeberg.org to keep the open source movement going.
- Number of downloads so far: https://hanadigital.github.io/grev/?user=DonutsNL&repo=glpisaml

# Contribute, ideas and help?
Join my (and hopefully our in the future) discord at: https://discord.gg/KyMdkqJcGz
Have coding experience (or are learning to code) and want to add meaningfull changes and additions? First start from your own repository by forking this repository and then create pull requests. Deal with any feedback you receive and see your pullrequest being merged. If you have proven to be consistant, then request access to the repository as contributor and help me build a great tool for people to enjoy. Just want to share your idea, then please create an issue outlining the issue or your idea.

**Coding:**
- [Follow PSR where possible](https://www.php-fig.org/psr/)
- Use a decent IDE and consider using plugins like:
- Gitlense (intelephense);
- PSR4 compliant namespace resolver;
- Composer integration;
- Xdebug profiler;
- SonarLint;
- Twig language support;

# Credits
OSS depends on community effort! So honor where honours are due 🫶:
- Raul, @gambware, Koen, Marc-henri, Vijay Nayani, Fabio Grasso, for supporting  the OSS-community.
- @MikeDevresse for providing fixes to the codebase.
- @andreaPress for figuring out and sharing the docker config needed.
- @SpyK-01 for licensing and sharing the logo via https://elements.envato.com/letter-shield-gradient-colorful-logo-XZ7LYCM.
- @dollierp for adding a cleanup task
- @CTparental, Alan Lehoux (sp), Achraf Chico (fr), Eduardo Peres (us), Jonathan Ronquillo (sp), Achraf Oueldelferraga (fr), Joaquin Etchegaray (sp), Soporte Infrastructura (sp) for working on translations


# My thoughts (ADR) on SAML2 versus oAuth 
While OAuth 2.0 combined with OpenID Connect (OIDC) is widely regarded as the modern standard for authentication, choosing between it and SAML depends heavily on the specific architecture of the application being secured.

Modernity and Microservices OAuth/OIDC was designed for modern, distributed architectures. It uses lightweight JSON Web Tokens (JWTs), which are easy for different programming languages to parse. This makes OIDC ideal for Single Page Applications (SPAs), mobile apps, and especially microservice environments, where a stateless token needs to be passed efficiently between many different services to authorize requests. SAML, by contrast, relies on heavy XML protocols, which are cumbersome and inefficient to process in high-speed microservice meshes.
 
OAuth is a **flexible** framework offering various implementation "flows." This flexibility is powerful but introduces the risk of implementation errors; choosing the wrong flow for a use case can create security vulnerabilities. SAML is a **rigid protocol**. It dictates a strict, formalized structure for authentication exchange. While initial configuration is notoriously difficult (requiring precise exchange of XML metadata and certificates), this rigidity means that once configured, the process is robust and offers few opportunities to deviate from a secure path.

**Security Considerations:** 
To mitigate token theft and Replay attacks, both protocols rely on digital signatures to validate authenticity. However, a validly signed token or assertion, if stolen, can potentially be replayed by an attacker.

OAuth/OIDC historically suffered from token theft risks in client-side browsers via XSS attacks. While modern best practices (like backend-for-frontend patterns) mitigate this, high-security scenarios sometimes require advanced, optional configurations like mutual TLS (mTLS). mTLS binds a token to a specific client certificate (PKI), ensuring that a stolen token cannot be replayed by an attacker without the corresponding private key. SAML inherently processes its heavy XML assertions on the backend, reducing client-side surface area. It also has built-in, mandatory mechanisms against replay attacks, such as strict timestamp windows and unique assertion IDs that the Service Provider tracks.

**Best for GLPI imho:** 
GLPI is fundamentally a classic, server-side monolithic (php) application, not a distributed microservice architecture. It does not (yet) require the lightweight JSON token passing that makes OIDC shine in modern apps. Because GLPI handles its logic and sessions almost entirely on the backend, the server-side processing model of SAML is a natural fit. Furthermore, for an internal IT service management tool where stability and strict security controls are paramount, the rigidity of the SAML protocol is an asset, not a drawback. It provides a mature, "locked-down" authentication mechanism that aligns perfectly with GLPI's monolithic architecture and use cases, without the complexity of navigating modern OAuth security flow or advanced mTLS/PKI configurations.

Other thoughts on the subject, im always open to learn and realign my reasoning 😊
