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
                    $boolPrefixes = ['enforce_', 'proxied', 'strict', 'debug', 'user_jit', 'security_', 'compress_', 'validate_', 'lowercase_', 'is_'];
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
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
