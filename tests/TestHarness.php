<?php

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
    if (!class_exists('OneLogin\Saml2\Response')) {
        class Response
        {
            public function __construct(\OneLogin\Saml2\Settings|array $settings, string $assertion) {}
            public function isValid(): bool
            {
                return true;
            }
            public function getAttributes(): array
            {
                return ['email' => ['test@example.com']];
            }
            public function getNameId(): string
            {
                return 'testuser';
            }
        }
    }
    if (!class_exists('OneLogin\Saml2\Auth')) {
        class Auth
        {
            public function __construct(array $settings) {}
            public function processResponse(): void {}
            public function getLastRequestID(): string
            {
                return 'ONELOGIN_12345';
            }
            public function getErrors(): array
            {
                return [];
            }
            public function getLastErrorReason(): string
            {
                return '';
            }
            public function login(
                ?string $returnTo = null,
                array $parameters = array(),
                bool $forceAuthn = false,
                bool $isPassive = false,
                bool $stay = false,
                bool $setNameIdPolicy = true,
                ?string $nameIdValueReq = null
            ): string {
                return '/plugins/samlsso/front/sso.php?idp=5';
            }
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
}

namespace OneLogin\Saml2\Utils {
    if (!function_exists('OneLogin\Saml2\Utils\setProxyVars')) {
        function setProxyVars(): void {}
        function getSelfURLhost(): string
        {
            return 'glpi.local';
        }
    }
}

namespace Glpi\Application\View {
    if (!class_exists('Glpi\Application\View\TemplateRenderer')) {
        class TemplateRenderer
        {
            public static function getInstance(): self
            {
                return new self();
            }
            public function render(string $template, array $vars): string
            {
                return "Rendered: $template" . (isset($vars['error']) ? " (Error: {$vars['error']})" : "");
            }
            public function display(string $template, array $vars): void
            {
                echo "Displayed: $template" . (isset($vars['error']) ? " (Error: {$vars['error']})" : "");
            }
        }
    }
}

namespace GlpiPlugin\Samlsso\LoginFlow {
    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\MockLoginFlowUser')) {
        class MockLoginFlowUser
        {
            public function getFromDBByField(string $field, string $value): bool
            {
                return true;
            }
        }
    }
    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\User')) {
        class_alias('GlpiPlugin\Samlsso\LoginFlow\MockLoginFlowUser', 'GlpiPlugin\Samlsso\LoginFlow\User');
    }

    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\MockAuth')) {
        class MockAuth
        {
            public function login(string $user, string $pass): bool
            {
                return true;
            }
        }
    }
    if (!class_exists('GlpiPlugin\Samlsso\LoginFlow\Auth')) {
        class_alias('GlpiPlugin\Samlsso\LoginFlow\MockAuth', 'GlpiPlugin\Samlsso\LoginFlow\Auth');
    }
}

namespace GlpiPlugin\Samlsso\Config {
    /**
     * Includes all constants from the real ConfigEntity to support static access.
     */
    if (!class_exists('GlpiPlugin\Samlsso\Config\MockConfigEntity')) {
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

            public static array $mockFields = [];
            public function __construct(int $id = -1) {}                         //NOSONAR Mocked function for tests 
            public function getField(string $field): mixed
            {                     //NOSONAR Mocked function for tests
                return self::$mockFields[$field] ?? null;
            }
            public function getFields(): array
            {
                return [];
            }                    //NOSONAR Mocked function for tests
            public function isValid(): bool
            {
                return true;
            }                     //NOSONAR Mocked function for tests
            public function isActive(): bool
            {
                return true;
            }                    //NOSONAR Mocked function for tests
            public function getConfigDomain(): ?string
            {
                return null;
            }          //NOSONAR Mocked function for tests
            public function getPhpSamlConfig(): array
            {
                return [];
            }             //NOSONAR Mocked function for tests
        }
    }
    if (!class_exists('GlpiPlugin\Samlsso\Config\ConfigEntity')) {
        class_alias('GlpiPlugin\Samlsso\Config\MockConfigEntity', 'GlpiPlugin\Samlsso\Config\ConfigEntity');
    }
}

namespace GlpiPlugin\Samlsso {
    if (!class_exists('GlpiPlugin\Samlsso\samlAuth')) {
        class samlAuth extends \OneLogin\Saml2\Auth {} //NOSONAR Mocked class
    }

    /**
     * Mock Config that replaces the production one for testing logic paths.
     */
    if (!class_exists('GlpiPlugin\Samlsso\MockConfig')) {
        class MockConfig
        {
            public static array $mockConfig = [];
            public static function getConfigIdByEmailDomain(string $email): ?int
            {  //NOSONAR Mocked function for tests
                return self::$mockConfig['domain_map'][$email] ?? null;
            }
            public static function getIsOnlyOneConfig(): ?int
            {                     //NOSONAR Mocked function for tests
                return self::$mockConfig['only_one_id'] ?? null;
            }
            public static function getIsEnforced(): bool
            {                          //NOSONAR Mocked function for tests
                return self::$mockConfig['enforced'] ?? false;
            }
            public static function getHideLoginFields(): bool
            {                     //NOSONAR Mocked function for tests
                return self::$mockConfig['hide_login_fields'] ?? false;
            }
            public static function getLoginButtons(int $limit = 12): array
            {        //NOSONAR Mocked function for tests, not used in samlsso
                return self::$mockConfig['login_buttons'] ?? [];
            }
            public static function getIsDebug(int $idpId): bool
            {                   //NOSONAR Mocked function for tests, not used in samlsso
                return self::$mockConfig['debug'] ?? false;
            }
            public static function getTable(?string $classname = null): string
            {
                return 'glpi_plugin_samlsso_configs';
            }
        }
    }

    if (!class_exists('GlpiPlugin\Samlsso\Config')) {
        class_alias('GlpiPlugin\Samlsso\MockConfig', 'GlpiPlugin\Samlsso\Config');
    }

    if (!class_exists('GlpiPlugin\Samlsso\Exclude')) {
        class Exclude
        {
            public static function isExcluded(): bool
            {
                return false;
            }
        }
    }

    /**
     * Mock Loginstate that replaces the production one for testing logic paths.
     */
    if (!class_exists('GlpiPlugin\Samlsso\Loginstate')) {
        class Loginstate
        {
            public const PHASE_INITIAL = 1;
            public const PHASE_SAML_ACS = 2;
            public const PHASE_SAML_AUTH = 3;
            public const PHASE_GLPI_AUTH = 4;
            public const PHASE_RESERVED = 5;
            public const PHASE_FORCE_LOG = 6;
            public const PHASE_TIMED_OUT = 7;
            public const PHASE_LOGOFF = 8;

            public static $lastInstance = null;
            public $trace = [];
            public $phase = 1;
            public $idpId = 0;

            public function __construct(int $id = -1)
            {
                self::$lastInstance = $this;
            }
            public function getStateId(): int
            {
                return 1;
            }
            public function getPhase(): int
            {
                return $this->phase;
            }
            public function setPhase(int $phase): bool
            {
                $this->phase = $phase;
                return true;
            }
            public function addLoginFlowTrace(array $entry): bool
            {
                $this->trace[] = $entry;
                return true;
            }
            public function getTrace(): array
            {
                return $this->trace;
            }
            public function writeState(): bool
            {
                return true;
            }
            public function setRedirect(string $redirect = ''): bool
            {
                return true;
            }
            public $requestId = '';
            public $samlAuthed = false;

            public function getIdpId(): int
            {
                return $this->idpId;
            }
            public function setIdpId(int $id): bool
            {
                $this->idpId = $id;
                return true;
            }
            public function setRequestId(string $requestId): bool
            {
                $this->requestId = $requestId;
                return true;
            }
            public function setSamlAuthTrue(): bool
            {
                $this->samlAuthed = true;
                return true;
            }
            public function isSamlAuthed(): bool
            {
                return $this->samlAuthed;
            }
        }
    }

    if (!class_exists('GlpiPlugin\Samlsso\RuleSamlCollection')) {
        class RuleSamlCollection
        {
            public static $lastMatchInput = null;
            public function processAllRules(array $matchInput, array $params, array $options): void
            {
                self::$lastMatchInput = $matchInput;
            }
        }
    }
}

namespace GlpiPlugin\Samlsso\Tests {

    use GlpiPlugin\Samlsso\Loginstate;

    /**
     * Mock Database for GLPI
     */
    class MockDB
    {
        public string $dbdefault = 'glpi_test';
        public string $lastQuery = '';
        public bool $mockTableExists = true;
        private array $responses = [];

        public function setResponse(string $table, array $data): void
        {
            $this->responses[$table] = $data;
        }

        public function request(array $params): object
        {
            return new class([]) {
                public function count(): int
                {
                    return 0;
                }
                public function current(): array
                {
                    return [];
                }
                public function numrows(): int
                {
                    return 0;
                }
            };
        }

        public function query(string $query): bool
        {
            $this->lastQuery = $query;
            return true;
        }
        public function doQuery(string $query): bool
        {
            $this->lastQuery = $query;
            return true;
        }
        public function tableExists(string $table): bool
        {
            return $this->mockTableExists;
        }
        public function error(): string
        {
            return '';
        }
    }

    /**
     * Mock GLPI User object
     */
    class TestableGlpiUser
    {
        public $mockUserData = null;
        public $createdUserData = null;
        public $mockIdToReturn = 999;

        public function getFromDBbyName(string $name, $instance): bool
        {
            if ($this->mockUserData && $this->mockUserData['name'] === $name) {
                $instance->fields = $this->mockUserData;
                return true;
            }
            return false;
        }
        public function getFromDBbyEmail(string $email, $instance): bool
        {
            if ($this->mockUserData && $this->mockUserData['email'] === $email) {
                $instance->fields = $this->mockUserData;
                return true;
            }
            return false;
        }
        public function getFromDB(int $id, $instance): bool
        {
            if ($this->createdUserData && $this->createdUserData['id'] === $id) {
                $instance->fields = $this->createdUserData;
                return true;
            }
            return false;
        }
        public function add(array $input, array $options = [], bool $history = true): int
        {
            return $this->mockIdToReturn;
        }
    }

    /**
     * TestHarness Base Class
     */
    class TestHarness
    {
        protected MockDB $db;

        public function __construct()
        {
            global $DB, $CFG_GLPI, $GLPI_IS_COMMAND_LINE;

            // Ensure $_SERVER has REQUEST_URI for production logic
            if (!isset($_SERVER['REQUEST_URI'])) {
                $_SERVER['REQUEST_URI'] = '/';
            }

            // Start output buffering
            if (ob_get_level() == 0) ob_start();

            $this->db = new MockDB();
            $DB = $this->db;
            $CFG_GLPI = ['url_base' => 'http://glpi.local'];
            $GLPI_IS_COMMAND_LINE = false;

            Loginstate::$lastInstance = null;
        }

        public function __destruct()
        {
            if (ob_get_level() > 0) ob_end_flush();
        }

        public function assertTraceContains(?Loginstate $state, string $key, string $valueSnippet): bool
        {
            if ($state === null) {
                throw new \Exception("Trace validation failed: State object is null.");
            }
            foreach ($state->getTrace() as $entry) {
                if (isset($entry[$key]) && str_contains($entry[$key], $valueSnippet)) {
                    return true;
                }
            }
            throw new \Exception("Trace key '$key' with value '$valueSnippet' not found in login flow trace.");
        }
    }
}
