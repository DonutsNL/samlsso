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
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

/**
 * Shims.php
 * 
 * Provides basic GLPI global shims required by both production code and tests.
 * This file declares functions, classes, and constants in the global namespace
 * to satisfy GLPI framework dependencies when running unit tests outside of GLPI.
 */

namespace {
    /**
     * Bootstraps the Composer autoloader for vendor dependencies if present.
     */
    if (file_exists('/var/www/glpi-dev_quinquies_nl/vendor/autoload.php')) {
        require_once '/var/www/glpi-dev_quinquies_nl/vendor/autoload.php';
    }

    /**
     * Define essential GLPI constants if not already defined.
     * These constants simulate the environment properties of a running GLPI instance.
     */
    if (!defined('PLUGIN_NAME')) {
        define('PLUGIN_NAME', 'samlsso');
    }
    if (!defined('GLPI_VERSION')) {
        define('GLPI_VERSION', '10.0.12');
    }
    if (!defined('GLPI_ROOT')) {
        define('GLPI_ROOT', '/var/www/glpi-dev_quinquies_nl');
    }
    if (!defined('PLUGIN_SAMLSSO_LOGEVENTS')) {
        define('PLUGIN_SAMLSSO_LOGEVENTS', '_events.log');
    }
    if (!defined('PLUGIN_SAMLSSO_VERSION')) {
        define('PLUGIN_SAMLSSO_VERSION', '1.3.0');
    }
    if (!defined('PLUGIN_SAMLSSO_SRCDIR')) {
        define('PLUGIN_SAMLSSO_SRCDIR', dirname(__DIR__) . '/src');
    }
    if (!defined('PLUGIN_SAMLSSO_WEBDIR')) {
        define('PLUGIN_SAMLSSO_WEBDIR', '/plugins/samlsso/');
    }
    if (!defined('NS_PLUG')) {
        define('NS_PLUG', 'GlpiPlugin\\');
    }
    if (!defined('GLPI_PLUGINS_DIRECTORIES')) {
        define('GLPI_PLUGINS_DIRECTORIES', ['/var/www/glpi-dev_quinquies_nl/plugins']);
    }

    /**
     * Shim for GLPI's isCommandLine function.
     * Determines whether the script is run from the command line.
     *
     * @return bool Returns false by default for test emulation.
     */
    if (!function_exists('isCommandLine')) {
        function isCommandLine(): bool {
            return false; 
        }
    }

    /**
     * Shim for GLPI's translation function.
     * Translates a string using the specified domain.
     *
     * @param string $str The string to translate.
     * @param mixed $domain The translation domain (usually 'glpi').
     * @return string The translated string (returns the original string for mock purposes).
     */
    if (!function_exists('__')) {
        function __(string $str, $domain = 'glpi'): string {
            return $str;
        }
    }

    /**
     * Shim for GLPI's plural translation function.
     *
     * @param string $single Single version of string.
     * @param string $plural Plural version of string.
     * @param int $nb Count to choose between single and plural.
     * @return string The appropriate version of string.
     */
    if (!function_exists('_n')) {
        function _n(string $single, string $plural, int $nb): string {
            return ($nb > 1) ? $plural : $single;
        }
    }

    /**
     * Shim for GLPI's getTableForItemType function.
     * Computes the table name for a given class.
     *
     * @param string $itemtype The class name.
     * @return string The resolved table name.
     */
    if (!function_exists('getTableForItemType')) {
        function getTableForItemType(string $itemtype): string {
            return CommonDBTM::getTable($itemtype);
        }
    }

    /**
     * Shim for GLPI's Plugin class.
     * Mock plugin management methods.
     */
    if (!class_exists('Plugin', false)) {
        class Plugin {
            /**
             * Returns the web directory path for a plugin.
             *
             * @param string $plugin Plugin name.
             * @param bool $full Whether to return absolute or relative path.
             * @return string The web directory path.
             */
            public static function getWebDir(string $plugin, bool $full = false): string {
                return "/plugins/$plugin";
            }

            /**
             * Checks if a plugin is loaded.
             *
             * @param string $plugin Plugin name.
             * @return bool True if loaded.
             */
            public static function isPluginLoaded(string $plugin): bool {
                return true;
            }
        }
    }

    /**
     * Shim for GLPI's Session class.
     * Mock session and UI messages state.
     */
    if (!class_exists('Session', false)) {
        class Session {
            /**
             * Mocks adding a message after a redirect.
             *
             * @param string $msg Message to display.
             * @param bool $show Whether to show the message.
             * @param int $level Severity level of the message.
             * @return bool Always returns true.
             */
            public static function addMessageAfterRedirect(string $msg, bool $show = true, int $level = 0): bool { 
                return true; 
            }

            /**
             * Gets the current interface user is on (e.g. central, helpdesk).
             *
             * @return string Mocks 'central' interface.
             */
            public static function getCurrentInterface(): string { 
                return 'central'; 
            }

            /**
             * Gets pluralization configuration number.
             *
             * @return int Always returns 2.
             */
            public static function getPluralNumber(): int { 
                return 2; 
            }

            /**
             * Mocks Session destroy.
             */
            public static function destroy(): void {}

            /**
             * Mocks Session start.
             */
            public static function start(): void {}

            /**
             * Mocks Session initialization with Auth.
             *
             * @param mixed $auth Auth instance.
             */
            public static function init($auth): void {}

            /**
             * Mocks Session cleanOnLogout.
             */
            public static function cleanOnLogout(): void {}
        }
    }

    /**
     * Shim for GLPI's Html class.
     * Mock HTML helper, header, footer and redirect methods.
     */
    if (!class_exists('Html', false)) {
        class Html {
            /**
             * Redirects the user to a target destination by throwing an exception to halt execution.
             *
             * @param string $dest Destination URL.
             * @param int $http_response_code HTTP response code.
             * @throws \Exception to simulate page termination and test redirect flow.
             */
            public static function redirect(string $dest, int $http_response_code = 302): never { 
                throw new \Exception("Redirect to: $dest");
            }

            /**
             * Mock rendering of null header.
             *
             * @param string $title Header title.
             * @param string $url Header URL.
             */
            public static function nullHeader(string $title = '', string $url = ''): void { 
                echo "HTML_NULL_HEADER: $title\n"; 
            }

            /**
             * Mock rendering of null footer.
             */
            public static function nullFooter(): void { 
                echo "HTML_NULL_FOOTER\n"; 
            }

            /**
             * Mock rendering of help header.
             *
             * @param string $title Header title.
             * @param string $url Header URL.
             */
            public static function helpHeader(string $title = '', string $url = ''): void { 
                echo "HTML_HELP_HEADER: $title\n"; 
            }

            /**
             * Mock rendering of help footer.
             */
            public static function helpFooter(): void { 
                echo "HTML_HELP_FOOTER\n"; 
            }

            /**
             * Mock rendering of standard header.
             *
             * @param string $title Header title.
             * @param string $url Header URL.
             */
            public static function header(string $title = '', string $url = ''): void { 
                echo "HTML_HEADER: $title\n"; 
            }

            /**
             * Mock rendering of standard footer.
             */
            public static function footer(): void { 
                echo "HTML_FOOTER\n"; 
            }
        }
    }

    /**
     * Shim for GLPI's Toolbox class.
     * Mock logging utility.
     */
    if (!class_exists('Toolbox', false)) {
        class Toolbox {
            /**
             * Log a message in a file.
             *
             * @param string $name File name.
             * @param string $text Message content.
             * @param bool $force Force write even if logging disabled.
             * @return bool Always returns true.
             */
            public static function logInFile(string $name, string $text, bool $force = false): bool { 
                return true; 
            }

            /**
             * Log a warning message.
             *
             * @param string $msg Warning message content.
             * @return bool Always returns true.
             */
            public static function logWarning(string $msg): bool { 
                return true; 
            }
        }
    }

    /**
     * Shim for GLPI's CommonDBTM class.
     * Mock base DB-mapped object behavior in GLPI.
     */
    if (!class_exists('CommonDBTM', false)) {
        class CommonDBTM {
            /** @var array Mocked fields of the active record. */
            public $fields = [];

            /**
             * Gets the database table name corresponding to a given class name.
             *
             * @param string|null $classname Class name to resolve.
             * @return string Resolves the table name based on GLPI conventions.
             */
            public static function getTable(?string $classname = null): string {
                $class = $classname ?? static::class;
                $parts = explode('\\', strtolower($class));
                if (count($parts) >= 3 && $parts[0] === 'glpiplugin') {
                    $plugin = $parts[1];
                    $name = $parts[2];
                    return 'glpi_plugin_' . $plugin . '_' . $name . 's';
                }
                return 'glpi_' . strtolower(basename(str_replace('\\', '/', $class))) . 's';
            }

            /**
             * Gets human-readable type name.
             *
             * @param int|float|string $nb Pluralization count.
             * @return string Type name.
             */
            public static function getTypeName($nb = 0): string {
                return 'CommonDBTM';
            }

            /**
             * Mock loading object from DB by ID.
             *
             * @param int $id Object ID.
             * @return bool Always returns true.
             */
            public function getFromDB(int $id): bool { 
                return true; 
            }

            /**
             * Mock updating record fields in DB.
             *
             * @param array $input Fields data.
             * @param bool $history Keep history logs.
             * @param array $options Additional options.
             * @return bool Always returns true.
             */
            public function update(array $input, bool $history = true, array $options = []): bool { 
                return true; 
            }

            /**
             * Mock inserting a new record.
             *
             * @param array $input Fields data.
             * @param array $options Additional options.
             * @param bool $history Keep history logs.
             * @return int Mocks returning inserted row ID.
             */
            public function add(array $input, array $options = [], bool $history = true): int { 
                return 1; 
            }

            /**
             * Mock deleting a record.
             *
             * @param array $input Options specifying target.
             * @param bool $force Force delete.
             * @return bool Always returns true.
             */
            public function delete(array $input, bool $force = false): bool { 
                return true; 
            }
        }
    }

    /**
     * Shim for GLPI's CommonDropdown class.
     * Mocks a dropdown-type DB-mapped object.
     */
    if (!class_exists('CommonDropdown', false)) {
        class CommonDropdown extends CommonDBTM {}
    }

    /**
     * GLPI User Shim.
     * Mocks User domain actions in GLPI.
     */
    if (!class_exists('User', false)) {
        class User extends CommonDBTM {
            /** @var mixed Static mock handler instance. */
            public static $mockObject = null;

            /**
             * Retrieves user data by user name.
             *
             * @param string $name User name.
             * @return bool True if user was found.
             */
            public function getFromDBbyName(string $name): bool {
                if (self::$mockObject) {
                    return self::$mockObject->getFromDBbyName($name, $this);
                }
                return false; 
            }

            /**
             * Retrieves user data by email.
             *
             * @param string $email User email.
             * @return bool True if user was found.
             */
            public function getFromDBbyEmail(string $email): bool { 
                if (self::$mockObject) {
                    return self::$mockObject->getFromDBbyEmail($email, $this);
                }
                return false; 
            }

            /**
             * Retrieves user data by ID.
             *
             * @param int $id User ID.
             * @return bool True if user was found.
             */
            public function getFromDB(int $id): bool {
                if (self::$mockObject) {
                    return self::$mockObject->getFromDB($id, $this);
                }
                return parent::getFromDB($id);
            }

            /**
             * Inserts a new user record.
             *
             * @param array $input User attributes.
             * @param array $options Additional options.
             * @param bool $history Keep history logs.
             * @return int Mocks returning inserted user ID.
             */
            public function add(array $input, array $options = [], bool $history = true): int {
                if (self::$mockObject) {
                    return self::$mockObject->add($input, $options, $history);
                }
                return 1;
            }
        }
    }

    /**
     * Shim for GLPI's Group class.
     */
    if (!class_exists('Group', false)) {
        class Group extends CommonDBTM {
            /**
             * Gets human-readable type name.
             *
             * @param int|float|string $nb Pluralization count.
             * @return string Type name.
             */
            public static function getTypeName($nb = 0): string { 
                return 'Group'; 
            }
        }
    }

    /**
     * Shim for GLPI's Entity class.
     */
    if (!class_exists('Entity', false)) {
        class Entity extends CommonDBTM {
            /**
             * Gets human-readable type name.
             *
             * @param int|float|string $nb Pluralization count.
             * @return string Type name.
             */
            public static function getTypeName($nb = 0): string { 
                return 'Entity'; 
            }
        }
    }

    /**
     * Shim for GLPI's Profile class.
     */
    if (!class_exists('Profile', false)) {
        class Profile extends CommonDBTM {
            /**
             * Gets human-readable type name.
             *
             * @param int|float|string $nb Pluralization count.
             * @return string Type name.
             */
            public static function getTypeName($nb = 0): string { 
                return 'Profile'; 
            }

            /**
             * Gets CSS icon class name.
             *
             * @return string CSS class name.
             */
            public static function getIcon(): string { 
                return 'fa-profile'; 
            }
        }
    }

    /**
     * Shim for GLPI's Group_User link table.
     */
    if (!class_exists('Group_User', false)) {
        class Group_User extends CommonDBTM {}
    }

    /**
     * Shim for GLPI's Profile_User link table.
     */
    if (!class_exists('Profile_User', false)) {
        class Profile_User extends CommonDBTM {
            /**
             * Get profile links for a user.
             *
             * @param int $id User ID.
             * @return array Mock profile list.
             */
            public static function getForUser(int $id): array { 
                return [1 => ['profiles_id' => 1]]; 
            }
        }
    }

    /**
     * Shim for GLPI's Rule engine class.
     */
    if (!class_exists('Rule', false)) {
        class Rule extends CommonDBTM {
            /**
             * Get actions defined for the rule.
             *
             * @return array Empty array for mock.
             */
            public function getActions(): array { 
                return []; 
            }
        }
    }

    /**
     * Shim for GLPI's DBConnection class.
     * Mock database configuration settings.
     */
    if (!class_exists('DBConnection', false)) {
        class DBConnection {
            /** @var string Mock character set. */
            public static string $defaultCharset = 'utf8mb4';
            /** @var string Mock collation. */
            public static string $defaultCollation = 'utf8mb4_unicode_ci';
            /** @var string Mock primary key sign option. */
            public static string $defaultPrimaryKeySignOption = '';

            /**
             * Get character set.
             *
             * @return string Character set.
             */
            public static function getDefaultCharset(): string { 
                return self::$defaultCharset; 
            }

            /**
             * Get collation.
             *
             * @return string Collation.
             */
            public static function getDefaultCollation(): string { 
                return self::$defaultCollation; 
            }

            /**
             * Get primary key sign option.
             *
             * @return string Primary key sign option.
             */
            public static function getDefaultPrimaryKeySignOption(): string { 
                return self::$defaultPrimaryKeySignOption; 
            }
        }
    }

    /**
     * Shim for GLPI's Migration class.
     * Mock migration processing.
     */
    if (!class_exists('Migration', false)) {
        class Migration {
            /**
             * Migration constructor.
             *
             * @param string $ver Version number.
             */
            public function __construct(string $ver) {}

            /**
             * Mock adding a field to a table.
             *
             * @param string $table Table name.
             * @param string $field Field name.
             * @param string $type Field type.
             * @param array $options Options.
             * @return bool Always returns true.
             */
            public function addField(string $table, string $field, string $type, array $options = []): bool { 
                return true; 
            }

            /**
             * Mock dropping a table.
             *
             * @param string $table Table name.
             * @return bool Always returns true.
             */
            public function dropTable(string $table): bool { 
                return true; 
            }

            /**
             * Mock displaying a CLI migration message.
             *
             * @param string $msg Message to display.
             */
            public function displayMessage(string $msg): void {}

            /**
             * Mock backing up tables.
             *
             * @param array $tables List of tables.
             */
            public function backupTables(array $tables): void {}
        }
    }

    /**
     * Shim for GLPI's QueryExpression class.
     * Emulates raw SQL database query expressions.
     */
    if (!class_exists('QueryExpression', false)) {
        class QueryExpression {
            /** @var string Raw database expression. */
            private string $expression;

            /**
             * QueryExpression constructor.
             *
             * @param string $expression SQL fragment.
             */
            public function __construct(string $expression) {
                $this->expression = $expression;
            }

            /**
             * Convert query expression to string representation.
             *
             * @return string Raw SQL fragment.
             */
            public function __toString(): string {
                return $this->expression;
            }
        }
    }
}

namespace Glpi\Toolbox {
    /**
     * Shim for GLPI's Sanitizer class.
     * Mocks the request parameter sanitization process.
     */
    if (!class_exists('Glpi\Toolbox\Sanitizer', false)) {
        class Sanitizer {
            /**
             * Simulates filtering input variables.
             *
             * @param array $input Input parameters.
             * @return array Sanitized input parameters.
             */
            public static function sanitize(array $input): array { 
                return $input; 
            }
        }
    }
}

namespace OneLogin\Saml2 {
    if (!class_exists('OneLogin\Saml2\Constants', false)) {
        class Constants {
            public const NAMEID_EMAIL_ADDRESS = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
            public const NAMEID_UNSPECIFIED = 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';
            public const NAMEID_TRANSIENT = 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient';
            public const NAMEID_PERSISTENT = 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';
        }
    }
}
