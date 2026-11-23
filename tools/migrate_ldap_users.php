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
 *  @version    1.4.1
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace {
    if (!class_exists('GlpiPlugin\Samlsso\Utility\LdapUserMigrator', false)) {
        $userFile = dirname(__DIR__) . '/src/LoginFlow/User.php';
        if (file_exists($userFile) && !class_exists('GlpiPlugin\Samlsso\LoginFlow\User', false)) {
            $loginFlowFile = dirname(__DIR__) . '/src/LoginFlow.php';
            if (file_exists($loginFlowFile) && !class_exists('GlpiPlugin\Samlsso\LoginFlow', false)) {
                $pluginAutoload = dirname(__DIR__) . '/vendor/autoload.php';
                if (file_exists($pluginAutoload)) {
                    require_once $pluginAutoload;
                }
            }
        }
        require_once dirname(__DIR__) . '/src/Utility/LdapUserMigrator.php';
    }

    if (!class_exists('GlpiPlugin\Samlsso\Tools\LdapUserMigrator', false)) {
        class_alias(
            \GlpiPlugin\Samlsso\Utility\LdapUserMigrator::class,
            'GlpiPlugin\\Samlsso\\Tools\\LdapUserMigrator'
        );
    }

    /**
     * Direct CLI entrypoint: bootstraps GLPI 11/12 Kernel when invoked directly from PHP CLI.
     * Prefer `php bin/console plugins:samlsso:migrate_ldap_users` in packaged Marketplace/ZIP installs.
     */
    if (\PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        global $DB;
        if ($DB === null) {
            $glpiRoot = dirname(__DIR__, 3);
            $autoload = $glpiRoot . '/vendor/autoload.php';
            if (!file_exists($autoload)) {
                fwrite(\STDERR, "[ERROR] Could not locate GLPI vendor/autoload.php at {$autoload}.\n");
                throw new \RuntimeException('GLPI bootstrap failed.');
            }
            require_once $autoload;
            if (class_exists('\Glpi\Kernel\Kernel')) {
                $kernel = new \Glpi\Kernel\Kernel();
                $kernel->boot();
            }
        }

        $pluginAutoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($pluginAutoload)) {
            require_once $pluginAutoload;
        }

        $migrator = new \GlpiPlugin\Samlsso\Utility\LdapUserMigrator();
        $status = $migrator->run($_SERVER['argv'] ?? ['migrate_ldap_users.php']);
        if ($status !== 0) {
            ini_set('display_errors', '0');
            error_reporting(0);
            throw new \RuntimeException('CLI command exited with non-zero status.', $status);
        }
    }
}
