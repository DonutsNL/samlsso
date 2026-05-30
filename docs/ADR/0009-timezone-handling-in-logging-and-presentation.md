# ADR 0009: Timezone Handling in Logging and Presentation

## Status
Accepted

## Context
MySQL database `TIMESTAMP` columns are timezone-aware and store timestamps internally in UTC. When reading dates/times from the database, MySQL converts them from UTC to the connection's current session timezone (`time_zone` variable). 

During initialization, GLPI configures the database connection session timezone to UTC (`SET time_zone = '+00:00'`) to ensure timezone-agnostic storage and query calculations. As a consequence, querying raw datetime or timestamp fields (such as `loginTime`, `lastClickTime`, `date_creation`, or `date_mod`) from the database connection always yields UTC string outputs. 

Directly presenting these UTC strings in the user interface (e.g., the SAML SSO logging table or config metadata warning bars) causes confusion because the displayed timestamps do not match the system's local clock or the user's localized timezone preference (e.g. CEST/UTC+2).

## Decision
To present all database date and time records accurately in the user's localized timezone, we have established the following rules:

1. **Query Layer Agnosticism**: Datetime fields must remain in UTC throughout the querying and database storage phases to avoid breaking timezone-independent calculations (such as session timeouts and cleanup tasks).
2. **PHP Presentation Layer Formatting**: Whenever database timestamps are fetched to be presented in custom logging panels or PHP-generated tables, they must be formatted using GLPI's timezone-aware helper class:
   ```php
   $localTime = \Html::convDateTime($row['loginTime']);
   ```
3. **Twig Presentation Layer Formatting**: Inside Twig templates, raw database date strings must be formatted using GLPI's custom Twig filter `formatted_datetime`:
   ```html
   {{ logrow.loginTime|formatted_datetime }}
   ```
   This ensures that variables rendered directly in templates conform to the logged-in user's timezone and date format preferences.

## Consequences
- **Positive**:
  - Timestamps displayed in the admin UI match the user's localized preferences and system time.
  - Keeps the database layer consistent and timezone-agnostic.
  - Avoids timezone conversion boilerplate code by reusing GLPI's core helpers (`\Html::convDateTime` and Twig `formatted_datetime`).
- **Negative**:
  - Developers must remember to apply formatting wrappers on custom template variables containing database timestamps instead of printing them directly.
