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
 *  @version    1.4.0
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
use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;

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

            $criterias[SamlUser::EMAIL]['table']      = '';
            $criterias[SamlUser::EMAIL]['field']      = '';
            $criterias[SamlUser::EMAIL]['name']       = _n('Email', 'Emails', 1);
            $criterias[SamlUser::EMAIL]['linkfield']  = '';
            $criterias[SamlUser::EMAIL]['virtual']    = true;
            $criterias[SamlUser::EMAIL]['id']         = SamlUser::EMAIL;

            $criterias[SamlUser::NAME]['table']             = '';
            $criterias[SamlUser::NAME]['field']             = '';
            $criterias[SamlUser::NAME]['name']              = __('Username', PLUGIN_NAME);
            $criterias[SamlUser::NAME]['linkfield']         = '';
            $criterias[SamlUser::NAME]['virtual']           = true;
            $criterias[SamlUser::NAME]['id']                = SamlUser::NAME;

            $criterias[SamlUser::REALNAME]['table']         = '';
            $criterias[SamlUser::REALNAME]['field']         = '';
            $criterias[SamlUser::REALNAME]['name']          = __('Surname', PLUGIN_NAME);
            $criterias[SamlUser::REALNAME]['linkfield']     = '';
            $criterias[SamlUser::REALNAME]['virtual']       = true;
            $criterias[SamlUser::REALNAME]['id']            = SamlUser::REALNAME;

            $criterias[SamlUser::FIRSTNAME]['table']        = '';
            $criterias[SamlUser::FIRSTNAME]['field']        = '';
            $criterias[SamlUser::FIRSTNAME]['name']         = __('First name', PLUGIN_NAME);
            $criterias[SamlUser::FIRSTNAME]['linkfield']    = '';
            $criterias[SamlUser::FIRSTNAME]['virtual']      = true;
            $criterias[SamlUser::FIRSTNAME]['id']           = SamlUser::FIRSTNAME;

            $criterias[SamlUser::MOBILE]['table']           = '';
            $criterias[SamlUser::MOBILE]['field']           = '';
            $criterias[SamlUser::MOBILE]['name']            = __('Mobile phone', PLUGIN_NAME);
            $criterias[SamlUser::MOBILE]['linkfield']       = '';
            $criterias[SamlUser::MOBILE]['virtual']         = true;
            $criterias[SamlUser::MOBILE]['id']              = SamlUser::MOBILE;

            $criterias[SamlUser::PHONE]['table']            = '';
            $criterias[SamlUser::PHONE]['field']            = '';
            $criterias[SamlUser::PHONE]['name']             = __('Phone', PLUGIN_NAME);
            $criterias[SamlUser::PHONE]['linkfield']        = '';
            $criterias[SamlUser::PHONE]['virtual']          = true;
            $criterias[SamlUser::PHONE]['id']               = SamlUser::PHONE;

            $criterias[SamlUser::SAMLGROUPS]['table']      = '';
            $criterias[SamlUser::SAMLGROUPS]['field']      = '';
            $criterias[SamlUser::SAMLGROUPS]['name']       = __('SAML Groups', PLUGIN_NAME);
            $criterias[SamlUser::SAMLGROUPS]['linkfield']  = '';
            $criterias[SamlUser::SAMLGROUPS]['virtual']    = true;
            $criterias[SamlUser::SAMLGROUPS]['id']         = SamlUser::SAMLGROUPS;

            $criterias[SamlUser::SAMLJOBTITLE]['table']    = '';
            $criterias[SamlUser::SAMLJOBTITLE]['field']    = '';
            $criterias[SamlUser::SAMLJOBTITLE]['name']     = __('SAML Job Title', PLUGIN_NAME);
            $criterias[SamlUser::SAMLJOBTITLE]['linkfield']= '';
            $criterias[SamlUser::SAMLJOBTITLE]['virtual']  = true;
            $criterias[SamlUser::SAMLJOBTITLE]['id']       = SamlUser::SAMLJOBTITLE;

            $criterias[SamlUser::SAMLCOUNTRY]['table']          = '';
            $criterias[SamlUser::SAMLCOUNTRY]['field']          = '';
            $criterias[SamlUser::SAMLCOUNTRY]['name']           = __('SAML Country', PLUGIN_NAME);
            $criterias[SamlUser::SAMLCOUNTRY]['linkfield']      = '';
            $criterias[SamlUser::SAMLCOUNTRY]['virtual']        = true;
            $criterias[SamlUser::SAMLCOUNTRY]['id']             = SamlUser::SAMLCOUNTRY;

            $criterias[SamlUser::SAMLCITY]['table']             = '';
            $criterias[SamlUser::SAMLCITY]['field']             = '';
            $criterias[SamlUser::SAMLCITY]['name']              = __('SAML City', PLUGIN_NAME);
            $criterias[SamlUser::SAMLCITY]['linkfield']         = '';
            $criterias[SamlUser::SAMLCITY]['virtual']           = true;
            $criterias[SamlUser::SAMLCITY]['id']                = SamlUser::SAMLCITY;

            $criterias[SamlUser::SAMLSTREET]['table']           = '';
            $criterias[SamlUser::SAMLSTREET]['field']           = '';
            $criterias[SamlUser::SAMLSTREET]['name']            = __('SAML Street', PLUGIN_NAME);
            $criterias[SamlUser::SAMLSTREET]['linkfield']       = '';
            $criterias[SamlUser::SAMLSTREET]['virtual']         = true;
            $criterias[SamlUser::SAMLSTREET]['id']              = SamlUser::SAMLSTREET;

            $criterias['scimDepartment']['table']        = '';
            $criterias['scimDepartment']['field']        = '';
            $criterias['scimDepartment']['name']         = __('SCIM Department', PLUGIN_NAME);
            $criterias['scimDepartment']['linkfield']    = '';
            $criterias['scimDepartment']['virtual']      = true;
            $criterias['scimDepartment']['id']           = 'scimDepartment';

            $criterias['scimOrganization']['table']      = '';
            $criterias['scimOrganization']['field']      = '';
            $criterias['scimOrganization']['name']       = __('SCIM Organization', PLUGIN_NAME);
            $criterias['scimOrganization']['linkfield']  = '';
            $criterias['scimOrganization']['virtual']    = true;
            $criterias['scimOrganization']['id']         = 'scimOrganization';

            $criterias['scimDivision']['table']          = '';
            $criterias['scimDivision']['field']          = '';
            $criterias['scimDivision']['name']           = __('SCIM Division', PLUGIN_NAME);
            $criterias['scimDivision']['linkfield']      = '';
            $criterias['scimDivision']['virtual']        = true;
            $criterias['scimDivision']['id']             = 'scimDivision';

            $criterias['scimCostCenter']['table']        = '';
            $criterias['scimCostCenter']['field']        = '';
            $criterias['scimCostCenter']['name']         = __('SCIM Cost Center', PLUGIN_NAME);
            $criterias['scimCostCenter']['linkfield']    = '';
            $criterias['scimCostCenter']['virtual']      = true;
            $criterias['scimCostCenter']['id']           = 'scimCostCenter';

            $criterias['scimEmployeeNumber']['table']    = '';
            $criterias['scimEmployeeNumber']['field']    = '';
            $criterias['scimEmployeeNumber']['name']     = __('SCIM Employee Number', PLUGIN_NAME);
            $criterias['scimEmployeeNumber']['linkfield']= '';
            $criterias['scimEmployeeNumber']['virtual']  = true;
            $criterias['scimEmployeeNumber']['id']       = 'scimEmployeeNumber';

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
                $canonicalBuiltins = [
                    ClaimMapItem::FIELD_GROUPS,
                    ClaimMapItem::FIELD_JOBTITLE,
                    ClaimMapItem::FIELD_USERNAME,
                    ClaimMapItem::FIELD_EMAIL,
                ];
                foreach ($iterator as $row) {
                    $field = (string)$row['glpi_field'];
                    if ($field === '' || isset($criterias[$field]) || in_array($field, $canonicalBuiltins, true)) {
                        continue;
                    }
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

        $actions[SamlUser::ENTITY_ID]['name']                         = Entity::getTypeName(1);
        $actions[SamlUser::ENTITY_ID]['type']                         = 'dropdown';
        $actions[SamlUser::ENTITY_ID]['table']                        = 'glpi_entities';

        $actions[SamlUser::PROFILESID]['name']                        = _n('Profile', 'Profiles', Session::getPluralNumber());
        $actions[SamlUser::PROFILESID]['type']                        = 'dropdown';
        $actions[SamlUser::PROFILESID]['table']                       = 'glpi_profiles';

        $actions[SamlUser::PROFILE_RECURSIVE]['name']                 = __('Recursive', PLUGIN_NAME);
        $actions[SamlUser::PROFILE_RECURSIVE]['type']                 = 'yesno';
        $actions[SamlUser::PROFILE_RECURSIVE]['table']                = '';

        $actions[SamlUser::ACTIVE]['name']                            = __('Active', PLUGIN_NAME);
        $actions[SamlUser::ACTIVE]['type']                            = 'yesno';
        $actions[SamlUser::ACTIVE]['table']                           = '';

        $actions[SamlUser::ENTITY_DEFAULT]['table']                   = 'glpi_entities';
        $actions[SamlUser::ENTITY_DEFAULT]['field']                   = 'name';
        $actions[SamlUser::ENTITY_DEFAULT]['name']                    = __('Default entity', PLUGIN_NAME);
        $actions[SamlUser::ENTITY_DEFAULT]['linkfield']               = SamlUser::ENTITY_ID;
        $actions[SamlUser::ENTITY_DEFAULT]['type']                    = 'dropdown';

        $actions[SamlUser::GROUP_DEFAULT]['name']                     = Group::getTypeName(Session::getPluralNumber());
        $actions[SamlUser::GROUP_DEFAULT]['type']                     = 'dropdown';
        $actions[SamlUser::GROUP_DEFAULT]['table']                    = 'glpi_groups';

        $actions[SamlUser::GROUPID]['table']                          = 'glpi_groups';
        $actions[SamlUser::GROUPID]['field']                          = 'name';
        $actions[SamlUser::GROUPID]['name']                           = __('Default group', PLUGIN_NAME);
        $actions[SamlUser::GROUPID]['linkfield']                      = SamlUser::GROUPID;
        $actions[SamlUser::GROUPID]['type']                           = 'dropdown';
        $actions[SamlUser::GROUPID]['condition']                      = ['is_usergroup' => 1];

        $actions[SamlUser::PROFILE_DEFAULT]['table']                  = 'glpi_profiles';
        $actions[SamlUser::PROFILE_DEFAULT]['field']                  = 'name';
        $actions[SamlUser::PROFILE_DEFAULT]['name']                   = __('Default profile', PLUGIN_NAME);
        $actions[SamlUser::PROFILE_DEFAULT]['linkfield']              = SamlUser::PROFILESID;
        $actions[SamlUser::PROFILE_DEFAULT]['type']                   = 'dropdown';

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
