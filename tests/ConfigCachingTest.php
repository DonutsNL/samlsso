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
 *  @since      1.3.2
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/Config/ConfigItem.php';
    require_once __DIR__ . '/../src/Config/ConfigEntity.php';
    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/../src/LoginFlow.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Config;

    /**
     * Class CountingMockDB
     * Intercepts and counts database queries.
     */
    class CountingMockDB extends MockDB
    {
        public int $requestCount = 0;

        /**
         * Intercept request query structure and return an Iterator mapping Mock row records.
         *
         * @param array $params Request parameters.
         * @return object Iterator structure representing query results.
         */
        public function request(array $params): object
        {
            $this->requestCount++;
            return parent::request($params);
        }
    }

    /**
     * Class ConfigCachingTest
     * Verifies that configuration queries are cached and do not hit the DB repeatedly.
     */
    class ConfigCachingTest extends TestHarness
    {
        /**
         * Test that ConfigEntity queries are cached.
         *
         * @throws \Exception
         */
        public function testConfigEntityCaching(): void
        {
            // Clear cache and call count to start fresh
            ConfigEntity::clearCache();
            \CommonDBTM::$getFromDBCallCount = 0;

            // First instantiation: should query the database (call getFromDB)
            $entity1 = new ConfigEntity(1);
            $initialCount = \CommonDBTM::$getFromDBCallCount;
            if ($initialCount !== 1) {
                throw new \Exception("Expected exactly 1 getFromDB call on first instantiation, got: $initialCount");
            }

            // Second instantiation: should use request-level cache
            $entity2 = new ConfigEntity(1);
            $secondCount = \CommonDBTM::$getFromDBCallCount;
            if ($secondCount !== 1) {
                throw new \Exception("Expected getFromDB call count to remain 1 due to caching, got: $secondCount");
            }

            // Clear cache and try again: should query the database
            ConfigEntity::clearCache();
            $entity3 = new ConfigEntity(1);
            $thirdCount = \CommonDBTM::$getFromDBCallCount;
            if ($thirdCount !== 2) {
                throw new \Exception("Expected exactly 2 getFromDB calls after clearing cache, got: $thirdCount");
            }

            echo "✅ ConfigEntity caching verified successfully\n";
        }

        /**
         * Test that Config static helper queries are cached.
         *
         * @throws \Exception
         */
        public function testConfigStaticGettersCaching(): void
        {
            global $DB;
            $originalDB = $DB;

            $countingDB = new CountingMockDB();
            $DB = $countingDB;

            // Clear static caches to start fresh
            Config::clearCache();

            // Set up mock DB responses
            $mockRows = [
                [
                    'id' => 1,
                    'name' => 'Test IdP',
                    'conf_domain' => 'example.com',
                    'is_active' => 1,
                    'is_deleted' => 0,
                    'enforce_sso' => 1,
                ]
            ];
            $countingDB->setResponse(Config::getTable(), $mockRows);

            // Test getIsEnforced caching
            $enforced1 = Config::getIsEnforced();
            $enforced2 = Config::getIsEnforced();
            if ($countingDB->requestCount !== 1) {
                $DB = $originalDB;
                throw new \Exception("Expected exactly 1 DB query for getIsEnforced caching, got: " . $countingDB->requestCount);
            }

            // Test getIsOnlyOneConfig caching
            $onlyOne1 = Config::getIsOnlyOneConfig();
            $onlyOne2 = Config::getIsOnlyOneConfig();
            // Should be 2 queries total (1 for getIsEnforced, 1 for getIsOnlyOneConfig)
            if ($countingDB->requestCount !== 2) {
                $DB = $originalDB;
                throw new \Exception("Expected exactly 2 DB queries total after getIsOnlyOneConfig, got: " . $countingDB->requestCount);
            }

            // Test getHideLoginFields caching
            $hide1 = Config::getHideLoginFields();
            $hide2 = Config::getHideLoginFields();
            if ($countingDB->requestCount !== 3) {
                $DB = $originalDB;
                throw new \Exception("Expected exactly 3 DB queries total after getHideLoginFields, got: " . $countingDB->requestCount);
            }

            // Test getConfigIdByEmailDomain caching
            $id1 = Config::getConfigIdByEmailDomain('test@example.com');
            $id2 = Config::getConfigIdByEmailDomain('test@example.com');
            if ($countingDB->requestCount !== 4) {
                $DB = $originalDB;
                throw new \Exception("Expected exactly 4 DB queries total after getConfigIdByEmailDomain, got: " . $countingDB->requestCount);
            }

            // Verify clearing the cache resets query counts
            Config::clearCache();
            $enforced3 = Config::getIsEnforced();
            if ($countingDB->requestCount !== 5) {
                $DB = $originalDB;
                throw new \Exception("Expected exactly 5 DB queries after clearing cache, got: " . $countingDB->requestCount);
            }

            $DB = $originalDB;
            echo "✅ Config static getters caching verified successfully\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\ConfigCachingTest();
    try {
        $test->testConfigEntityCaching();
        $test->testConfigStaticGettersCaching();
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
