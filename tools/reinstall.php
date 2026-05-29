<?php
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

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    throw new \Exception("This script must be run from the command line.");
}

$glpi_root = realpath(dirname(__DIR__, 3));
$autoload_path = $glpi_root . '/vendor/autoload.php';
if (!file_exists($autoload_path)) {
    throw new \Exception("Error: GLPI vendor autoloader not found at: $autoload_path");
}

// Override GLPI log directory to a writable folder in the plugin directory to avoid permission issues
$plugin_log_dir = dirname(__DIR__) . '/tests/logs';
if (!is_dir($plugin_log_dir)) {
    mkdir($plugin_log_dir, 0777, true);
}
define('GLPI_LOG_DIR', $plugin_log_dir);

// Bootstrap GLPI Kernel
require_once $autoload_path;
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Now require plugin scripts
require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/hook.php';

echo "================================================\n";
echo " GLPI SAMLSSO - Database Reinstall & Validator\n";
echo "================================================\n\n";

if (!defined('PLUGIN_SAMLSSO_CLASSES') || !is_array(PLUGIN_SAMLSSO_CLASSES)) {
    throw new \Exception("Error: PLUGIN_SAMLSSO_CLASSES is not defined or is not an array.");
}

global $DB;
$DB->disableTableCaching();

echo "Step 1: Dropping existing tables...\n";
$version = plugin_version_samlsso();
$migration = new Migration($version['version']);

foreach (array_reverse(PLUGIN_SAMLSSO_CLASSES) as $class) {
    if (method_exists($class, 'uninstall')) {
        $table = $class::getTable();
        if ($DB->tableExists($table)) {
            echo " - Uninstalling $class ($table)...\n";
            try {
                $class::uninstall($migration);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'Unknown table') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                    echo "   - Note: Table already dropped or renamed during backup: " . $e->getMessage() . "\n";
                } else {
                    throw $e;
                }
            }
        } else {
            echo " - Skipping uninstall for $class ($table) as table does not exist.\n";
        }
    }
}

echo "\nStep 2: Installing tables in dependency order...\n";
foreach (PLUGIN_SAMLSSO_CLASSES as $class) {
    if (method_exists($class, 'install')) {
        $table = $class::getTable();
        echo " - Installing $class ($table)...\n";
        $class::install($migration);
    }
}

echo "\nStep 3: Validating structural integrity...\n";
$all_valid = true;

foreach (PLUGIN_SAMLSSO_CLASSES as $class) {
    if ($class === \GlpiPlugin\Samlsso\CronTask::class) {
        echo "✅ Class $class verified (lifecycle only, no custom table).\n";
        continue;
    }
    $table = $class::getTable();
    if (!$DB->tableExists($table)) {
        echo "❌ Failure: Table $table does not exist after install.\n";
        $all_valid = false;
        continue;
    }
    echo "✅ Table $table exists.\n";

    // Validate expected columns
    if ($class === \GlpiPlugin\Samlsso\ClaimMap::class) {
        $expected_cols = ['id', 'configs_id', 'target_type', 'glpi_field', 'saml_claim', 'default_value', 'is_required'];
        foreach ($expected_cols as $col) {
            if (!$DB->fieldExists($table, $col)) {
                echo "❌ Failure: Table $table is missing expected column: $col\n";
                $all_valid = false;
            } else {
                echo "   - Column '$col' verified.\n";
            }
        }
    } elseif ($class === \GlpiPlugin\Samlsso\Config::class) {
        $expected_cols = ['id', 'name', 'saml_xml_structure'];
        foreach ($expected_cols as $col) {
            if (!$DB->fieldExists($table, $col)) {
                echo "❌ Failure: Table $table is missing expected column: $col\n";
                $all_valid = false;
            } else {
                echo "   - Column '$col' verified.\n";
            }
        }
    }
}

echo "\n================================================\n";
if ($all_valid) {
    echo "Overall Status: REINSTALLATION SUCCESSFUL & VALID! 🚀\n";
} else {
    echo "Overall Status: VALIDATION DETECTED INTEGRITY ERRORS! 🛑\n";
    throw new \Exception("VALIDATION DETECTED INTEGRITY ERRORS!");
}
