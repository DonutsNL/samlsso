<?php
declare(strict_types=1);
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

namespace GlpiPlugin\Samlsso;

use Rule;
use Group;
use Entity;
use Session;
use Profile;

class RuleSaml extends Rule
{
    /**
     * Define Rights
     * defines the rights a user must posses to be able to access this menu option in the rules section
     **/
    static $rightname = 'rule_import';

    /**
     *
     **/
    public $can_sort = true;            //NOSONAR

    /**
     * Define order
     * defines how to order the list
     **/
    public $orderby   = "name";

    /**
     * getTitle
     *
     * @return string Title to use in Rules list
     **/
    public function getTitle()
    {
        return __('JIT rules', PLUGIN_NAME);
    }

    /**
     * getIcon
     * @return string icon to use in rules list
     * @see Free icon set of FontAwesome for valid Icons
     **/
    public static function getIcon()
    {
        return Profile::getIcon();
    }

    public function invoke()
    {
        $rulecollection = new RuleSamlCollection();
        include_once  GLPI_ROOT . "/front/rule.common.php";                                      // NOSONAR - Cant be included with USE.
    }

    public function invokeForm()
    {
        $rulecollection = new RuleSamlCollection();
        include_once  GLPI_ROOT . "/front/rule.common.form.php";                                 // NOSONAR - Cant be included with USE.
    }


    /**
     * @see Rule::getCriterias()
     * @return array    returns available criteria
     **/
    public function getCriterias()
    {
        static $criterias = [];

        if (!count($criterias)) {
            $criterias['common']                    = __('Global criteria');
            $criterias['_useremails']['table']      = '';
            $criterias['_useremails']['field']      = '';
            $criterias['_useremails']['name']       = _n('Email', 'Emails', 1);
            $criterias['_useremails']['linkfield']  = '';
            $criterias['_useremails']['virtual']    = true;
            $criterias['_useremails']['id']         = '_useremails';
            
        }
        return $criterias;
    }

    /**
     * @see Rule::getActions()
     **/
    public function getActions()
    {

        $actions                                                = parent::getActions();

        $actions['entities_id']['name']                         = Entity::getTypeName(1);
        $actions['entities_id']['type']                         = 'dropdown';
        $actions['entities_id']['table']                        = 'glpi_entities';

        $actions['profiles_id']['name']                         = _n('Profile', 'Profiles', Session::getPluralNumber());
        $actions['profiles_id']['type']                         = 'dropdown';
        $actions['profiles_id']['table']                        = 'glpi_profiles';

        $actions['is_recursive']['name']                        = __('Recursive');
        $actions['is_recursive']['type']                        = 'yesno';
        $actions['is_recursive']['table']                       = '';

        $actions['is_active']['name']                           = __('Active');
        $actions['is_active']['type']                           = 'yesno';
        $actions['is_active']['table']                          = '';

        $actions['_entities_id_default']['table']                = 'glpi_entities';
        $actions['_entities_id_default']['field']               = 'name';
        $actions['_entities_id_default']['name']                = __('Default entity');
        $actions['_entities_id_default']['linkfield']           = 'entities_id';
        $actions['_entities_id_default']['type']                = 'dropdown';

        $actions['specific_groups_id']['name']                  = Group::getTypeName(Session::getPluralNumber());
        $actions['specific_groups_id']['type']                  = 'dropdown';
        $actions['specific_groups_id']['table']                 = 'glpi_groups';

        $actions['groups_id']['table']                        = 'glpi_groups';
        $actions['groups_id']['field']                        = 'name';
        $actions['groups_id']['name']                         = __('Default group');
        $actions['groups_id']['linkfield']                    = 'groups_id';
        $actions['groups_id']['type']                         = 'dropdown';
        $actions['groups_id']['condition']                    = ['is_usergroup' => 1];

        $actions['_profiles_id_default']['table']             = 'glpi_profiles';
        $actions['_profiles_id_default']['field']             = 'name';
        $actions['_profiles_id_default']['name']              = __('Default profile');
        $actions['_profiles_id_default']['linkfield']         = 'profiles_id';
        $actions['_profiles_id_default']['type']              = 'dropdown';

        $actions['timezone']['name']                          = __('Timezone');
        $actions['timezone']['type']                          = 'timezone';

        return $actions;
    }
}
