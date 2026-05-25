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
 * Class ObservedClaim tracks all SAML claim keys seen in SAML responses.
 */
class ObservedClaim extends CommonDBTM
{
    public const ID = 'id';
    public const CONFIGS_ID = 'configs_id';
    public const SAML_CLAIM = 'saml_claim';
    public const DATE_CREATION = 'date_creation';

    /**
     * Install the database table.
     *
     * @param Migration $migration The migration object
     * @return void
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = <<<SQL
            CREATE TABLE `$table` (
                `id`             INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `configs_id`     INT NOT NULL,
                `saml_claim`     VARCHAR(255) NOT NULL,
                `date_creation`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `configs_id` (`configs_id`),
                UNIQUE KEY `configs_id_saml_claim` (`configs_id`, `saml_claim`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        }
    }

    /**
     * Uninstall the database table.
     *
     * @param Migration $migration The migration object
     * @return void
     */
    public static function uninstall(Migration $migration): void
    {
        $table = self::getTable();
        $migration->backupTables([$table]);
        Session::addMessageAfterRedirect("🆗 backup: $table.");
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }

    /**
     * Record a new observed claim key.
     *
     * @param int $configs_id The IDP configuration ID
     * @param string $claim The observed SAML claim
     * @return void
     */
    public static function trackClaim(int $configs_id, string $claim): void
    {
        global $DB;
        $claim = trim($claim);
        if ($claim === '') {
            return;
        }

        $table = self::getTable();

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
