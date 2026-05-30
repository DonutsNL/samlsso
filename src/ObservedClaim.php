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
 * ObservedClaim passively tracks every distinct SAML claim key that appears
 * in a SAML response received from any configured Identity Provider.
 *
 * Every time a SAML response is processed successfully, the ACS handler calls
 * ObservedClaim::trackClaim() for each claim key present in the assertion.
 * The resulting dataset is then surfaced in the Claim Mapping tab of the IDP
 * configuration form, giving administrators a concrete list of claim names to
 * map — without having to consult IdP documentation or inspect raw XML.
 *
 * Each row stores a unique (configs_id, saml_claim) pair so that the same
 * claim key observed from multiple responses is recorded only once per IDP.
 *
 * @since  1.3.0
 * @see    ClaimMap   Stores the administrator-configured mappings that translate
 *                    observed claim keys to GLPI user fields.
 */
class ObservedClaim extends CommonDBTM
{
    /** @var string Database column: auto-increment primary key. */
    public const ID = 'id';

    /**
     * @var string Database column: foreign key to glpi_plugin_samlsso_configs.
     *             Scopes each observed claim to the IDP that produced it.
     */
    public const CONFIGS_ID = 'configs_id';

    /**
     * @var string Database column: the raw SAML claim key (e.g.
     *             'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress').
     *             Combined with CONFIGS_ID this forms the unique constraint.
     */
    public const SAML_CLAIM = 'saml_claim';

    /** @var string Database column: UTC timestamp of the first observation. */
    public const DATE_CREATION = 'date_creation';

    /**
     * Install the ObservedClaim database table.
     *
     * Called by hook.php:plugin_samlsso_install() during plugin installation or
     * upgrade. The method is idempotent: it creates the table only when it does
     * not yet exist and performs no structural migrations (the table schema is
     * intentionally simple and not expected to change).
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

        /**
         * Only create the table if it does not already exist.
         * Skipping an already-existing table prevents data loss on re-install
         * and avoids duplicate-table errors during GLPI upgrades.
         */
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = <<<SQL
            CREATE TABLE `$table` (
                `id`             INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `configs_id`     INT {$default_key_sign} NOT NULL,
                `saml_claim`     VARCHAR(255) NOT NULL,
                `date_creation`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `configs_id` (`configs_id`),
                UNIQUE KEY `configs_id_saml_claim` (`configs_id`, `saml_claim`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        } else {
            /**
             * UPGRADE PATH: correct the sign of the configs_id foreign key column
             * for installations created before this fix was applied. GLPI 11 emits
             * a deprecation warning for signed integer foreign keys; making it
             * UNSIGNED aligns it with the primary key it references in
             * glpi_plugin_samlsso_configs.
             */
            $migration->changeField($table, 'configs_id', 'configs_id', "INT {$default_key_sign} NOT NULL");
            $migration->migrationOneTable($table);
        }
    }

    /**
     * Uninstall the ObservedClaim database table.
     *
     * Called by hook.php:plugin_samlsso_uninstall() in reverse dependency order.
     * The GLPI Migration helper first creates a backup copy of the table before
     * dropping it, so observed claim history is preserved in the backup table
     * and can be inspected or restored after removal.
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

    /**
     * Record a newly observed SAML claim key for the given IDP configuration.
     *
     * This method is intended to be called once per claim key per ACS response
     * processing cycle. It silently deduplicates: if the claim was already
     * observed for this IDP the row already exists (enforced by the database
     * UNIQUE KEY) and the method exits without writing a duplicate.
     *
     * Empty claim keys (after trimming) are discarded immediately to avoid
     * polluting the observed-claims list with meaningless entries.
     *
     * @param  int    $configs_id  Foreign key of the IDP configuration that
     *                             produced this SAML response.
     * @param  string $claim       Raw SAML claim key string (e.g. a URI or a
     *                             short name such as 'emailaddress').
     * @return void
     */
    public static function trackClaim(int $configs_id, string $claim): void
    {
        global $DB;

        /**
         * Strip leading/trailing whitespace from the claim key. An empty string
         * after trimming means the attribute element existed in the XML but had
         * no useful name — discard it silently.
         */
        $claim = trim($claim);
        if ($claim === '') {
            return;
        }

        $table = self::getTable();

        /**
         * Query for an existing row with the same (configs_id, saml_claim) pair.
         * If no row is found the claim is new for this IDP and must be recorded.
         * The UNIQUE KEY on the table would prevent a duplicate insert anyway,
         * but the explicit existence check avoids a superfluous INSERT attempt
         * and the associated database round-trip on every repeated observation.
         */
        $iterator = $DB->request([
            'FROM'  => $table,
            'WHERE' => [
                'configs_id' => $configs_id,
                'saml_claim' => $claim
            ]
        ]);

        if (count($iterator) === 0) {
            $model = new self();
            $model->add([
                'configs_id' => $configs_id,
                'saml_claim' => $claim
            ]);
        }
    }
}
