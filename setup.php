<?php
/**
 *  ------------------------------------------------------------------------
 *  Glpisaml
 *
 *  Glpisaml was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Glpisaml project.
 * Glpisaml plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Glpisaml is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Glpisaml. If not, see <http://www.gnu.org/licenses/>.
 *
 * ------------------------------------------------------------------------
 *
 *  @package    Glpisaml
 *  @version    1.1.6
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2023 by Chris Gralike
 *  @license    GPLv2+
 *  @see        https://github.com/DonutsNL/GLPISaml/readme.md
 *  @link       https://github.com/DonutsNL/GLPISaml
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

// This file is included into the GLPI Plugin base class.
// https://codeberg.org/QuinQuies/glpisaml/issues/18
use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpisaml\Config;
use GlpiPlugin\Glpisaml\Exclude;
use GlpiPlugin\Glpisaml\LoginFlow;
use GlpiPlugin\Glpisaml\RuleSamlCollection;

// Setup constants
define('PLUGIN_GLPISAML_VERSION', '1.1.11');
define('PLUGIN_GLPISAML_MIN_GLPI', '10.0.11');                                                  // Min required GLPI version
define('PLUGIN_GLPISAML_MAX_GLPI', '10.9.99');                                                  // Max GLPI compat version
define('PLUGIN_NAME', 'glpisaml');                                                              // Plugin name
define('LOGEVENTS','events');                                                                   // specifies log extention
define('LOGERRORS','errors');                                                                   // specifies log extention
// Directories
define('PLUGIN_GLPISAML_WEBDIR', Plugin::getWebDir(PLUGIN_NAME, false));                        // Plugin web directory
define('PLUGIN_GLPISAML_SRCDIR', __DIR__ . '/src');                                             // Location of the main classes
// WebPaths
define('PLUGIN_GLPISAML_ATOM_URL', 'https://codeberg.org/QuinQuies/glpisaml/releases.rss');     // Location of the repository versions
define('PLUGIN_GLPISAML_META_PATH', '/front/meta.php');                                         // Location where to get metadata about sp
define('PLUGIN_GLPISAML_CONF_PATH', '/front/config.php');                                       // Location of the config page
define('PLUGIN_GLPISAML_CONF_FORM', '/front/config.form.php');                                  // Location of config form
define('PLUGIN_GLPISAML_CONFCSS_PATH', 'templates/css/glpiSaml.css');                           // Location of the config CSS

/**
 * Default GLPI Plugin Init function.
 * @return void
 * @see https://glpi-developer-documentation.readthedocs.io/en/master/plugins/requirements.html
 * @see https://codeberg.org/QuinQuies/glpisaml/issues/8
 */
function plugin_init_glpisaml() : void                                                          //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    global $PLUGIN_HOOKS;                                                                       //NOSONAR
    $plugin = new Plugin();

    // INCLUDE PSR4 AUTOLOADER
    include_once(__DIR__. '/vendor/autoload.php');                                              //NOSONAR - intentional include_once to load composer autoload;

    // CSRF
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT][PLUGIN_NAME] = true;                                   //NOSONAR - These are GLPI default variable names  
    
    // Do not show config buttons if plugin is not enabled.
    if ($plugin->isInstalled(PLUGIN_NAME) || $plugin->isActivated(PLUGIN_NAME)) {

        // is registration still required with PSR4 auto loading?
        //Plugin::registerClass(Config::class);
        //Plugin::registerClass(Exclude::class);

        // Hook the configuration page
        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page'][PLUGIN_NAME]       = PLUGIN_GLPISAML_CONF_PATH;        //NOSONAR
        }
        $PLUGIN_HOOKS['menu_toadd'][PLUGIN_NAME]['config']  = [Config::class];
        $PLUGIN_HOOKS[Hooks::ADD_CSS][PLUGIN_NAME][]        = PLUGIN_GLPISAML_CONFCSS_PATH;

        // Register and hook the saml rules
        Plugin::registerClass(RuleSamlCollection::class, ['rulecollections_types' => true]);
        $PLUGIN_HOOKS[Hooks::RULE_MATCHED][PLUGIN_NAME]     = 'updateUser';

        // Register and hook the loginFlow directly after GLPI init.
        Plugin::registerClass(LoginFlow::class);
        $PLUGIN_HOOKS[Hooks::POST_INIT][PLUGIN_NAME]        = 'plugin_glpisaml_evalAuth';       //NOSONAR

        // Hook the login buttons
        $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN][PLUGIN_NAME]    = 'plugin_glpisaml_displaylogin';
    }
}


/**
 * Returns the name and the version of the plugin
 * @return array
 */
function plugin_version_glpisaml() : array                                                      //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    return [
        'name'           => 'samlSSO',
        'version'        => PLUGIN_GLPISAML_VERSION,
        'author'         => 'Chris Gralike',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://codeberg.org/QuinQuies/glpisaml',
        'requirements'   => [
            'glpi' => [
            'min' => PLUGIN_GLPISAML_MIN_GLPI,
            'max' => PLUGIN_GLPISAML_MAX_GLPI,
            ],
            'php'    => [
            'min' => '8.0'
            ]
        ]
    ];
}


/**
 * Check pre-requisites before install
 * @return boolean
 */
function plugin_glpisaml_check_prerequisites() : bool                                           //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    // include plugin composer
    // https://github.com/pluginsGLPI/example/issues/49#issuecomment-1891552141
    if (!is_readable(__DIR__ . '/vendor/autoload.php') ||
        !is_file(__DIR__ . '/vendor/autoload.php')     ){
            echo 'Run composer install --no-dev in the plugin directory<br>';
            return false;
    }
    // https://codeberg.org/QuinQuies/glpisaml/issues/20#issuecomment-1800440
    // Validate PHP is capable of writing session data to disk.
    if (!is_writable(session_save_path())) {
        echo 'Session path "'.session_save_path().'" is not writable for PHP!';
    }
   return true;
}

/**
 * Check configuration process
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 * @return boolean
 */
function plugin_glpisaml_check_config($verbose = false) : bool                                  //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
   if ($verbose) {
      echo __('Installed / not configured', PLUGIN_NAME);
   }
   return (true) ? true : false;
}
