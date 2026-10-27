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
 *  Copyright (C) 2026 by DonutsNL
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
 *  @since      1.3.2
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

/**
 * compat.php
 *
 * Version-selected compatibility layer for GLPI 11 and GLPI 12.
 *
 * GLPI 12 typed the core properties plugins commonly redeclare
 * (CommonGLPI::$rightname, CommonDBTM::$dohistory, RuleCollection::$menu_option).
 * PHP enforces property type invariance across the whole hierarchy, in BOTH
 * directions:
 *   - untyped child over typed parent -> "Type of C::$rightname must be string
 *     (as in class P)";
 *   - typed child over untyped parent -> "Type of C::$rightname must not be
 *     defined (as in class P)".
 * A single declaration inside a plugin class can therefore never satisfy GLPI 11
 * (untyped parents) and GLPI 12 (typed parents) at once: whichever typing is
 * chosen, loading that class on the other version is a fatal compile error.
 * Traits do not help either, because a trait property whose default differs from
 * the inherited one is fatal too.
 *
 * The only safe shape is one abstract intermediate per plugin class, declared
 * once per GLPI major version with EXACTLY the parent typing, and selected at
 * load time from GLPI_VERSION. The concrete plugin classes extend the
 * intermediate and redeclare nothing that collides with the core, so plugin
 * sources stay free of any conditional code.
 *
 * One intermediate per concrete class (never a shared one) is mandatory: static
 * properties such as $rightname live in the declaring class, and sharing one
 * intermediate between two plugin classes would merge their $rightname slots.
 *
 * Require this file (require_once) from every plugin class that extends a GLPI
 * core class and needs one of the properties above. It is idempotent: function
 * and class guards allow any number of inclusions.
 */

namespace GlpiPlugin\Samlsso\Compat {

    use CommonDBTM;
    use CommonDropdown;
    use Rule;
    use RuleCollection;

    if (!function_exists('GlpiPlugin\Samlsso\Compat\samlsso_glpi12')) {
        /**
         * Tells whether the running GLPI core is version 12 or above.
         *
         * GLPI_VERSION is defined by the core bootstrap
         * (src/autoload/constants.php) long before any plugin class loads. When
         * it is missing (standalone tooling, unit-test shims) the pre-12 untyped
         * shape is assumed, which matches those untyped parents.
         *
         * @return bool True on GLPI >= 12.0.0.
         */
        function samlsso_glpi12(): bool
        {
            return defined('GLPI_VERSION') && version_compare((string) GLPI_VERSION, '12.0.0', '>=');
        }
    }

    if (samlsso_glpi12()) {
        // ------------------------------------------------------------
        // GLPI >= 12 : parents are typed. Intermediates repeat the parent's
        // exact type (invariance); defaults may differ from the parent.
        // Verified against GLPI 12.0.0:
        //   CommonGLPI     :: public static string $rightname = '';
        //   CommonDBTM     :: public bool $dohistory = false;   (via CommonGLPI)
        //   CommonDropdown :: public static string $rightname = 'dropdown';
        //   Rule           :: public static string $rightname = 'config';
        //   RuleCollection :: public static string $rightname = 'config';
        //   RuleCollection :: public string $menu_option = "";
        // ------------------------------------------------------------

        /**
         * Config intermediate: 'config' rights + history on, GLPI 12 typing.
         */
        abstract class ConfigCompat extends CommonDBTM
        {
            public bool $dohistory = true;
            public static string $rightname = 'config';
            public static array $undisclosedFields = ['sp_private_key'];
        }

        /**
         * LoginFlow intermediate: 'config' rights + history on, GLPI 12 typing.
         */
        abstract class LoginFlowCompat extends CommonDBTM
        {
            public bool $dohistory = true;
            public static string $rightname = 'config';
        }

        /**
         * Exclude intermediate: dropdown item using 'config' rights, GLPI 12 typing.
         * $dohistory repeats the CommonDropdown default (same type, same value) so
         * the behaviour also holds against untyped test doubles of the parent.
         */
        abstract class ExcludeCompat extends CommonDropdown
        {
            public bool $dohistory = true;
            public static string $rightname = 'config';
        }

        /**
         * RuleSaml intermediate: user-rights-classified rule, GLPI 12 typing.
         */
        abstract class RuleSamlCompat extends Rule
        {
            public static string $rightname = 'rule_ldap';
        }

        /**
         * RuleSamlCollection intermediate: collection, GLPI 12 typing.
         * $stop_on_first_match keeps the parent default (false): not redeclared.
         * Guarded on the parent: unit-test doubles omit RuleCollection.
         */
        if (class_exists('RuleCollection')) {
            abstract class RuleSamlCollectionCompat extends RuleCollection
            {
                public static string $rightname = 'rule_ldap';
                public string $menu_option = "Saml";
            }
        }
    } else {
        // ------------------------------------------------------------
        // GLPI < 12 (and the untyped unit-test shims): parents are untyped.
        // Intermediates repeat that untyped shape, since PHP forbids ADDING a
        // type over an untyped parent property.
        // Verified against GLPI 11.0.x:
        //   CommonGLPI     :: public static $rightname = '';
        //   CommonDBTM     :: public $dohistory = false;         (via CommonGLPI)
        //   CommonDropdown :: public static $rightname = 'dropdown';
        //   Rule           :: public static $rightname = 'config';
        //   RuleCollection :: public static $rightname = 'config';
        //   RuleCollection :: public $menu_option = "";
        // ------------------------------------------------------------

        /**
         * Config intermediate: 'config' rights + history on, GLPI 11 shape.
         */
        abstract class ConfigCompat extends CommonDBTM
        {
            public $dohistory = true;
            public static $rightname = 'config';
            public static $undisclosedFields = ['sp_private_key'];
        }

        /**
         * LoginFlow intermediate: 'config' rights + history on, GLPI 11 shape.
         */
        abstract class LoginFlowCompat extends CommonDBTM
        {
            public $dohistory = true;
            public static $rightname = 'config';
        }

        /**
         * Exclude intermediate: dropdown item using 'config' rights, GLPI 11 shape.
         * $dohistory repeats the CommonDropdown default so the behaviour also
         * holds against test doubles of the parent.
         */
        abstract class ExcludeCompat extends CommonDropdown
        {
            public $dohistory = true;
            public static $rightname = 'config';
        }

        /**
         * RuleSaml intermediate: user-rights-classified rule, GLPI 11 shape.
         */
        abstract class RuleSamlCompat extends Rule
        {
            public static $rightname = 'rule_ldap';
        }

        /**
         * RuleSamlCollection intermediate: collection, GLPI 11 shape.
         * $stop_on_first_match keeps the parent default (false): not redeclared.
         * Guarded on the parent: unit-test doubles omit RuleCollection.
         */
        if (class_exists('RuleCollection')) {
            abstract class RuleSamlCollectionCompat extends RuleCollection
            {
                public static $rightname = 'rule_ldap';
                public $menu_option = "Saml";
            }
        }

    }

}
