<?php

declare(strict_types=1);

/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2026 by DonutsNL
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of samlSSO plugin for GLPI.
 *
 * samlSSO plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * samlSSO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with samlSSO. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    samlSSO
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.3.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso;

use CommonDBTM;
use DBConnection;
use Migration;
use Session;

/**
 * ClaimMap persists administrator-configured mappings between SAML claim keys
 * and GLPI user-object fields for a specific Identity Provider configuration.
 *
 * Each row in the underlying table answers the question:
 *   "When SAML claim <saml_claim> is present in a response from IDP <configs_id>,
 *    write its value into GLPI user field <glpi_field> (of type <target_type>),
 *    defaulting to <default_value> when the claim is absent."
 *
 * The unique constraint on (configs_id, target_type, glpi_field) ensures that
 * each GLPI field can only be sourced from one SAML claim per IDP, preventing
 * ambiguous or conflicting mapping rules.
 *
 * Mappings are consumed by the JIT (Just-In-Time) user provisioning logic in
 * LoginFlow\User during the ACS assertion processing phase.
 *
 * @since  1.3.0
 * @see    ObservedClaim   Tracks claim keys seen in live SAML responses, giving
 *                         administrators candidate values to map here.
 * @see    LoginFlow\User  Reads and applies these mappings during JIT provisioning.
 */
class ClaimMap extends CommonDBTM
{
    /** @var string Database column: auto-increment primary key. */
    public const ID = 'id';

    /**
     * @var string Database column: foreign key to glpi_plugin_samlsso_configs.
     *             Scopes each mapping rule to a specific IDP configuration.
     */
    public const CONFIGS_ID = 'configs_id';

    /**
     * @var string Database column: category of the GLPI target field.
     *             Distinguishes between different GLPI object types such as
     *             'user_field' (core user attributes) or future extension points.
     *             Defaults to 'user_field' for backward compatibility.
     */
    public const TARGET_TYPE = 'target_type';

    /**
     * @var string Database column: the GLPI user object field name to populate
     *             (e.g. 'name', 'email', 'phone'). Together with TARGET_TYPE and
     *             CONFIGS_ID this forms the composite unique key, guaranteeing
     *             each GLPI field is mapped by at most one SAML claim per IDP.
     */
    public const GLPI_FIELD = 'glpi_field';

    /**
     * @var string Database column: the SAML claim key whose value should be
     *             written into GLPI_FIELD (e.g.
     *             'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress').
     */
    public const SAML_CLAIM = 'saml_claim';

    /**
     * @var string Database column: fallback value applied to GLPI_FIELD when
     *             the SAML response does not include SAML_CLAIM. Stored as an
     *             empty string when no default is configured.
     */
    public const DEFAULT_VALUE = 'default_value';

    /**
     * @var string Database column: boolean flag (TINYINT 0/1) indicating whether
     *             this mapping is mandatory. When set to 1, JIT provisioning will
     *             fail (and deny login) if the claim is absent and no default
     *             value is configured.
     */
    public const IS_REQUIRED = 'is_required';

    /**
     * Install the ClaimMap database table.
     *
     * Called by hook.php:plugin_samlsso_install() during plugin installation or
     * upgrade. The method handles two scenarios:
     *
     * 1. Fresh install — creates the full table schema from scratch.
     * 2. Upgrade from an older version — adds any columns or indexes that were
     *    introduced in newer releases, making the migration forward-compatible
     *    without requiring a full table rebuild.
     *
     * Table resolation uses getTableForItemType(static::class) rather than
     * self::getTable() so that the method remains safe to call even before the
     * Composer autoloader is fully registered (e.g. when the plugin is disabled).
     *
     * @param  Migration $migration  GLPI migration object used for DDL operations
     *                               and user-facing status messages.
     * @return void
     * @see    hook.php::plugin_samlsso_install()
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        /**
         * Fetch the GLPI-recommended charset, collation, and primary-key sign
         * options so the table layout matches the rest of the GLPI database and
         * remains compatible with future GLPI schema migrations.
         */
        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();

        /**
         * Resolve the physical table name via the GLPI helper function.
         * Using getTableForItemType() (pure string manipulation) instead of
         * self::getTable() (requires an active class instance / autoloader)
         * ensures this works safely when the plugin is in a disabled state.
         */
        $table = getTableForItemType(static::class);

        if (!$DB->tableExists($table)) {
            /**
             * FRESH INSTALL: create the full schema.
             * The UNIQUE KEY on (configs_id, target_type, glpi_field) enforces
             * the one-claim-per-GLPI-field constraint at the database level,
             * preventing duplicate or conflicting mapping rules even if the
             * application layer fails to enforce it.
             */
            $migration->displayMessage("Installing $table");
            $query = <<<SQL
            CREATE TABLE `$table` (
                `id`            INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `configs_id`    INT {$default_key_sign} NOT NULL,
                `target_type`   VARCHAR(50) NOT NULL,
                `glpi_field`    VARCHAR(255) NOT NULL,
                `saml_claim`    VARCHAR(255) NOT NULL,
                `default_value` VARCHAR(255) NOT NULL DEFAULT '',
                `is_required`   TINYINT NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `configs_id` (`configs_id`),
                UNIQUE KEY `configs_id_target_glpi` (`configs_id`, `target_type`, `glpi_field`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        } else {
            /**
             * UPGRADE PATH: correct the sign of configs_id for existing installations
             * before adding the new columns introduced in later releases. Doing this
             * first avoids running the MODIFY on a column that is being altered in the
             * same migration cycle, which can cause unexpected DDL conflicts.
             * GLPI 11 warns about signed foreign key columns; making it UNSIGNED aligns
             * it with the primary key it references in glpi_plugin_samlsso_configs.
             */
            $migration->changeField($table, 'configs_id', 'configs_id', "INT {$default_key_sign} NOT NULL");
            $migration->migrationOneTable($table);

            if (!$DB->fieldExists($table, 'target_type', false)) {
                $migration->addField($table, 'target_type', 'VARCHAR(50)', ['value' => 'user_field', 'after' => 'configs_id', 'update' => true]);
            }
            if (!$DB->fieldExists($table, 'default_value', false)) {
                $migration->addField($table, 'default_value', 'VARCHAR(255)', ['value' => '', 'after' => 'saml_claim', 'update' => true]);
            }
            if (!$DB->fieldExists($table, 'is_required', false)) {
                $migration->addField($table, 'is_required', 'TINYINT', ['value' => '0', 'after' => 'default_value', 'update' => true]);
            }

            /**
             * Add the composite unique key if it is not yet present.
             * We query information_schema.STATISTICS rather than relying solely
             * on Migration::addKey() to avoid ERROR 1061 (Duplicate key name)
             * on installations that already have the key from a manual migration.
             */
            $indexes = $DB->request([
                'SELECT' => 'INDEX_NAME',
                'FROM'   => 'information_schema.STATISTICS',
                'WHERE'  => [
                    'TABLE_SCHEMA' => $_SESSION['glpidbname'] ?? $DB->dbdefault,
                    'TABLE_NAME'   => $table,
                    'INDEX_NAME'   => 'configs_id_target_glpi'
                ]
            ]);
            if ($indexes->count() === 0) {
                $migration->addKey($table, ['configs_id', 'target_type', 'glpi_field'], 'configs_id_target_glpi', 'UNIQUE');
            }
        }
    }

    /**
     * Uninstall the ClaimMap database table.
     *
     * Called by hook.php:plugin_samlsso_uninstall() in reverse dependency order
     * (i.e. after Config has been uninstalled, since ClaimMap rows reference
     * Config rows). The GLPI Migration helper creates a backup copy of the table
     * before dropping it, preserving the administrator's configured mappings so
     * they can be inspected or restored after removal.
     *
     * @param  Migration $migration  GLPI migration object used for DDL operations
     *                               and user-facing status messages.
     * @return void
     * @see    hook.php::plugin_samlsso_uninstall()
     */
    public static function uninstall(Migration $migration): void
    {
        /**
         * Resolve the physical table name the same way as install() to remain
         * autoloader-independent.
         */
        $table = getTableForItemType(static::class);
        $migration->backupTables([$table]);
        Session::addMessageAfterRedirect("🆗 backup: $table.");
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }
}
