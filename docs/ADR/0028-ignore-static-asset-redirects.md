# ADR 0028: Ignore Static Asset Redirects as Post Login Target

Fix for issue: https://github.com/DonutsNL/samlsso/issues/177

## Status
Accepted

## Context
Since the deep link preservation of issue https://github.com/DonutsNL/samlsso/issues/158, `LoginState::setRedirect()` stores the target the user should return to after the SSO login. The target is taken from an explicit parameter, the `?redirect=` GET parameter, the `RelayState` or, as fallback, the unauthenticated `REQUEST_URI`. Only the entry points, login/logout screens and `/plugins/samlsso` are excluded.

Browsers request static assets (scripts, stylesheets, images, fonts) of the login page before the user is authenticated. Every such request bootstraps GLPI, runs `LoginFlow::doAuth()` and overwrites the stored target. Plugins that load scripts on the login page make this visible: with the news plugin users landed on `https://<host>/var/lib/glpi/plugins/news/js/news.js?v=…` after the login instead of the page they requested (issue https://github.com/DonutsNL/samlsso/issues/177). GLPI itself also answers unauthenticated asset requests with `?redirect=<asset>`, which leads to the same result.

## Decision
1. Add `LoginState::isStaticAssetRedirect()`, which detects a target whose URL-decoded path ends in a static asset extension (`js`, `mjs`, `css`, `map`, `png`, `jpg`, `jpeg`, `gif`, `svg`, `ico`, `webp`, `woff`, `woff2`, `ttf`, `eot`, `otf`). The query string is ignored.
2. `LoginState::setRedirect()` does not store such targets, regardless of their source. A previously captured deep link is kept.
3. `LoginState::getSafeRedirect()` also ignores such targets, so a value stored before this change is not used either.

The login phases and their transitions are not changed.

## Alternatives
- **Exclude asset paths through the exclude table**: excluded requests skip `doAuth()` entirely, but the exclude table matches configured paths, while assets come from arbitrary core and plugin locations. Administrators would have to know and maintain every path, and excluding them from `doAuth()` changes more than the redirect handling.
- **Only store the first captured target**: prevents overwriting, but the first request of a session is not necessarily the page the user wants (e.g. an asset requested before the page itself), and later deep links (e.g. a second ticket link) would be ignored.
- **Check `Sec-Fetch-Dest` / `Accept` headers**: identifies document requests more precisely, but the headers are not sent by all clients and proxies, and `?redirect=` targets produced by GLPI carry no such information.

## Consequences
- **Positive**:
  - Users land on the requested page (or the dashboard) after the SSO login instead of a raw static file.
  - Works for assets of any plugin or of GLPI core without configuration.
  - Previously stored asset targets are ignored as well.
- **Negative**:
  - A deep link that really points to a file with one of the listed extensions (e.g. a document URL ending in `.png`) is not restored after the login; the user lands on the dashboard instead. GLPI documents are served through `front/document.send.php`, so this is not expected for regular GLPI pages.
