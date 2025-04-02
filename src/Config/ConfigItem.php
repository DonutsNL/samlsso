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
namespace GlpiPlugin\Glpisaml\Config;

use DateTime;
use DateTimeImmutable;
use GlpiPlugin\Glpisaml\Config\ConfigEntity;
use Plugin;

/*
 * Validate, evaluate, clean, normalizes, enriches, saml config items before
 * assigning them to the configEntity or invalidates the passed value with an
 * understandable translatable errormessage.
 */
class ConfigItem    //NOSONAR
{
    public const FIELD      = 'field';                                  // Name of the database field
    public const TYPE       = 'datatype';                               // Database type
    public const NULL       = 'notnull';                                // NOT NULL setting
    public const VALUE      = 'value';                                  // Database value
    public const VALID      = 'valid';                                  // Is content valid?
    public const INVALID    = 'invalid';                                // Is content invalid?
    public const RICHVALUE  = 'richvalue';                              // Rich values (like date object)
    public const EVAL       = 'eval';                                   // Evaluated properties
    public const ERRORS     = 'errors';                                 // Encountered problems notnull will prevent DB update/inserts
    public const VALIDATE   = 'validate';                               // Could either be string or array
    public const CONSTANT   = 'itemconstant';                           // What class constant is used for item
    public const FORMEXPLAIN= 'formexplain';                            // Field explanation
    public const FORMTITLE  = 'formtitle';                              // Form title to use with field
    public const VALIDATOR  = 'validator';                              // What validator was used



    protected function noMethod(string $field, string $value): array
    {
        return [ConfigItem::FORMEXPLAIN => ConfigItem::INVALID,
                ConfigItem::VALUE     => $value,
                ConfigItem::FIELD     => $field,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::EVAL      => false,
                ConfigItem::ERRORS    => __("⭕ Undefined or no type validation found in ConfigValidate for item: $field", PLUGIN_NAME)];
    }



    protected function id(mixed $var): array
    {
        // Do some validation
        $error = false;
        if($var               &&
            $var != -1        &&
            !is_numeric($var) ){
            $error = __('⭕ ID must be a positive numeric value!');
        }

        return [ConfigItem::FORMEXPLAIN => __('Unique identifier for this configuration', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('CONFIG ID', PLUGIN_NAME),
                ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
                ConfigItem::VALUE     => $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($error) ? $error : null,
        ];
    }



    protected function name(mixed $var): array
    {
        return [ConfigItem::FORMEXPLAIN => __('This name is shown with the login button on the login page.
                                         Try to keep this name short en to the point.', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('FRIENDLY NAME', PLUGIN_NAME),
                ConfigItem::EVAL      => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($var) ? null : __('⭕ Name is a required field', PLUGIN_NAME)];
    }



    protected function conf_domain(mixed $var): array //NOSONAR
    {
        $error = '';
        return [ConfigItem::FORMEXPLAIN => __('Setting this value with the expected domain.tld, for example:
                                         with "google.com" will allow a user to trigger this IDP by
                                         providing their whatever@[google.com] username in the default
                                         GLPI username field. Setting this field to: youruserdomain.tld
                                         or to nothing disables this feature. Be aware that in the
                                         current implementation, configuring this field will hide
                                         the IDP button from the login screen', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('USERDOMAIN', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => (!$error) ? null : __('⭕ '.$error, PLUGIN_NAME)];
    }



    protected function sp_certificate(mixed $var): array //NOSONAR
    {
        // Certificate is not required, if missing the ConfigEntity will toggle
        // depending security options false if there is an error. Provided certificate
        // string (if any) should be valid.
         $e = false;
        if((!empty($var))                                     &&
           ($certificate = ConfigItem::parseX509Certificate($var)) &&
           (!array_key_exists('subject', $certificate))      ){

            $e = __('⭕ Provided certificate does not like look a valid (base64 encoded) certificate', PLUGIN_NAME);
        }
        return [ConfigItem::FORMEXPLAIN => __('The base64 encoded x509 service provider certificate. Used to sign and encrypt
                                         messages send by the service provider to the identity provider. Required for most
                                         of the security options', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('SP CERTIFICATE', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($e) ? $e : null,
                ConfigItem::VALIDATE  => $certificate];
    }



    protected function sp_private_key(mixed $var): array //NOSONAR
    {
        // Private is not required, if missing or invalid the ConfigEntity will toggle
        // depending security options to false.
        return [ConfigItem::FORMEXPLAIN => __('The base64 encoded x509 service providers private key. Should match the modulus of the
                                         provided X509 service provider certificate', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('SP PRIVATE KEY', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,];
    }



    protected function sp_nameid_format(mixed $var): array //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('The Service Provider nameid format specifies the constraints
                                         on the name identifier to be used to represent the requested
                                         subject.', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('NAMEID FORMAT', PLUGIN_NAME),
                ConfigItem::EVAL   => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
                ConfigItem::VALUE  => (string) $var,
                ConfigItem::FIELD  => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS => ($var) ? null : __('Service provider name id is a required field', PLUGIN_NAME)];
    }



    protected function idp_entity_id(mixed $var): array //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('Identifier of the IdP entity which is an URL provided by
                                         the SAML2 Identity Provider (IdP)', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('ENTITY ID', PLUGIN_NAME),
                ConfigItem::EVAL   => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
                ConfigItem::VALUE  => (string) $var,
                ConfigItem::FIELD  => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS => ($var) ? null : __('⭕ Identity provider entity id is a required field', PLUGIN_NAME)];
    }

    /**
     * Validates the URL the passed SAML Single Sign On Service string
     * @param string    Single Sign On Service URL to be validated
     * @return array    Contextual information about the parameter and validation outcomes
     */
    protected function idp_single_sign_on_service(string $var): array //NOSONAR
    {
        $error = '';
        // This setting is required for SAML to function
        if(empty($var)){
            $error .= __('⭕ The IdP SSO URL is a required field!<br>', PLUGIN_NAME);
        }
        // The value should look like a valid URL
        $options = [FILTER_FLAG_PATH_REQUIRED];
        if(!filter_var($var, FILTER_VALIDATE_URL, $options)){
            $error .= __('⭕ Invalid IdP SSO URL, use: scheme://host.domain.tld/path/', PLUGIN_NAME);
        }
        // Maybe add actual web call here to validate if the URL is accessible
        // if its not, show a warning that the validity of the url could not be validated
        // Accessibility by the server is not a requirement given its the client browser
        // that needs to access the provided resource not the webserver itself.
        
        return [ConfigItem::FORMEXPLAIN => __('Single Sign On Service endpoint of the IdP. URL Target of the IdP where the
                                         Authentication Request Message will be sent. OneLogin PHPSAML
                                         only supports the \'HTTP-redirect\' binding for this endpoint.', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('SSO URL', PLUGIN_NAME),
                ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($error) ? $error : null,];
    }

    /**
     * Validates the URL the passed SAML Single Log Off Service string
     * @param string    Single Sign On Service URL to be validated
     * @return array    Contextual information about the parameter and validation outcomes
     */
    protected function idp_single_logout_service(string $var): array //NOSONAR
    {
        $error = false;
        // This setting is not required because for example in Azure it will log the user out
        // of all online sessions not just GLPI. No url will result in SAML not performing a
        // SLO when the glpi Logoff is triggered. It will allow the user to 're-login' by
        // pressing the correct button.
        $options = [FILTER_FLAG_PATH_REQUIRED];
        if(!empty($var) && !filter_var($var, FILTER_VALIDATE_URL, $options)){
            $error = __('⭕ Invalid Idp SLO URL, use: scheme://host.domain.tld/path/', PLUGIN_NAME);
        }

        return [ConfigItem::FORMEXPLAIN  => __('Single Logout service endpoint of the IdP. URL Location of the IdP where
                                          SLO Request will be sent.OneLogin PHPSAML only supports
                                          the \'HTTP-redirect\' binding for this endpoint.', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('SLO URL', PLUGIN_NAME),
                ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($error) ? $error : null,];
    }

    // Im not yet happy with the structure and complexity. 
    // Should be simplified.
    protected function idp_certificate(mixed $var): array //NOSONAR
    {
        // Is a required field!
        $e = false;
        if(($certificate = ConfigItem::parseX509Certificate($var)) &&
           (!array_key_exists('subject', $certificate))      ){
            if(array_key_exists('validations', $certificate)){
                $e = $certificate['validations'];
            }else{
                $e = __('⭕ Valid Idp X509 certificate is required! (base64 encoded)', PLUGIN_NAME);
            }
        }

        return [ConfigItem::FORMEXPLAIN  => __('The Public Base64 encoded x509 certificate used by the IdP. Fingerprinting
                                          can be used, but is not recommended. Fingerprinting requires you to manually
                                          alter the Saml Config array located in ConfigEntity.php and provide the
                                          required configuration options', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('X509 CERTIFICATE', PLUGIN_NAME),
                ConfigItem::EVAL      => ($e) ? ConfigItem::INVALID : ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($e) ? $e : null,
                ConfigItem::VALIDATE  => $certificate];
    }


    protected function requested_authn_context(mixed $var): array //NOSONAR
    {
        // Normalize multiselect for database insert, form will pass an array
        // Database field expects a string.
        $val = '';
        if(is_array($var)){
            $j = (count($var)-1);
            for($i = 0; $i <= $j; $i++){
                $val .= ($i == $j) ? $var[$i] : $var[$i].':';
            }
        }else{
            $val = $var;
        }
        $val = (empty($val)) ? 'none' : $val;

        return [ConfigItem::FORMEXPLAIN => __('Authentication context needs to be satisfied by the IdP in order to allow Saml login. Set
                                         to "none" and OneLogin PHPSAML will not send an AuthContext in the AuthNRequest. Or,
                                         select one or more options using the "control+click" combination.', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('REQ AUTHN CONTEXT', PLUGIN_NAME),
                ConfigItem::EVAL      => ($val) ? ConfigItem::VALID : ConfigItem::INVALID,
                ConfigItem::VALUE     => (string) $val,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($val) ? null : __('⭕ Requested authN context is a required field', PLUGIN_NAME)];
    }

    protected function requested_authn_context_comparison(mixed $var): array  //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('AUTHN Comparison attribute value', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('AUTHN COMPARISON', PLUGIN_NAME),
                ConfigItem::EVAL      => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::ERRORS    => ($var) ? null : __('⭕ Requested authN context comparison is a required field', PLUGIN_NAME)];
    }

    protected function conf_icon(mixed $var): array                     //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('The FontAwesome (https://fontawesome.com/) icon to show on the button on the login page.', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('LOGIN ICON', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::FIELD     => __function__,
                ConfigItem::ERRORS    => ($var) ? null : __('⭕ Configuration icon is a required field', PLUGIN_NAME)];
    }

    protected function comment(mixed $var): array                       //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('The comments', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('COMMENTS', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::FIELD     => __function__,];
    }

    // Might cast it into an EPOCH date with invalid values.
    protected function date_creation(mixed $var): array                 //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('The date this configuration item was created', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('CREATE DATE', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::RICHVALUE => new DateTime($var)];
    }

    // Might cast it into an EPOCH date with invalid values.
    protected function date_mod(mixed $var): array                      //NOSONAR
    {
        return [ConfigItem::FORMEXPLAIN => __('The date this config was modified', PLUGIN_NAME),
                ConfigItem::FORMTITLE => __('MODIFICATION DATE', PLUGIN_NAME),
                ConfigItem::EVAL      => ConfigItem::VALID,
                ConfigItem::VALUE     => (string) $var,
                ConfigItem::FIELD     => __function__,
                ConfigItem::VALIDATOR => __method__,
                ConfigItem::RICHVALUE => new DateTime($var)];
    }

    // BOOLEANS, We accept mixed, normalize in the handleAsBool function.
    // non ints are defaulted to boolean false.
    protected function is_deleted(mixed $var): array                    //NOSONAR
    {
        if(empty($var)){ $var = '0'; }

        return array_merge([ConfigItem::FORMEXPLAIN   => __('Is this configuration marked as deleted by GLPI', PLUGIN_NAME),
                            ConfigItem::FORMTITLE     => __('IS DELETED', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, 'is_deleted'));
    }

    protected function is_active(mixed $var): array                     //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('Indicates if this configuration activated. Disabled configurations cannot be
                                                       used to login into GLPI and will NOT be shown on the login page.', PLUGIN_NAME),
                            ConfigItem::FORMTITLE     => __('IS ACTIVE', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::IS_ACTIVE));
    }

    protected function enforce_sso(mixed $var): array                   //NOSONAR 
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('If enabled PHPSAML will replace the default GLPI login screen with a version
                                                       that does not have the default GLPI login options and only allows the user to
                                                       authenticate using the configured SAML2 idps. This setting can be bypassed using
                                                       a bypass URI parameter', PLUGIN_NAME),
                            ConfigItem::FORMTITLE     => __('ENFORCED', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::ENFORCE_SSO));
    }

    protected function proxied(mixed $var): array
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('Is GLPI positioned behind a proxy that alters the SAML response scheme?', PLUGIN_NAME),
                            ConfigItem::FORMTITLE     => __('REQUESTS PROXIED', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::PROXIED));
    }

    protected function strict(mixed $var): array
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('If enabled the OneLogin PHPSAML Toolkit will reject unsigned or unencrypted
                                                       messages if it expects them to be signed or encrypted. Also it will reject the
                                                       messages if the SAML standard is not strictly followed: Destination, NameId,
                                                       Conditions are validated too. Strongly advised in production environments.', PLUGIN_NAME),
                            ConfigItem::FORMTITLE     => __('STRICT', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::STRICT));
    }

    protected function debug(mixed $var): array
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('If enabled it will enforce OneLogin PHPSAML to print status and error messages.
                                                       be aware that not all message\'s might be captured by GLPISAML and might therefor
                                                       not become visible.'),
                            ConfigItem::FORMTITLE     => __('DEBUG', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::DEBUG));
    }

    protected function user_jit(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled GLPISAML will create new GLPI users on the fly and assign the properties
                                                         defined in the GLPISAML assignment rules. If disables users that do not have a valid
                                                         GLPI user will not be able to login into GLPI until a user is manually created.'),
                            ConfigItem::FORMTITLE     => __('JIT USER CREATION', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::USER_JIT));
    }

    protected function security_nameidencrypted(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled the OneLogin PHPSAML toolkit will encrypt the <samlp:logoutRequest> sent by
                                                         this SP using the provided SP certificate and private key. This option will be toggled
                                                         "off" automatically if no, or no valid SP certificate and key is provided.'),
                            ConfigItem::FORMTITLE     => __('ENCRYPT NAMEID', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::ENCRYPT_NAMEID));
    }

    protected function security_authnrequestssigned(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled the OneLogin PHPSAML toolkit will sign the <samlp:AuthnRequest> messages
                                                         send by this SP. The IDP should consult the metadata to get the information required
                                                         to validate the signatures.'),
                            ConfigItem::FORMTITLE     => __('SIGN AUTHN REQUEST', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::SIGN_AUTHN));
    }

    protected function security_logoutrequestsigned(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled the OneLogin PHPSAML toolkit will sign the <samlp:logoutRequest> messages
                                                         send by this SP.'),
                            ConfigItem::FORMTITLE     => __('SIGN LOGOUT REQUEST', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::SIGN_SLO_REQ));
    }

    protected function security_logoutresponsesigned(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled the OneLogin PHPSAML toolkit will sign the <samlp:logoutResponse> messages
                                                         send by this SP.'),
                            ConfigItem::FORMTITLE     => __('SIGN LOGOUT RESPONSE', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::SIGN_SLO_RES));
    }

    protected function compress_requests(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled the authentication requests send to the IdP will be compressed by the SP.'),
                            ConfigItem::FORMTITLE     => __('COMPRESS REQUESTS', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::COMPRESS_REQ));
    }

    protected function compress_responses(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN     => __('If enabled the SP expects responses send by the IdP to be compressed.'),
                            ConfigItem::FORMTITLE     => __('COMPRESS RESPONSES', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::COMPRESS_RES));
    }

    protected function validate_xml(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('If enabled the SP will validate all received XMLs. In order to validate the XML
                                                        "strict" security setting must be true.'),
                            ConfigItem::FORMTITLE     => __('VALIDATE XML', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::XML_VALIDATION));
    }

    protected function validate_destination(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('If enabled, SAMLResponses with an empty value at its
                                                       Destination attribute will not be rejected for this fact.'),
                            ConfigItem::FORMTITLE     => __('RELAX DEST VALIDATION', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::DEST_VALIDATION));
    }

    protected function lowercase_url_encoding(mixed $var): array //NOSONAR
    {
        return array_merge([ConfigItem::FORMEXPLAIN   => __('ADFS URL-Encodes SAML data as lowercase, and the OneLogin PHPSAML
                                                       toolkit by default uses uppercase. Enable this setting for ADFS
                                                       compatibility on signature verification'),
                            ConfigItem::FORMTITLE     => __('LOWER CASE ENCODING', PLUGIN_NAME),
                            ConfigItem::FIELD         => __function__,
                            ConfigItem::VALIDATOR     => __method__,],
                            ConfigItem::handleAsBool($var, ConfigEntity::LOWERCASE_URL));
    }

    // Make sure we always return the correct boolean datatype.
    protected function handleAsBool(mixed $var, $field = null): array
    {
        // Default to false if no or an impropriate value is provided.
        $error = (!empty($var) && !preg_match('/[0-1]/', $var)) ? __("⭕ $field can only be 1 or 0", PLUGIN_NAME) : null;

        return [ConfigItem::EVAL   => (is_numeric($var)) ? ConfigItem::VALID : ConfigItem::INVALID,
                ConfigItem::VALUE  => (!$error) ? $var : '0',
                ConfigItem::ERRORS => $error];
    }

    // TODO: Im not yet happy with the structure and complexity.
    // Certificate string should have certain properties to be recognized correctly
    // https://www.man7.org/linux/man-pages/man7/ascii.7.html
    // https://datatracker.ietf.org/doc/rfc7468/ (2.  General Considerations)
    // https://datatracker.ietf.org/doc/html/rfc1421 (<CR> <LF>)
    protected function parseX509Certificate(string $certificate): array|bool         //NOSONAR - Maybe fix complexity in the future
    {
        // Try to parse the reconstructed certificate.
        if (function_exists('openssl_x509_parse')) {
            // Start with an empty array
            $validations = [];
            // Try to parse the certificate using Openssl.
            if ($parsedCertificate = openssl_x509_parse($certificate)) {
                // Create time object from current timestamp to calculate with
                $n = new DateTimeImmutable('now');
                // Create time object from validTo certificate property
                $t = (array_key_exists('validTo', $parsedCertificate)) ? DateTimeImmutable::createFromFormat("ymdHisT", $parsedCertificate['validTo']) : '';
                // Create time object from validFrom certificate property
                $f = (array_key_exists('validFrom', $parsedCertificate)) ? DateTimeImmutable::createFromFormat("ymdHisT", $parsedCertificate['validFrom']) : '';
                // Calculate if the current date is past the validTo certificate property
                $aged = $n->diff($t);
                // Format the age to days between.
                $aged = $aged->format('%R%a');
                // Calculate if the current date is before the validFrom certificate property.
                $born = $f->diff($n);
                // Format the born date to days between.
                $born = $born->format('%R%a');
                // Get the certificate's common name property.
                $cn= $parsedCertificate['subject']['CN'];
                // Validate if we got a negative sign in the calculated ValidTo days.
                if(strpos($aged,'-') !== false){
                    $validations['validTo'] = __("⚠️ Warning, certificate with Common Name (CN): $cn is expired: $aged days", PLUGIN_NAME);
                }
                // Validate if we got a negative sign in the calculated validFrom days.
                if(strpos($born,'-') !== false){
                    $validations['validFrom'] = __("⚠️ Warning, certificate with Common Name (CN): $cn issued in the future ($born days)", PLUGIN_NAME);
                }
                if($cn == 'withlove.from.donuts.nl'){
                    $validations['validFrom'] = __("⚠️ Warning, do not use the 'withlove.from.donuts.nl' example certificates. They offer no additional protection.", PLUGIN_NAME);
                }
                $parsedCertificate['validations'] = $validations;
                return $parsedCertificate;
            }else{
                // Base64 encoded certificates should have these tags (see rfc7468 chap 2)
                if(strpos($certificate, '-----BEGIN CERTIFICATE-----') === false ||
                   strpos($certificate, '-----END CERTIFICATE-----') === false   ){
                    return ['validations'   => __('⭕ Certificate must be wrapped in valid BEGIN CERTIFICATE and END CERTIFICATE tags', PLUGIN_NAME)];
                }
                // Certificates texts should not have SMTP special meaning characters only <LF> (see rfc1421 referenced by rfc7468)
                if(strpos($certificate, chr(13)) === true){
                    return ['validations'   => __('⭕ Certificate should not contain "carriage returns" [<CR>]', PLUGIN_NAME)];
                }
            }
            // Else return generic error.
            return ['validations'   => __('⭕ No valid X509 certificate found', PLUGIN_NAME)];
        }
        // Return message OpenSSL is not available.
        return ['validations'   => __('⚠️ OpenSSL is not available, GLPI cant validate your certificate', PLUGIN_NAME)];
    }

    protected function validateCertKeyPairModulus(string $certificate, string $privateKey): bool         //NOSONAR - Maybe fix complexity in the future
    {
        if (function_exists('openssl_x509_parse') && function_exists('openssl_x509_check_private_key')){
            return (openssl_x509_check_private_key($certificate, [$privateKey, ''])) ? true : false;
        }else{
            // Cannot validate always return true;
            return true;
        }
    }

}
