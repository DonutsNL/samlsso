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
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
