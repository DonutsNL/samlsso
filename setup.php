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
 *  @version    1.2.3
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
define('PLUGIN_SAMLSSO_VERSION', '1.2.4');                                                      // Plugin version
define('PLUGIN_SAMLSSO_MIN_GLPI', '11.0.0');                                                    // Min required GLPI version
define('PLUGIN_SAMLSSO_MAX_GLPI', '11.9.99');                                                   // Max GLPI compat version
define('PLUGIN_SAMLSSO_LOGEVENTS','events');                                                    // specifies log extention
define('PLUGIN_SAMLSSO_SRCDIR', __DIR__ . '/src');                                              // Location of the main classes
define('PLUGIN_SAMLSSO_WEBDIR', $CFG_GLPI['url_base'] .'/plugins/'.PLUGIN_NAME.'/');            // Make sure we dont use this messy code everywhere


// METHODS
/**
 * Default GLPI Plugin Init function./**
 * Default GLPI Plugin bootstrap function.
 * @param void
 * @return void
 * @see https://github.com/glpi-project/glpi/issues/21414
 */
function plugin_samlsso_boot(): void {
        SessionManager::RegisterPluginStatelessPath(PLUGIN_NAME, '#^/front/acs/#');             // Register the assertion Service as stateless (prevent csrf checking)
        SessionManager::registerPluginStatelessPath(PLUGIN_NAME, '#^/front/slo/#');             // Register the logout service as stateless (prevent csrf checking)
}

/** 
 * @param void
 * @return void
 * @see https://glpi-developer-documentation.readthedocs.io/en/master/plugins/requirements.html
 */
function plugin_init_samlsso() : void                                                           // NOSONAR - GLPI default naming
{

    global $PLUGIN_HOOKS;                                                                       // NOSONAR - GLPI default naming. 
    $plugin = new Plugin();

    // Include additional composer PSR4 autoloader
    include_once(__DIR__. '/vendor/autoload.php');                                              // NOSONAR - intentional include_once to load composer autoload;
    
    // Do not show config buttons if plugin is not enabled.
    if ( $plugin->isInstalled(PLUGIN_NAME) || $plugin->isActivated(PLUGIN_NAME) ){

        // Hook the configuration page
        if ( Session::haveRight('config', UPDATE) ){
            $PLUGIN_HOOKS['config_page'][PLUGIN_NAME]       = SamlSsoController::CONFIG_ROUTE;
        }

        // Add samlSSO configuration page to menu
        $PLUGIN_HOOKS['menu_toadd'][PLUGIN_NAME]['config']  = [Config::class];

        // Register and hook the samlRules to Hooks::RULE_MATCHED
        Plugin::registerClass(RuleSamlCollection::class, ['rulecollections_types' => true]);
        $PLUGIN_HOOKS[Hooks::RULE_MATCHED][PLUGIN_NAME]     = 'updateUser';

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
function plugin_version_samlsso() : array                                                      // NOSONAR - GLPI default naming.
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
function plugin_samlsso_check_prerequisites() : bool                                           // NOSONAR - GLPI default naming.
{
    // Make sure the external libs can be loaded
    if (!is_readable(__DIR__ . '/vendor/autoload.php') ||
        !is_file(__DIR__ . '/vendor/autoload.php')     ){
            echo 'Run composer install --no-dev in the plugin directory<br>';
            return false;
    }

    // Test for simpleXML
    if(!extension_loaded('simplexml')){
        echo 'Please make sure php-xml is installed and loaded!<br>';
        return false;
    }

    // Add additional cookie validation because this is known to be
    // faulty in many installations resulting in Session Timeout issues
    // recognisable by the &error=3 in the redirect URL.
    // https://github.com/DonutsNL/samlsso/issues/13
    if(ini_get('session.cookie_secure') == 1   ||
       !ini_get('session.cookie_httponly') == 1 ||
       ini_get('session.cookie_samesite') == 0  ){
        echo "PHP is configured with the following Cookie settings.";
        echo "session.cookie_secure = ".ini_get('session.cookie_secure')."<br>";
        echo "session.cookie_httponly = ".ini_get('session.cookie_httponly')."<br>";
        echo "session.cookie_samesite =".ini_get('session.cookie_samesite')."<br>";
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
function plugin_samlsso_check_config($verbose = false) : bool                                  // NOSONAR - GLPI default naming.
{
   if ($verbose) {
      echo __('Installed ', PLUGIN_NAME);
   }
   return true;
}
