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
 *  @version    1.2.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/
namespace GlpiPlugin\Samlsso\Config;

use Plugin;
use Session;
use ReflectionClass;
use GlpiPlugin\Samlsso\Config as SamlConfig;
use GlpiPlugin\Samlsso\Config\ConfigItem;


/*
 * Class ConfigEntity's job is to populate, evaluate, test, normalize and
 * make sure we always return a consistent, valid, and usable instance of
 * a samlConfiguration thats either based on a template or based on an
 * existing database row
 */
class ConfigEntity extends ConfigItem
{
    /*
     * ConfigEntity can be reflected by this->getConstants(),
     * private constants are not!
     * 32 database fields expected
     */
    public const ID              = 'id';                                     // Database ID
    public const NAME            = 'name';                                   // Configuration name, not used in SAML handling
    public const CONF_DOMAIN     = 'conf_domain';                            // Configuration domain, used to identify saml handled logins
    public const CONF_ICON       = 'conf_icon';                              // Configuration ICON to use in UI elements
    public const ENFORCE_SSO     = 'enforce_sso';                            // Enforce SSO
    public const PROXIED         = 'proxied';                                // Handle proxied responses (X-forwarded-for)
    public const STRICT          = 'strict';                                 // Enforce encryption
    public const DEBUG           = 'debug';                                  // Enable debug logging
    public const USER_JIT        = 'user_jit';                               // Enable Just In Time user creation
    public const SP_CERTIFICATE  = 'sp_certificate';                         // Service provider certificate
    public const SP_KEY          = 'sp_private_key';                         // Service provider certificate key
    public const SP_NAME_FORMAT  = 'sp_nameid_format';                       // Service provider nameID formatting
    public const IDP_ENTITY_ID   = 'idp_entity_id';                          // Identity provider Entity ID
    public const IDP_SSO_URL     = 'idp_single_sign_on_service';             // Identity provider Single Sign On Url
    public const IDP_SLO_URL     = 'idp_single_logout_service';              // Identity provider Logout Url
    public const IDP_CERTIFICATE = 'idp_certificate';                        // Identity provider certificate
    public const AUTHN_CONTEXT   = 'requested_authn_context';                // Requested authn context (to be provided by Idp)
    public const AUTHN_COMPARE   = 'requested_authn_context_comparison';     // Requested authn context comparison (to be evaluated by Idp)
    public const ENCRYPT_NAMEID  = 'security_nameidencrypted';               // Encrypt nameId field using service provider certificate
    public const SIGN_AUTHN      = 'security_authnrequestssigned';           // Sign authN request using service provider certificate
    public const SIGN_SLO_REQ    = 'security_logoutrequestsigned';           // Sign logout request using service provider certificate
    public const SIGN_SLO_RES    = 'security_logoutresponsesigned';          // Sign logout response using service provider certificate
    public const COMPRESS_REQ    = 'compress_requests';                      // Compress all requests
    public const COMPRESS_RES    = 'compress_responses';                     // Compress all responses
    public const XML_VALIDATION  = 'validate_xml';                           // Validate XML messages
    public const DEST_VALIDATION = 'validate_destination';                   // relax destination validation
    public const LOWERCASE_URL   = 'lowercase_url_encoding';                 // lowercaseUrlEncoding
    public const COMMENT         = 'comment';                                // Field for comments on configuration page
    public const IS_ACTIVE       = 'is_active';                              // Toggle SAML config active or disabled
    public const IS_DELETED      = 'is_deleted';
    public const CREATE_DATE     = 'date_creation';
    public const MOD_DATE        = 'date_mod';

    /**
     * True, if an configuration issue is found its set to false.
     */
    private $isValid            = true;


    /**
     * For debugging, shows how entity was populated
     */
    private $populationSource   = null;

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
     * @param  int      $id             - Saml configuration ID to fetch, or null for default template
     * @param  array    $options        - Options['template'] what template to use;
     * @return object   ConfigEntity    - returns an instance of populated ConfigEntity;
     */
    public function __construct(int $id = -1, array $options = [])
    {
        if(!$id || $id == -1) {
            // negative identifier equals populate using a template file
            $options = (!empty($options['template'])) ? $options : ['template' => 'default'];
            $this->validateAndPopulateTemplateEntity($options);
        } else {
            // positive identifier equals populate using on a database row
            $this->validateAndPopulateDBEntity($id);
        }
    }


    /**
     * Populates the instance of ConfigEntity using a template.
     *
     * @param  array   $options      - name of the Config[NAME]Tpl class to use as template.
     * @return object  ConfigEntity    - returns instance of ConfigEntity.
     */
    private function validateAndPopulateTemplateEntity(array $options): void    //NOSONAR we are to lazy to split method further.
    {
        // Create entity based on post inputs
        if($options['template'] == 'post'       &&
           array_key_exists('postData', $options)   ){
            $this->populationSource = 'post';
            // Only evaluate valid Config Items;
            $configItems = $this->getConstants();
            foreach($options['postData'] as $field => $value){
                if(array_search($field, $configItems)){
                    $this->evaluateItem($field, $value);
                }
            }
        }else{
            // Locate our template file
            $templateClass = 'GlpiPlugin\Samlsso\Config\Config'.$options['template'].'Tpl';
            $this->populationSource = $templateClass;
            if(!class_exists($templateClass)){
                //Fallback
                $templateClass = 'GlpiPlugin\Samlsso\Config\ConfigDefaultTpl';
                if(!class_exists($templateClass)){
                    // Fatal issue.
                    Session::addMessageAfterRedirect(__("Could not locate configuration template $templateClass, please verify installation!"));
                    exit;
                }
            }// Use found template.
            // Perform same validation
            foreach($templateClass::template() as $field => $value){
                // Might be issue, we assume the correct fields are declared in returned array.
                $this->evaluateItem($field, $value);
            }
        }
    }


    /**
     * Populates the instance of ConfigEntity using a DB query from the glpisaml config table.
     *
     * @param  int      $id             - id of the database row to fetch
     * @return object   ConfigEntity    - returns instance of ConfigEntity.
     */
    private function validateAndPopulateDBEntity($id): void
    {
        $this->populationSource = 'Database:'.$id;

        // Get configuration from database;
        $config = new SamlConfig();
        if($config->getFromDB($id)) {
            // Iterate through fetched fields
            foreach($config->fields as $field => $value) {
                // Do validations on all provided fields. All fields need to be
                // verified by GlpiPlugin\Glpisaml\Config\ConfigItem per default.
                $this->evaluateItem($field, $value);
            }
        }else{
            // Return the default configuration, this exception can be verified
            // by checking the absence of the 'id' field in the returned ConfigEntity.
            $this->validateAndPopulateTemplateEntity(['template' => 'default']);
        }
        // Do some final consistency check here.
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
        // TODO: Clean up using class extend instead of external static call. //NOSONAR
        // TODO: We want coders to be forced to always use configEntity and not create loopholes.   //NOSONAR 
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
        return $reflectedObj->getConstants();                  //NOSONAR - ignore S3011 all constants here are intended to be public!
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
        $classConstants = ConfigEntity::getConstants();
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
            // https://github.com/DonutsNL/glpisaml/issues/11
            if(is_array($ignoreFields) && !in_array($key, $ignoreFields)){
                $fields[$key] = $value[ConfigItem::VALUE];
            }
        }
        return $fields;
    }

    /**
     * Fetches the config domain from the populated config entity
     * if the entity is anything else than the default 'youruserdomain.tld' or empty
     * it returns that value or an empty string.
     *
     * @param  array $ignoreFields fields to skip
     * @return array $fields with validated and corrected configuration
     * @since 1.1.3
     */
    public function getConfigDomain(): string
    {
        return (key_exists(ConfigEntity::CONF_DOMAIN, $this->fields) &&
                !empty($this->fields[ConfigEntity::CONF_DOMAIN])     &&
                $this->fields[ConfigEntity::CONF_DOMAIN] != 'youruserdomain.tld') ? $this->fields[ConfigEntity::CONF_DOMAIN] : '';
    }

    /**
     * Validate advanced configuration options and correct params if not supported by provided setup.
     *
     * @param  array $fields from getFields()
     * @return array $fields with corrected configuration options
     */
    private function validateAdvancedConfig(array $fields): array
    {
        $disable = false;
        if(empty($fields[configEntity::SP_CERTIFICATE][ConfigItem::VALUE]) || empty($fields[configEntity::SP_KEY][ConfigItem::VALUE])){
            $disable = true;
        }else{
            // Perform key validation
            if(!$this->validateCertKeyPairModulus($fields[configEntity::SP_CERTIFICATE][configItem::VALUE], $fields[configEntity::SP_KEY][configItem::VALUE])){
                $fields[configEntity::SP_KEY][configItem::ERRORS] = __('⚠️ SP private key does not seem to match provided SP certificates modulus.', PLUGIN_NAME);
                $disable = true;
            } // Only show key issue on error.
        }
        if($disable){
            $errormsg = __('⚠️ Will be defaulted to "No" because the provided SP certificate does not look valid!', PLUGIN_NAME);
            // Strict cannot be enabled without valid certificate!
            if($fields[configEntity::ENCRYPT_NAMEID][configItem::VALUE]){
                $fields[configEntity::ENCRYPT_NAMEID][configItem::VALUE] = false;
                $fields[configEntity::ENCRYPT_NAMEID][configItem::ERRORS] = $errormsg;
            }
            // Strict cannot be enabled without valid certificate!
            if($fields[configEntity::SIGN_AUTHN][configItem::VALUE]){
                $fields[configEntity::SIGN_AUTHN][configItem::VALUE] = false;
                $fields[configEntity::SIGN_AUTHN][configItem::ERRORS] = $errormsg;
            }
            // Strict cannot be enabled without valid certificate!
            if($fields[configEntity::SIGN_SLO_REQ][configItem::VALUE]){
                $fields[configEntity::SIGN_SLO_REQ][configItem::VALUE] = false;
                $fields[configEntity::SIGN_SLO_REQ][configItem::ERRORS] = $errormsg;
            }
            // Strict cannot be enabled without valid certificate!
            if($fields[configEntity::SIGN_SLO_RES][configItem::VALUE]){
                $fields[configEntity::SIGN_SLO_RES][configItem::VALUE] = false;
                $fields[configEntity::SIGN_SLO_RES][configItem::ERRORS] = $errormsg;
            }
        }

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
        return (key_exists($fieldName, $this->fields)) ? $this->fields[$fieldName] : false;
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
        return $this->isValid;
    }

    /**
     * Returns the validity state of the currently loaded ConfigEntity
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->fields[ConfigEntity::IS_ACTIVE];
    }

    public function getRequestedAuthnContextArray(): array
    {
        if(strstr($this->fields[ConfigEntity::AUTHN_CONTEXT], ':')){
            return explode(':', $this->fields[ConfigEntity::AUTHN_CONTEXT]);
        }else{
            return [$this->fields[ConfigEntity::AUTHN_CONTEXT]];
        }
    }

    /**
     * Populates and returns the configuration array for the PHP-saml library.
     *
     * @return          array   $config
     * @since           1.0.0
     * @example         https://github.com/SAML-Toolkits/php-saml/blob/master/settings_example.php
     */
    public function getPhpSamlConfig(): array
    {
        global $CFG_GLPI;
        if($this->isValid()){

            return ['strict'                                => $this->fields[ConfigEntity::STRICT],
                    'debug'                                 => $this->fields[ConfigEntity::DEBUG],
                    'baseurl'                               => null,
                    'sp' => [
                        'entityId'                          => $CFG_GLPI['url_base'].'/',
                        'assertionConsumerService'          => [
                            'url'                           => $CFG_GLPI['url_base'].'/'.PLUGIN_SAMLSSO_WEBDIR.'/front/acs.php?idpId='.$this->fields[ConfigEntity::ID],
                        ],
                        'singleLogoutService'               => [
                            'url'                           => $CFG_GLPI['url_base'].'/'.PLUGIN_SAMLSSO_WEBDIR.'/front/slo.php',
                        ],
                        'x509cert'                          => $this->fields[ConfigEntity::SP_CERTIFICATE],
                        'privateKey'                        => $this->fields[ConfigEntity::SP_KEY],
                        'NameIDFormat'                      => (isset($this->fields[ConfigEntity::SP_NAME_FORMAT]) ? $this->fields[ConfigEntity::SP_NAME_FORMAT]
                                                                                                                   : 'unspecified'),
                    ],
                    'idp'                                   => [
                        'entityId'                          => $this->fields[ConfigEntity::IDP_ENTITY_ID],
                        'singleSignOnService'               => [
                            'url'                           => $this->fields[ConfigEntity::IDP_SSO_URL],
                        ],
                        'singleLogoutService'               => [
                            'url'                           => $this->fields[ConfigEntity::IDP_SLO_URL],
                        ],
                        'x509cert'                          => $this->fields[ConfigEntity::IDP_CERTIFICATE],
                    ],
                    'compress'                              => [
                        'requests'                          => (bool) $this->fields[ConfigEntity::COMPRESS_REQ],
                        'responses'                         => (bool) $this->fields[ConfigEntity::COMPRESS_RES],
                    ],
                    'security'                              => [
                        'nameIdEncrypted'                   => $this->fields[ConfigEntity::ENCRYPT_NAMEID],
                        'authnRequestsSigned'               => $this->fields[ConfigEntity::SIGN_AUTHN],
                        'logoutRequestSigned'               => $this->fields[ConfigEntity::SIGN_SLO_REQ],
                        'logoutResponseSigned'              => $this->fields[ConfigEntity::SIGN_SLO_RES],

                        //'signMetadata'                    => false,
                        //'wantMessagesSigned'              => false,
                        //'wantAssertionsEncrypted'         => false,
                        //'wantAssertionsSigned'            => false,
                        //'wantNameId'                      => true,
                        //'wantNameIdEncrypted'             => false,
                        // Set true or don't present this parameter and you will get an AuthContext 'exact' 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport'
                        // Set an array with the possible auth context values: array ('urn:oasis:names:tc:SAML:2.0:ac:classes:Password', 'urn:oasis:names:tc:SAML:2.0:ac:classes:X509'),
                        'requestedAuthnContext'             => $this->getAuthn($this->fields[ConfigEntity::AUTHN_CONTEXT]),
                        'requestedAuthnContextComparison'   => (isset($this->fields[ConfigEntity::AUTHN_COMPARE]) ? $this->fields[ConfigEntity::AUTHN_COMPARE] : 'exact'),
                        'wantXMLValidation'                 => $this->fields[ConfigEntity::XML_VALIDATION],
                        'relaxDestinationValidation'        => $this->fields[ConfigEntity::DEST_VALIDATION],

                        // Algorithm that the toolkit will use on signing process. Options:
                        //    'http://www.w3.org/2000/09/xmldsig#rsa-sha1'
                        //    'http://www.w3.org/2000/09/xmldsig#dsa-sha1'
                        //    'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                        //    'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384'
                        //    'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512'
                        // Notice that sha1 is a deprecated algorithm and should not be used
                        'signatureAlgorithm'            => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                        //'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,

                        // Algorithm that the toolkit will use on digest process. Options:
                        //    'http://www.w3.org/2000/09/xmldsig#sha1'
                        //    'http://www.w3.org/2001/04/xmlenc#sha256'
                        //    'http://www.w3.org/2001/04/xmldsig-more#sha384'
                        //    'http://www.w3.org/2001/04/xmlenc#sha512'
                        // Notice that sha1 is a deprecated algorithm and should not be used
                        'digestAlgorithm'               => 'http://www.w3.org/2001/04/xmlenc#sha256',
                        'lowercaseUrlencoding'          => $this->fields[ConfigEntity::LOWERCASE_URL],
                    ]];
        }else{
            // ConfigEntity was not usable!
            return [];
        }
    }

    /**
     * Calculates correct AuthN required for SAML request
     *
     * @return          array|bool   $config
     * @since           1.0.0
     * @example         https://github.com/SAML-Toolkits/php-saml/blob/master/settings_example.php
     */
    private static function getAuthn($value): array|bool
    {
        if (preg_match('/^none,.+/i', $value)) {
            $array  = explode(':', $value);
            $output = array();
            foreach ($array as $item) {
                switch ($item) {
                    case 'PasswordProtectedTransport':
                        $output[] = 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport';
                        break;
                    case 'Password':
                        $output[] = 'urn:oasis:names:tc:SAML:2.0:ac:classes:Password';
                        break;
                    case 'X509':
                        $output[] = 'urn:oasis:names:tc:SAML:2.0:ac:classes:X509';
                        break;
                    default:
                        $output[] = '';
                        break;
                }
            }
            return $output;
        } else {
            return false;
        }
    }
}

