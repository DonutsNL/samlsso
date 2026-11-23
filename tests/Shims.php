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
 *  @version    1.4.1
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace Glpi\Cache {
    if (!class_exists('Glpi\Cache\CacheManager', false)) {
        /**
         * Shim class for GLPI core CacheManager to prevent errors in tests.
         */
        class CacheManager {
            /**
             * Static flag to track if resetAllCaches was invoked.
             *
             * @var bool
             */
            public static bool $wasResetCalled = false;

            /**
             * Reset all caches shim.
             *
             * @return bool
             */
            public function resetAllCaches(): bool {
                self::$wasResetCalled = true;
                return true;
            }
        }
    }
}

namespace {
    /**
     * Shims.php
     * 
     * Provides basic GLPI global shims required by both production code and tests.
     * This file declares functions, classes, and constants in the global namespace
     * to satisfy GLPI framework dependencies when running unit tests outside of GLPI.
     */
    /**
     * Bootstraps the Composer autoloader for vendor dependencies if present.
     */
    if (file_exists('/var/www/glpi-dev_quinquies_nl/vendor/autoload.php')) {
        require_once '/var/www/glpi-dev_quinquies_nl/vendor/autoload.php';
    }

    spl_autoload_register(function ($class) {
        $prefix = 'GlpiPlugin\\Samlsso\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });

    /**
     * Define essential GLPI constants if not already defined.
     * These constants simulate the environment properties of a running GLPI instance.
     */
    if (!defined('PLUGIN_NAME')) {
        define('PLUGIN_NAME', 'samlsso');
    }
    if (!defined('GLPI_VERSION')) {
        define('GLPI_VERSION', '12.0.0');
    }
    if (!defined('GLPI_ROOT')) {
        define('GLPI_ROOT', '/var/www/glpi-dev_quinquies_nl');
    }
    if (!defined('GLPI_SYSTEM_CRON')) {
        define('GLPI_SYSTEM_CRON', -1);
    }
    if (!defined('READ')) {
        define('READ', 1);
    }
    if (!defined('UPDATE')) {
        define('UPDATE', 2);
    }
    if (!defined('PLUGIN_SAMLSSO_LOGEVENTS')) {
        define('PLUGIN_SAMLSSO_LOGEVENTS', '_events.log');
    }
    if (!defined('PLUGIN_SAMLSSO_VERSION')) {
        define('PLUGIN_SAMLSSO_VERSION', '1.4.1');
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

    if (!defined('PLUGIN_SAMLSSO_CLASSES')) {
        define('PLUGIN_SAMLSSO_CLASSES', [
            'GlpiPlugin\\Samlsso\\Config',
            'GlpiPlugin\\Samlsso\\Exclude',
            'GlpiPlugin\\Samlsso\\LoginState',
            'GlpiPlugin\\Samlsso\\ClaimMap',
            'GlpiPlugin\\Samlsso\\ObservedClaim',
            'GlpiPlugin\\Samlsso\\ScimLink',
            'GlpiPlugin\\Samlsso\\CronTask',
        ]);
    }

    /**
     * Shim for samlsso_webdir function to supply absolute or relative web base URL in tests.
     *
     * @param bool $relative Whether to return relative web path.
     * @return string Base URL of the plugin, with a trailing slash.
     */
    if (!function_exists('samlsso_webdir')) {
        function samlsso_webdir(bool $relative = false): string {
            return PLUGIN_SAMLSSO_WEBDIR;
        }
    }

    /**
     * Shim for the relative plugin web-directory helper declared in setup.php.
     *
     * @return string Web path without trailing slash, e.g. "/plugins/samlsso".
     */
    if (!function_exists('samlsso_plugin_web_dir')) {
        function samlsso_plugin_web_dir(): string {
            return rtrim(samlsso_webdir(true), '/');
        }
    }

    /**
     * Shim for plugin_version_samlsso function to supply version details in tests.
     *
     * @return array
     */
    if (!function_exists('plugin_version_samlsso')) {
        function plugin_version_samlsso(): array {
            return [
                'version' => PLUGIN_SAMLSSO_VERSION,
            ];
        }
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
            /** @var array Mock fields */
            public array $fields = ['id' => 1];

            /**
             * Checks if a plugin is loaded.
             *
             * @param string $plugin Plugin name.
             * @return bool True if loaded.
             */
            public static function isPluginLoaded(string $plugin): bool {
                return true;
            }

            public function isInstalled(string $plugin): bool {
                return true;
            }

            public function isActivated(string $plugin): bool {
                return true;
            }

            public static function registerClass(string $class, array $options = []): bool {
                return true;
            }

            public function unactivate(int $id): bool {
                return true;
            }

            public function getFromDBByCrit(array $crit): bool {
                $this->fields['id'] = 1;
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
            /** @var array<int, string> Recorded messages added via addMessageAfterRedirect. */
            public static array $messages = [];

            /**
             * Mocks adding a message after a redirect.
             *
             * @param string $msg Message to display.
             * @param bool $show Whether to show the message.
             * @param int $level Severity level of the message.
             * @return bool Always returns true.
             */
            public static function addMessageAfterRedirect(string $msg, bool $show = true, int $level = 0): bool { 
                self::$messages[] = $msg;
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
             * Mocks if impersonation is active.
             *
             * @return bool
             */
            public static function isImpersonateActive(): bool {
                return isset($_SESSION['impersonator_id']);
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

            public static $userId = false;

            /**
             * Mocks Session cleanOnLogout.
             */
            public static function cleanOnLogout(): void {}

            /**
             * Mocks Session getLoginUserID.
             */
            public static function getLoginUserID($force_human = true) {
                return self::$userId !== false ? self::$userId : ($_SESSION['glpiID'] ?? false);
            }

            /** @var bool Configurable permission result for haveRight() in tests. */
            public static bool $mockHaveRight = true;

            /**
             * Mocks Session::haveRight().
             *
             * @param string $module Right module name.
             * @param int    $right  Right bitmask.
             * @return bool
             */
            public static function haveRight(string $module, int $right): bool {
                return self::$mockHaveRight;
            }
        }
    }

    /**
     * Shim for GLPI's Auth class.
     */
    if (!class_exists('Auth', false)) {
        class Auth
        {
            public const NOT_YET_AUTHENTICATED = 0;
            public const NOT_YET_AUTHENTIFIED = 0;
            public const DB_GLPI = 1;
            public const MAIL = 2;
            public const LDAP = 3;
            public const EXTERNAL = 4;
            public const CAS = 5;
            public const X509 = 6;

            /**
             * Validates login format aligned with GLPI Core Auth::isValidLogin().
             *
             * @param string|null $login Candidate login string.
             * @return bool
             */
            public static function isValidLogin(?string $login): bool
            {
                return $login !== null && preg_match("/^[[:alnum:]'@.\\-_ ]+$/iu", $login) === 1;
            }
        }
    }

    /**
     * Shim for GLPI's GLPIKey class.
     */
    if (!class_exists('GLPIKey', false)) {
        class GLPIKey
        {
            public static ?string $mockRawKey = null;

            public function get(): ?string
            {
                if (self::$mockRawKey === null) {
                    self::$mockRawKey = random_bytes(32);
                }
                return self::$mockRawKey;
            }

            public function encrypt(string $string, ?string $key = null): string
            {
                if (empty($string)) {
                    return '';
                }
                return 'GLPICRYPT:' . base64_encode($string);
            }

            public function decrypt(?string $string, ?string $key = null): ?string
            {
                if (empty($string)) {
                    return $string;
                }
                if (str_starts_with($string, 'GLPICRYPT:')) {
                    return base64_decode(substr($string, 10));
                }
                return $string;
            }
        }
    }

    /**
     * Shim for GLPI's Log history recorder.
     */
    if (!class_exists('Log', false)) {
        class Log
        {
            public const HISTORY_LOG_SIMPLE_MESSAGE = 12;

            /** @var array<int, array<string, mixed>> */
            public static array $entries = [];

            public static function history($items_id, $itemtype, $changes, $itemtype_link = '', $linked_action = 0): bool
            {
                self::$entries[] = [
                    'items_id'      => (int)$items_id,
                    'itemtype'      => (string)$itemtype,
                    'changes'       => $changes,
                    'itemtype_link' => $itemtype_link,
                    'linked_action' => (int)$linked_action,
                ];
                return true;
            }
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

            /**
             * Mock date/time localized conversion helper.
             */
            public static function convDateTime(string $datetime, $format = null, bool $with_seconds = true): string {
                return $datetime . ' (LOCAL)';
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

            /**
             * Check if the current request is an AJAX request.
             *
             * @return bool
             */
            public static function isAjax(): bool {
                return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
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
            public static function getTable($classname = null) {
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
            public static function getTypeName($nb = 0) {
                return 'CommonDBTM';
            }

            /**
             * Mocks CommonDBTM::canUpdate() delegating to Session::haveRight().
             *
             * @return bool
             */
            public static function canUpdate() {
                return Session::haveRight('config', UPDATE);
            }

            public function getFromDBByCrit(array $crit): bool {
                return false;
            }

            public function find($condition = [], $order = [], $limit = null)
            {
                global $DB;
                $criteria = [
                    'FROM' => static::getTable(),
                ];
                if (!empty($condition)) {
                    $criteria['WHERE'] = $condition;
                }
                if (!empty($order)) {
                    $criteria['ORDERBY'] = is_array($order) ? $order : [$order];
                }
                if ($limit !== null) {
                    $criteria['LIMIT'] = $limit;
                }
                $results = [];
                $iterator = $DB->request($criteria);
                if ($iterator) {
                    foreach ($iterator as $row) {
                        $id = $row['id'] ?? count($results) + 1;
                        $results[$id] = $row;
                    }
                }
                return $results;
            }

            public static int $getFromDBCallCount = 0;

            public function getFromDB(int $id): bool { 
                self::$getFromDBCallCount++;
                global $DB;
                $iterator = $DB->request([
                    'FROM' => static::getTable(),
                    'WHERE' => ['id' => $id]
                ]);
                if (count($iterator) > 0) {
                    $this->fields = $iterator->current();
                    return true;
                }
                return false; 
            }

            /**
             * Mock updating record fields in DB.
             *
             * @param array $input Fields data.
             * @param bool $history Keep history logs.
             * @param array $options Additional options.
             * @return bool Always returns true.
             */
            public static int $updateCallCount = 0;

            public function update(array $input, bool $history = true, array $options = []): bool { 
                self::$updateCallCount++;
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

            /**
             * Mocks updating a user record.
             *
             * @param array $input User attributes to update.
             * @param bool $history Keep history logs.
             * @param array $options Additional options.
             * @return bool True on success.
             */
            public function update(array $input, bool $history = true, array $options = []): bool {
                if (self::$mockObject && method_exists(self::$mockObject, 'update')) {
                    return self::$mockObject->update($input, $history, $options);
                }
                return parent::update($input, $history, $options);
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
        class Group_User extends CommonDBTM {
            public static array $added = [];
            public static array $mockMemberships = [];
            public static function isUserInGroup($users_id, $groups_id): bool {
                return in_array([$users_id, $groups_id], self::$mockMemberships, true);
            }
            public function add(array $input, array $options = [], bool $history = true): int {
                self::$added[] = $input;
                return 1;
            }
        }
    }

    /**
     * Shim for GLPI's Profile_User link table.
     */
    if (!class_exists('Profile_User', false)) {
        class Profile_User extends CommonDBTM {
            public static array $added = [];
            public static array $deleted = [];
            public static array $userProfiles = [];
            /**
             * Get profile links for a user.
             *
             * @param int $id User ID.
             * @return array Mock profile list.
             */
            public static function getForUser(int $id): array {
                if (isset(self::$userProfiles[$id])) {
                    return self::$userProfiles[$id];
                }
                return [1 => ['profiles_id' => 1, 'entities_id' => 0]];
            }
            public function add(array $input, array $options = [], bool $history = true): int {
                self::$added[] = $input;
                return 1;
            }
            public function delete(array $input, bool $force = false): bool {
                self::$deleted[] = $input;
                return parent::delete($input, $force);
            }
        }
    }

    /**
     * Shim for GLPI's UserEmail class.
     */
    if (!class_exists('UserEmail', false)) {
        class UserEmail extends CommonDBTM {
            /**
             * Mocks retrieving associated emails for a user.
             *
             * @param int $id User ID.
             * @return array Empty array for mock.
             */
            public function getForUser(int $id): array {
                return [];
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

            public function changeField(string $table, string $field, string $new_field, string $type, array $options = []): bool { 
                return true; 
            }

            public function migrationOneTable(string $table): void {}

            public function addKey(string $table, array $fields, string $keyname, string $keytype = ''): bool { 
                return true; 
            }

            public function dropKey(string $table, string $name): bool { 
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

    if (!class_exists('Glpi\DBAL\QueryExpression', false)) {
        class_alias('QueryExpression', 'Glpi\DBAL\QueryExpression');
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

namespace Symfony\Component\Console\Input {
    if (!interface_exists('Symfony\Component\Console\Input\InputInterface')) {
        interface InputInterface {
            public function getOption(string $name): mixed;
        }
    }
    if (!class_exists('Symfony\Component\Console\Input\InputOption')) {
        class InputOption {
            public const VALUE_NONE = 1;
            public const VALUE_REQUIRED = 2;
            public const VALUE_OPTIONAL = 4;
        }
    }
    if (!class_exists('Symfony\Component\Console\Input\ArrayInput')) {
        class ArrayInput implements InputInterface {
            /** @var array<string, mixed> */
            private array $options = [];

            /**
             * @param array<string, mixed> $parameters
             * @param mixed $definition
             */
            public function __construct(array $parameters = [], mixed $definition = null) {
                foreach ($parameters as $key => $val) {
                    $normalized = ltrim((string)$key, '-');
                    $this->options[$normalized] = $val;
                }
            }

            public function getOption(string $name): mixed {
                return $this->options[$name] ?? null;
            }
        }
    }
}

namespace Symfony\Component\Console\Output {
    if (!interface_exists('Symfony\Component\Console\Output\OutputInterface')) {
        interface OutputInterface {
            public function write(string|iterable $messages, bool $newline = false, int $options = 0): void;
        }
    }
    if (!interface_exists('Symfony\Component\Console\Output\ConsoleOutputInterface')) {
        interface ConsoleOutputInterface extends OutputInterface {
            public function getErrorOutput(): OutputInterface;
        }
    }
    if (!class_exists('Symfony\Component\Console\Output\BufferedOutput')) {
        class BufferedOutput implements OutputInterface {
            private string $buffer = '';

            public function write(string|iterable $messages, bool $newline = false, int $options = 0): void {
                if (is_iterable($messages)) {
                    foreach ($messages as $m) {
                        $this->buffer .= (string)$m;
                    }
                } else {
                    $this->buffer .= (string)$messages;
                }
                if ($newline) {
                    $this->buffer .= "\n";
                }
            }

            public function fetch(): string {
                $content = $this->buffer;
                $this->buffer = '';
                return $content;
            }
        }
    }
}

namespace Symfony\Component\Console\Command {
    if (!class_exists('Symfony\Component\Console\Command\Command')) {
        class Command {
            public const SUCCESS = 0;
            public const FAILURE = 1;
            public const INVALID = 2;

            private string $commandName = '';
            private string $description = '';
            private string $helpText = '';
            /** @var array<string, array<string, mixed>> */
            private array $registeredOptions = [];

            public function __construct(?string $name = null) {
                if ($name !== null) {
                    $this->commandName = $name;
                }
                $this->configure();
            }

            protected function configure() {}

            public function setName(string $name): static {
                $this->commandName = $name;
                return $this;
            }

            public function getName(): ?string {
                return $this->commandName;
            }

            public function setDescription(string $description): static {
                $this->description = $description;
                return $this;
            }

            public function getDescription(): string {
                return $this->description;
            }

            public function setHelp(string $help): static {
                $this->helpText = $help;
                return $this;
            }

            public function getHelp(): string {
                return $this->helpText;
            }

            public function addOption(
                string $name,
                string|array|null $shortcut = null,
                ?int $mode = null,
                string $description = '',
                mixed $default = null
            ): static {
                $this->registeredOptions[$name] = [
                    'shortcut'    => $shortcut,
                    'mode'        => $mode,
                    'description' => $description,
                    'default'     => $default,
                ];
                return $this;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                return self::SUCCESS;
            }

            public function run(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                return $this->execute($input, $output);
            }
        }
    }
}

namespace Glpi\Console {
    if (!class_exists('Glpi\Console\AbstractCommand')) {
        abstract class AbstractCommand extends \Symfony\Component\Console\Command\Command {
            protected function configure() {
                parent::configure();
            }
        }
    }
}
