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
 *  @version    1.2.4
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

// This file is included in the GLPI\Plugins\Hooks context.
// USE
use GlpiPlugin\Samlsso\Exclude;
use GlpiPlugin\Samlsso\RuleSaml;
use GlpiPlugin\Samlsso\LoginFlow;
use GlpiPlugin\Samlsso\LoginFlow\User;

// METHODS
/**
 * This function is hooked by rule engine if an user import rule matches configured criteria.
 * it will call the implementation with the params passed by the ruleEngine.
 *
 * @param array $params
 * @return void
 *
 * @see - rgst - setup.php->plugin_init_samlsso();
 * @see - call - src\LoginFlow\User.php->getOrCreateUser();
 * @see - impl - src\LoginFlow\User.php->updateUserRights();
 */
function updateUser(array $params): void
{
    // RuleEngine does not discriminate rulesets on execution
    // so validate sub_type is correct class before executing.
    if( $params['sub_type'] == RuleSaml::class ){
        // Pass the params to the updateUserRight method non statically.
        (new User)->updateUserRights($params);
    }
}


/**
 * Add Excludes to setup dropdown menu.
 *
 * @param void
 * @return array [ClassName => __('Menu label') ]
 */
function plugin_samlsso_getDropdown() : array                                      // NOSONAR - Default GLPI naming
{
   // Tell GLPI to add Excludes to Setup>dropdowns
   return [Exclude::class => __("samlSSO exclusions", PLUGIN_NAME)];
}


/**
 * This function is hooked by Hooks::POST_INIT to trigger our loginFlow logic.
 * This hook is registered by setup.php
 *
 * @param void
 * @return void
 */
function plugin_samlsso_evalAuth() : void                                          // NOSONAR - Default GLPI naming
{
    // Call the evalAuth hook;
    (new LoginFlow())->doAuth();
}


/**
 * This function is hooked by Hooks::DISPLAY_LOGIN to show our custom login form.
 * This hook is registered by setup.php
 *
 * @param void
 * @return void
 */
function plugin_samlsso_displaylogin() : void                                      // NOSONAR - Default GLPI naming
{
    // Call the showLoginScreen method
    (new LoginFlow())->showLoginScreen();
}


/**
 * This function performs install of all plugin classes.
 *
 * @param void
 * @return boolean
 */
function plugin_samlsso_install() : bool                                           // NOSONAR - Default GLPI naming
{
    // Init the migration object
    $version   = plugin_version_samlsso();
    $migration = new Migration($version['version']);

    // Report the version we are installing
    Session::addMessageAfterRedirect(__('🆗 Installing version:'.PLUGIN_SAMLSSO_VERSION));

    // openssl is nice to have therefore it is not included in the prerequisites.
    if ( !function_exists('openssl_x509_parse') ) {
        Session::addMessageAfterRedirect( __('⚠️ OpenSSL not available, cant verify provided certificates') );
    } else {
        Session::addMessageAfterRedirect( __('🆗 OpenSSL found!') );
    }

    // Traverse pkugin files and call install methods if they exist within the class.
    if( $files = plugin_samlsso_getSrcClasses() ){
        if( is_array($files) ){                                                      // NOSONAR - For readability ifs nested.
            foreach( $files as $name ){
                $class = "GlpiPlugin\\Samlsso\\" . basename($name, '.php');
                if( method_exists($class, 'install') ){
                    $class::install($migration);
                }
            }
        } // Should never be emtpy, but not handling that.
    } // Should never be emtpy, but not handling that.
    return true;
}


/**
 * Performs uninstall of plugin classes in /src.
 *
 * @return boolean
 * @see https://codeberg.org/QuinQuies/glpisaml/issues/65
 */
function plugin_samlsso_uninstall() : bool                                         // NOSONAR - Default GLPI naming
{
    if( $files = plugin_samlsso_getSrcClasses() ) {
        if( is_array($files) ){                                                     // NOSONAR - For readability ifs nested.
            foreach( $files as $name ){
                $class = "GlpiPlugin\\Samlsso\\" . basename($name, '.php');
                if( method_exists($class, 'install') ){
                    $version   = plugin_version_samlsso();
                    $migration = new Migration($version['version']);
                    $class::uninstall($migration);
                }
            }
        } // Should never be emtpy, but not handling that.
    } // Should never be emtpy, but not handling that.
    return true;
}

/**
 * Fetches all classes from the plugin \src directory
 * Used by installation.
 *
 * @return array
 */
function plugin_samlsso_getSrcClasses() : array                                    // NOSONAR - Default GLPI naming
{
    if( is_dir(PLUGIN_SAMLSSO_SRCDIR) && is_readable(PLUGIN_SAMLSSO_SRCDIR) ){
        return array_filter(scandir(PLUGIN_SAMLSSO_SRCDIR, SCANDIR_SORT_NONE), function($item) {
            return !is_dir(PLUGIN_SAMLSSO_SRCDIR.'/'.$item);
        });
    } else {
        echo "The directory". PLUGIN_SAMLSSO_SRCDIR . "Is not accessible, Plugin installation failed!";
        return [];
    }
}