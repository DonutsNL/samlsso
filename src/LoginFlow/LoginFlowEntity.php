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
namespace GlpiPlugin\Samlsso\LoginFlow;

use ReflectionClass;
use GlpiPlugin\Samlsso\Config as SamlConfig;
use GlpiPlugin\Samlsso\Config\ConfigItem;

/*
 * Class ConfigEntity's job is to populate, evaluate, test, normalize and
 * make sure we always return a consistent, valid, and usable instance of
 * a samlConfiguration thats either based on a template or based on an
 * existing database row
 */
class LoginFlowEntity extends LoginFlowItem
{
    // Be aware, this class is being reflected, so dont store 'private' constants
    // in this class as they will be included in the reflection!
    public const ID              = 'id';                                    // Database ID
    public const DEBUG           = 'debug';                                 // Enable debugging
    public const ENFORCED        = 'enforced';                              // Enable enforced SSO
    public const ENFORCEDIDP     = 'forcedIdp';                             // Enforce specific IDP configuration
    public const ENABLEDOMAIN    = 'enableDomainLogin';                     // Enable domain based Login using domain in username
    public const ENABLEIDPGETTER = 'enableGetterLogin';                     // Enable getter based Login remote SP initiated login
    public const HIDEGLPILOGIN   = 'hideGlpiLogin';                         // hide default Glpi Login fields
    public const HIDEBUTTONS     = 'hideSamlButtons';                       // hide Saml Buttons
    public const HIDEPASSWORD    = 'hidePassword';                          // hide GLPI PasswordField
    public const RULESONAUTH     = 'applyRulesOnAuth';                      // (re)Apply rules on each succesfull auth
    public const RULESONJIT      = 'applyRulesOnJit';                       // Apply rules on JIT
    public const ALLOWUNSOLICITED= 'allowUnsolicited';                      // Allow unsolicited responses
    public const ENABLEREDIRECT  = 'processRedirects';                      // Store and process redirects
    public const BYPASSSTR       = 'byPassString';                          // What value to verify to allow enforced bypass
    public const BYPASSVAR       = 'byPassVar';                             // What property to use for {bypass var}={bypass string}
    public const ENABLEIDPLOGOUT = 'enableIdpLogout';                       // Also log user out with IDP if user logs out of GLPI
    public const ENFORCEIDLE     = 'enforceReAuthAfterIdle';                // Always perform 'reauth' after designated timeout period
    public const BLOCKLOGIN      = 'blockAfterEnfocedLogout';               // future use, not sure yet how to best implement this.
    public const IS_DELETED      = 'is_deleted';
    public const CREATE_DATE     = 'date_creation';
    public const MOD_DATE        = 'date_mod';
    

    /**
     * True, if an configuration issue is found its set to false.
     */
    private $isValid            = true;


     /**
     * Contains all field values of a certain configuration
     */
    private $fields             = [];


    /**
     * Contains all validation error messages generated during validation
     */
    private $invalidMessages    = [];


    /**
     * The ConfigEntity class constructor
     *
     * @param  int      $id             - LoginFlow configuration ID to fetch, always 1
     * @param  array    $options        - Options['template'] what template to use;
     * @return object   ConfigEntity    - returns an instance of populated ConfigEntity;
     */
    public function __construct(int $id = 1)
    {
        // Fetch the configuration from the database;
        $this->validateAndPopulateDBEntity($id);
    }

    /**
     * Populates the instance of ConfigEntity using a DB query from the LoginFlow table.
     *
     * @param  int      $id             - id of the database row to fetch
     * @return object   ConfigEntity    - returns instance of ConfigEntity.
     */
    private function validateAndPopulateDBEntity($id): void
    {
        // Get configuration from database;
        $config = new SamlConfig();
        if($config->getFromDB($id)) {
            // Iterate through fetched fields
            foreach($config->fields as $field => $value) {
                // Do validations on all provided fields. All fields need to be
                // verified by GlpiPlugin\Glpisaml\Config\ConfigItem per default.
                $this->evaluateItem((string) $field, $value);
            }
        }
    }


    /**
     * Validates and normalizes configuration fields using checks defined
     * in class GlpiPlugin\Glpisaml\Config\ConfigItem. For instance
     * if defined in ConfigItem, it will convert DB result (string) '1'
     * too (boolean) true in the returned array for type safety purposes.
     *
     * @param  string   $field  - name of the field to validate
     * @param  mixed    $val    - value belonging to the field.
     * @return array            - result of the validation including normalized values.
     * @see https://www.mysqltutorial.org/mysql-basics/mysql-boolean/
     */
    private function evaluateItem(string $field, mixed $value, $invalidate = false): array
    {
        // Verify we have a method to evaluate the given field.
        $evaluatedItem = (method_exists(get_parent_class($this), $field)) ? $this->$field($value) : $this->noMethod($field, $value);

        if(isset($evaluatedItem[ConfigItem::EVAL])      &&
           $evaluatedItem[ConfigItem::EVAL] == 'valid'  ){
            $this->fields[$field] = $evaluatedItem[ConfigItem::VALUE];
        }else{
            // Pass or invalidate
            $this->fields[$field] = ($invalidate) ? '' : $value;
            // Add errormessage
            $this->invalidMessages[$field] = (isset($evaluatedItem[ConfigItem::ERRORS])) ? $evaluatedItem[ConfigItem::ERRORS] : 'UNDEFINED';
            // Mark entity invalid
            $this->isValid = false;
        }
        return $evaluatedItem;
    }


    /**
     * This static function will return the configuration constants
     * defined in this class. Idea is to use this reflection to
     * validate the database fields names, numbers and so forth to detect
     * update caused DB issues.
     *
     * @return array            - defined ConfigEntity class constants.
     * @see https://www.php.net/manual/en/reflectionclass.getconstants.php
     */
    public static function getConstants(): array
    {
        $reflectedObj = new ReflectionClass(__CLASS__);
        return $reflectedObj->getConstants();                  //NOSONAR - ignore S3011 all constants in this object are intended to be public!
    }


    /**
     * This function will return contextual and actual information about the handled
     * configuration fields. It will also perform advanced validations and correct
     * invalid configuration options before save in database.
     *
     * Intended for generating Config->searchOptions, ConfigForm->showForm().
     *
     * @param  bool     $debug  - If true will only return fields without predefined class Constant and preloaded value.
     * @return array            - ConfigEntity field information
     */
    public function getFields(): array        //NOSONAR - Maybe reduce complexity reduce calls to validateConfigFields?;
    {
        global $DB;
        // Fetch config item constants;
        $classConstants = LoginFlowEntity::getConstants();
        // Fetch database columns;
        $sql = 'SHOW COLUMNS FROM '.SamlConfig::getTable();
        if ($result = $DB->doQuery($sql)) {
            while ($data = $result->fetch_assoc()) {
                $fields[$data['Field']] = [
                    ConfigItem::FIELD       => $data['Field'],
                    ConfigItem::TYPE        => $data['Type'],
                    ConfigItem::NULL        => $data['Null'],
                    ConfigItem::CONSTANT    => ($key = array_search($data['Field'], $classConstants)) ? "ConfigEntity::$key" : 'UNDEFINED',
                    ConfigItem::VALUE       => (isset($this->fields[$data['Field']])) ? $this->fields[$data['Field']] : null,
                ];
                // Evaluate and merge results.
                $fields[$data['Field']] = array_merge($fields[$data['Field']], $this->evaluateItem($data['Field'], (isset($this->fields[$data['Field']])) ? $this->fields[$data['Field']] : ''));
            }
        }
        // Validate spcert and key if provided
        $fields = $this->validateAdvancedConfig($fields);
        return $fields;
    }


    /**
     * Returns the validated and normalized fields in the ConfigEntity
     * for database insertion. It will not add fields added to the
     * ignoreFields param.
     *
     * @param  array $ignoreFields fields to skip
     * @return array $fields with validated and corrected configuration
     */
    public function getDBFields($ignoreFields = []): array
    {
        foreach($this->getFields() as $key => $value){
            if(is_array($ignoreFields) && !in_array($key, $ignoreFields)){
                $fields[$key] = $value[ConfigItem::VALUE];
            }
        }
        return $fields;
    }


    /**
     * Validate advanced configuration options and correct params if not supported by provided setup.
     *
     * @param  array $fields from getFields()
     * @return array $fields with corrected configuration options
     */
    private function validateAdvancedConfig(array $fields): array
    {
        // Allows for additional manipulation of configuration fields
        // before passing them back to the form, for instance
        // illegal combinations of configuration options or cross object validations
        return $fields;
    }


    /**
     * This function will return specific config field if it exists
     *
     * @param  bool     $fieldName  - Name of the configuration item we are looking for, use class constants.
     * @return string               - Value of the configuration or (bool) false if not found.
     */
    public function getField(string $fieldName): string|bool
    {
        if(key_exists($fieldName, $this->fields) &&
           is_int($this->fields[$fieldName])     ){
            return (bool) $this->fields[$fieldName];
        }elseif (key_exists($fieldName, $this->fields)){
            return (string) $this->fields[$fieldName];
        }else{
            return false;
        }
    }


    /**
     * This function will return all registered error messages
     *
     * @return array           - Value of the configuration or (bool) false if not found.
     */
    public function getErrorMessages(): array
    {
            return (count($this->invalidMessages) > 0) ? $this->invalidMessages : [];
    }


    /**
     * Returns the validity state of the currently loaded ConfigEntity
     * @return bool
     */
    public function isValid(): bool
    {
        return (bool) $this->isValid;
    }
}

