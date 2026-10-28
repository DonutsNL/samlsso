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
 *  @version    1.3.2
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
 * ConfigIntegrityTest.php
 * 
 * Unit tests validating schema definitions, configuration field mapping,
 * validator existence, and identifying dead configuration fields.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/Config/ConfigItem.php';
    require_once __DIR__ . '/../src/Config/ConfigEntity.php';
    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Config\ConfigItem;
    use GlpiPlugin\Samlsso\Config;
    use DBConnection;
    use Migration;

    /**
     * ConfigIntegrityTest class.
     * Evaluates configuration schemas, validator presence, and references.
     */
    class ConfigIntegrityTest extends TestHarness {

        /**
         * Test that the captured database schema matches constants, environmental mocks, and data types.
         *
         * @throws \Exception if capturing schema fails or if mismatched types are found.
         */
        public function testSchemaVsConstants(): void {
            global $DB;
            
            $customCharset = 'test_charset_' . uniqid();
            $customCollation = 'test_collation_' . uniqid();
            $customSign = 'UNSIGNED_TEST';
            
            DBConnection::$defaultCharset = $customCharset;
            DBConnection::$defaultCollation = $customCollation;
            DBConnection::$defaultPrimaryKeySignOption = $customSign;
            
            $this->db->mockTableExists = false;
            $migration = new Migration('1.0.0');
            
            Config::install($migration);
            
            $sql = $this->db->lastQuery;
            
            if (empty($sql) || !str_contains($sql, 'CREATE TABLE')) {
                throw new \Exception("Failed to capture CREATE TABLE statement.");
            }

            if (!str_contains($sql, $customCharset)) {
                throw new \Exception("SQL missing custom charset: $customCharset");
            }
            if (!str_contains($sql, $customCollation)) {
                throw new \Exception("SQL missing custom collation: $customCollation");
            }
            if (!str_contains($sql, $customSign)) {
                throw new \Exception("SQL missing custom PK sign: $customSign");
            }

            if (!preg_match('/\((.*)\)/s', $sql, $matches)) {
                throw new \Exception("Could not parse fields from SQL.");
            }
            
            $fieldsBlock = $matches[1];
            preg_match_all('/`(\w+)`?\s+([A-Za-z]+)/', $fieldsBlock, $fieldMatches);
            
            $dbFields = [];
            for ($i = 0; $i < count($fieldMatches[1]); $i++) {
                $dbFields[$fieldMatches[1][$i]] = strtoupper($fieldMatches[2][$i]);
            }

            $reflection = new \ReflectionClass(ConfigEntity::class);
            $constants = $reflection->getConstants();
            $itemReflection = new \ReflectionClass(ConfigItem::class);
            $metaConstants = $itemReflection->getConstants();

            foreach ($constants as $name => $field) {
                if ($name === 'class' || !is_string($field)) {
                    continue;
                }
                if (array_key_exists($name, $metaConstants)) {
                    continue;
                }
                
                if (!isset($dbFields[$field])) {
                    throw new \Exception("ConfigEntity constant '$name' refers to field '$field' which is NOT in the captured database schema.");
                }

                $actualType = $dbFields[$field];
                
                if ($field === 'id') {
                    if ($actualType !== 'INT') {
                        throw new \Exception("Field 'id' should be INT, found $actualType");
                    }
                } elseif (str_starts_with($field, 'date_')) {
                    if ($actualType !== 'TIMESTAMP') {
                        throw new \Exception("Field '$field' should be TIMESTAMP, found $actualType");
                    }
                } elseif (in_array($field, ['sp_certificate', 'sp_private_key', 'idp_certificate', 'requested_authn_context', 'comment'])) {
                    if ($actualType !== 'TEXT') {
                        throw new \Exception("Field '$field' should be TEXT, found $actualType");
                    }
                } elseif (in_array($actualType, ['TINYINT', 'INT', 'VARCHAR', 'TEXT', 'TIMESTAMP'])) {
                    $boolPrefixes = ['enforce_', 'proxied', 'strict', 'debug', 'user_jit', 'security_', 'compress_', 'validate_', 'lowercase_', 'is_', 'force_'];
                    foreach ($boolPrefixes as $prefix) {
                        if (str_starts_with($field, $prefix) && $actualType !== 'TINYINT') {
                            throw new \Exception("Flag field '$field' should be TINYINT, found $actualType");
                        }
                    }
                }
            }
            echo "✅ Deep Schema Integrity: Constants, Environmental Mocks, and Datatypes validated\n";
        }

        /**
         * Test that validator methods exist in ConfigItem for each defined ConfigEntity constant field.
         *
         * @throws \Exception if a validator method is missing.
         */
        public function testValidatorsExistence(): void {
            $reflection = new \ReflectionClass(ConfigEntity::class);
            $constants = $reflection->getConstants();
            
            /**
             * Validators are methods in ConfigItem named exactly like the corresponding database field.
             */
            $itemReflection = new \ReflectionClass(ConfigItem::class);
            $metaConstants = $itemReflection->getConstants();

            foreach ($constants as $name => $field) {
                if ($name === 'class' || !is_string($field)) {
                    continue;
                }
                if (array_key_exists($name, $metaConstants)) {
                    continue;
                }
                
                if (!$itemReflection->hasMethod($field)) {
                    throw new \Exception("Field '$field' (ConfigEntity::$name) missing validator method '$field()' in ConfigItem.");
                }
            }
            echo "✅ Configuration field validator existence\n";
        }

        /**
         * Test that all configuration fields defined in ConfigEntity are used/referenced in the codebase.
         *
         * @throws \Exception if a configuration field is dead/unreferenced.
         */
        public function testDeadConfigDetection(): void {
            $reflection = new \ReflectionClass(ConfigEntity::class);
            $constants = $reflection->getConstants();
            $metaConstants = (new \ReflectionClass(ConfigItem::class))->getConstants();
            
            $pluginDir = GLPI_ROOT . '/plugins/samlsso';
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pluginDir));
            foreach ($constants as $name => $field) {
                if ($name === 'class' || !is_string($field) || $field === 'id') {
                    continue;
                }
                if (array_key_exists($name, $metaConstants)) {
                    continue;
                }
                
                $found = false;
                foreach ($files as $file) {
                    if ($file->isDir() || str_contains($file->getPathname(), '/tests/')) {
                        continue;
                    }
                    $content = file_get_contents($file->getPathname());
                    if (str_contains($content, "ConfigEntity::$name") || str_contains($content, "'$field'")) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new \Exception("Field '$field' (ConfigEntity::$name) is never referenced.");
                }
            }
            echo "✅ Dead configuration field detection\n";
        }

        /**
         * Test that Enforce SSO is automatically disabled when another active IDP exists.
         *
         * @throws \Exception if validation behaves incorrectly.
         */
        public function testMultiIdpEnforceAutoDisable(): void {
            global $DB;

            // Mock database response to simulate another active IDP
            $this->db->setResponse(Config::getTable(), [
                [
                    'id' => 1,
                    'is_active' => 1,
                    'is_deleted' => 0,
                    'enforce_sso' => 1,
                ]
            ]);

            // Create ConfigEntity representing a second IDP being configured
            $entity = new ConfigEntity(-1, [
                'template' => 'post',
                'postData' => [
                    'id' => 2,
                    'is_active' => 1,
                    'is_deleted' => 0,
                    'enforce_sso' => 1,
                    'name' => 'Entra 2',
                ]
            ]);

            $fields = $entity->getFields();

            // Verify that enforce_sso has been set to 0 and warning populated in errors
            $enforceSso = $fields[ConfigEntity::ENFORCE_SSO] ?? null;
            if ($enforceSso === null) {
                throw new \Exception("enforce_sso field not populated in fields array.");
            }

            if ((int)$enforceSso[ConfigItem::VALUE] !== 0) {
                throw new \Exception("Expected enforce_sso value to be corrected to 0, got: " . var_export($enforceSso[ConfigItem::VALUE], true));
            }

            if (empty($enforceSso[ConfigItem::ERRORS])) {
                throw new \Exception("Expected enforce_sso error message to be set, but it was empty.");
            }

            echo "✅ Multi-IDP Enforce auto-disable and warning verified\n";
        }

        /**
         * Test that inbound security fields are automatically disabled and warnings
         * are populated when strict mode is disabled.
         *
         * @throws \Exception if validation behaves incorrectly.
         */
        public function testStrictDisabledAutoDisableInboundSecurity(): void {
            /**
             * Create ConfigEntity with strict mode disabled and inbound security enabled.
             */
            $entity = new ConfigEntity(-1, [
                'template' => 'post',
                'postData' => [
                    'id' => 3,
                    'is_active' => 1,
                    'is_deleted' => 0,
                    'strict' => 0,
                    'security_wantmessagessigned' => 1,
                    'security_wantassertionssigned' => 1,
                    'security_wantassertionsencrypted' => 1,
                    'security_wantnameid' => 1,
                ]
            ]);

            $fields = $entity->getFields();

            $inboundFields = [
                ConfigEntity::SECURITY_WANTMESSAGESSIGNED,
                ConfigEntity::SECURITY_WANTASSERTIONSSIGNED,
                ConfigEntity::SECURITY_WANTASSERTIONSENCRYPTED,
                ConfigEntity::SECURITY_WANTNAMEID
            ];

            foreach ($inboundFields as $field) {
                $f = $fields[$field] ?? null;
                if ($f === null) {
                    throw new \Exception("Field '$field' not populated in fields array.");
                }

                if ((int)$f[ConfigItem::VALUE] !== 0) {
                    throw new \Exception("Expected '$field' value to be corrected to 0, got: " . var_export($f[ConfigItem::VALUE], true));
                }

                if (empty($f[ConfigItem::ERRORS])) {
                    throw new \Exception("Expected '$field' error message to be set, but it was empty.");
                }
            }

            echo "✅ Inbound Security Auto-Disable when strict mode disabled verified\n";
        }

        /**
         * Test that SP private key sources (db, file, env) are loaded and validated correctly.
         */
        public function testSpPrivateKeySources(): void {
            // Generate matching cert and private key
            $res = \openssl_pkey_new([
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ]);
            $dn = ["countryName" => "NL", "organizationName" => "Test", "commonName" => "localhost"];
            $csr = \openssl_csr_new($dn, $res);
            $certRes = \openssl_csr_sign($csr, null, $res, 365);
            \openssl_x509_export($certRes, $certStr);
            \openssl_pkey_export($res, $privateKeyStr);

            $basePostData = [
                ConfigEntity::ID                 => '1',
                ConfigEntity::NAME               => 'Test IDP',
                ConfigEntity::CONF_DOMAIN        => 'example.com',
                ConfigEntity::IS_ACTIVE          => '1',
                ConfigEntity::IS_DELETED         => '0',
                ConfigEntity::STRICT             => '1',
                ConfigEntity::DEBUG              => '0',
                ConfigEntity::SP_CERTIFICATE     => $certStr,
                ConfigEntity::IDP_ENTITY_ID      => 'http://idp.example.com',
                ConfigEntity::IDP_SSO_URL        => 'http://idp.example.com/sso',
                ConfigEntity::IDP_SLO_URL        => 'http://idp.example.com/slo',
                ConfigEntity::IDP_CERTIFICATE    => $certStr,
                ConfigEntity::REQUEST_TIMEOUT    => '15',
                ConfigEntity::INACTIVITY_TIMEOUT => '0',
                ConfigEntity::ENFORCE_SSO        => '1',
                ConfigEntity::PROXIED            => '0',
                ConfigEntity::USER_JIT           => '1',
                ConfigEntity::SYNC_ON_LOGIN      => '0',
                ConfigEntity::ENCRYPT_NAMEID     => '0',
                ConfigEntity::SIGN_AUTHN         => '0',
                ConfigEntity::SIGN_SLO_REQ       => '0',
                ConfigEntity::SIGN_SLO_RES       => '0',
                ConfigEntity::COMPRESS_REQ       => '1',
                ConfigEntity::COMPRESS_RES       => '1',
                ConfigEntity::XML_VALIDATION     => '1',
                ConfigEntity::DEST_VALIDATION    => '1',
                ConfigEntity::LOWERCASE_URL      => '0',
                ConfigEntity::SECURITY_WANTMESSAGESSIGNED => '0',
                ConfigEntity::SECURITY_WANTASSERTIONSSIGNED => '0',
                ConfigEntity::SECURITY_WANTASSERTIONSENCRYPTED => '0',
                ConfigEntity::SECURITY_SIGNMETADATA => '0',
                ConfigEntity::SECURITY_WANTNAMEID => '0',
                ConfigEntity::SP_NAME_FORMAT => 'unspecified',
                ConfigEntity::AUTHN_CONTEXT => 'none',
                ConfigEntity::AUTHN_COMPARE => 'exact',
            ];

            // 1. Test legacy 'db' source
            ConfigEntity::clearCache();
            $postDataDb = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'db',
                ConfigEntity::SP_KEY        => $privateKeyStr,
            ]);
            $entityDb = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataDb]);
            $entityDb->getFields();
            if (!$entityDb->isValid()) {
                throw new \Exception("Legacy 'db' source failed validation.");
            }
            $phpSamlConfigDb = $entityDb->getPhpSamlConfig();
            if ($phpSamlConfigDb['sp']['privateKey'] !== $privateKeyStr) {
                throw new \Exception("Failed to read private key from DB source.");
            }

            // 2. Test 'env' source
            // 2a. Valid env var
            putenv("TEST_SAMLSSO_SP_KEY=" . $privateKeyStr);
            ConfigEntity::clearCache();
            $postDataEnv = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'env',
                ConfigEntity::SP_KEY        => 'TEST_SAMLSSO_SP_KEY',
            ]);
            $entityEnv = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataEnv]);
            $fieldsEnv = $entityEnv->getFields();
            if (!$entityEnv->isValid()) {
                $err = $fieldsEnv[ConfigEntity::SP_KEY][ConfigItem::ERRORS] ?? 'unknown';
                throw new \Exception("Valid 'env' source failed validation. Error: " . $err);
            }
            $phpSamlConfigEnv = $entityEnv->getPhpSamlConfig();
            if ($phpSamlConfigEnv['sp']['privateKey'] !== $privateKeyStr) {
                throw new \Exception("Failed to read private key from Env source.");
            }
            // 2b. Invalid/missing env var
            ConfigEntity::clearCache();
            $postDataEnvInvalid = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'env',
                ConfigEntity::SP_KEY        => 'NON_EXISTENT_ENV_VAR_123',
            ]);
            $entityEnvInvalid = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataEnvInvalid]);
            $entityEnvInvalid->getFields();
            if ($entityEnvInvalid->isValid()) {
                throw new \Exception("Invalid 'env' source should have failed validation.");
            }

            // 3. Test 'file' source
            // 3a. Valid file path outside GLPI_ROOT
            $outsidePath = sys_get_temp_dir() . '/saml_test_key.key';
            file_put_contents($outsidePath, $privateKeyStr);
            ConfigEntity::clearCache();
            $postDataFile = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'file',
                ConfigEntity::SP_KEY        => $outsidePath,
            ]);
            $entityFile = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataFile]);
            $entityFile->getFields();
            $isValid = $entityFile->isValid();
            @unlink($outsidePath);
            if (!$isValid) {
                throw new \Exception("Valid 'file' source outside docroot failed validation.");
            }

            // 3b. Invalid path inside GLPI_ROOT
            $insidePath = GLPI_ROOT . '/plugins/samlsso/tests/saml_test_key.key';
            file_put_contents($insidePath, $privateKeyStr);
            ConfigEntity::clearCache();
            $postDataFileInside = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'file',
                ConfigEntity::SP_KEY        => $insidePath,
            ]);
            $entityFileInside = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataFileInside]);
            $entityFileInside->getFields();
            $isValidInside = $entityFileInside->isValid();
            @unlink($insidePath);
            if ($isValidInside) {
                throw new \Exception("File path inside GLPI_ROOT should have failed docroot validation.");
            }

            /**
             * 4. Test 'define' source (Issue #142)
             */
            if (!defined('GLPI_SAML_TEST_SP_PRIVATE_KEY')) {
                define('GLPI_SAML_TEST_SP_PRIVATE_KEY', $privateKeyStr);
            }
            ConfigEntity::clearCache();
            $postDataDefine = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'define',
                ConfigEntity::SP_KEY        => 'GLPI_SAML_TEST_SP_PRIVATE_KEY',
            ]);
            $entityDefine = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataDefine]);
            $entityDefine->getFields();
            if (!$entityDefine->isValid()) {
                throw new \Exception("Valid 'define' source failed validation.");
            }
            $phpSamlConfigDefine = $entityDefine->getPhpSamlConfig();
            if ($phpSamlConfigDefine['sp']['privateKey'] !== $privateKeyStr) {
                throw new \Exception("Failed to read private key from Define source.");
            }

            /**
             * 4b. Invalid/missing define constant
             */
            ConfigEntity::clearCache();
            $postDataDefineInvalid = array_merge($basePostData, [
                ConfigEntity::SP_KEY_SOURCE => 'define',
                ConfigEntity::SP_KEY        => 'NON_EXISTENT_DEFINE_CONST_123',
            ]);
            $entityDefineInvalid = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postDataDefineInvalid]);
            $entityDefineInvalid->getFields();
            if ($entityDefineInvalid->isValid()) {
                throw new \Exception("Invalid 'define' source should have failed validation.");
            }

            echo "✅ Config: SP private key source (db, file, env, define) loading and docroot validation verified\n";
        }

        /**
         * Test that force_authn configuration option is mapped and propagated correctly.
         *
         * @throws \Exception if force_authn behavior is incorrect.
         */
        public function testForceAuthnConfig(): void {
            if (ConfigEntity::FORCE_AUTHN !== 'force_authn') {
                throw new \Exception("ConfigEntity::FORCE_AUTHN should be 'force_authn'");
            }

            $entity = new ConfigEntity();
            $fields = $entity->getFields();
            if (!array_key_exists(ConfigEntity::FORCE_AUTHN, $fields)) {
                throw new \Exception("force_authn not present in ConfigEntity getFields()");
            }

            $reflectionMethod = new \ReflectionMethod(ConfigItem::class, 'force_authn');
            $reflectionMethod->setAccessible(true);

            $res = $reflectionMethod->invoke($entity, 1);
            if (empty($res[ConfigItem::VALUE])) {
                throw new \Exception("force_authn validator did not handle 1 as active value");
            }

            $resOff = $reflectionMethod->invoke($entity, 0);
            if (!empty($resOff[ConfigItem::VALUE])) {
                throw new \Exception("force_authn validator did not handle 0 as inactive value");
            }

            echo "✅ Config: force_authn configuration mapping and validation verified (Issue #130)\n";
        }

        /**
         * Test that custom button icon URL and login screen header text are correctly
         * configured, validated, and retrievable via ConfigEntity (Issue #159).
         */
        public function testCustomLoginScreenHeaderAndButtonIcon(): void {
            if (ConfigEntity::CONF_ICON_URL !== 'conf_icon_url') {
                throw new \Exception("ConfigEntity::CONF_ICON_URL should be 'conf_icon_url'");
            }
            if (ConfigEntity::LOGIN_HEADER_TEXT !== 'login_header_text') {
                throw new \Exception("ConfigEntity::LOGIN_HEADER_TEXT should be 'login_header_text'");
            }

            $entity = new ConfigEntity();
            $fields = $entity->getFields();
            if (!array_key_exists(ConfigEntity::CONF_ICON_URL, $fields)) {
                throw new \Exception("conf_icon_url not present in ConfigEntity getFields()");
            }
            if (!array_key_exists(ConfigEntity::LOGIN_HEADER_TEXT, $fields)) {
                throw new \Exception("login_header_text not present in ConfigEntity getFields()");
            }

            $iconUrlRefl = new \ReflectionMethod(ConfigItem::class, 'conf_icon_url');
            $iconUrlRefl->setAccessible(true);
            $resIcon = $iconUrlRefl->invoke($entity, 'https://example.com/logo.png');
            if ($resIcon[ConfigItem::VALUE] !== 'https://example.com/logo.png') {
                throw new \Exception("conf_icon_url validator did not store value");
            }

            $headerRefl = new \ReflectionMethod(ConfigItem::class, 'login_header_text');
            $headerRefl->setAccessible(true);
            $resHeader = $headerRefl->invoke($entity, 'Sign in with Corporate SSO');
            if ($resHeader[ConfigItem::VALUE] !== 'Sign in with Corporate SSO') {
                throw new \Exception("login_header_text validator did not store value");
            }

            echo "✅ Config: custom login screen header and icon URL verified (Issue #159)\n";
        }

        /**
         * Test that sp_private_key is excluded from rawSearchOptions(),
         * included in getUndisclosedFields(), and encrypted with GLPIKey.
         */
        public function testSpPrivateKeySecurity(): void
        {
            $config = new Config();
            $options = $config->rawSearchOptions();
            foreach ($options as $opt) {
                if (($opt['field'] ?? '') === ConfigEntity::SP_KEY) {
                    throw new \Exception("sp_private_key must NOT be exposed in rawSearchOptions()");
                }
            }

            if (!in_array(ConfigEntity::SP_KEY, Config::getUndisclosedFields())) {
                throw new \Exception("sp_private_key must be registered in getUndisclosedFields()");
            }

            // Test GLPIKey encryption / decryption helpers in ConfigEntity
            $pemKey = "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQD\n-----END PRIVATE KEY-----";
            $encrypted = ConfigEntity::encryptPrivateKey($pemKey);
            if ($encrypted === $pemKey) {
                throw new \Exception("encryptPrivateKey did not encrypt the PEM key");
            }
            $decrypted = ConfigEntity::decryptPrivateKey($encrypted);
            if ($decrypted !== $pemKey) {
                throw new \Exception("decryptPrivateKey did not return original PEM key");
            }

            echo "✅ Config: SP private key excluded from search options, marked undisclosed, and encrypted\n";
        }

        /**
         * Test that forcelogoff requires POST method and rejects GET requests (CSRF protection).
         */
        public function testForceLogoffCsrfProtection(): void {
            $configForm = new \GlpiPlugin\Samlsso\Config\ConfigForm();

            // GET request with forcelogoff action
            $requestGet = \Symfony\Component\HttpFoundation\Request::create(
                '/plugins/samlsso/front/configform.php?action=forcelogoff&state_id=999',
                'GET'
            );
            $response = $configForm->invokeForm($requestGet);
            if (!($response instanceof \Symfony\Component\HttpFoundation\RedirectResponse)) {
                throw new \Exception("Expected RedirectResponse when forcelogoff is invoked via GET");
            }

            echo "✅ ConfigForm: forcelogoff GET request rejected (CSRF protection)\n";
        }

        /**
         * Test that inactivity timeout validation checks against GLPI core session lifetime
         * (session.gc_maxlifetime), provides advisory warnings when SAML timeout exceeds GLPI session,
         * and includes the active ceiling in the field explanation.
         *
         * @return void
         * @throws \Exception If validation or advisory warnings behave unexpectedly.
         */
        public function testInactivityTimeoutSessionCeilingValidation(): void {
            $configEntity = new \GlpiPlugin\Samlsso\Config\ConfigEntity();
            $method = new \ReflectionMethod($configEntity, 'inactivity_timeout');
            $method->setAccessible(true);

            $originalLifetime = ini_get('session.gc_maxlifetime');
            try {
                // Set core session lifetime to 3600 seconds (60 minutes)
                ini_set('session.gc_maxlifetime', '3600');

                // Case 1: Timeout <= core session lifetime (45 min <= 60 min)
                $resValid = $method->invoke($configEntity, 45);
                if ($resValid[\GlpiPlugin\Samlsso\Config\ConfigItem::EVAL] !== \GlpiPlugin\Samlsso\Config\ConfigItem::VALID) {
                    throw new \Exception("Expected timeout <= core session lifetime to be valid");
                }
                if ($resValid[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS] !== false && str_contains((string)$resValid[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS], '⚠️ Inactivity timeout')) {
                    throw new \Exception("Unexpected advisory warning when timeout <= core session lifetime");
                }
                if (!str_contains((string)$resValid[\GlpiPlugin\Samlsso\Config\ConfigItem::FORMEXPLAIN], '60 minutes')) {
                    throw new \Exception("Expected formexplain to include active GLPI core session lifetime (60 minutes)");
                }

                // Case 2: Disabled timeout (0 min)
                $resZero = $method->invoke($configEntity, 0);
                if ($resZero[\GlpiPlugin\Samlsso\Config\ConfigItem::EVAL] !== \GlpiPlugin\Samlsso\Config\ConfigItem::VALID) {
                    throw new \Exception("Expected disabled timeout (0) to be valid");
                }
                if ($resZero[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS] !== false && str_contains((string)$resZero[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS], '⚠️ Inactivity timeout')) {
                    throw new \Exception("Disabled timeout (0) must not produce an advisory warning");
                }

                // Case 3: Timeout > core session lifetime (120 min > 60 min)
                $resExceed = $method->invoke($configEntity, 120);
                if ($resExceed[\GlpiPlugin\Samlsso\Config\ConfigItem::EVAL] !== \GlpiPlugin\Samlsso\Config\ConfigItem::VALID) {
                    throw new \Exception("Expected timeout exceeding session lifetime to remain valid with advisory warning");
                }
                if (empty($resExceed[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS]) || !str_contains((string)$resExceed[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS], '⚠️ Inactivity timeout (120 min) exceeds GLPI session lifetime (60 min)')) {
                    throw new \Exception("Expected advisory warning when inactivity timeout exceeds core session lifetime, got: " . json_encode($resExceed[\GlpiPlugin\Samlsso\Config\ConfigItem::ERRORS]));
                }

                // Case 4: Invalid negative timeout
                $resNeg = $method->invoke($configEntity, -5);
                if ($resNeg[\GlpiPlugin\Samlsso\Config\ConfigItem::EVAL] !== \GlpiPlugin\Samlsso\Config\ConfigItem::INVALID) {
                    throw new \Exception("Expected negative timeout to be invalid");
                }

            } finally {
                if ($originalLifetime !== false) {
                    ini_set('session.gc_maxlifetime', (string)$originalLifetime);
                }
            }

            echo "✅ Config: inactivity timeout session ceiling validation and advisory warning verified (Issue #181)\n";
        }

        /**
         * Test that provider.html.twig template defines dynamic input fields for alternative
         * private key sources (file, env, define) and disables raw PEM textarea (Issue #182).
         *
         * @return void
         * @throws \Exception If template elements or switching logic are missing.
         */
        public function testSpPrivateKeyTemplateInputs(): void {
            $templatePath = __DIR__ . '/../templates/configForm/provider.html.twig';
            if (!file_exists($templatePath)) {
                throw new \Exception("provider.html.twig template not found at $templatePath");
            }
            $content = file_get_contents($templatePath);

            // Verify reference container and text field
            if (!str_contains($content, 'id="sp_private_key_ref_container"')) {
                throw new \Exception("Missing #sp_private_key_ref_container in provider.html.twig (Issue #182)");
            }
            if (!str_contains($content, "'id': 'sp_private_key_ref'")) {
                throw new \Exception("Missing sp_private_key_ref text input in provider.html.twig (Issue #182)");
            }

            // Verify raw PEM textarea container
            if (!str_contains($content, 'id="sp_private_key_raw_container"')) {
                throw new \Exception("Missing #sp_private_key_raw_container in provider.html.twig (Issue #182)");
            }
            if (!str_contains($content, "'id': 'sp_private_key_raw'")) {
                throw new \Exception("Missing sp_private_key_raw textarea input in provider.html.twig (Issue #182)");
            }

            // Verify client-side dynamic switching logic
            if (!str_contains($content, "updateKeySourceUI") || !str_contains($content, "select[name=\"sp_private_key_source\"]")) {
                throw new \Exception("Missing updateKeySourceUI change listener in provider.html.twig (Issue #182)");
            }

            echo "✅ Config: dynamic SP private key source input switching in provider.html.twig verified (Issue #182)\n";
        }

        /**
         * Test dynamic self-signed SP certificate generation method and endpoint guards (Issue #183, #184).
         *
         * @return void
         * @throws \Exception If certificate generation, modulus validation, or endpoint security checks fail.
         */
        public function testGenerateCertMethodAndEndpoint(): void {
            if (!extension_loaded('openssl') || !function_exists('openssl_pkey_new')) {
                echo "⚠️ Skipping testGenerateCertMethodAndEndpoint: OpenSSL not available\n";
                return;
            }

            // 1. Test ConfigEntity::generateSelfSignedCertificate() with RSA (2048 and 3072 bits)
            $pairRsa = ConfigEntity::generateSelfSignedCertificate('saml-test.example.com', 365, 'RSA', 2048, 'prime256v1', 'sha256');
            if ($pairRsa === false || !is_array($pairRsa)) {
                throw new \Exception("ConfigEntity::generateSelfSignedCertificate returned false for RSA 2048");
            }
            if (empty($pairRsa['certificate']) || !str_contains($pairRsa['certificate'], '-----BEGIN CERTIFICATE-----')) {
                throw new \Exception("Generated RSA certificate is missing or invalid PEM structure");
            }
            if (empty($pairRsa['private_key']) || !str_contains($pairRsa['private_key'], '-----BEGIN PRIVATE KEY-----')) {
                throw new \Exception("Generated RSA private key is missing or invalid PEM structure");
            }

            // Parse generated certificate
            $parsedCert = openssl_x509_parse($pairRsa['certificate']);
            if (!$parsedCert || empty($parsedCert['subject']['CN'])) {
                throw new \Exception("Failed to parse generated X.509 certificate");
            }
            if ($parsedCert['subject']['CN'] !== 'saml-test.example.com') {
                throw new \Exception("Expected CN 'saml-test.example.com', got '{$parsedCert['subject']['CN']}'");
            }

            // Validate key pair modulus match via ConfigEntity reflection
            $configEntity = new ConfigEntity();
            $method = new \ReflectionMethod($configEntity, 'validateCertKeyPairModulus');
            $method->setAccessible(true);
            $modulusMatches = $method->invoke($configEntity, $pairRsa['certificate'], $pairRsa['private_key']);
            if (!$modulusMatches) {
                throw new \Exception("Generated RSA certificate and private key modulus do not match");
            }

            // Test EC generation (prime256v1)
            $pairEc = ConfigEntity::generateSelfSignedCertificate('saml-ec.example.com', 730, 'EC', 2048, 'prime256v1', 'sha384');
            if ($pairEc === false || !is_array($pairEc)) {
                throw new \Exception("ConfigEntity::generateSelfSignedCertificate returned false for EC prime256v1");
            }
            $modulusMatchesEc = $method->invoke($configEntity, $pairEc['certificate'], $pairEc['private_key']);
            if (!$modulusMatchesEc) {
                throw new \Exception("Generated EC certificate and private key do not match");
            }

            // Test strict security rejection of weak key lengths (< 2048 bits for RSA)
            $pairWeak = ConfigEntity::generateSelfSignedCertificate('weak-key.example.com', 365, 'RSA', 1024);
            if ($pairWeak !== false) {
                throw new \Exception("ConfigEntity::generateSelfSignedCertificate must strictly reject RSA key lengths < 2048 bits");
            }

            // 2. Test template UI modal elements in provider.html.twig
            $templatePath = __DIR__ . '/../templates/configForm/provider.html.twig';
            $tplContent = file_get_contents($templatePath);
            if (!str_contains($tplContent, 'id="samlsso-cert-generator-modal"')) {
                throw new \Exception("Missing #samlsso-cert-generator-modal in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="cert_gen_cn"')) {
                throw new \Exception("Missing #cert_gen_cn in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="cert_gen_type"')) {
                throw new \Exception("Missing #cert_gen_type in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="cert_gen_key_bits"')) {
                throw new \Exception("Missing #cert_gen_key_bits in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="cert_gen_curve"')) {
                throw new \Exception("Missing #cert_gen_curve in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="cert_gen_valid_years"')) {
                throw new \Exception("Missing #cert_gen_valid_years in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="cert_gen_digest"')) {
                throw new \Exception("Missing #cert_gen_digest in provider.html.twig (Issue #184)");
            }
            if (!str_contains($tplContent, 'id="btn-confirm-generate-cert"')) {
                throw new \Exception("Missing #btn-confirm-generate-cert button in provider.html.twig (Issue #184)");
            }

            // 3. Test ConfigForm endpoint guards: GET request must be rejected with 405 Method Not Allowed
            $configForm = new \GlpiPlugin\Samlsso\Config\ConfigForm();
            $requestGet = \Symfony\Component\HttpFoundation\Request::create(
                '/plugins/samlsso/front/configform.php?action=generate_cert',
                'GET'
            );
            $response = $configForm->invokeForm($requestGet);
            if ($response->getStatusCode() !== \Symfony\Component\HttpFoundation\Response::HTTP_METHOD_NOT_ALLOWED) {
                throw new \Exception("Expected HTTP 405 Method Not Allowed for GET generate_cert request, got " . $response->getStatusCode());
            }

            echo "✅ Config: dynamic configurable SP certificate generation modal, parameters, and security bounds verified (Issues #183, #184)\n";
        }
    }
}

namespace {
    /**
     * Executes the ConfigIntegrityTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\ConfigIntegrityTest();
    try {
        $test->testSchemaVsConstants();
        $test->testValidatorsExistence();
        $test->testDeadConfigDetection();
        $test->testMultiIdpEnforceAutoDisable();
        $test->testStrictDisabledAutoDisableInboundSecurity();
        $test->testSpPrivateKeySources();
        $test->testForceAuthnConfig();
        $test->testCustomLoginScreenHeaderAndButtonIcon();
        $test->testSpPrivateKeySecurity();
        $test->testForceLogoffCsrfProtection();
        $test->testInactivityTimeoutSessionCeilingValidation();
        $test->testSpPrivateKeyTemplateInputs();
        $test->testGenerateCertMethodAndEndpoint();
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
