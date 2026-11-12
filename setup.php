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
 *  @version    1.3.2
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

// USE
// This file is included in the GLPI\Plugins context.
use Glpi\Plugin\Hooks;
use Glpi\Http\SessionManager;
use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\LoginFlow;
use GlpiPlugin\Samlsso\RuleSamlCollection;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;

global $CFG_GLPI;

// PLUGIN CONSTANTS
define('PLUGIN_NAME', 'samlsso');                                                               // Plugin name
define('PLUGIN_SAMLSSO_VERSION', '1.3.2');                                                      // Plugin version
define('PLUGIN_SAMLSSO_MIN_GLPI', '11.0.0');                                                    // Min required GLPI version
define('PLUGIN_SAMLSSO_MAX_GLPI', '12.9.99');                                                   // Max GLPI compat version
define('PLUGIN_SAMLSSO_LOGEVENTS', 'events');                                                    // specifies log extention
define('PLUGIN_SAMLSSO_SRCDIR', __DIR__ . '/src');                                              // Location of the main classes
/**
 * Ordered list of fully-qualified class names for every plugin class that
 * implements a database lifecycle (install / uninstall).
 *
 * IMPORTANT: String literals are used intentionally instead of ::class constants.
 * This constant is defined at file-load time, before plugin_init_samlsso() has
 * had a chance to register the Composer autoloader. Using ::class would require
 * each class to be already loadable, which is NOT guaranteed when GLPI calls
 * this file while the plugin is in a disabled state (e.g. during uninstall).
 *
 * The order is significant: install() iterates forward (dependency order) and
 * uninstall() iterates in reverse (reverse dependency order), so child tables
 * that reference parent tables must appear after their parent in this list.
 */
define('PLUGIN_SAMLSSO_CLASSES', [
    'GlpiPlugin\\Samlsso\\Config',       // Core IDP configuration table (parent)
    'GlpiPlugin\\Samlsso\\Exclude',      // URL exclusion rules
    'GlpiPlugin\\Samlsso\\LoginState',   // Session state tracking
    'GlpiPlugin\\Samlsso\\ClaimMap',     // Claim-to-field mapping rules (references Config)
    'GlpiPlugin\\Samlsso\\ObservedClaim', // Passively observed claim keys (references Config)
    'GlpiPlugin\\Samlsso\\CronTask',     // Cron task registration
]);


/**
 * Returns the plugin web base URL or relative web path, computed at call time.
 *
 * On GLPI >= 12, 'setup.php' may be included during the kernel boot phase
 * (PostBootListener 'BootPlugins'), which runs BEFORE the GLPI configuration
 * ('glpi_configs' values such as 'url_base') is loaded from the database.
 * Computing the URL at call time ensures 'url_base' is always populated when
 * building absolute SAML ACS, SLO, and metadata URLs.
 *
 * @param bool $relative When true, returns only the relative web path without url_base.
 * @return string Plugin web URL with a trailing slash.
 * @see https://github.com/DonutsNL/samlsso/issues/41
 */
function samlsso_webdir(bool $relative = false): string
{
    global $CFG_GLPI;

    $webPath = '';
    if (method_exists('Plugin', 'getWebDir')) {
        $resolved = Plugin::getWebDir(PLUGIN_NAME);
        if (is_string($resolved) && $resolved !== '') {
            $webPath = $resolved;
        }
    }
    if ($webPath === '') {
        $rootDoc = (isset($CFG_GLPI['root_doc'])) ? (string) $CFG_GLPI['root_doc'] : '';
        $webPath = $rootDoc . '/plugins/' . PLUGIN_NAME;
    }

    if ($relative) {
        return rtrim($webPath, '/') . '/';
    }

    $urlBase = (isset($CFG_GLPI['url_base'])) ? rtrim((string) $CFG_GLPI['url_base'], '/') : '';
    return $urlBase . rtrim($webPath, '/') . '/';
}

/**
 * Returns the relative web path of the plugin without a trailing slash.
 * Kept as a thin wrapper around samlsso_webdir(true) for backward compatibility.
 *
 * @return string Relative web path without trailing slash, e.g. "/glpi/plugins/samlsso".
 */
function samlsso_plugin_web_dir(): string                                                      // NOSONAR - GLPI default naming.
{
    return rtrim(samlsso_webdir(true), '/');
}


// METHODS
/**
 * Default GLPI Plugin bootstrap function.
 * @param void
 * @return void
 * @see https://github.com/glpi-project/glpi/issues/21414
 */
function plugin_samlsso_boot(): void
{
    SessionManager::RegisterPluginStatelessPath(PLUGIN_NAME, '#^/' . SamlSsoController::ACS_ROUTE . '/#');
    SessionManager::registerPluginStatelessPath(PLUGIN_NAME, '#^/' . SamlSsoController::SLO_ROUTE . '#');
}

/**
 * @param void
 * @return void
 * @see https://glpi-developer-documentation.readthedocs.io/en/master/plugins/requirements.html
 */
function plugin_init_samlsso(): void                                                           // NOSONAR - GLPI default naming
{

    global $PLUGIN_HOOKS;                                                                       // NOSONAR - GLPI default naming. 
    $plugin = new Plugin();

    // Include additional composer PSR4 autoloader
    include_once(__DIR__ . '/vendor/autoload.php');                                              // NOSONAR - intentional include_once to load composer autoload;

    // Backend block for local login when Enforced is enabled
    $is_login_post = isset($_POST['login_name']) && isset($_POST['login_password']);
    
    // Check if bypass flag exists in the whitelisted plugin session namespace
    $is_bypassed = !empty($_SESSION['glpi_plugins']['samlsso']['bypass']);

    if (Session::getLoginUserID() !== false && $is_bypassed) {
        unset($_SESSION['glpi_plugins']['samlsso']['bypass'], $_SESSION['glpi_plugins']['samlsso']['bypass_token']);
    }

    if ($is_login_post && Session::getLoginUserID() === false && Config::getIsEnforced()) {
        $allowed = false;
        if ($is_bypassed) {
            $requiredToken = defined('PLUGIN_SAMLSSO_BYPASS_TOKEN')
                ? (string)PLUGIN_SAMLSSO_BYPASS_TOKEN
                : (string)getenv('SAMLSSO_BYPASS_TOKEN');
            $sessionToken = (string)($_SESSION['glpi_plugins']['samlsso']['bypass_token'] ?? '');
            if ($requiredToken !== '' && $sessionToken !== '' && hash_equals($requiredToken, $sessionToken)) {
                $allowed = true;
            }
        }

        if (!$allowed) {
            // Log the blocked login attempt to the plugin events log
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                sprintf("SSO enforcement blocked local login attempt for user: %s\n", (string)$_POST['login_name'])
            );
            // Nullify both username and password payloads to securely prevent native login
            $_POST['login_name'] = null;
            $_POST['login_password'] = null;
        } else {
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                sprintf("SSO bypass authorized local login attempt for user: %s\n", (string)$_POST['login_name'])
            );
        }
    }

    // Do not show config buttons if plugin is not enabled.
    if ($plugin->isInstalled(PLUGIN_NAME) || $plugin->isActivated(PLUGIN_NAME)) {

        // Hook the configuration page
        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page'][PLUGIN_NAME]       = SamlSsoController::CONFIG_ROUTE;
        }

        // Add samlSSO configuration page to menu
        $PLUGIN_HOOKS['menu_toadd'][PLUGIN_NAME]['config']  = [Config::class];

        // Register CSS file for the plugin
        $PLUGIN_HOOKS['add_css'][PLUGIN_NAME]               = ['css/samlSSO.css'];

        // Register and hook the samlRules to Hooks::RULE_MATCHED
        Plugin::registerClass(RuleSamlCollection::class, ['rulecollections_types' => true]);
        $PLUGIN_HOOKS[Hooks::RULE_MATCHED][PLUGIN_NAME]     = 'plugin_samlsso_updateUser';

        // Register and hook the loginFlow to Hooks::POST_INIT.
        Plugin::registerClass(LoginFlow::class);
        $PLUGIN_HOOKS[Hooks::POST_INIT][PLUGIN_NAME]        = 'plugin_samlsso_evalAuth';

        // Hook the login buttons to Hooks::DISPLAY_LOGIN
        $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN][PLUGIN_NAME]    = 'plugin_samlsso_displaylogin';
    }
}


/**
 * Returns the name and the version of the plugin.
 *
 * @param void
 * @return array
 */
function plugin_version_samlsso(): array                                                      // NOSONAR - GLPI default naming.
{
    return [
        'name'           => 'samlsso',
        'oldname'        => 'glpisaml',
        'version'        => PLUGIN_SAMLSSO_VERSION,
        'author'         => 'Chris Gralike',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/DonutsNL/samlSSO/',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_SAMLSSO_MIN_GLPI,
                'max' => PLUGIN_SAMLSSO_MAX_GLPI,
            ],
            'php'    => [
                'min' => '8.0'
            ],
        ],
    ];
}


/**
 * Check pre-requisites before install.
 *
 * @param void
 * @return boolean
 */
function plugin_samlsso_check_prerequisites(): bool                                           // NOSONAR - GLPI default naming.
{
    // Make sure the external libs can be loaded
    if (
        !is_readable(__DIR__ . '/vendor/autoload.php') ||
        !is_file(__DIR__ . '/vendor/autoload.php')
    ) {
        echo 'Run composer install --no-dev in the plugin directory<br>';
        return false;
    }

    // Test for simpleXML
    if (!extension_loaded('simplexml')) {
        echo 'Please make sure php-xml is installed and loaded!<br>';
        return false;
    }

    // Add additional cookie validation because this is known to be
    // faulty in many installations resulting in Session Timeout issues
    // recognisable by the &error=3 in the redirect URL.
    // https://github.com/DonutsNL/samlsso/issues/13
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $cookieSecure   = (string) ini_get('session.cookie_secure');
    $cookieHttpOnly = (string) ini_get('session.cookie_httponly');
    $cookieSameSite = (string) ini_get('session.cookie_samesite');
    if (
        ($isHttps && !filter_var($cookieSecure, FILTER_VALIDATE_BOOLEAN)) ||
        !filter_var($cookieHttpOnly, FILTER_VALIDATE_BOOLEAN) ||
        strtolower($cookieSameSite) === 'strict'
    ) {
        echo "PHP is configured with the following Cookie settings.";
        echo "session.cookie_secure = " . htmlspecialchars($cookieSecure, ENT_QUOTES, 'UTF-8') . "<br>";
        echo "session.cookie_httponly = " . htmlspecialchars($cookieHttpOnly, ENT_QUOTES, 'UTF-8') . "<br>";
        echo "session.cookie_samesite = " . htmlspecialchars($cookieSameSite, ENT_QUOTES, 'UTF-8') . "<br>";
        echo "These settings are <b>not aligned</b> with GLPI prerequisites. Please
              correct them as described <a href='https://glpi-install.readthedocs.io/en/latest/prerequisites.html#security-configuration-for-sessions'>
              in the GLPI Documentation</a>. SAML and GLPI redirects might not work correctly.";
    }

    return true;
}

/**
 * Check configuration process
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 * @return boolean
 */
function plugin_samlsso_check_config($verbose = false): bool                                  // NOSONAR - GLPI default naming.
{
    if ($verbose) {
        echo __('Installed ', PLUGIN_NAME);
    }
    return true;
}
