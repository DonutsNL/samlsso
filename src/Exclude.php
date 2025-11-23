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

use Html;
use Search;
use Session;
use Migration;
use DBConnection;
use CommonDropdown;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;

/**
 * Be careful with PSR4 Namespaces when extending common GLPI objects.
 * Only Characters are allowed in namespaces extending glpi Objects.
 * @see https://github.com/pluginsGLPI/example/issues/51
 */
class Exclude extends CommonDropdown
{
    /**
     * Exclude DB fields
     */
    const NAME              = 'name';
    const DATE_CREATION     = 'date_creation';
    const DATE_MOD          = 'date_mod';
    const CLIENTAGENT       = 'ClientAgent';
    const EXCLUDEPATH       = 'ExcludePath';
    const ACTION            = 'action';


    public static $rightname = 'config';
    
     /**
     * getTypeName(int nb) : string -
     * Method called by pre_item_add hook validates the object and passes
     * it to the RegEx Matching then decides what to do.
     *
     * @param  int      $nb     number of items.
     * @return void
     */
    public static function getTypeName($nb = 0) : string
    {
        return __('Excluded paths', PLUGIN_NAME);
    }

    /**
     * Overloads missing canCreate Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canCreate(): bool
    {
        return (bool) static::canUpdate();
    }

    /**
     * Overloads missing canPurge Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canPurge(): bool
    {
        return (bool) static::canUpdate();
    }

    /**
     * getIcon() : string -
     * Sets icon for object.
     *
     * @return string   $icon
     */
    public static function getIcon() : string
    {
        return 'fa-regular fa-eye-slash';
    }

    public function invoke()
    {
        Html::header(__('samlSSO Excludes'),
        SamlSsoController::EXCLUDE_ROUTE,
        SamlSsoController::EXCLUDE_PNAME,
        self::class);
        
        Search::show(Exclude::class);
        Html::footer();
    }
    

    /**
     * getAdditionalFields(): array
     * Fetch fields for Dropdown 'add' form. Array order is equal with
     * field order in the form
     *
     * @return array   additional fields for dropdown
     */
    public function getAdditionalFields(): array
    {
        return [
            [
                'name'      => 'ClientAgent',
                'label'     => __('Agent contains', PLUGIN_NAME),
                'type'      => 'text',
                'list'      => true,
            ],
            [
                'name'      => 'action',
                'label'     => __('Bypass SAML auth', PLUGIN_NAME),
                'type'      => 'bool',
            ],
            [
                'name'      => 'ExcludePath',
                'label'     => __('Url contains path or file', PLUGIN_NAME),
                'type'      => 'text',
                'list'      => true,
                'min'       => 1,
            ],
        ];
    }

    /**
     * rawSearchOptions() : array -
     * Add fields to search and potential table columns
     *
     * @return array   $rawSearchOptions
     */
    public function rawSearchOptions() : array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'                 => '5',
            'table'              => $this->getTable(),
            'field'              => 'ClientAgent',
            'name'               => __('Client Agent performing the call', PLUGIN_NAME),
            'searchtype'         => ['equals', 'notequals'],
            'datatype'           => 'text',
        ];

        $tab[] = [
            'id'                 => '6',
            'table'              => $this->getTable(),
            'field'              => 'ExcludePath',
            'name'               => __('To be excluded path', PLUGIN_NAME),
            'datatype'           => 'text',
        ];
        return $tab;
    }


    /**
     * getExcludes(): array -
     * Get configured excludes from Excludes dropdown
     *
     * @return array    Array with all configured patterns
     * @since           1.1.0
     * @see             https://github.com/DonutsNL/samlSSO/issues/1
     */
    public static function getExcludes(): array
    {
        global $DB;
        $excludes = [];
        $dropdown = new Exclude();
        $table = $dropdown::getTable();
        
        foreach($DB->request(['FROM' => $table]) as $row){
            $excludes[] = [Exclude::NAME                => $row[Exclude::NAME],
                           Exclude::ACTION              => $row[Exclude::ACTION],
                           Exclude::DATE_CREATION       => $row[Exclude::DATE_CREATION],
                           Exclude::DATE_MOD            => $row[Exclude::DATE_MOD],
                           Exclude::CLIENTAGENT         => $row[Exclude::CLIENTAGENT],
                           Exclude::EXCLUDEPATH         => $row[Exclude::EXCLUDEPATH]];
        }
        return $excludes;
    }

    /**
     * Process excluded from SAML auth return true if excluded.
     *
     * @return bool     On success
     * @since           1.1.0
     *
     */
    public static function ProcessExcludes(): bool                                                         //NOSONAR - Maybe fix complexity in future.
    {
        // Glpi-Agents now post to the root url https://my-server/
        // So if we see the inventory's UserAgent then we need to exclude it, always.
        // @see  https://github.com/glpi-project/glpi-agent/blob/77f7cdda24aa6d14ba8800f6bb71a18b98f4526d/lib/GLPI/Agent.pm#L28
        // @see  https://github.com/glpi-project/glpi/blob/26997c8dbff582c63d407f5599ad2dd69c50f847/index.php#L91
        // @see  https://codeberg.org/QuinQuies/glpisaml/issues/92
        if($_SERVER['REQUEST_METHOD'] == 'POST'                       &&     // The glpi agent uses POST method to send data to the server
           !empty($_SERVER['HTTP_USER_AGENT'])                        &&     // The user-agent field must not be empty
           (strpos($_SERVER['HTTP_USER_AGENT'], 'Agent_v') !== false) ){     // The user-agent field must contain the 'Agent-v' substring
            // Dont log, dont register, these may include thousands of calls per period and
            // will be logged by apache's access logging.
           return true;
        } // else continue processing

        // Get the excludes from the database.
        $excludes = Exclude::getExcludes();
        // Process configured excluded URIs and agents.
        foreach($excludes as $exclude){
            if (strpos($_SERVER['REQUEST_URI'], $exclude[Exclude::EXCLUDEPATH]) !== false) {
                // Do we need to validate client agent?
                if(!empty($exclude[Exclude::CLIENTAGENT])                                        &&         //NOSONAR - Maybe fix verbosity in future.
                   strpos($_SERVER['HTTP_USER_AGENT'], $exclude[Exclude::CLIENTAGENT]) !== false ){
                    return ($exclude[Exclude::ACTION]) ? true : false;
                }else{
                    // return configured action true for bypass, false for auth.
                    return ($exclude[Exclude::ACTION]) ? true : false;
                }
            } // Else Continue
        }
        return false;
    }

    /**
     * Validate object $excluded is excluded and get the configured action
     * @param string $excluded String describing the object to be validated
     * @param string $agent    Optional, the userAgent to be validated
     * @return bool  Configured action, or false
     * @since        1.1.4
     */
    public static function GetExcludeAction(string $excluded, $agent = false): bool    //NOSONAR - complexity by design
    {
        // Get all the excluded objects from the database
        $excludes = Exclude::getExcludes();
        // Process configured excluded URIs and agents.
        foreach($excludes as $exclude){
            if (strpos($excluded, $exclude[Exclude::EXCLUDEPATH]) !== false) {
                // Do we need to validate client agent?
                if( $agent  && strpos($agent, $exclude[Exclude::CLIENTAGENT]) !== false ){ //NOSONAR - additional 'if branch' by design
                    return ($exclude[Exclude::ACTION]) ? true : false;
                }else{
                    // return configured action true for bypass, false for auth.
                    return ($exclude[Exclude::ACTION]) ? true : false;
                }
            } // Else Continue
        }
        return false;
    }

    /**
     * Combines database excludes with hardcoded excludes.
     * returns true (with context) if excluded, else it will return false.
     * @since   1.0.0
     */
    public static function isExcluded(): string|bool
    {
        //https://github.com/derricksmith/phpsaml/issues/159
        // Do not perform auth on CLI, asserter service and manually excluded files.
        if (PHP_SAPI != 'cli'){
            // https://codeberg.org/QuinQuies/glpisaml/issues/18#issuecomment-1785444
            // $_SERVER['REQUEST_URI'] obviously isn't populated in 'CLI' mode.
            if( isset($_SERVER['REQUEST_URI'])                               &&         // Make sure REQ URI is available
              ( strpos($_SERVER['REQUEST_URI'], 'acs.php') !== false         ||         // do not process acs
                strpos($_SERVER['REQUEST_URI'], 'common.tabs.php') !== false ||         // do not process common.tabs
                strpos($_SERVER['REQUEST_URI'], 'dashboard.php') !== false   ||         // do not process dashboard
                Exclude::ProcessExcludes()                                   ))
            {
                return $_SERVER['REQUEST_URI'];
            } else {
                return false;
            }
        }else{
            global $argv;
            $command = '';
            foreach ($argv as $value){
                $command .= $value .' ';
            }
            return 'Saml auth skipped, CLI executed command: '.$command;
        }
    }

    /**
     * install(Migration migration) : void -
     * Install table needed for Ticket Filter configuration dropdowns
     *
     * @return void
     * @see             hook.php:plugin_GLPISaml_install()
     */
    public static function install(Migration $migration) : void
    {
        global $DB;
        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = Exclude::getTable();

        // Create the base table if it does not yet exist;
        // Do not update this table for later versions, use the migration class;
        if (!$DB->tableExists($table)) {
            $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`                        int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `name`                      varchar(255) DEFAULT NULL,
                `comment`                   text,
                `date_creation`             timestamp NULL DEFAULT NULL,
                `date_mod`                  timestamp NULL DEFAULT NULL,
                `ClientAgent`               text      NOT NULL,
                `ExcludePath`               text      NOT NULL,
                `action`                    tinyint unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
            // insert default excludes;
            $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass Cron.php', 'backport configuration', '1', '', '/front/cron.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());

            // insert default excludes;
            $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass Inventory.php', '', '1', '', 'front/inventory.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());

            // insert default excludes;
            $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass ldap_mass_sync.php', '', '1', '', 'ldap_mass_sync.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());

            // insert default excludes;
            $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass apirest.php', '', '1', '', 'apirest.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());

            // insert default excludes;
            $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass all fusioninventory files', '', '1', '', '/fusioninventory/');
            SQL;
            $DB->doQuery($query) or die($DB->error());
            

            // insert default excludes;
            // https://codeberg.org/QuinQuies/glpisaml/issues/36
            $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass dashboard.php', '', '1', '', 'dashboard.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());
        }

        // Version 1.2.3 add exclude for GLPI11
        // https://github.com/DonutsNL/samlsso/issues/32
        $result = $DB->request(['FROM' => Exclude::getTable(), 'WHERE' => [Exclude::EXCLUDEPATH => '/ajax/webhook.php']]) or die($DB->error());
        if(!$result->numrows() > 0){
             $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass webhook.php', '', '1', '', '/ajax/webhook.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());
        }

        // https://github.com/DonutsNL/samlsso/issues/36
        $result = $DB->request(['FROM' => Exclude::getTable(), 'WHERE' => [Exclude::EXCLUDEPATH => '/api.php']]) or die($DB->error());
        if(!$result->numrows() > 0){
             $query = <<<SQL
                INSERT INTO `$table`(name, comment, action, ClientAgent, ExcludePath)
                VALUES('Bypass api.php', '', '1', '', '/api.php');
            SQL;
            $DB->doQuery($query) or die($DB->error());
        }

        Session::addMessageAfterRedirect("🆗 Inserted default excludes.");
    }

    /**
     * uninstall(Migration migration) : void -
     * Uninstall tables uncomment the line to make plugin clean table.
     *
     * @return void
     * @see             hook.php:plugin_GLPISaml_uninstall()
     */
    public static function uninstall(Migration $migration) : void
    {
        $table = Exclude::getTable();
        Session::addMessageAfterRedirect("🆗 Removed: $table");
        $migration->dropTable($table);
    }
}
