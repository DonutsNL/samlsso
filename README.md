# samlSSO
This plugin is a full rewrite by Chris Gralike of Derrick Smith's initial PHPSAML plugin for GLPI. This plugin has evolved quit a bit sinds then and is fully redesigned and rewritten to be compatible with GLPI11. It now support multiple saml idp's, it implement's user right rules and more. The plugin is fully configurable from the GLPI UI and doesnt require any coding skills. It uses GLPI core components where possible for maximum compatibility and maintainability. It implements composer for quick 3rd party library updates if security issue's requires it. It follows the PSR best-practices where possible. And most importantly it is written with a security-by-design-by-default approach in mind to help you visually identify security issues where possible.

## Feedback
Im very interrested in your challanges and ideas. Want to contribute those? Look for issues with the label 'Public feedback wanted' or create a FB issue yourself. Love to engage with you guys!

## Current Focus
* Adding functionality
* hardening the plugin
* Translations : https://app.transifex.com/quinquies/glpisaml/

## Documentation
* Officially supported by Teclib: https://glpi-plugins.readthedocs.io/en/latest/saml/requirements.html
* Further documentation see wiki: https://github.com/DonutsNL/samlsso/wiki.

## Current Focus
* Translations
* Optimizing usability
* Implementing multiple configuration strategies
* Implement decent logout strategy
* Add SCIM capabilities
* Optimize the state table and functionality for SIEM monitoring and security manipulation (remote logout, lockout)

## Building some awareness about the OSS funding gap.
- 4000+ downloads and counting.
- Hours spent maintaining and supporting glpisaml/samlsso for the past 3 years,  +1000h and counting, coffees received ytd: 29, hourly compensation for efforts: €0,145. 
- Im not in the begging for money business, but I do want to build some awareness about the OSS funding gap problem. Be honost about the benefits and consider to support the OSS projects you are using and making money off like GLPI. Building quality software is time consuming and expensive!

## Want to support my work?
- Star ⭐ my repo and contribute to my stargazer achievement. 
- Want to do more, I just love coffee: https://www.buymeacoffee.com/donutsnl
- Consider to donate codeberg.org to keep Europe's open source movement going.

## Contribute, or learn to code yourself?
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
Honor when honours are due:
- Raul, @gambware, Koen, Marc-henri, Vijay Nayani, Fabio Grasso, for supporting the OSS-community cheers and very much appreciated ❤️.
- @MikeDevresse for providing fixes to the codebase.
- @SpyK-01 for licensing and sharing the logo via https://elements.envato.com/letter-shield-gradient-colorful-logo-XZ7LYCM.
- @dollierp for adding a cleanup task
- Translations: @CTparental, Alan Lehoux (sp), Achraf Chico (fr), Eduardo Peres (us), Jonathan Ronquillo (sp), Achraf Oueldelferraga (fr), Joaquin Etchegaray (sp), Soporte Infrastructura (sp).
- Number of downloads so far: https://hanadigital.github.io/grev/?user=DonutsNL&repo=samlsso (not counting codeberg downloads +3K)


