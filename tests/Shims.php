<?php
declare(strict_types=1);

/**
 * Shims.php
 * 
 * Provides basic GLPI global shims required by both production code and tests.
 */

namespace {
    // Define essential GLPI constants if not present
    if (!defined('PLUGIN_NAME')) define('PLUGIN_NAME', 'samlsso');
    if (!defined('GLPI_VERSION')) define('GLPI_VERSION', '10.0.12');
    if (!defined('GLPI_ROOT')) define('GLPI_ROOT', '/var/www/glpi-dev_quinquies_nl');
    if (!defined('PLUGIN_SAMLSSO_LOGEVENTS')) define('PLUGIN_SAMLSSO_LOGEVENTS', '_events.log');

    /**
     * Shim for GLPI's isCommandLine function.
     */
    if (!function_exists('isCommandLine')) {
        function isCommandLine(): bool {
            return false; 
        }
    }

    /**
     * Shim for GLPI's translation function.
     * Relaxed type hint for $domain to allow the production code's non-standard usage.
     */
    if (!function_exists('__')) {
        function __(string $str, $domain = 'glpi'): string {
            return $str;
        }
    }

    if (!function_exists('_n')) {
        function _n(string $single, string $plural, int $nb): string {
            return ($nb > 1) ? $plural : $single;
        }
    }

    /**
     * Shim for GLPI's Plugin class
     */
    if (!class_exists('Plugin')) {
        class Plugin {
            public static function getWebDir(string $plugin, bool $full = false): string {
                return "/plugins/$plugin";
            }
        }
    }

    /**
     * Shim for GLPI's Session class
     */
    if (!class_exists('Session')) {
        class Session {
            public static function addMessageAfterRedirect(string $msg, bool $show = true, int $level = 0): bool { return true; }
            public static function getCurrentInterface(): string { return 'central'; }
            public static function getPluralNumber(): int { return 2; }
        }
    }

    /**
     * Shim for GLPI's Html class
     */
    if (!class_exists('Html')) {
        class Html {
            public static function redirect(string $dest, int $http_response_code = 302): never { 
                throw new \Exception("Redirect to: $dest");
            }
            public static function nullHeader(string $title = '', string $url = ''): void { echo "HTML_NULL_HEADER: $title\n"; }
            public static function nullFooter(): void { echo "HTML_NULL_FOOTER\n"; }
            public static function helpHeader(string $title = '', string $url = ''): void { echo "HTML_HELP_HEADER: $title\n"; }
            public static function helpFooter(): void { echo "HTML_HELP_FOOTER\n"; }
            public static function header(string $title = '', string $url = ''): void { echo "HTML_HEADER: $title\n"; }
            public static function footer(): void { echo "HTML_FOOTER\n"; }
        }
    }

    /**
     * Shim for GLPI's Toolbox class
     */
    if (!class_exists('Toolbox')) {
        class Toolbox {
            public static function logInFile(string $name, string $text, bool $force = false): bool { return true; }
            public static function logWarning(string $msg): bool { return true; }
        }
    }

    /**
     * Shim for GLPI's CommonDBTM class
     */
    if (!class_exists('CommonDBTM')) {
        class CommonDBTM {
            public $fields = [];
            public static function getTable(?string $classname = null): string { return 'glpi_table'; }
            public function getFromDB(int $id): bool { return true; }
            public function update(array $input, bool $history = true, array $options = []): bool { return true; }
            public function add(array $input, array $options = [], bool $history = true): int { return 1; }
            public function delete(array $input, bool $force = false): bool { return true; }
        }
    }

    /**
     * GLPI User Shim
     */
    if (!class_exists('User')) {
        class User extends CommonDBTM {
            public static $mockObject = null;

            public function getFromDBbyName(string $name): bool {
                if (self::$mockObject) return self::$mockObject->getFromDBbyName($name, $this);
                return false; 
            }
            public function getFromDBbyEmail(string $email): bool { 
                if (self::$mockObject) return self::$mockObject->getFromDBbyEmail($email, $this);
                return false; 
            }
            public function getFromDB(int $id): bool {
                if (self::$mockObject) return self::$mockObject->getFromDB($id, $this);
                return parent::getFromDB($id);
            }
            public function add(array $input, array $options = [], bool $history = true): int {
                if (self::$mockObject) return self::$mockObject->add($input, $options, $history);
                return 1;
            }
        }
    }

    if (!class_exists('Group')) {
        class Group extends CommonDBTM {
            public static function getTypeName(int $nb = 1): string { return 'Group'; }
        }
    }

    if (!class_exists('Entity')) {
        class Entity extends CommonDBTM {
            public static function getTypeName(int $nb = 1): string { return 'Entity'; }
        }
    }

    if (!class_exists('Profile')) {
        class Profile extends CommonDBTM {
            public static function getTypeName(int $nb = 1): string { return 'Profile'; }
            public static function getIcon(): string { return 'fa-profile'; }
        }
    }

    if (!class_exists('Group_User')) {
        class Group_User extends CommonDBTM {}
    }

    if (!class_exists('Profile_User')) {
        class Profile_User extends CommonDBTM {
            public function getForUser(int $id): array { return []; }
        }
    }

    if (!class_exists('Rule')) {
        class Rule extends CommonDBTM {
            public function getActions(): array { return []; }
        }
    }

    /**
     * Shim for GLPI's DBConnection class
     */
    if (!class_exists('DBConnection')) {
        class DBConnection {
            public static string $defaultCharset = 'utf8mb4';
            public static string $defaultCollation = 'utf8mb4_unicode_ci';
            public static string $defaultPrimaryKeySignOption = '';

            public static function getDefaultCharset(): string { return self::$defaultCharset; }
            public static function getDefaultCollation(): string { return self::$defaultCollation; }
            public static function getDefaultPrimaryKeySignOption(): string { return self::$defaultPrimaryKeySignOption; }
        }
    }

    /**
     * Shim for GLPI's Migration class
     */
    if (!class_exists('Migration')) {
        class Migration {
            public function addField(string $table, string $field, string $type, array $options = []): bool { return true; }
            public function dropTable(string $table): bool { return true; }
            public function displayMessage(string $msg): void {}
            public function backupTables(array $tables): void {}
        }
    }
}

namespace Glpi\Toolbox {
    if (!class_exists('Glpi\Toolbox\Sanitizer')) {
        class Sanitizer {
            public static function sanitize(array $input): array { return $input; }
        }
    }
}
