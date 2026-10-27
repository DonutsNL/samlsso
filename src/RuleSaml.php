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
 *  @version    1.3.2
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso;

use Group;
use Entity;
use Session;
use Profile;
use Location;
use UserCategory;
use UserTitle;

use GlpiPlugin\Samlsso\Config\ClaimMapItem;
use GlpiPlugin\Samlsso\Compat\RuleSamlCompat;

// Version-selected compatibility layer (GLPI 11 untyped / GLPI 12 typed core
// properties). Must be required before the class below extends RuleSamlCompat.
require_once __DIR__ . '/compat.php';

/**
 * Rule executed on JIT provisioning, extending the core Rule object through
 * the version-selected compatibility layer: $rightname ('rule_ldap') is
 * carried by RuleSamlCompat, version-typed for GLPI 11 and GLPI 12.
 **/
class RuleSaml extends RuleSamlCompat
{

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

    /**
     * Invokes the JIT rules management list interface.
     *
     * @return void
     * @since 1.0.0
     */
    public function invoke(): void
    {
        Session::checkRight(self::$rightname, READ);
        $rulecollection = new RuleSamlCollection();
        include_once  GLPI_ROOT . "/front/rule.common.php";                                      // NOSONAR - Cant be included with USE.
    }

    /**
     * Invokes the rule details form.
     *
     * @return void
     * @since 1.0.0
     */
    public function invokeForm(): void
    {
        Session::checkRight(self::$rightname, READ);
        $rulecollection = new RuleSamlCollection();
        include_once  GLPI_ROOT . "/front/rule.common.form.php";                                 // NOSONAR - Cant be included with USE.
    }


    public function getCriterias(): array
    {
        static $criterias = [];

        if (!count($criterias)) {
            $criterias['common']                    = __('Global criteria', PLUGIN_NAME);

            $criterias['_useremails']['table']      = '';
            $criterias['_useremails']['field']      = '';
            $criterias['_useremails']['name']       = _n('Email', 'Emails', 1);
            $criterias['_useremails']['linkfield']  = '';
            $criterias['_useremails']['virtual']    = true;
            $criterias['_useremails']['id']         = '_useremails';

            $criterias['name']['table']             = '';
            $criterias['name']['field']             = '';
            $criterias['name']['name']              = __('Username', PLUGIN_NAME);
            $criterias['name']['linkfield']         = '';
            $criterias['name']['virtual']           = true;
            $criterias['name']['id']                = 'name';

            $criterias['realname']['table']         = '';
            $criterias['realname']['field']         = '';
            $criterias['realname']['name']          = __('Surname', PLUGIN_NAME);
            $criterias['realname']['linkfield']     = '';
            $criterias['realname']['virtual']       = true;
            $criterias['realname']['id']            = 'realname';

            $criterias['firstname']['table']        = '';
            $criterias['firstname']['field']        = '';
            $criterias['firstname']['name']         = __('First name', PLUGIN_NAME);
            $criterias['firstname']['linkfield']    = '';
            $criterias['firstname']['virtual']      = true;
            $criterias['firstname']['id']           = 'firstname';

            $criterias['mobile']['table']           = '';
            $criterias['mobile']['field']           = '';
            $criterias['mobile']['name']            = __('Mobile phone', PLUGIN_NAME);
            $criterias['mobile']['linkfield']       = '';
            $criterias['mobile']['virtual']         = true;
            $criterias['mobile']['id']              = 'mobile';

            $criterias['phone']['table']            = '';
            $criterias['phone']['field']            = '';
            $criterias['phone']['name']             = __('Phone', PLUGIN_NAME);
            $criterias['phone']['linkfield']        = '';
            $criterias['phone']['virtual']          = true;
            $criterias['phone']['id']               = 'phone';

            $criterias['samlClaimedGroups']['table']      = '';
            $criterias['samlClaimedGroups']['field']      = '';
            $criterias['samlClaimedGroups']['name']       = __('SAML Groups', PLUGIN_NAME);
            $criterias['samlClaimedGroups']['linkfield']  = '';
            $criterias['samlClaimedGroups']['virtual']    = true;
            $criterias['samlClaimedGroups']['id']         = 'samlClaimedGroups';

            $criterias['samlClaimedJobTitle']['table']    = '';
            $criterias['samlClaimedJobTitle']['field']    = '';
            $criterias['samlClaimedJobTitle']['name']     = __('SAML Job Title', PLUGIN_NAME);
            $criterias['samlClaimedJobTitle']['linkfield']= '';
            $criterias['samlClaimedJobTitle']['virtual']  = true;
            $criterias['samlClaimedJobTitle']['id']       = 'samlClaimedJobTitle';

            $criterias['country']['table']          = '';
            $criterias['country']['field']          = '';
            $criterias['country']['name']           = __('SAML Country', PLUGIN_NAME);
            $criterias['country']['linkfield']      = '';
            $criterias['country']['virtual']        = true;
            $criterias['country']['id']             = 'country';

            $criterias['city']['table']             = '';
            $criterias['city']['field']             = '';
            $criterias['city']['name']              = __('SAML City', PLUGIN_NAME);
            $criterias['city']['linkfield']         = '';
            $criterias['city']['virtual']           = true;
            $criterias['city']['id']                = 'city';

            $criterias['street']['table']           = '';
            $criterias['street']['field']           = '';
            $criterias['street']['name']            = __('SAML Street', PLUGIN_NAME);
            $criterias['street']['linkfield']       = '';
            $criterias['street']['virtual']         = true;
            $criterias['street']['id']              = 'street';

            global $DB;
            if (isset($DB) && method_exists($DB, 'tableExists') && $DB->tableExists(ClaimMap::getTable())) {
                $claimMapTable = ClaimMap::getTable();
                $iterator = $DB->request([
                    'SELECT'   => ['glpi_field'],
                    'DISTINCT' => true,
                    'FROM'     => $claimMapTable,
                    'WHERE'    => [
                        'target_type' => ClaimMapItem::TARGET_TYPE_RULE_FIELD
                    ]
                ]);
                foreach ($iterator as $row) {
                    $field = (string)$row['glpi_field'];
                    $criterias[$field] = [
                        'table'     => '',
                        'field'     => '',
                        'name'      => sprintf(__('SAML Claim: %s', PLUGIN_NAME), ucfirst($field)),
                        'linkfield' => '',
                        'virtual'   => true,
                        'id'        => $field
                    ];
                }
            }
        }
        return $criterias;
    }

    /**
     * @see Rule::getActions()
     **/
    public function getActions(): array
    {

        $actions                                                = parent::getActions();

        $actions['entities_id']['name']                         = Entity::getTypeName(1);
        $actions['entities_id']['type']                         = 'dropdown';
        $actions['entities_id']['table']                        = 'glpi_entities';

        $actions['profiles_id']['name']                         = _n('Profile', 'Profiles', Session::getPluralNumber());
        $actions['profiles_id']['type']                         = 'dropdown';
        $actions['profiles_id']['table']                        = 'glpi_profiles';

        $actions['is_recursive']['name']                        = __('Recursive', PLUGIN_NAME);
        $actions['is_recursive']['type']                        = 'yesno';
        $actions['is_recursive']['table']                       = '';

        $actions['is_active']['name']                           = __('Active', PLUGIN_NAME);
        $actions['is_active']['type']                           = 'yesno';
        $actions['is_active']['table']                          = '';

        $actions['_entities_id_default']['table']                = 'glpi_entities';
        $actions['_entities_id_default']['field']               = 'name';
        $actions['_entities_id_default']['name']                = __('Default entity', PLUGIN_NAME);
        $actions['_entities_id_default']['linkfield']           = 'entities_id';
        $actions['_entities_id_default']['type']                = 'dropdown';

        $actions['specific_groups_id']['name']                  = Group::getTypeName(Session::getPluralNumber());
        $actions['specific_groups_id']['type']                  = 'dropdown';
        $actions['specific_groups_id']['table']                 = 'glpi_groups';

        $actions['groups_id']['table']                        = 'glpi_groups';
        $actions['groups_id']['field']                        = 'name';
        $actions['groups_id']['name']                         = __('Default group', PLUGIN_NAME);
        $actions['groups_id']['linkfield']                    = 'groups_id';
        $actions['groups_id']['type']                         = 'dropdown';
        $actions['groups_id']['condition']                    = ['is_usergroup' => 1];

        $actions['_profiles_id_default']['table']             = 'glpi_profiles';
        $actions['_profiles_id_default']['field']             = 'name';
        $actions['_profiles_id_default']['name']              = __('Default profile', PLUGIN_NAME);
        $actions['_profiles_id_default']['linkfield']         = 'profiles_id';
        $actions['_profiles_id_default']['type']              = 'dropdown';

        $actions['timezone']['name']                          = __('Timezone', PLUGIN_NAME);
        $actions['timezone']['type']                          = 'timezone';

        $actions['locations_id']['name']                      = Location::getTypeName(Session::getPluralNumber());
        $actions['locations_id']['type']                      = 'dropdown';
        $actions['locations_id']['table']                     = 'glpi_locations';

        $actions['usercategories_id']['name']                  = UserCategory::getTypeName(Session::getPluralNumber());
        $actions['usercategories_id']['type']                  = 'dropdown';
        $actions['usercategories_id']['table']                 = 'glpi_usercategories';

        $actions['usertitles_id']['name']                     = UserTitle::getTypeName(Session::getPluralNumber());
        $actions['usertitles_id']['type']                     = 'dropdown';
        $actions['usertitles_id']['table']                    = 'glpi_usertitles';

        $actions['language']['name']                          = __('Language', PLUGIN_NAME);
        $actions['language']['type']                          = 'language';

        return $actions;
    }
}
