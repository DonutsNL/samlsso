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
 *  Copyright (C) 2024 by Chris Gralike
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
 *  @version    1.3.2
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.3.2
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Utility;

use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\Exclude;
use GlpiPlugin\Samlsso\LoginState;
use GlpiPlugin\Samlsso\ClaimMap;
use GlpiPlugin\Samlsso\ObservedClaim;

class SanityChecker
{
    /**
     * Run consistency check on files, translations, and database schema.
     *
     * @return array Array of check results containing 'status' (bool) and 'messages' (array)
     */
    public static function check(): array
    {
        global $DB;

        $status = true;
        $messages = [];

        // 1. Validate Plugin Files Consistency
        $requiredFiles = [
            GLPI_ROOT . '/plugins/samlsso/setup.php',
            GLPI_ROOT . '/plugins/samlsso/hook.php',
            GLPI_ROOT . '/plugins/samlsso/src/Config.php',
            GLPI_ROOT . '/plugins/samlsso/src/CronTask.php',
            GLPI_ROOT . '/plugins/samlsso/src/LoginState.php',
        ];

        foreach ($requiredFiles as $file) {
            if (!is_file($file) || !is_readable($file)) {
                $status = false;
                $messages[] = "❌ Missing or unreadable core file: " . basename($file);
            }
        }

        // 2. Validate Translation Assets
        $localesDir = GLPI_ROOT . '/plugins/samlsso/locales';
        if (!is_dir($localesDir)) {
            $status = false;
            $messages[] = "❌ Locales directory is missing.";
        } else {
            $moFiles = glob($localesDir . '/*.mo');
            if (empty($moFiles)) {
                $status = false;
                $messages[] = "❌ Compiled translation files (.mo) are missing.";
            }
        }

        // 3. Validate Database Tables
        $expectedTables = [
            'glpi_plugin_samlsso_configs'        => ['id', 'name', 'inactivity_timeout'],
            'glpi_plugin_samlsso_excludes'       => ['id', 'excludePath'],
            'glpi_plugin_samlsso_loginstates'    => ['id', 'lastClickTime', 'phase'],
            'glpi_plugin_samlsso_claimmaps'      => ['id', 'configs_id', 'target_type'],
            'glpi_plugin_samlsso_observedclaims' => ['id', 'configs_id', 'claim_key']
        ];

        foreach ($expectedTables as $table => $columns) {
            if (!$DB->tableExists($table)) {
                $status = false;
                $messages[] = "❌ Database table missing: $table";
                continue;
            }

            foreach ($columns as $column) {
                if (!$DB->fieldExists($table, $column, false)) {
                    $status = false;
                    $messages[] = "❌ Column missing in table $table: $column";
                }
            }
        }

        if ($status) {
            $messages[] = "✅ Plugin integrity check completed successfully. No inconsistencies found.";
        }

        return [
            'status'   => $status,
            'messages' => $messages
        ];
    }
}
