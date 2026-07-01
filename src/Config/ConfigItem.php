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

namespace GlpiPlugin\Samlsso\Config;

use DateTime;
use DateTimeImmutable;
use GlpiPlugin\Samlsso\Config\ConfigEntity;

/*
 * Validate, evaluate, clean, normalizes, enriches, saml config items before
 * assigning them to the configEntity or invalidates the passed value with an
 * understandable translatable errormessage.
 */

class ConfigItem    //NOSONAR
{
    protected $fields = [];

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
    public const FORMEXPLAIN = 'formexplain';                            // Field explanation
    public const FORMTITLE  = 'formtitle';                              // Form title to use with field
    public const VALIDATOR  = 'validator';                              // What validator was used



    protected function noMethod(string $field, mixed $value): array
    {
        return [
            ConfigItem::FORMEXPLAIN => ConfigItem::INVALID,
            ConfigItem::VALUE     => $value,
            ConfigItem::FIELD     => $field,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::EVAL      => false,
            ConfigItem::ERRORS    => sprintf(__("⭕ Undefined or no type validation found in ConfigValidate for item: %s", PLUGIN_NAME), $field)
        ];
    }



    protected function id(mixed $var): array
    {
        // Do some validation
        $error = false;
        if (
            $var               &&
            $var != -1        &&
            !is_numeric($var)
        ) {
            $error = __('⭕ ID must be a positive numeric value!', PLUGIN_NAME);
        }

        return [
            ConfigItem::FORMEXPLAIN => __('Unique identifier for this configuration', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('CONFIG ID', PLUGIN_NAME),
            ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
            ConfigItem::VALUE     => $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($error) ? $error : false,
        ];
    }



    protected function name(mixed $var): array
    {
        return [
            ConfigItem::FORMEXPLAIN => __('This name is shown with the login button on the login page. Try to keep this name short and to the point.', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('FRIENDLY NAME', PLUGIN_NAME),
            ConfigItem::EVAL      => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($var) ? false : __('⭕ Name is a required field', PLUGIN_NAME)
        ];
    }



    protected function conf_domain(mixed $var): array //NOSONAR
    {
        $error = '';
        return [
            ConfigItem::FORMEXPLAIN => __(trim('Setting this value with the expected domain.tld, for example: with "google.com" will allow a user to trigger this IDP by providing their whatever@google.com username in the default GLPI username field. Setting this field to: youruserdomain.tld or to nothing disables this feature. Configuring this field will hide the IDP button from the login screen. Use a comma separated list if you want to link multiple domains. e.g. domain1.com,domain1.org,domain1.edu'), PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('USERDOMAIN', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => (!$error) ? false : sprintf(__('⭕ %s', PLUGIN_NAME), $error)
        ];
    }



    protected function sp_certificate(mixed $var): array //NOSONAR
    {
        $e = false;
        $certificate = '';
        if (!empty($var)) {
            $parsed = ConfigItem::parseX509Certificate($var);
            if ($parsed) {
                if (!array_key_exists('subject', $parsed)) {
                    $e = __('⭕ Provided certificate does not look like a valid (base64 encoded) certificate', PLUGIN_NAME);
                } else {
                    $certificate = $parsed;
                    if (!empty($parsed['validations'])) {
                        $e = implode('<br>', $parsed['validations']);
                    }
                }
            } else {
                $e = __('⭕ Provided certificate does not look like a valid (base64 encoded) certificate', PLUGIN_NAME);
            }
        }
        return [
            ConfigItem::FORMEXPLAIN => __('The base64 encoded x509 service provider certificate. Used to sign and encrypt messages sent by the service provider to the identity provider. Required for most of the security options', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('SP CERTIFICATE', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => $e,
            ConfigItem::VALIDATE  => $certificate
        ];
    }



    protected function sp_private_key(mixed $var): array //NOSONAR
    {
        // Private is not required, if missing or invalid the ConfigEntity will toggle
        // depending security options to false.
        return [
            ConfigItem::FORMEXPLAIN => __('The base64 encoded x509 service provider\'s private key. Should match the modulus of the provided X509 service provider certificate', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('SP PRIVATE KEY', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
        ];
    }



    protected function sp_nameid_format(mixed $var): array //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => __('The Service Provider nameid format specifies the constraints on the name identifier to be used to represent the requested subject.', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('NAMEID FORMAT', PLUGIN_NAME),
            ConfigItem::EVAL   => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
            ConfigItem::VALUE  => (string) $var,
            ConfigItem::FIELD  => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS => ($var) ? false : __('Service provider name id is a required field', PLUGIN_NAME)
        ];
    }



    protected function idp_entity_id(mixed $var): array //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => __('Identifier of the IdP entity which is an URL provided by the SAML2 Identity Provider (IdP)', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('ENTITY ID', PLUGIN_NAME),
            ConfigItem::EVAL   => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
            ConfigItem::VALUE  => (string) $var,
            ConfigItem::FIELD  => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS => ($var) ? false : __('⭕ Identity provider entity id is a required field', PLUGIN_NAME)
        ];
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
        if (empty($var)) {
            $error .= __('⭕ The IdP SSO URL is a required field!<br>', PLUGIN_NAME);
        }
        // The value should look like a valid URL
        $options = [FILTER_FLAG_PATH_REQUIRED];
        if (!filter_var($var, FILTER_VALIDATE_URL, $options)) {
            $error .= __('⭕ Invalid IdP SSO URL, use: scheme://host.domain.tld/path/', PLUGIN_NAME);
        }
        // Maybe add actual web call here to validate if the URL is accessible
        // if its not, show a warning that the validity of the url could not be validated
        // Accessibility by the server is not a requirement given its the client browser
        // that needs to access the provided resource not the webserver itself.

        return [
            ConfigItem::FORMEXPLAIN => __('Single Sign On Service endpoint of the IdP. URL Target of the IdP where the Authentication Request Message will be sent. OneLogin PHPSAML only supports the \'HTTP-redirect\' binding for this endpoint.', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('SSO URL', PLUGIN_NAME),
            ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($error) ? $error : false
        ];
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
        if (!empty($var) && !filter_var($var, FILTER_VALIDATE_URL, $options)) {
            $error = __('⭕ Invalid Idp SLO URL, use: scheme://host.domain.tld/path/', PLUGIN_NAME);
        }

        return [
            ConfigItem::FORMEXPLAIN  => __('Single Logout service endpoint of the IdP. URL Location of the IdP where SLO Request will be sent.OneLogin PHPSAML only supports the \'HTTP-redirect\' binding for this endpoint.', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('SLO URL', PLUGIN_NAME),
            ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($error) ? $error : false
        ];
    }

    // Im not yet happy with the structure and complexity.
    // Should be simplified.
    protected function idp_certificate(mixed $var): array //NOSONAR
    {
        // Is a required field!
        $e = false;
        $certificate = '';
        if (empty($var)) {
            $e = __('⭕ Valid Idp X509 certificate is required! (base64 encoded)', PLUGIN_NAME);
        } else {
            $parsed = ConfigItem::parseX509Certificate($var);
            if ($parsed) {
                if (!array_key_exists('subject', $parsed)) {
                    if (array_key_exists('validations', $parsed)) {
                        $e = $parsed['validations'];
                    } else {
                        $e = __('⭕ Valid Idp X509 certificate is required! (base64 encoded)', PLUGIN_NAME);
                    }
                } else {
                    $certificate = $parsed;
                    if (!empty($parsed['validations'])) {
                        $e = implode('<br>', $parsed['validations']);
                    }
                }
            } else {
                $e = __('⭕ Valid Idp X509 certificate is required! (base64 encoded)', PLUGIN_NAME);
            }
        }

        return [
            ConfigItem::FORMEXPLAIN  => __('The Public Base64 encoded x509 certificate used by the IdP. Fingerprinting can be used, but is not recommended. Fingerprinting requires you to manually alter the Saml Config array located in ConfigEntity.php and provide the required configuration options', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('X509 CERTIFICATE', PLUGIN_NAME),
            ConfigItem::EVAL      => ($e) ? ConfigItem::INVALID : ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => $e,
            ConfigItem::VALIDATE  => $certificate
        ];
    }


    protected function requested_authn_context(mixed $var): array //NOSONAR
    {
        // Normalize multiselect for database insert, form will pass an array
        // Database field expects a string.
        $val = '';
        if (is_array($var)) {
            $j = (count($var) - 1);
            for ($i = 0; $i <= $j; $i++) {
                $val .= ($i == $j) ? $var[$i] : $var[$i] . ':';
            }
        } else {
            $val = $var;
        }
        $val = (empty($val)) ? 'none' : $val;

        return [
            ConfigItem::FORMEXPLAIN => __('Authentication context needs to be satisfied by the IdP in order to allow Saml login. Set to "none" and OneLogin PHPSAML will not send an AuthContext in the AuthNRequest. Or, select one or more options using the "control+click" combination.', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('REQ AUTHN CONTEXT', PLUGIN_NAME),
            ConfigItem::EVAL      => ($val) ? ConfigItem::VALID : ConfigItem::INVALID,
            ConfigItem::VALUE     => (string) $val,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($val) ? false : __('⭕ Requested authN context is a required field', PLUGIN_NAME)
        ];
    }

    protected function requested_authn_context_comparison(mixed $var): array  //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => __('AUTHN Comparison attribute value', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('AUTHN COMPARISON', PLUGIN_NAME),
            ConfigItem::EVAL      => ($var) ? ConfigItem::VALID : ConfigItem::INVALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($var) ? false : __('⭕ Requested authN context comparison is a required field', PLUGIN_NAME)
        ];
    }

    protected function conf_icon(mixed $var): array                     //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => sprintf(__('The FontAwesome (%s) icon to show on the button on the login page.', PLUGIN_NAME), 'https://fontawesome.com/'),
            ConfigItem::FORMTITLE => __('LOGIN ICON', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::FIELD     => __function__,
            ConfigItem::ERRORS    => ($var) ? false : __('⭕ Configuration icon is a required field', PLUGIN_NAME)
        ];
    }

    protected function comment(mixed $var): array                       //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => __('The comments', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('COMMENTS', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::FIELD     => __function__,
            ConfigItem::ERRORS      => false
        ];
    }

    protected function saml_xml_structure(mixed $var): array
    {
        return [
            ConfigItem::FORMEXPLAIN => __('The anonymized SAML response XML structure', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('SAML Response XML Structure', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => false
        ];
    }

    // Might cast it into an EPOCH date with invalid values.
    protected function date_creation(mixed $var): array                 //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => __('The date this configuration item was created', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('CREATE DATE', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::RICHVALUE => new DateTime($var),
            ConfigItem::ERRORS    => false
        ];
    }

    // Might cast it into an EPOCH date with invalid values.
    protected function date_mod(mixed $var): array                      //NOSONAR
    {
        return [
            ConfigItem::FORMEXPLAIN => __('The date this config was modified', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('MODIFICATION DATE', PLUGIN_NAME),
            ConfigItem::EVAL      => ConfigItem::VALID,
            ConfigItem::VALUE     => (string) $var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::RICHVALUE => new DateTime($var),
            ConfigItem::ERRORS    => false
        ];
    }

    // BOOLEANS, We accept mixed, normalize in the handleAsBool function.
    // non ints are defaulted to boolean false.
    protected function is_deleted(mixed $var): array                    //NOSONAR
    {
        if (empty($var)) {
            $var = '0';
        }

        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('Is this configuration marked as deleted by GLPI', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('IS DELETED', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, 'is_deleted'),
        );
    }

    protected function is_active(mixed $var): array                     //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('Indicates if this configuration is activated. Disabled configurations cannot be used to log in to GLPI and will NOT be shown on the login page.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('IS ACTIVE', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::IS_ACTIVE)
        );
    }

    protected function enforce_sso(mixed $var): array                   //NOSONAR 
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, PHPSAML will replace the default GLPI login screen with a version that does not have the default GLPI login options and only allows the user to authenticate using the configured SAML2 IDPs. This setting can be bypassed using a bypass URI parameter.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('ENFORCED', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::ENFORCE_SSO)
        );
    }

    protected function proxied(mixed $var): array
    {
        $result = ConfigItem::handleAsBool($var, ConfigEntity::PROXIED);
        
        if ($result[ConfigItem::VALUE] == 1) {
            $validation = $this->validateProxyEnvironment();
            if (!$validation['isValid']) {
                $result[ConfigItem::ERRORS] = $validation['htmlTable'];
            }
        }
        
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('Is GLPI positioned behind a proxy that alters the SAML response scheme?', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('REQUESTS PROXIED', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            $result
        );
    }

    protected function strict(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, the OneLogin PHPSAML Toolkit will reject unsigned or unencrypted messages if it expects them to be signed or encrypted. Also, it will reject the messages if the SAML standard is not strictly followed: Destination, NameID, and Conditions are validated too. Strongly advised in production environments.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('STRICT', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::STRICT)
        );
    }

    protected function debug(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, it will force OneLogin PHPSAML to print status and error messages. Be aware that not all messages might be captured by samlSSO and might therefore not become visible. WARNING: Enabling debug mode will expose the Service Provider metadata publicly.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('DEBUG', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::DEBUG)
        );
    }

    protected function user_jit(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => __('If enabled, samlSSO will create new GLPI users on the fly and assign the properties defined in the samlSSO assignment rules. If disabled, users that do not have a valid GLPI user will not be able to log in to GLPI until a user is manually created.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('JIT USER CREATION', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::USER_JIT)
        );
    }

    /**
     * Validates and normalizes the sync_on_login boolean configuration option.
     *
     * @param  mixed $var  Raw input value (usually '1' or '0')
     * @return array       Contextual metadata and validation results
     */
    protected function sync_on_login(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, user fields mapped from SAML claims and assignment rules will be synchronized on every successful login.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('SYNC ON LOGIN', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SYNC_ON_LOGIN)
        );
    }

    /**
     * Validates and normalizes the security_wantmessagessigned option.
     *
     * @param  mixed $var  Raw input value
     * @return array       Metadata and validation results
     */
    protected function security_wantmessagessigned(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, GLPI will require all SAML protocol messages received from the Identity Provider (IdP) to be cryptographically signed.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('REQUIRE SIGNED MESSAGES', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SECURITY_WANTMESSAGESSIGNED)
        );
    }

    /**
     * Validates and normalizes the security_wantassertionssigned option.
     *
     * @param  mixed $var  Raw input value
     * @return array       Metadata and validation results
     */
    protected function security_wantassertionssigned(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, GLPI will require individual SAML assertions within the SAML message to be signed. If unsigned, assertions will be rejected.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('REQUIRE SIGNED ASSERTIONS', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SECURITY_WANTASSERTIONSSIGNED)
        );
    }

    /**
     * Validates and normalizes the security_wantassertionsencrypted option.
     *
     * @param  mixed $var  Raw input value
     * @return array       Metadata and validation results
     */
    protected function security_wantassertionsencrypted(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, GLPI will expect the SAML assertions containing user claims to be encrypted. GLPI will decrypt them using the SP private key.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('REQUIRE ENCRYPTED ASSERTIONS', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SECURITY_WANTASSERTIONSENCRYPTED)
        );
    }

    /**
     * Validates and normalizes the security_signmetadata option.
     *
     * @param  mixed $var  Raw input value
     * @return array       Metadata and validation results
     */
    protected function security_signmetadata(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, the SAML SP metadata XML generated by GLPI will be cryptographically signed using the Service Provider certificate.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('SIGN SP METADATA', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SECURITY_SIGNMETADATA)
        );
    }

    /**
     * Validates and normalizes the security_wantnameid option.
     *
     * @param  mixed $var  Raw input value
     * @return array       Metadata and validation results
     */
    protected function security_wantnameid(mixed $var): array
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, GLPI will strictly require a NameID element to be present in the SAML assertion payload.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('REQUIRE NAMEID', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SECURITY_WANTNAMEID)
        );
    }

    protected function security_nameidencrypted(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => htmlspecialchars(__('If enabled, the OneLogin PHPSAML toolkit will encrypt the <samlp:logoutRequest> sent by this SP using the provided SP certificate and private key. This option will be toggled "off" automatically if no, or no valid SP certificate and key is provided.', PLUGIN_NAME)),
                ConfigItem::FORMTITLE     => __('ENCRYPT NAMEID', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::ENCRYPT_NAMEID)
        );
    }

    protected function security_authnrequestssigned(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => htmlspecialchars(__('If enabled, the OneLogin PHPSAML toolkit will sign the <samlp:AuthnRequest> messages sent by this SP. The IDP should consult the metadata to get the information required to validate the signatures.', PLUGIN_NAME)),
                ConfigItem::FORMTITLE     => __('SIGN AUTHN REQUEST', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SIGN_AUTHN)
        );
    }

    protected function security_logoutrequestsigned(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => htmlspecialchars(__('If enabled, the OneLogin PHPSAML toolkit will sign the <samlp:logoutRequest> messages sent by this SP.', PLUGIN_NAME)),
                ConfigItem::FORMTITLE     => __('SIGN LOGOUT REQUEST', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SIGN_SLO_REQ)
        );
    }

    protected function security_logoutresponsesigned(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => htmlspecialchars(__('If enabled, the OneLogin PHPSAML toolkit will sign the <samlp:logoutResponse> messages sent by this SP.', PLUGIN_NAME)),
                ConfigItem::FORMTITLE     => __('SIGN LOGOUT RESPONSE', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::SIGN_SLO_RES)
        );
    }

    protected function compress_requests(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => __('If enabled, the authentication requests sent to the IdP will be compressed by the SP.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('COMPRESS REQUESTS', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::COMPRESS_REQ)
        );
    }

    protected function compress_responses(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN     => __('If enabled, the SP expects responses sent by the IdP to be compressed.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('COMPRESS RESPONSES', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::COMPRESS_RES)
        );
    }

    protected function validate_xml(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, the SP will validate all received XMLs. In order to validate the XML, the "strict" security setting must be enabled.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('VALIDATE XML', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::XML_VALIDATION)
        );
    }

    protected function validate_destination(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('If enabled, SAMLResponses with an empty value at its Destination attribute will not be rejected for this fact.', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('RELAX DEST VALIDATION', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::DEST_VALIDATION)
        );
    }

    protected function lowercase_url_encoding(mixed $var): array //NOSONAR
    {
        return array_merge(
            [
                ConfigItem::FORMEXPLAIN   => __('ADFS URL-Encodes SAML data as lowercase, and the OneLogin PHPSAML toolkit by default uses uppercase. Enable this setting for ADFS compatibility on signature verification', PLUGIN_NAME),
                ConfigItem::FORMTITLE     => __('LOWER CASE ENCODING', PLUGIN_NAME),
                ConfigItem::FIELD         => __function__,
                ConfigItem::VALIDATOR     => __method__,
            ],
            ConfigItem::handleAsBool($var, ConfigEntity::LOWERCASE_URL)
        );
    }

    // Make sure we always return the correct boolean datatype.
    protected function handleAsBool(mixed $var, $field = null): array
    {
        if ($var === '' || $var === null) {
            $var = '0';
        }
        // Default to false if no or an impropriate value is provided.
        $error = (!empty($var) && !preg_match('/[0-1]/', (string) $var)) ? sprintf(__("⭕ %s can only be 1 or 0", PLUGIN_NAME), $field) : false;

        return [
            ConfigItem::EVAL   => (is_numeric($var)) ? ConfigItem::VALID : ConfigItem::INVALID,
            ConfigItem::VALUE  => (!$error) ? $var : '0',
            ConfigItem::ERRORS => $error
        ];
    }

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
            if (($parsedCertificate = openssl_x509_parse($certificate))) {
                // Create time object from current timestamp to calculate with
                $n = new DateTimeImmutable('now');
                // Create time objects from certificate validity properties.
                $t = $this->createCertificateDateTime($parsedCertificate, 'validTo');
                $f = $this->createCertificateDateTime($parsedCertificate, 'validFrom');
                // Get the certificate's common name property.
                /** @var array $subject */
                $subject = $parsedCertificate['subject'];
                $cn = (string)($subject['CN'] ?? '');
                if ($t instanceof DateTimeImmutable) {
                    // Calculate if the current date is past the validTo certificate property.
                    $aged = $n->diff($t)->format('%R%a');
                    if (strpos($aged, '-') !== false) {
                        $validations['validTo'] = sprintf(__("⚠️ Warning, certificate with Common Name (CN): %s is expired: %s days", PLUGIN_NAME), $cn, $aged);
                    }
                } else {
                    $validations['validTo'] = __('⭕ Unable to parse certificate validTo date', PLUGIN_NAME);
                }
                if ($f instanceof DateTimeImmutable) {
                    // Calculate if the current date is before the validFrom certificate property.
                    $born = $f->diff($n)->format('%R%a');
                    if (strpos($born, '-') !== false) {
                        $validations['validFrom'] = sprintf(__("⚠️ Warning, certificate with Common Name (CN): %s issued in the future (%s days)", PLUGIN_NAME), $cn, $born);
                    }
                } else {
                    $validations['validFrom'] = __('⭕ Unable to parse certificate validFrom date', PLUGIN_NAME);
                }
                if ($cn == 'withlove.from.donuts.nl') {
                    $validations['validFrom'] = __("⚠️ Warning, do not use the 'withlove.from.donuts.nl' example certificates. They offer no additional protection.", PLUGIN_NAME);
                }
                $parsedCertificate['validations'] = $validations;
                return $parsedCertificate;
            } else {
                // Base64 encoded certificates should have these tags (see rfc7468 chap 2)
                if (
                    strpos($certificate, '-----BEGIN CERTIFICATE-----') === false ||
                    strpos($certificate, '-----END CERTIFICATE-----') === false
                ) {
                    return ['validations'   => __('⭕ Certificate must be wrapped in valid BEGIN CERTIFICATE and END CERTIFICATE tags', PLUGIN_NAME)];
                }
                // While RFC1421/7468 permit CRLF, we explicitly forbid carriage returns [\r] to prevent
                // XML Canonicalization (C14N) issues. Literal [\r] characters in stored certificates
                // can lead to signature validation failures during SAML processing.
                if (strpos($certificate, chr(13)) !== false) {
                    return ['validations'   => __('⭕ Certificate should not contain "carriage returns" [<CR>]', PLUGIN_NAME)];
                }
            }
            // Else return generic error.
            return ['validations'   => __('⭕ No valid X509 certificate found', PLUGIN_NAME)];
        }
        // Return message OpenSSL is not available.
        return ['validations'   => __('⚠️ OpenSSL is not available, GLPI cant validate your certificate', PLUGIN_NAME)];
    }

    private function createCertificateDateTime(array $parsedCertificate, string $field): ?DateTimeImmutable
    {
        $timestampField = $field . '_time_t';
        if (isset($parsedCertificate[$timestampField]) && is_numeric($parsedCertificate[$timestampField])) {
            return (new DateTimeImmutable())->setTimestamp((int)$parsedCertificate[$timestampField]);
        }

        if (empty($parsedCertificate[$field]) || !is_string($parsedCertificate[$field])) {
            return null;
        }

        foreach (['ymdHisT', 'YmdHisT'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $parsedCertificate[$field]);
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    protected function validateCertKeyPairModulus(string $certificate, string $privateKey): bool         //NOSONAR - Maybe fix complexity in the future
    {
        if (function_exists('openssl_x509_parse') && function_exists('openssl_x509_check_private_key')) {
            return (openssl_x509_check_private_key($certificate, [$privateKey, ''])) ? true : false;
        } else {
            // Cannot validate always return true;
            return true;
        }
    }

    protected function request_timeout(mixed $var): array
    {
        $error = false;
        if (!is_numeric($var) || (int)$var <= 0) {
            $error = __('⭕ Request timeout must be a positive integer (minutes)', PLUGIN_NAME);
        }

        $result = [
            ConfigItem::FORMEXPLAIN => __('The duration in minutes before an uncompleted SAML request is considered expired.', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('REQUEST TIMEOUT (MINUTES)', PLUGIN_NAME),
            ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
            ConfigItem::VALUE     => ($error) ? '15' : (string)(int)$var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($error) ? $error : false,
        ];

        if (!$error) {
            $tzValidation = $this->validateTimezoneEnvironment();
            if (!empty($tzValidation['htmlTable'])) {
                $result[ConfigItem::ERRORS] = $tzValidation['htmlTable'];
                if (!$tzValidation['isValid']) {
                    $result[ConfigItem::EVAL] = ConfigItem::INVALID;
                }
            }
        }

        return $result;
    }

    protected function inactivity_timeout(mixed $var): array
    {
        $error = false;
        if (!is_numeric($var) || (int)$var < 0) {
            $error = __('⭕ Inactivity timeout must be a non-negative integer (minutes)', PLUGIN_NAME);
        }

        $result = [
            ConfigItem::FORMEXPLAIN => __('The duration in minutes of inactivity before a session is forcefully logged out (0 to disable).', PLUGIN_NAME),
            ConfigItem::FORMTITLE => __('INACTIVITY TIMEOUT (MINUTES)', PLUGIN_NAME),
            ConfigItem::EVAL      => ($error) ? ConfigItem::INVALID : ConfigItem::VALID,
            ConfigItem::VALUE     => ($error) ? '0' : (string)(int)$var,
            ConfigItem::FIELD     => __function__,
            ConfigItem::VALIDATOR => __method__,
            ConfigItem::ERRORS    => ($error) ? $error : false,
        ];

        if (!$error) {
            $tzValidation = $this->validateTimezoneEnvironment();
            if (!empty($tzValidation['htmlTable'])) {
                $result[ConfigItem::ERRORS] = $tzValidation['htmlTable'];
                if (!$tzValidation['isValid']) {
                    $result[ConfigItem::EVAL] = ConfigItem::INVALID;
                }
            }
        }

        return $result;
    }

    /**
     * Validates the reverse proxy headers and secure cookie configuration.
     *
     * @return array Array with keys 'isValid' (bool) and 'htmlTable' (string) containing details.
     */
    private function validateProxyEnvironment(): array
    {
        $xff = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
        $xfp = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '';
        $xfh = !empty($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : '';
        
        $isHttps = (
            (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
            (strtolower($xfp) === 'https')
        );
        
        $cookieSecureEnabled = (bool)ini_get('session.cookie_secure');
        $cookieConflict = ($cookieSecureEnabled && !$isHttps);
        $cookieSecurityMissing = ($isHttps && !$cookieSecureEnabled);
        
        $hasXForwarded = !empty($xff) || !empty($xfh) || !empty($xfp);
        
        if ($hasXForwarded && $isHttps && !$cookieConflict && !$cookieSecurityMissing) {
            return ['isValid' => true, 'htmlTable' => ''];
        }
        
        $cookieSecure = $cookieSecureEnabled ? __('Active (Secure)', PLUGIN_NAME) : __('Inactive', PLUGIN_NAME);
        
        $statusXff = !empty($xff) ? '✅' : '❌';
        $statusXfp = (strtolower($xfp) === 'https') ? '✅' : '❌';
        $statusXfh = !empty($xfh) ? '✅' : '❌';
        $statusHttps = $isHttps ? '✅' : '❌';
        $statusCookie = ($cookieConflict || $cookieSecurityMissing) ? '❌' : '✅';
        
        $detectedHttpsStr = $isHttps ? 'HTTPS' : 'HTTP';
        
        $table = '<table class="table table-bordered table-sm text-start mt-2" style="font-size: 0.8rem; min-width: 320px; margin-bottom: 0;">';
        $table .= '<thead><tr class="table-light"><th>' . __('Condition / Parameter', PLUGIN_NAME) . '</th><th>' . __('Expected', PLUGIN_NAME) . '</th><th>' . __('Detected', PLUGIN_NAME) . '</th><th>' . __('Status', PLUGIN_NAME) . '</th></tr></thead>';
        $table .= '<tbody>';
        
        // session.cookie_secure check
        $expectedCookie = $isHttps ? __('Active (Secure)', PLUGIN_NAME) : __('Any', PLUGIN_NAME);
        $table .= '<tr><td>session.cookie_secure</td><td>' . $expectedCookie . '</td><td>' . $cookieSecure . '</td><td>' . $statusCookie . '</td></tr>';
        
        // Proxy headers
        $detectedXff = !empty($xff) ? sprintf(__('Detected (%s)', PLUGIN_NAME), $xff) : __('Not Detected', PLUGIN_NAME);
        $detectedXfp = !empty($xfp) ? sprintf(__('Detected (%s)', PLUGIN_NAME), $xfp) : __('Not Detected', PLUGIN_NAME);
        $detectedXfh = !empty($xfh) ? sprintf(__('Detected (%s)', PLUGIN_NAME), $xfh) : __('Not Detected', PLUGIN_NAME);
        
        $linkXff = '<a href="https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-For" target="_blank" rel="noopener noreferrer">X-Forwarded-For</a>';
        $linkXfp = '<a href="https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-Proto" target="_blank" rel="noopener noreferrer">X-Forwarded-Proto</a>';
        $linkXfh = '<a href="https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-Host" target="_blank" rel="noopener noreferrer">X-Forwarded-Host</a>';
        
        $table .= '<tr><td>' . $linkXff . '</td><td>' . __('Present', PLUGIN_NAME) . '</td><td>' . $detectedXff . '</td><td>' . $statusXff . '</td></tr>';
        $table .= '<tr><td>' . $linkXfp . '</td><td>https</td><td>' . $detectedXfp . '</td><td>' . $statusXfp . '</td></tr>';
        $table .= '<tr><td>' . $linkXfh . '</td><td>' . __('Present', PLUGIN_NAME) . '</td><td>' . $detectedXfh . '</td><td>' . $statusXfh . '</td></tr>';
        
        // HTTPS Context
        $expectedHttps = $cookieSecureEnabled ? __('HTTPS (Secure)', PLUGIN_NAME) : __('HTTP or HTTPS', PLUGIN_NAME);
        $table .= '<tr><td>HTTPS Context (PHP)</td><td>' . $expectedHttps . '</td><td>' . $detectedHttpsStr . '</td><td>' . $statusHttps . '</td></tr>';
        $table .= '</tbody></table>';
        
        if ($cookieConflict) {
            $table .= '<div class="mt-2 text-danger" style="font-size: 0.75rem; font-weight: 600;">' . __('⚠️ Conflict: Secure cookie is enabled but PHP is running in a non-secure HTTP context. Cookies will be rejected by the browser!', PLUGIN_NAME) . '</div>';
        }
        if ($cookieSecurityMissing) {
            $table .= '<div class="mt-2 text-warning" style="font-size: 0.75rem; font-weight: 600;">' . __('⚠️ Security Warning: HTTPS is detected but session.cookie_secure is disabled. Cookies are not protected!', PLUGIN_NAME) . '</div>';
        }
        $table .= '<div class="mt-2 text-muted" style="font-size: 0.75rem;">' . __('Unsatisfied conditions might break the authentication flow in proxied or containerized environments depending on your setup and naming schemes.', PLUGIN_NAME) . '</div>';
        
        return ['isValid' => false, 'htmlTable' => $table];
    }

    /**
     * Validates if the PHP timezone and database timezone are synchronized.
     *
     * @return array Array with keys 'isValid' (bool) and 'htmlTable' (string) containing details.
     */
    private function validateTimezoneEnvironment(): array
    {
        global $DB;
        $dbNow = null;
        try {
            $iterator = $DB->request('SELECT CURRENT_TIMESTAMP() as now');
            if ($iterator && $iterator->count() > 0) {
                $dbNow = $iterator->current()['now'];
            }
        } catch (\Throwable $e) {
            // Keep null
        }

        if (empty($dbNow)) {
            return ['isValid' => true, 'htmlTable' => ''];
        }

        $dbTime = strtotime($dbNow);
        $phpTime = time();
        $diff = abs($phpTime - $dbTime);

        $isDebug = false;
        if (isset($_POST['debug'])) {
            $isDebug = ((int)$_POST['debug'] === 1);
        } elseif (isset($this->fields['debug'])) {
            $isDebug = !empty($this->fields['debug']);
        }

        // If not misaligned and debug is not active, do not show anything
        if ($diff <= 15 && !$isDebug) {
            return ['isValid' => true, 'htmlTable' => ''];
        }

        // Format times for presentation
        $phpTz = date_default_timezone_get();
        $phpTimeStr = date('Y-m-d H:i:s', $phpTime) . ' (' . $phpTz . ')';

        $dbTzQuery = null;
        try {
            $tzIterator = $DB->request('SELECT @@session.time_zone as tz');
            if ($tzIterator && $tzIterator->count() > 0) {
                $dbTzQuery = $tzIterator->current()['tz'];
            }
        } catch (\Throwable $e) {
            // Keep null
        }
        $dbTz = $dbTzQuery ?: 'Unknown';
        $dbTimeStr = $dbNow . ' (' . $dbTz . ')';

        $table = '<table class="table table-bordered table-sm text-start mt-2" style="font-size: 0.8rem; min-width: 320px; margin-bottom: 0;">';
        $table .= '<thead><tr class="table-light"><th>' . __('System', PLUGIN_NAME) . '</th><th>' . __('Current Time', PLUGIN_NAME) . '</th><th>' . __('Status', PLUGIN_NAME) . '</th></tr></thead>';
        $table .= '<tbody>';
        $table .= '<tr><td>PHP Environment</td><td>' . $phpTimeStr . '</td><td>✅</td></tr>';

        if ($diff <= 15) {
            $table .= '<tr><td>Database Session</td><td>' . $dbTimeStr . '</td><td>✅</td></tr>';
            $table .= '</tbody></table>';
            $table .= '<div class="mt-2 text-success" style="font-size: 0.75rem; font-weight: 600;">' . __('✅ Timezone Alignment: PHP and Database connection clocks are synchronized. (Shown because Debug mode is enabled)', PLUGIN_NAME) . '</div>';
            return ['isValid' => true, 'htmlTable' => $table];
        }

        $table .= '<tr><td>Database Session</td><td>' . $dbTimeStr . '</td><td>❌</td></tr>';
        $table .= '</tbody></table>';

        $diffHours = round($diff / 3600, 2);
        $table .= '<div class="mt-2 text-danger" style="font-size: 0.75rem; font-weight: 600;">' . sprintf(__('⚠️ Timezone Mismatch: PHP and Database connection clocks differ by %s hours (%s seconds). This will cause authentication requests to time out prematurely or linger too long.', PLUGIN_NAME), $diffHours, $diff) . '</div>';
        $table .= '<div class="mt-2 text-muted" style="font-size: 0.75rem;">' . __('Ensure GLPI\'s timezone usage configuration is enabled (under Setup > General > System), MySQL timezone tables are loaded, or PHP\'s date.timezone matches the database timezone.', PLUGIN_NAME) . '</div>';

        return ['isValid' => true, 'htmlTable' => $table];
    }
}
