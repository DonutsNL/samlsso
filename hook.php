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

/** This file is included in the GLPI\Plugins\Hooks context. */
use GlpiPlugin\Samlsso\Exclude;
use GlpiPlugin\Samlsso\RuleSaml;
use GlpiPlugin\Samlsso\LoginFlow;
use GlpiPlugin\Samlsso\LoginFlow\User;

/**
 * Register the Composer PSR-4 autoloader unconditionally at hook-file scope.
 *
 * hook.php is included by GLPI before plugin_init_samlsso() runs, which means
 * the autoloader registered inside plugin_init_samlsso() is NOT yet active when
 * GLPI calls plugin_samlsso_install() or plugin_samlsso_uninstall() for a
 * disabled plugin. Without this include the 'use' statements above and the
 * static dispatch ($class::install()) in the lifecycle loops would both fail
 * with a class-not-found error.
 */
include_once __DIR__ . '/vendor/autoload.php';

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
    /**
     * The GLPI rule engine fires all registered rule collections without
     * filtering by type, so we must verify that the sub_type matches our
     * own RuleSaml class before delegating. Dispatching on a wrong sub_type
     * would pass unrelated rule parameters to our handler.
     */
    if ($params['sub_type'] == RuleSaml::class) {
        (new User)->updateUserRights($params);
    }
}


/**
 * Add Excludes to setup dropdown menu.
 *
 * @param void
 * @return array [ClassName => __('Menu label') ]
 */
function plugin_samlsso_getDropdown(): array                                      // NOSONAR - Default GLPI naming
{
    /**
     * Return a class-to-label map that instructs GLPI to surface the Exclude
     * object type inside Setup → Dropdowns, allowing administrators to manage
     * exclusion rules from the standard dropdown configuration UI.
     */
    return [Exclude::class => __("samlSSO exclusions", PLUGIN_NAME)];
}


/**
 * This function is hooked by Hooks::POST_INIT to trigger our loginFlow logic.
 * This hook is registered by setup.php
 *
 * @param void
 * @return void
 */
function plugin_samlsso_evalAuth(): void                                          // NOSONAR - Default GLPI naming
{
    /**
     * Delegate to LoginFlow::doAuth() which evaluates whether the current
     * request should be intercepted for SAML authentication, redirected to an
     * IdP, or allowed to proceed as a standard GLPI login.
     */
    (new LoginFlow())->doAuth();
}


/**
 * This function is hooked by Hooks::DISPLAY_LOGIN to show our custom login form.
 * This hook is registered by setup.php
 *
 * @param void
 * @return void
 */
function plugin_samlsso_displaylogin(): void                                      // NOSONAR - Default GLPI naming
{
    /**
     * Delegate to LoginFlow::showLoginScreen() which injects the plugin's IdP
     * login buttons into the standard GLPI login page output.
     */
    (new LoginFlow())->showLoginScreen();
}


/**
 * Install the samlSSO plugin.
 *
 * Called by GLPI when the administrator clicks "Install" in the Plugin Manager.
 * Iterates through PLUGIN_SAMLSSO_CLASSES in forward (dependency) order so that
 * parent tables are created before child tables that reference them.
 *
 * @return bool  True on success (GLPI expects a bool return value).
 * @see    setup.php  PLUGIN_SAMLSSO_CLASSES for the ordered class registry.
 */
function plugin_samlsso_install(): bool                                           // NOSONAR - Default GLPI naming
{
    /**
     * Initialise the GLPI Migration object scoped to the current plugin version.
     * Migration is used by each class's install() method to perform DDL operations
     * and emit user-visible status messages in the GLPI admin interface.
     */
    $version   = plugin_version_samlsso();
    $migration = new Migration($version['version']);

    Session::addMessageAfterRedirect(__('🆗 Installing version:' . PLUGIN_SAMLSSO_VERSION, PLUGIN_NAME));

    /**
     * Warn if PHP memory_limit is below 512 MB. The OneLogin SAML library
     * may decode and validate large XML responses in-memory, and an insufficient
     * limit can cause silent process crashes during ACS response processing.
     * The check is advisory only — it does not abort the installation.
     */
    $memory_limit = ini_get('memory_limit');
    if ($memory_limit && $memory_limit != -1) {
        $value = trim($memory_limit);
        if ($value !== '') {
            $last = strtolower($value[strlen($value) - 1]);
            $bytes = (int)$value;
            switch ($last) {
                case 'g':
                    $bytes *= 1024;
                case 'm':
                    $bytes *= 1024;
                case 'k':
                    $bytes *= 1024;
            }

            if ($bytes < 536870912) {
                Session::addMessageAfterRedirect(__('⚠️ PHP memory_limit is less than 512M. This may cause crashes during SAML Login. See: ', PLUGIN_NAME) . ' <a href="https://github.com/DonutsNL/samlsso/wiki/Container-crashes-during-SAML-Login" target="_blank">Wiki</a>', false, WARNING);
            }
        }
    }

    /**
     * OpenSSL is needed to verify IdP certificates. It is not listed as a hard
     * prerequisite because the plugin can still function (with reduced security)
     * without it. Emit a warning so the administrator is aware of the gap.
     */
    if (!function_exists('openssl_x509_parse')) {
        Session::addMessageAfterRedirect(__('⚠️ OpenSSL not available, cant verify provided certificates', PLUGIN_NAME));
    } else {
        Session::addMessageAfterRedirect(__('🆗 OpenSSL found!', PLUGIN_NAME));
    }

    /**
     * Iterate through PLUGIN_SAMLSSO_CLASSES in forward (dependency) order and
     * call each class's static install() method. The method_exists() guard makes
     * the loop tolerant of classes that do not manage database tables (e.g. pure
     * service classes that may be added to the array in the future).
     */
    foreach (PLUGIN_SAMLSSO_CLASSES as $class) {
        if (method_exists($class, 'install')) {
            $class::install($migration);
        }
    }
    return true;
}


/**
 * Performs uninstall of plugin classes.
 *
 * @return boolean
 * @see https://codeberg.org/QuinQuies/glpisaml/issues/65
 */
/**
 * Uninstall the samlSSO plugin.
 *
 * Called by GLPI when the administrator clicks "Uninstall" in the Plugin Manager.
 * Iterates through PLUGIN_SAMLSSO_CLASSES in reverse (anti-dependency) order so
 * that child tables referencing parent tables are removed before the parent
 * tables are dropped, preventing foreign-key constraint violations.
 *
 * Each class's uninstall() method is responsible for creating a GLPI backup table
 * before dropping the live table, preserving data for potential recovery.
 *
 * @return bool  True on success (GLPI expects a bool return value).
 * @see    setup.php  PLUGIN_SAMLSSO_CLASSES for the ordered class registry.
 */
function plugin_samlsso_uninstall(): bool                                         // NOSONAR - Default GLPI naming
{
    /**
     * Initialise the GLPI Migration object scoped to the current plugin version.
     * Passed to each class's uninstall() method so backup and drop operations
     * are logged consistently.
     */
    $version   = plugin_version_samlsso();
    $migration = new Migration($version['version']);

    /**
     * array_reverse() ensures child tables (defined later in PLUGIN_SAMLSSO_CLASSES)
     * are removed before the parent tables they reference. The method_exists()
     * guard mirrors the install loop for symmetry and future-proofing.
     */
    foreach (array_reverse(PLUGIN_SAMLSSO_CLASSES) as $class) {
        if (method_exists($class, 'uninstall')) {
            $class::uninstall($migration);
        }
    }
    return true;
}

