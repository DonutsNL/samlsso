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
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

/**
 * TestHarness.php
 * 
 * Provides a base environment for all tests.
 * Includes shims for GLPI core and local mocks for samlsso components.
 */

namespace {
    require_once __DIR__ . '/Shims.php';
}

namespace OneLogin\Saml2 {
    /**
     * Mock OneLogin\Saml2\Response class for testing SAML response parsing.
     */
    if (!class_exists('OneLogin\Saml2\Response')) {
        class Response
        {
            /** @var bool Mocks response validity status. */
            public static bool $mockValid = true;
            /** @var string Mocks SAML response identifier. */
            public static string $mockId = 'MOCK_RESPONSE_ID_123';
            /** @var string Mocks requestId matching (InResponseTo attribute). */
            public static string $mockInResponseTo = 'ONELOGIN_12345';
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockThrow = null;
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockIsValidThrow = null;

            /**
             * Response constructor.
             *
             * @param \OneLogin\Saml2\Settings|array $settings SAML Settings.
             * @param string $assertion The base64-encoded assertion.
             */
            public function __construct(\OneLogin\Saml2\Settings|array $settings, string $assertion) {
                if (self::$mockThrow !== null) {
                    throw self::$mockThrow;
                }
            }

            /**
             * Mocks checking response validity.
             *
             * @param string|null $requestId Original request ID.
             * @return bool Response validity.
             */
            public function isValid(?string $requestId = null): bool
            {
                if (self::$mockIsValidThrow !== null) {
                    throw self::$mockIsValidThrow;
                }
                return self::$mockValid;
            }

            /**
             * Mocks getting parsed attributes.
             *
             * @return array Mock user attributes.
             */
            public function getAttributes(): array
            {
                return ['email' => ['test@example.com']];
            }

            /**
             * Mocks getting user Name ID.
             *
             * @return string Mock name ID.
             */
            public function getNameId(): string
            {
                return 'testuser';
            }

            /**
             * Mocks getting response ID.
             *
             * @return string Mock response ID.
             */
            public function getId(): string
            {
                return self::$mockId;
            }

            /**
             * Mocks getting validation error message.
             *
             * @param bool $escape Whether to HTML escape.
             * @return string Mock error message.
             */
            public function getError(bool $escape = true): string
            {
                return 'Mock Response Error';
            }

            /**
             * Mocks getting the XML Document object.
             *
             * @return object Mock XML document structure.
             */
            public function getXMLDocument(): object
            {
                $inResponseTo = self::$mockInResponseTo;
                return new class($inResponseTo) {
                    /** @var object Document root element wrapper. */
                    public $documentElement;

                    /**
                     * Inner XML document constructor.
                     *
                     * @param string $inResponseTo InResponseTo value.
                     */
                    public function __construct(string $inResponseTo) {
                        $this->documentElement = new class($inResponseTo) {
                            /** @var string InResponseTo value. */
                            private string $inResponseTo;

                            /**
                             * Inner document element constructor.
                             *
                             * @param string $inResponseTo InResponseTo value.
                             */
                            public function __construct(string $inResponseTo) {
                                $this->inResponseTo = $inResponseTo;
                            }

                            /**
                             * Mock retrieving attribute value.
                             *
                             * @param string $name Attribute name.
                             * @return string Attribute value.
                             */
                            public function getAttribute(string $name): string {
                                if ($name === 'InResponseTo') {
                                    return $this->inResponseTo;
                                }
                                return '';
                            }
                        };
                    }

                    /**
                     * Mock saving XML to string.
                     *
                     * @return string Mock XML string.
                     */
                    public function saveXML(): string {
                        return '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" InResponseTo="' . $this->documentElement->getAttribute('InResponseTo') . '"></samlp:Response>';
                    }
                };
            }
        }
    }

    /**
     * Mock OneLogin\Saml2\Auth class for handling SAML flows.
     */
    if (!class_exists('OneLogin\Saml2\Auth')) {
        class Auth
        {
            /** @var array Settings store. */
            private array $settings;

            /**
             * Auth constructor.
             *
             * @param array $settings Configuration settings.
             */
            public function __construct(array $settings) {
                $this->settings = $settings;
            }

            /**
             * Mock processing the SAML Response.
             */
            public function processResponse(): void {}

            /**
             * Mock getting last request ID.
             *
             * @return string Last request ID.
             */
            public function getLastRequestID(): string
            {
                return 'ONELOGIN_12345';
            }

            /**
             * Mock getting errors.
             *
             * @return array List of errors.
             */
            public function getErrors(): array
            {
                return [];
            }

            /**
             * Mock getting last error reason.
             *
             * @return string Error reason.
             */
            public function getLastErrorReason(): string
            {
                return '';
            }

            /**
             * Mock generating login redirect URL.
             *
             * @param string|null $returnTo Redirect target.
             * @param array $parameters URL parameters.
             * @param bool $forceAuthn Force re-authentication.
             * @param bool $isPassive Passive authentication.
             * @param bool $stay Stay on page.
             * @param bool $setNameIdPolicy Specify name ID policy.
             * @param string|null $nameIdValueReq Specific name ID.
             * @return string Redirect URL.
             */
            public function login(
                ?string $returnTo = null,
                array $parameters = array(),
                bool $forceAuthn = false,
                bool $isPassive = false,
                bool $stay = false,
                bool $setNameIdPolicy = true,
                ?string $nameIdValueReq = null
            ): string {
                $id = $this->settings['idp_id'] ?? 5;
                return "/plugins/samlsso/front/sso.php?idp=" . $id;
            }

            /**
             * Mock generating logout redirect URL.
             *
             * @param string|null $returnTo Redirect target.
             * @param array $parameters URL parameters.
             * @param string|null $nameId Name ID.
             * @param string|null $sessionIndex Session index.
             * @param bool $stay Stay on page.
             * @param string|null $nameIdFormat Name ID format.
             * @param string|null $nameIdNameQualifier Qualifier.
             * @param string|null $nameIdSPNameQualifier SP Qualifier.
             * @return string Redirect URL.
             */
            public function logout(
                ?string $returnTo = null,
                array $parameters = array(),
                ?string $nameId = null,
                ?string $sessionIndex = null,
                bool $stay = false,
                ?string $nameIdFormat = null,
                ?string $nameIdNameQualifier = null,
                ?string $nameIdSPNameQualifier = null
            ): string {
                return '/plugins/samlsso/front/slo.php';
            }
        }
    }

    if (!class_exists('OneLogin\Saml2\Utils')) {
        class Utils
        {
            public static ?\Throwable $mockThrow = null;
            public function __construct() {
                if (self::$mockThrow !== null) {
                    throw self::$mockThrow;
                }
            }
            public static function setProxyVars(bool $enabled = false): void
            {
                if (self::$mockThrow !== null) {
                    throw self::$mockThrow;
                }
            }
        }
    }
}

namespace OneLogin\Saml2\Utils {
    /**
     * Mock functions under OneLogin\Saml2\Utils namespace.
     */
    if (!function_exists('OneLogin\Saml2\Utils\setProxyVars')) {
        /**
         * Mock setting proxy environment variables.
         */
        function setProxyVars(): void {}

        /**
         * Mock getting the self URL host.
         *
         * @return string Host name.
         */
        function getSelfURLhost(): string
        {
            return 'glpi.local';
        }
    }
}

namespace Glpi\Application\View {
    /**
     * Mock GLPI TemplateRenderer for rendering pages and error views.
     */
    if (!class_exists('Glpi\Application\View\TemplateRenderer', false)) {
        class TemplateRenderer
        {
            /**
             * Get TemplateRenderer instance singleton.
             *
             * @return self Instance.
             */
            public static function getInstance(): self
            {
                return new self();
            }

            /**
             * Mocks rendering a Twig template.
             *
             * @param string $template Template path.
             * @param array $vars Template variables.
             * @return string Rendered output string.
             */
            public function render(string $template, array $vars): string
            {
                $varsStr = '';
                foreach ($vars as $k => $v) {
                    if (is_scalar($v)) {
                        $varsStr .= " [$k: " . ($v === true ? 'true' : ($v === false ? 'false' : $v)) . "]";
                    }
                }
                return "Rendered: $template" . (isset($vars['error']) ? " (Error: {$vars['error']})" : "") . $varsStr;
            }

            /**
             * Mocks displaying/echoing a Twig template.
             *
             * @param string $template Template path.
             * @param array $vars Template variables.
             */
            public function display(string $template, array $vars): void
            {
                $varsStr = '';
                foreach ($vars as $k => $v) {
                    if (is_scalar($v)) {
                        $varsStr .= " [$k: " . ($v === true ? 'true' : ($v === false ? 'false' : $v)) . "]";
                    }
                }
                echo "Displayed: $template" . (isset($vars['error']) ? " (Error: {$vars['error']})" : "") . $varsStr;
            }
        }
    }
}

namespace GlpiPlugin\Samlsso\LoginFlow {
    /**
     * Mock class for database user lookup in LoginFlow namespace.
     */
    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\MockLoginFlowUser', false)) {
        class MockLoginFlowUser
        {
            /**
             * Mocks searching for a user in the database by field.
             *
             * @param string $field Database field.
             * @param string $value Search value.
             * @return bool True if found.
             */
            public function getFromDBByField(string $field, string $value): bool
            {
                return true;
            }

            /**
             * Mocks extracting user fields from SAML claims.
             *
             * @param mixed $response SAML Response object.
             * @return array Extracted user fields.
             */
            public static function getUserInputFieldsFromSamlClaim($response): array
            {
                return ['username' => 'testuser'];
            }
        }
    }

    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\User', false)) {
        class_alias('GlpiPlugin\Samlsso\LoginFlow\MockLoginFlowUser', 'GlpiPlugin\Samlsso\LoginFlow\User');
    }

    /**
     * Mock class for authenticating users.
     */
    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\MockAuth', false)) {
        class MockAuth
        {
            /**
             * Mocks logging in a user by username/password.
             *
             * @param string $user Username.
             * @param string $pass Password.
             * @return bool True if successful.
             */
            public function login(string $user, string $pass): bool
            {
                return true;
            }

            /**
             * Mocks loading user details.
             *
             * @param array $userFields Loaded user fields.
             * @param \GlpiPlugin\Samlsso\Config\ConfigEntity $configEntity Associated configuration.
             * @return self Reference.
             */
            public function loadUser(array $userFields, \GlpiPlugin\Samlsso\Config\ConfigEntity $configEntity): self
            {
                return $this;
            }

            /**
             * Mocks getting user loading errors.
             *
             * @return array List of errors.
             */
            public function getErrors(): array
            {
                return [];
            }
        }
    }

    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\Auth', false)) {
        class_alias('GlpiPlugin\Samlsso\LoginFlow\MockAuth', 'GlpiPlugin\Samlsso\LoginFlow\Auth');
    }
}

namespace GlpiPlugin\Samlsso\Config {
    /**
     * Mock config entity class containing field mappings and constants.
     */
    if (!class_exists('GlpiPlugin\Samlsso\Config\MockConfigEntity', false)) {
        class MockConfigEntity
        {
            public const ID              = 'id';
            public const NAME            = 'name';
            public const CONF_DOMAIN     = 'conf_domain';
            public const CONF_ICON       = 'conf_icon';
            public const ENFORCE_SSO     = 'enforce_sso';
            public const PROXIED         = 'proxied';
            public const STRICT          = 'strict';
            public const DEBUG           = 'debug';
            public const USER_JIT        = 'user_jit';
            public const SP_CERTIFICATE  = 'sp_certificate';
            public const SP_KEY          = 'sp_private_key';
            public const SP_NAME_FORMAT  = 'sp_nameid_format';
            public const IDP_ENTITY_ID   = 'idp_entity_id';
            public const IDP_SSO_URL     = 'idp_single_sign_on_service';
            public const IDP_SLO_URL     = 'idp_single_logout_service';
            public const IDP_CERTIFICATE = 'idp_certificate';
            public const AUTHN_CONTEXT   = 'requested_authn_context';
            public const AUTHN_COMPARE   = 'requested_authn_context_comparison';
            public const ENCRYPT_NAMEID  = 'security_nameidencrypted';
            public const SIGN_AUTHN      = 'security_authnrequestssigned';
            public const SIGN_SLO_REQ    = 'security_logoutrequestsigned';
            public const SIGN_SLO_RES    = 'security_logoutresponsesigned';
            public const COMPRESS_REQ    = 'compress_requests';
            public const COMPRESS_RES    = 'compress_responses';
            public const XML_VALIDATION  = 'validate_xml';
            public const DEST_VALIDATION = 'validate_destination';
            public const LOWERCASE_URL   = 'lowercase_url_encoding';
            public const COMMENT         = 'comment';
            public const IS_ACTIVE       = 'is_active';
            public const IS_DELETED      = 'is_deleted';
            public const CREATE_DATE     = 'date_creation';
            public const MOD_DATE        = 'date_mod';
            public const SYNC_ON_LOGIN   = 'sync_on_login';
            public const SECURITY_WANTMESSAGESSIGNED = 'security_wantmessagessigned';
            public const SECURITY_WANTASSERTIONSSIGNED = 'security_wantassertionssigned';
            public const SECURITY_WANTASSERTIONSENCRYPTED = 'security_wantassertionsencrypted';
            public const SECURITY_SIGNMETADATA = 'security_signmetadata';
            public const SECURITY_WANTNAMEID = 'security_wantnameid';

            /** @var array Mocks configuration field storage. */
            public static array $mockFields = [];
            /** @var int Identity ID. */
            private int $id = -1;
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockThrow = null;

            /**
             * ConfigEntity constructor.
             *
             * @param int $id Entity ID.
             */
            public function __construct(int $id = -1) {
                if (self::$mockThrow !== null) {
                    throw self::$mockThrow;
                }
                $this->id = $id;
            }

            /**
             * Retrieves a config field value.
             *
             * @param string $field Field name.
             * @return mixed Field value.
             */
            public function getField(string $field): mixed
            {
                return self::$mockFields[$field] ?? null;
            }

            /**
             * Retrieves all config fields.
             *
             * @return array List of fields.
             */
            public function getFields(): array
            {
                return [];
            }

            /**
             * Checks if entity config parameters are valid.
             *
             * @return bool True if valid.
             */
            public function isValid(): bool
            {
                return true;
            }

            /**
             * Checks if configuration is active.
             *
             * @return bool True if active.
             */
            public function isActive(): bool
            {
                return true;
            }

            /**
             * Gets configuration domain constraint.
             *
             * @return string|null Domain string or null.
             */
            public function getConfigDomain(): ?string
            {
                return null;
            }

            /**
             * Transforms entity parameters into a php-saml config array structure.
             *
             * @return array Saml config array.
             */
            public function getPhpSamlConfig(): array
            {
                return ['idp_id' => $this->id];
            }

            public static function anonymizeXml(string $xml): string
            {
                if (empty($xml)) {
                    return '';
                }

                $dom = new \DOMDocument();
                $old_entity_loader = null;
                if (\PHP_VERSION_ID < 80000) {
                    $old_entity_loader = libxml_disable_entity_loader(true);
                }
                
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;

                if (!@$dom->loadXML($xml, LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR | LIBXML_NOCDATA)) {
                    if (\PHP_VERSION_ID < 80000 && $old_entity_loader !== null) {
                        libxml_disable_entity_loader($old_entity_loader);
                    }
                    return 'Invalid XML response';
                }
                
                if (\PHP_VERSION_ID < 80000 && $old_entity_loader !== null) {
                    libxml_disable_entity_loader($old_entity_loader);
                }

                self::anonymizeNode($dom->documentElement);

                return $dom->saveXML() ?: 'Error processing XML';
            }

            private static function anonymizeNode(\DOMNode $node): void
            {
                $cryptoTags = ['Signature', 'SignatureValue', 'DigestValue', 'X509Certificate', 'CipherValue', 'EncryptedData'];
                
                $toRemove = [];
                foreach ($node->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $localName = $child->localName ?: $child->nodeName;
                        if (in_array($localName, $cryptoTags, true)) {
                            $toRemove[] = $child;
                        } else {
                            self::anonymizeNode($child);
                        }
                    }
                }

                foreach ($toRemove as $child) {
                    $node->removeChild($child);
                }

                $allowedAttributes = ['Name', 'FriendlyName', 'NameFormat', 'Format', 'Version'];
                if ($node->attributes) {
                    foreach ($node->attributes as $attr) {
                        if (str_starts_with($attr->nodeName, 'xmlns') || in_array($attr->nodeName, $allowedAttributes, true)) {
                            continue;
                        }
                        $attr->nodeValue = '[STRIPPED]';
                    }
                }

                if ($node->childNodes->length === 1 && $node->firstChild->nodeType === XML_TEXT_NODE) {
                    $val = trim($node->firstChild->nodeValue);
                    if ($val !== '') {
                        $node->firstChild->nodeValue = '[STRIPPED]';
                    }
                }
            }

            public function updateXmlStructure(string $anonymizedXml): void
            {
                self::$mockFields['saml_xml_structure'] = $anonymizedXml;
            }
        }
    }

    if (!class_exists('GlpiPlugin\Samlsso\Config\ConfigEntity', false)) {
        class_alias('GlpiPlugin\Samlsso\Config\MockConfigEntity', 'GlpiPlugin\Samlsso\Config\ConfigEntity');
    }
}

namespace GlpiPlugin\Samlsso {
    /**
     * Wrapper class for OneLogin Auth.
     */
    if (!class_exists('GlpiPlugin\Samlsso\samlAuth', false)) {
        class samlAuth extends \OneLogin\Saml2\Auth {}
    }

    /**
     * Mock plugin configuration system.
     */
    if (!class_exists('GlpiPlugin\Samlsso\MockConfig', false)) {
        class MockConfig
        {
            /** @var array Storage for plugin config parameters. */
            public static array $mockConfig = [];

            /**
             * Resolve configuration ID by user email domain.
             *
             * @param string $email User email address.
             * @return int|null Config ID or null.
             */
            public static function getConfigIdByEmailDomain(string $email): ?int
            {
                return self::$mockConfig['domain_map'][$email] ?? null;
            }

            /**
             * Determines if there is exactly one active configuration.
             *
             * @return int|null Config ID if single, otherwise null.
             */
            public static function getIsOnlyOneConfig(): ?int
            {
                return self::$mockConfig['only_one_id'] ?? null;
            }

            /**
             * Check if SSO is strictly enforced.
             *
             * @return bool True if SSO enforced.
             */
            public static function getIsEnforced(): bool
            {
                return self::$mockConfig['enforced'] ?? false;
            }

            /**
             * Check if traditional login fields should be hidden.
             *
             * @return bool True if hidden.
             */
            public static function getHideLoginFields(): bool
            {
                return self::$mockConfig['hide_login_fields'] ?? false;
            }

            /**
             * Retrieve configured IDP login buttons.
             *
             * @param int $limit Maximum buttons count.
             * @return array Configured buttons metadata.
             */
            public static function getLoginButtons(int $limit = 12): array
            {
                return self::$mockConfig['login_buttons'] ?? [];
            }

            /**
             * Check if debug logging is enabled for an IDP.
             *
             * @param int $idpId IDP identifier.
             * @return bool True if debug enabled.
             */
            public static function getIsDebug(int $idpId): bool
            {
                return self::$mockConfig['debug'] ?? false;
            }

            /**
             * Retrieve the config table name.
             *
             * @param string|null $classname Class name.
             * @return string Table name.
             */
            public static function getTable(?string $classname = null): string
            {
                return 'glpi_plugin_samlsso_configs';
            }
        }
    }

    if (!class_exists('GlpiPlugin\Samlsso\Config', false)) {
        class_alias('GlpiPlugin\Samlsso\MockConfig', 'GlpiPlugin\Samlsso\Config');
    }

    /**
     * Exclude handler mock or require production file.
     */
    if (defined('LOAD_REAL_EXCLUDE') && constant('LOAD_REAL_EXCLUDE')) {
        if (!class_exists('GlpiPlugin\Samlsso\Exclude', false)) {
            require_once dirname(__DIR__) . '/src/Exclude.php';
        }
    } else {
        if (!class_exists('GlpiPlugin\Samlsso\Exclude', false)) {
            class Exclude
            {
                /**
                 * Mock checks if the request is excluded from SSO rules.
                 *
                 * @return bool True if request excluded.
                 */
                public static function isExcluded(): bool
                {
                    return false;
                }
            }
        }
    }

    /**
     * Mock login state machine to track steps during mock authentication.
     */
    if (!class_exists('GlpiPlugin\Samlsso\Loginstate', false)) {
        class Loginstate
        {
            public const SESSION_GLPI_NAME_ACCESSOR = 'glpiname';
            public const SESSION_VALID_ID_ACCESSOR  = 'valid_id';
            public const STATE_ID                   = 'id';
            public const USER_ID                    = 'userId';
            public const USER_NAME                  = 'userName';
            public const SESSION_ID                 = 'sessionId';
            public const SESSION_NAME               = 'sessionName';
            public const GLPI_AUTHED                = 'glpiAuthed';
            public const SAML_AUTHED                = 'samlAuthed';
            public const LOCATION                   = 'location';
            public const REDIRECT                   = 'redirect';
            public const IDP_ID                     = 'idpId';
            public const LOGIN_DATETIME             = 'loginTime';
            public const LAST_ACTIVITY              = 'lastClickTime';
            public const ENFORCE_LOGOFF             = 'enforceLogoff';
            public const SAML_RESPONSE              = 'serverParams';
            public const SAML_REQUEST               = 'requestParams';
            public const SAML_REQUEST_ID            = 'requestId';
            public const SAML_UNSOLICITED           = 'requesUnsol';
            public const SAML_RESPONSE_ID           = 'responseId';
            public const LOGIN_FLOW_TRACE           = 'loginFlowTrace';
            public const PHASE                      = 'phase';
            public const DATABASE                   = 'database';
            public const PHASE_INITIAL              = 1;
            public const PHASE_SAML_ACS             = 2;
            public const PHASE_SAML_AUTH            = 3;
            public const PHASE_GLPI_AUTH            = 4;
            public const PHASE_RESERVED             = 5;
            public const PHASE_FORCE_LOG            = 6;
            public const PHASE_TIMED_OUT            = 7;
            public const PHASE_LOGOFF               = 8;

            /**
             * Gets database table name.
             *
             * @param string|null $classname Class name.
             * @return string Table name.
             */
            public static function getTable(?string $classname = null): string
            {
                return 'glpi_plugin_samlsso_loginstates';
            }

            /** @var Loginstate|null Tracking last created instance. */
            public static $lastInstance = null;
            /** @var array Array to collect trace checkpoints. */
            public $trace = [];
            /** @var int Execution flow phase. */
            public $phase = 1;
            /** @var int Associated IDP identifier. */
            public $idpId = 0;
            /** @var string Request identifier. */
            public $requestId = '';
            /** @var bool Active SAML authentication state. */
            public $samlAuthed = false;
            /** @var string Active SAML Response identifier. */
            public $samlResponseId = '';
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockConstructorThrow = null;
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockSetSamlResponseIdThrow = null;
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockSetPhaseThrow = null;
            /** @var ?array Static phase sequence mock mapping. */
            public static ?array $mockPhases = null;
            /** @var int Call counter for phase sequence mock. */
            public int $phaseCallCount = 0;

            /**
             * Loginstate constructor.
             *
             * @param string $id Config identifier.
             */
            public function __construct($id = '')
            {
                if (self::$mockConstructorThrow !== null) {
                    throw self::$mockConstructorThrow;
                }
                if (self::$lastInstance !== null) {
                    $this->phase = self::$lastInstance->phase;
                    $this->idpId = self::$lastInstance->idpId;
                    $this->requestId = self::$lastInstance->requestId;
                    $this->samlResponseId = self::$lastInstance->samlResponseId;
                    $this->samlAuthed = self::$lastInstance->samlAuthed;
                }
                self::$lastInstance = $this;
            }

            /**
             * Gets mock state ID.
             *
             * @return int State ID.
             */
            public function getStateId(): int
            {
                return 1;
            }

            /**
             * Gets the active phase.
             *
             * @return int Active phase.
             */
            public function getPhase(): int
            {
                if (self::$mockPhases !== null) {
                    $val = self::$mockPhases[$this->phaseCallCount] ?? end(self::$mockPhases);
                    $this->phaseCallCount++;
                    return $val;
                }
                return $this->phase;
            }

            /**
             * Transition login flow to a new phase.
             *
             * @param int $phase Target phase.
             * @return bool True if transitioned.
             */
            public function setPhase(int $phase): bool
            {
                if (self::$mockSetPhaseThrow !== null) {
                    throw self::$mockSetPhaseThrow;
                }
                $this->phase = $phase;
                return true;
            }

            /**
             * Add an event to the login flow trace.
             *
             * @param array $entry Event dictionary.
             * @return bool True if added.
             */
            public function addLoginFlowTrace(array $entry): bool
            {
                $this->trace[] = $entry;
                return true;
            }

            /**
             * Retrieve the complete event trace log.
             *
             * @return array Event list.
             */
            public function getTrace(): array
            {
                return $this->trace;
            }

            /**
             * Write current state parameters.
             *
             * @return bool True if written.
             */
            public function writeState(): bool
            {
                return true;
            }

            /**
             * Store redirect URL.
             *
             * @param string $redirect Target URL.
             * @return bool True if updated.
             */
            public function setRedirect(string $redirect = ''): bool
            {
                return true;
            }

            /**
             * Gets associated IDP identifier.
             *
             * @return int IDP identifier.
             */
            public function getIdpId(): int
            {
                return $this->idpId;
            }

            /**
             * Set associated IDP identifier.
             *
             * @param int $id IDP identifier.
             * @return bool True if updated.
             */
            public function setIdpId(int $id): bool
            {
                $this->idpId = $id;
                return true;
            }

            /**
             * Set request identifier.
             *
             * @param string $requestId Request ID.
             * @return bool True if updated.
             */
            public function setRequestId(string $requestId): bool
            {
                $this->requestId = $requestId;
                return true;
            }

            /**
             * Asserts that SAML authentication succeeded.
             *
             * @return bool True if updated.
             */
            public function setSamlAuthTrue(): bool
            {
                $this->samlAuthed = true;
                return true;
            }

            /**
             * Checks if SAML authentication is flagged as successful.
             *
             * @return bool True if authenticated.
             */
            public function isSamlAuthed(): bool
            {
                return $this->samlAuthed;
            }

            /**
             * Gets the recorded SAML response ID.
             *
             * @return string Response ID.
             */
            public function getSamlResponseId(): string
            {
                return $this->samlResponseId;
            }

            /**
             * Set response identifier.
             *
             * @param string $id Response ID.
             * @return bool True if updated.
             */
            public function setSamlResponseId(string $id): bool
            {
                if (self::$mockSetSamlResponseIdThrow !== null) {
                    throw self::$mockSetSamlResponseIdThrow;
                }
                $this->samlResponseId = $id;
                return true;
            }

            /**
             * Gets the recorded SAML request ID.
             *
             * @return string Request ID.
             */
            public function getSamlRequestId(): string
            {
                return $this->requestId;
            }

            /**
             * Validates whether a response ID is unique (i.e. not replayed).
             *
             * @param string $id Response ID.
             * @return bool True if unique.
             */
            public function checkResponseIdUnique(string $id): bool
            {
                return $this->samlResponseId !== $id;
            }

            /**
             * Obtains safe state parameters for database and logging operations.
             *
             * @param bool $debug Debug flag.
             * @return array Sanitized state list.
             */
            public function getSafeStateForLogging(bool $debug): array
            {
                return ['id' => 1, 'phase' => $this->phase];
            }

            /**
             * Mocks setting active session ID.
             *
             * @param string $sessionId Optional session ID.
             * @return bool True if updated.
             */
            public function setSessionId(string $sessionId = ''): bool
            {
                return true;
            }

            /**
             * Mocks retrieving safe redirect URL.
             *
             * @return string Redirect URL.
             */
            public function getSafeRedirect(): string
            {
                return '';
            }
        }
    }

    /**
     * Mock Rule engine collection.
     */
    if (!class_exists('GlpiPlugin\Samlsso\RuleSamlCollection', false)) {
        class RuleSamlCollection
        {
            /** @var array|null Stores last processed match parameters. */
            public static $lastMatchInput = null;

            /**
             * Process SAML rules against a user context.
             *
             * @param array $matchInput Match attributes.
             * @param array $params Parameter criteria.
             * @param array $options Additional options.
             */
            public function processAllRules(array $matchInput, array $params, array $options): void
            {
                self::$lastMatchInput = $matchInput;
            }
        }
    }

    /**
     * Mock header redirection function.
     */
    if (!function_exists('GlpiPlugin\Samlsso\header')) {
        /**
         * Emulates PHP header command and intercepts location redirects as exceptions.
         *
         * @param string $header HTTP header statement.
         * @param bool $replace Replace existing headers.
         * @param int $response_code HTTP response code.
         * @throws \Exception to intercept redirection paths.
         */
        function header(string $header, bool $replace = true, int $response_code = 0): void {
            if (preg_match('/^[Ll]ocation:\s*(.*)$/', $header, $matches)) {
                throw new \Exception("Redirect to: " . trim($matches[1]));
            }
        }
    }
}

namespace GlpiPlugin\Samlsso\Tests {

    use GlpiPlugin\Samlsso\Loginstate;

    /**
     * Mock database client to intercept and validate query parameters.
     */
    class MockDB
    {
        /** @var string Database name. */
        public string $dbdefault = 'glpi_test';
        /** @var string Last intercepted query. */
        public string $lastQuery = '';
        /** @var bool Emulate target table existence status. */
        public bool $mockTableExists = true;
        /** @var array Mock database responses by table name. */
        private array $responses = [];
        /** @var array Tracking deleted records. */
        public array $deletedRows = [];
        /** @var array Tracking inserted records. */
        public array $insertedRows = [];

        /**
         * Configure a mock response structure for a given table name.
         *
         * @param string $table Database table name.
         * @param array $data Set of mock rows.
         */
        public function setResponse(string $table, array $data): void
        {
            $this->responses[$table] = $data;
        }

        /**
         * Intercept request query structure and return an Iterator mapping Mock row records.
         *
         * @param array $params Request parameters.
         * @return object Iterator structure representing query results.
         */
        public function request(array $params): object
        {
            $table = $params['FROM'] ?? '';
            $data = $this->responses[$table] ?? [];
            return new class($data) implements \Iterator, \Countable {
                /** @var array Results storage. */
                private array $data;
                /** @var int Array iteration index. */
                private int $position = 0;

                /**
                 * Inner iterator constructor.
                 *
                 * @param array $data Dataset.
                 */
                public function __construct(array $data) {
                    $this->data = $data;
                }

                /**
                 * Rewind iteration index.
                 */
                public function rewind(): void {
                    $this->position = 0;
                }

                /**
                 * Retrieve current record.
                 *
                 * @return mixed Row dictionary.
                 */
                public function current(): mixed {
                    return $this->data[$this->position];
                }

                /**
                 * Retrieve active index key.
                 *
                 * @return mixed Key index.
                 */
                public function key(): mixed {
                    return $this->position;
                }

                /**
                 * Proceed to next index position.
                 */
                public function next(): void {
                    ++$this->position;
                }

                /**
                 * Validates if active index is set.
                 *
                 * @return bool True if valid index.
                 */
                public function valid(): bool {
                    return isset($this->data[$this->position]);
                }

                /**
                 * Retrieve database records count.
                 *
                 * @return int Row count.
                 */
                public function count(): int {
                    return count($this->data);
                }

                /**
                 * Retrieve database records count.
                 *
                 * @return int Row count.
                 */
                public function numrows(): int {
                    return count($this->data);
                }
            };
        }

        /**
         * Intercept records deletion queries.
         *
         * @param string $table Table name.
         * @param array $where Filter parameters.
         * @return bool Always returns true.
         */
        public function delete(string $table, array $where): bool
        {
            $this->deletedRows[] = [
                'table' => $table,
                'where' => $where
            ];
            return true;
        }

        /**
         * Intercept plain query commands.
         *
         * @param string $query SQL string.
         * @return bool Always returns true.
         */
        public function query(string $query): bool
        {
            $this->lastQuery = $query;
            return true;
        }

        /**
         * Intercept direct SQL queries.
         *
         * @param string $query SQL string.
         * @return bool Always returns true.
         */
        public function doQuery(string $query): mixed
        {
            $this->lastQuery = $query;
            if (stripos($query, 'SHOW COLUMNS') !== false) {
                return new class() {
                    private array $columns = [
                        ['Field' => 'id', 'Type' => 'int', 'Null' => 'NO'],
                        ['Field' => 'is_active', 'Type' => 'tinyint', 'Null' => 'NO'],
                        ['Field' => 'is_deleted', 'Type' => 'tinyint', 'Null' => 'NO'],
                        ['Field' => 'enforce_sso', 'Type' => 'tinyint', 'Null' => 'NO'],
                        ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'YES'],
                        ['Field' => 'sp_certificate', 'Type' => 'text', 'Null' => 'YES'],
                        ['Field' => 'sp_private_key', 'Type' => 'text', 'Null' => 'YES'],
                    ];
                    private int $index = 0;
                    public function fetch_assoc() {
                        if ($this->index < count($this->columns)) {
                            return $this->columns[$this->index++];
                        }
                        return null;
                    }
                };
            }
            return true;
        }

        /**
         * Check if table exists in mock DB.
         *
         * @param string $table Table name.
         * @return bool True if table exists.
         */
        public function tableExists(string $table): bool
        {
            return $this->mockTableExists;
        }

        /**
         * Retrieve last query error.
         *
         * @return string Empty string for mock.
         */
        public function error(): string
        {
            return '';
        }

        /**
         * Intercept records insertion queries.
         *
         * @param string $table Table name.
         * @param array  $data  Data to insert.
         * @return bool Always returns true.
         */
        public function insert(string $table, array $data): bool
        {
            $this->insertedRows[] = [
                'table' => $table,
                'data'  => $data
            ];
            return true;
        }

        /**
         * Return last inserted ID (mocked as fixed value).
         *
         * @return int Always returns 1 for mocked inserts.
         */
        public function insertId(): int
        {
            return count($this->insertedRows ?? []);
        }
    }

    /**
     * Mock GLPI User object mapper.
     */
    class TestableGlpiUser
    {
        /** @var array|null Mock user fields. */
        public $mockUserData = null;
        /** @var array|null Created user fields. */
        public $createdUserData = null;
        /** @var int User identifier to mock return. */
        public $mockIdToReturn = 999;

        /**
         * Retrieve user fields by username and bind to instance.
         *
         * @param string $name Username.
         * @param object $instance Target active record.
         * @return bool True if found.
         */
        public function getFromDBbyName(string $name, $instance): bool
        {
            if ($this->mockUserData && $this->mockUserData['name'] === $name) {
                $instance->fields = $this->mockUserData;
                return true;
            }
            return false;
        }

        /**
         * Retrieve user fields by email and bind to instance.
         *
         * @param string $email User email.
         * @param object $instance Target active record.
         * @return bool True if found.
         */
        public function getFromDBbyEmail(string $email, $instance): bool
        {
            if ($this->mockUserData && $this->mockUserData['email'] === $email) {
                $instance->fields = $this->mockUserData;
                return true;
            }
            return false;
        }

        /**
         * Retrieve user fields by ID and bind to instance.
         *
         * @param int $id User identifier.
         * @param object $instance Target active record.
         * @return bool True if found.
         */
        public function getFromDB(int $id, $instance): bool
        {
            if ($this->createdUserData && $this->createdUserData['id'] === $id) {
                $instance->fields = $this->createdUserData;
                return true;
            }
            return false;
        }

        /**
         * Create a new user record.
         *
         * @param array $input User fields.
         * @param array $options Additional options.
         * @param bool $history Keep history logs.
         * @return int Created user ID.
         */
        /** @var bool Track if update was called. */
        public bool $mockUpdateCalled = false;
        /** @var array|null Captured update data. */
        public ?array $updatedUserData = null;

        /**
         * Create a new user record.
         *
         * @param array $input User fields.
         * @param array $options Additional options.
         * @param bool $history Keep history logs.
         * @return int Created user ID.
         */
        public function add(array $input, array $options = [], bool $history = true): int
        {
            return $this->mockIdToReturn;
        }

        /**
         * Mocks updating a user record.
         *
         * @param array $input Fields to update.
         * @param bool $history Keep history logs.
         * @param array $options Additional options.
         * @return bool Always returns true.
         */
        public function update(array $input, bool $history = true, array $options = []): bool
        {
            $this->mockUpdateCalled = true;
            $this->updatedUserData = $input;
            return true;
        }
    }

    /**
     * TestHarness base class setup.
     * Resets global variables and buffers output for cleaner CLI output execution.
     */
    class TestHarness
    {
        /** @var MockDB Mock database object reference. */
        protected MockDB $db;

        /**
         * TestHarness constructor.
         * Initializes GLPI globals and setups clean output buffers.
         */
        public function __construct()
        {
            global $DB, $CFG_GLPI, $GLPI_IS_COMMAND_LINE;

            if (!isset($_SERVER['REQUEST_URI'])) {
                $_SERVER['REQUEST_URI'] = '/';
            }

            if (!isset($_SERVER['REMOTE_ADDR'])) {
                $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            }

            if (ob_get_level() == 0) {
                ob_start();
            }

            $this->db = new MockDB();
            $DB = $this->db;
            $CFG_GLPI = ['url_base' => 'http://glpi.local'];
            $GLPI_IS_COMMAND_LINE = false;

            Loginstate::$lastInstance = null;
        }

        /**
         * TestHarness destructor.
         * Flushes the active output buffer cleanly.
         */
        public function __destruct()
        {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        /**
         * Asserts that the login state trace collection contains a specific value pattern.
         *
         * @param Loginstate|null $state LoginState instance to inspect.
         * @param string $key Event key.
         * @param string $valueSnippet Value substring.
         * @throws \Exception if pattern was not recorded in state traces.
         * @return bool True if found.
         */
        public function assertTraceContains(?Loginstate $state, string $key, string $valueSnippet): bool
        {
            if ($state === null) {
                throw new \Exception("Trace validation failed: State object is null.");
            }
            foreach ($state->getTrace() as $entry) {
                if (isset($entry[$key]) && str_contains((string)$entry[$key], $valueSnippet)) {
                    return true;
                }
            }
            throw new \Exception("Trace key '$key' with value '$valueSnippet' not found in login flow trace.");
        }
    }
}
