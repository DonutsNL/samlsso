<?php

/**
 *  ------------------------------------------------------------------------
 *  GLPISaml
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

 /**
 * Be careful with PSR4 Namespaces when extending common GLPI objects.
 * Only Characters are allowed in namespaces extending glpi Objects.
 * @see https://github.com/pluginsGLPI/example/issues/51
 * @see https://github.com/DonutsNL/phpsaml2/issues/6
 */
namespace GlpiPlugin\Samlsso;

use Session;
use Migration;
use CommonDBTM;
use DBConnection;
use GlpiPlugin\Samlsso\Config\ConfigItem;
use GlpiPlugin\Samlsso\Config\ConfigEntity;

/**
 * Class Handles the installation and listing of configuration front/config.php
 * is is also the baseClass that extends the CommonDBTM GLPI object. All other
 * glpisaml config classes reference this class for CRUD operations on the config
 * database.
 */
class Config extends CommonDBTM
{
    /**
     * Tell DBTM to keep history
     * @var    bool     - $dohistory
     */
    public $dohistory = true;

    /**
     * Tell CommonGLPI to use config (Setup->Setup in UI) rights.
     * @var    string   - $rightname
     */
    public static $rightname = 'config';

    /**
     * Overloads missing canCreate Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canCreate(): bool
    {
        return static::canUpdate();
    }

    /**
     * Overloads missing canDelete Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canDelete(): bool
    {
        return static::canUpdate();
    }

    /**
     * Overloads missing canPurge Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canPurge(): bool
    {
        return static::canUpdate();
    }

    /**
     * returns class friendly TypeName
     * @param  int      - $nb return plural or singular friendly name.
     * @return string   - returns translated friendly name.
     */
    public static function getTypeName($nb = 0): string
    {
        return __('Saml SSO applications', PLUGIN_NAME);
    }

    /**
     * Returns class icon to use in menus and tabs
     *
     * @return string   - returns Font Awesome icon className.
     * @see             - https://fontawesome.com/search
     */
    public static function getIcon(): string
    {
        return 'fa-fw fas fa-sign-in-alt';
    }

    /**
     * Added links for user convenience
     * @see CommonGLPI::getAdditionalMenuLinks()
     * @see https://codeberg.org/QuinQuies/glpisaml/issues/8
     **/
    public static function getAdditionalMenuLinks() {
        $links[__('Excluded paths', PLUGIN_NAME)] = PLUGIN_GLPISAML_WEBDIR.'/front/exclude.php';
        $links[__('JIT import rules', PLUGIN_NAME)] = PLUGIN_GLPISAML_WEBDIR.'/front/rulesaml.php';
        return $links;
    }

    /**
     * Provides search options for DBTM.
     * Do not rely on this, @see CommonDBTM::searchOptions instead.
     *
     * @return array  $tab  - returns searchOptions
     * @see                 - https://glpi-developer-documentation.readthedocs.io/en/master/devapi/search.html
     * @see                 - https://codeberg.org/QuinQuies/glpisaml/issues/9
     * @see                 - https://codeberg.org/QuinQuies/glpisaml/issues/11
     */
    function rawSearchOptions(): array                          //NOSONAR - phpcs:ignore PSR1.Function.CamelCapsMethodName
    {
        $tab[] = [
            'id'                 => '1',                        // By GLPI convention Name field should have ID 1.
            'table'              => $this->getTable(),
            'field'              => ConfigEntity::NAME,
            'name'               => __('Name'),
            'massiveaction'      => false,
            'datatype'           => 'itemlink'
        ];
        $tab[] = [
            'id'                 => '2',                        // By GLPI convention ID field should have ID 2.
            'table'              => $this->getTable(),
            'field'              => ConfigEntity::ID,
            'name'               => __('ID'),
            'massiveaction'      => false, // implicit field is id
            'datatype'           => 'itemlink'
        ];
        $tab[] = [
            'id'                 => '3',                        // If this was the glpi entities_id the id should by convention be ID `86`
            'table'              => $this->getTable(),
            'field'              => ConfigEntity::IDP_ENTITY_ID,
            'name'               => __('Idp entity ID'),
            'massiveaction'      => false,
            'datatype'           => 'text'
        ];
        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => ConfigEntity::IS_ACTIVE,
            'name'               => __('Is active'),
            'massiveaction'      => false,
            'datatype'           => 'bool'
        ];

        // Lets not be as verbose as default GLPI objects when we do not need to.
        // continue tabId index where we left off.
        $index = 5;
        foreach((new ConfigEntity())->getFields() as $field)
        {
            $field['list'] = false;
           // skip the following fields
            if($field[ConfigItem::FIELD] != ConfigEntity::ID            &&
               $field[ConfigItem::FIELD] != ConfigEntity::NAME          &&
               $field[ConfigItem::FIELD] != ConfigEntity::IDP_ENTITY_ID &&
               $field[ConfigItem::FIELD] != ConfigEntity::IS_ACTIVE     &&
               $field[ConfigItem::FIELD] != ConfigEntity::IS_DELETED    ){
                // Remap DB fields to Search dataTypes
                if(strstr($field[ConfigItem::TYPE], 'varchar') ){
                    $field[ConfigItem::TYPE] = 'string';
                }elseif($field[ConfigItem::TYPE] == 'tinyint' ){
                    $field[ConfigItem::TYPE] = 'bool';
                }elseif($field[ConfigItem::TYPE] == 'text' ){
                    $field[ConfigItem::TYPE] = 'text';
                }elseif($field[ConfigItem::TYPE] == 'timestamp' ){
                    $field[ConfigItem::TYPE] = 'date';
                }elseif(strstr($field[ConfigItem::TYPE], 'int') ){
                    $field[ConfigItem::TYPE] = 'number';
                }
                // Build tab array
                $tab[] = [
                    'id'                 => $index,
                    'table'              => Config::getTable(),
                    'field'              => $field[ConfigItem::FIELD],
                    'name'               => __(str_replace('_', ' ', ucfirst($field[ConfigItem::FIELD]))),
                    'datatype'           => $field[ConfigItem::TYPE],
                    'list'               => $field['list'],
                ];
                // Only increase index if we processed an item.
                $index++;
            }
        }
        return $tab;
    }


    /**
     * Get all valid configurations and return config buttons only if config is valid
     * and active.
     * @return  array
     * @see                             - src/LoginFlow/showLoginScreen()
     * @since 1.0.0
     */
    public static function getLoginButtons(int $length): array
    {
        
        global $DB;         // Get global DB object to query the configTable.
        $tplvars = [];      // Define the array used to store the buttons (if any)

        // $length is used to strip the length of the button name to fit the button.
        $length = (is_numeric($length)) ? $length : 255;

        // Iterate through the IDP config rows and generate the buttons for twig template.
        foreach( $DB->request(['FROM' => Config::getTable(), 'WHERE' => ['is_deleted'  => 0]]) as $value)
        {
            // Only populate buttons that are considered valid by ConfigEntity;
            $configEntity = new ConfigEntity($value[ConfigEntity::ID]);
            if($configEntity->isValid() && $configEntity->isActive() && !$configEntity->getConfigDomain()){
                $tplvars['buttons'][] = ['id'      => $value[ConfigEntity::ID],
                                        'icon'    => $value[ConfigEntity::CONF_ICON],
                                        'name'    => sprintf("%.".$length."s", $value[ConfigEntity::NAME]) ];
            }
        }
        // Return the buttons (if any) else empty array.
        return $tplvars;
    }

     /**
     * Returns true if any of the configured IdPs is set to enforced.
     * this will hide the password and database fields from the login
     * page.
     * @return  bool
     * @see                             - src/LoginFlow/showLoginScreen()
     * @since 1.0.0
     */
    public static function getIsEnforced(): bool
    {
        global $DB;
        return (count($DB->request(['FROM' => Config::getTable(), 'WHERE' => [ConfigEntity::ENFORCE_SSO  => 1, ConfigEntity::IS_DELETED => 0]])) > 0) ? true : false;
    }

    /**
     * Returns the configId if there is only 1 configuration present.
     * @return  bool
     * @see                             - src/LoginFlow/doAuth()
     * @see                             - https://codeberg.org/QuinQuies/glpisaml/issues/61
     * @since 1.1.5
     */
    public static function getIsOnlyOneConfig(): int
    {
        global $DB;
        $res = $DB->request(['FROM' => Config::getTable(), 'WHERE' => [ConfigEntity::IS_DELETED  => 0, ConfigEntity::IS_ACTIVE => 1]]);
        if (count($res) == 1       &&   // If we only get one row, return the ID
            $row = $res->current() ){   // Assign the result to a var
            $r = (is_numeric($row[ConfigEntity::ID])) ? $row[ConfigEntity::ID] : 0;
        }else{
            $r = 0;                     // If we get no, or multiple, return 0;
        }
        return $r;
    }

    /**
     * Search saml configurations based on provided username@[domain.ext]
     * and return the configuration ID of the matching saml configuration.
     * @return  int     ConfigId
     * @see             https://codeberg.org/QuinQuies/glpisaml/issues/3
     * @since 1.1.3
     */
    public static function getConfigIdByEmailDomain(string $fielda): int
    {
        global $DB;
        // Make sure we are dealing with a valid emailaddress.
        if($upn = filter_var($fielda, FILTER_VALIDATE_EMAIL)){
            // Domain portion of address is at index [1];
            $domain = explode('@', $upn);
            // Query the database for the given domain;
            $req = $DB->request(['SELECT'   =>  ConfigEntity::ID,
                                 'FROM'     =>  Config::getTable(),
                                 'WHERE'    =>  [ConfigEntity::CONF_DOMAIN => $domain[1]]]);
            // If we got a result, cast it to int and return it
            if($req->numrows() == 1){
                foreach($req as $row){
                    $id = (int) $row['id'];
                }
                return $id; // Return the correct idp id
            }          // We found nothing, return 0
        }              // Username is not an email, return 0
        return 0;
    }

    /**
     * Install table needed for Ticket Filter configuration dropdowns
     * @param   Migration $migration    - Plugin migration information;
     * @return  void
     * @see                             - GLPISaml/hook.php
     */
    public static function install(Migration $migration): void
    {
        global $DB;
        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();
        $table              = Config::getTable();

        // Create the base table if it does not yet exist;
        // Do not update this table for later versions, use the migration class;
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = <<<SQL
            CREATE TABLE `$table` (
            `id`                            INT {$default_key_sign} NOT NULL auto_increment,
            `name`                          VARCHAR(255) NOT NULL,
            `conf_domain`                   VARCHAR(50) NOT NULL,
            `conf_icon`                     VARCHAR(50) NOT NULL,
            `enforce_sso`                   tinyint NOT NULL DEFAULT '0',
            `proxied`                       tinyint NOT NULL DEFAULT '0',
            `strict`                        tinyint NOT NULL DEFAULT '0',
            `debug`                         tinyint NOT NULL DEFAULT '0',
            `user_jit`                      tinyint NOT NULL DEFAULT '0',
            `sp_certificate`                TEXT NOT NULL,
            `sp_private_key`                TEXT NOT NULL,
            `sp_nameid_format`              VARCHAR(128) NOT NULL,
            `idp_entity_id`                 VARCHAR(128) NOT NULL,
            `idp_single_sign_on_service`    VARCHAR(128) NOT NULL,
            `idp_single_logout_service`     VARCHAR(128) NOT NULL,
            `idp_certificate`               TEXT NOT NULL,
            `requested_authn_context`       TEXT NOT NULL,
            `requested_authn_context_comparison` VARCHAR(25) NOT NULL,
            `security_nameidencrypted`      tinyint NOT NULL DEFAULT '0',
            `security_authnrequestssigned`  tinyint NOT NULL DEFAULT '0',
            `security_logoutrequestsigned`  tinyint NOT NULL DEFAULT '0',
            `security_logoutresponsesigned` tinyint NOT NULL DEFAULT '0',
            `compress_requests`             tinyint NOT NULL DEFAULT '0',
            `compress_responses`            tinyint NOT NULL DEFAULT '0',
            `validate_xml`                  tinyint NOT NULL DEFAULT '0',
            `validate_destination`          tinyint NOT NULL DEFAULT '0',
            `lowercase_url_encoding`        tinyint NOT NULL DEFAULT '0',
            `comment`                       text NULL,
            `is_active`                     tinyint NOT NULL DEFAULT '0',
            `is_deleted`                    tinyint NOT NULL default '0',
            `date_creation`                 timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`                      timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        }

        // Alter column width for conf_domain
        // https://codeberg.org/QuinQuies/glpisaml/issues/30
        if($DB->tableExists($table)){
            $migration->displayMessage("Updating table layout for $table");
            $query = <<<SQL
                ALTER TABLE $table
                MODIFY COLUMN `conf_domain` varchar(255) null;
            SQL;
            $DB->doQuery($query) or die($DB->error());

            Session::addMessageAfterRedirect("🆗 Updated: $table layout.");
        }
    }

    /**
     * Uninstall table needed for Ticket Filter configuration dropdowns
     * @param   Migration $migration    - Plugin migration information;
     * @return  void
     * @see                             - GLPISaml/hook.php
     */
    public static function uninstall(Migration $migration): void
    {
        $table = Config::getTable();
        // Make this smarter in the future. Never create a backup
        // when the source table is empty and an existing table is
        // populated! Allow user to restore from backup table. Current
        // implementation will 'overwrite' the backup with an empty
        // table if uninstall->reinstall->uninstall is performed.
        $migration->backupTables([$table]);
        Session::addMessageAfterRedirect("🆗 backup: $table.");
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }
}
