# ADR 0024: Request-Level Configuration Caching

## Status
Accepted

## Context
When loading the GLPI login screen or evaluating authentication rules during the `POST_INIT` hook, the samlsso plugin repeatedly accesses the main configuration. This access occurs via static getters in `Config.php` and instantiations of `ConfigEntity.php` (which calls `getFromDB()`).

In large environments or pages loading many components (e.g. GLPI Forms rendering multiple fields), this resulted in nearly 6,000 redundant database `SELECT` queries targeting the `glpi_plugin_samlsso_configs` table in a single HTTP request (Issue #151). Although the database processed each query in 0 seconds, the sequential network round-trips caused extreme page load delays (up to 3+ seconds latency).

An index does not resolve the issue, as the database table already queried by primary key `id = 1` which is naturally indexed. The bottleneck is purely the volume of queries.

## Decision
1. Introduce in-memory request-level caching using PHP static properties on the `Config` and `ConfigEntity` classes.
2. In `ConfigEntity.php`, cache database rows by ID in a static array `$dbRowCache`. If an entity configuration ID has already been loaded, retrieve it from the cache instead of triggering another `getFromDB()` database query. Update `updateXmlStructure()` to keep the cache synchronized when writing back to the DB.
3. In `Config.php`, cache the return values of static queries (`getIsEnforced()`, `getIsOnlyOneConfig()`, `getConfigIdByEmailDomain()`, and `getHideLoginFields()`) using static variables (`$isEnforcedCache`, `$isOnlyOneConfigCache`, `$configIdByEmailDomainCache`, and `$hideLoginFieldsCache`).
4. Implement static `clearCache()` methods on both `Config` and `ConfigEntity` to support testing environments and cache clearing between separate test cases.
5. Create a new test suite (`ConfigCachingTest.php`) using a mock database subclass to verify that duplicate calls do not trigger multiple database requests.

Since PHP operates on a shared-nothing request lifecycle (memory is fully cleared at the end of each HTTP request), this request-level cache does not risk serving stale configuration data to subsequent requests.

## Consequences
- **Positive**:
  - Dramatically improves page response times (TTFB) in multi-user production environments by reducing the database query count from ~6,000 to just 1 query per config.
  - Eliminates network round-trip bottlenecks between application and database containers.
  - Zero risk of serving stale data across different web requests.
- **Negative**:
  - Requires explicit cache clearing in unit tests when modifying mock database states within a single PHP runner process.
