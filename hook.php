<?php
/**
 *  ------------------------------------------------------------------------
 *  glpisaml plugin
 *
 *  GLPISaml was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPISaml project.
 *
 * GLPISaml plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * GLPISaml is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with GLPISaml. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    GLPISaml
 *  @version    1.1.6
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/GLPISaml/readme.md
 *  @link       https://github.com/DonutsNL/GLPISaml
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

use GlpiPlugin\Glpisaml\Exclude;
use GlpiPlugin\Glpisaml\RuleSaml;
use GlpiPlugin\Glpisaml\LoginFlow;
use GlpiPlugin\Glpisaml\LoginFlow\User;

/**
 * Hooked by rule engine if an user import rule matches
 * sadly we cannot call the User::updateUser() method directly
 * from the hook itself.
 * @see setup.php
 * @see src\LoginFlow\User.php
 */
function updateUser(array $params): void
{
    // Only call the update if sub_type is our ruleSaml::class
    // https://codeberg.org/QuinQuies/glpisaml/issues/55
    if($params['sub_type'] == RuleSaml::class) {
        // Call the update User method
        (new User)->updateUserRights($params);
    }
}

/**
 * Add Excludes to setup dropdown menu.
 * @return array [ClassName => __('Menu label') ]
 */
function plugin_glpisaml_getDropdown() : array                                      //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
   return [Exclude::class => __("SAML exclusions", PLUGIN_NAME)];
}

/**
 * function to inject the loginFlow logic
 * @return void
 */
function plugin_glpisaml_evalAuth() : void                                          //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    // Call the evalAuth hook;
    (new LoginFlow())->doAuth();
}

/**
 * function to inject the loginFlow show login form.
 * @return void
 */
function plugin_glpisaml_displaylogin() : void                                      //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    // Call the showLoginScreen method
    (new LoginFlow())->showLoginScreen();
}

/**
 * Performs install of plugin classes in /src.
 *
 * @return boolean
 * @see https://codeberg.org/QuinQuies/glpisaml/issues/65
 */
//phpcs:ignore PSR1.Function.CamelCapsMethodName
function plugin_glpisaml_install() : bool                                           //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    // Init the migration object
    $version   = plugin_version_glpisaml();
    $migration = new Migration($version['version']);

    // Report the version we are installing
    Session::addMessageAfterRedirect(__('🆗 Installing version:'.PLUGIN_GLPISAML_VERSION));

    // openssl is nice to have!
    if (!function_exists('openssl_x509_parse')){
        Session::addMessageAfterRedirect(__('⚠️ OpenSSL not available, cant verify provided certificates'));
    }else{
        Session::addMessageAfterRedirect(__('🆗 OpenSSL found!'));
    }

    if($files = plugin_glpisaml_getSrcClasses())
    {
        if(is_array($files)) {                                                      //NOSONAR
            foreach($files as $name){
                $class = "GlpiPlugin\\Glpisaml\\" . basename($name, '.php');
                if(method_exists($class, 'install')){
                    $class::install($migration);
                }
            }
        } // We are not handling an empty array on error;
    }
    return true;
}

/**
 * Performs uninstall of plugin classes in /src.
 *
 * @return boolean
 * @see https://codeberg.org/QuinQuies/glpisaml/issues/65
 */
function plugin_glpisaml_uninstall() : bool                                         //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    if($files = plugin_glpisaml_getSrcClasses()) {
        if(is_array($files)) {                                                      //NOSONAR
            foreach($files as $name){
                $class = "GlpiPlugin\\Glpisaml\\" . basename($name, '.php');
                if(method_exists($class, 'install')){
                    $version   = plugin_version_glpisaml();
                    $migration = new Migration($version['version']);
                    $class::uninstall($migration);
                }
            }
        }   // We are not handling an empty array on error;
    }
    return true;
}

/**
 * Fetches all classes from the plugin \src directory
 * Used by installation.
 *
 * @return array
 */
function plugin_glpisaml_getSrcClasses() : array                                    //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
{
    if(is_dir(PLUGIN_GLPISAML_SRCDIR)       &&
       is_readable(PLUGIN_GLPISAML_SRCDIR)  ){
        return array_filter(scandir(PLUGIN_GLPISAML_SRCDIR, SCANDIR_SORT_NONE), function($item) {
            return !is_dir(PLUGIN_GLPISAML_SRCDIR.'/'.$item);
        });
    }else{
        echo "The directory". PLUGIN_GLPISAML_SRCDIR . "Is not accessible, Plugin installation failed!";
        return [];
    }
}