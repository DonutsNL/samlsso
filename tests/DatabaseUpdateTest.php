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
 *  Copyright (C) 2026 by DonutsNL
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
    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/../src/ClaimMap.php';
    require_once __DIR__ . '/../src/LoginState.php';

    use GlpiPlugin\Samlsso\Config;
    use GlpiPlugin\Samlsso\ClaimMap;
    use GlpiPlugin\Samlsso\LoginState;

    /**
     * Interceptor Migration mock to capture and validate options passed to addField.
     */
    class InterceptingMigration extends \Migration {
        /**
         * @var array
         */
        public array $addFieldCalls = [];

        /**
         * @var array
         */
        public array $changeFieldCalls = [];

        /**
         * @var array
         */
        public array $addKeyCalls = [];

        /**
         * @var array
         */
        public array $migrationOneTableCalls = [];

        /**
         * Intercept call to addField.
         *
         * @param string $table Table name
         * @param string $field Field name
         * @param string $type Field type
         * @param array $options Options
         * @return bool
         */
        public function addField(string $table, string $field, string $type, array $options = []): bool {
            $this->addFieldCalls[] = [
                'table'   => $table,
                'field'   => $field,
                'type'    => $type,
                'options' => $options,
            ];
            return true;
        }

        /**
         * Stub changeField method.
         *
         * @param string $table Table name
         * @param string $field Field name
         * @param string $new_field New field name
         * @param string $type Field type
         * @param array $options Options
         * @return bool
         */
        public function changeField(string $table, string $field, string $new_field, string $type, array $options = []): bool {
            $this->changeFieldCalls[] = [
                'table'     => $table,
                'field'     => $field,
                'new_field' => $new_field,
                'type'      => $type,
                'options'   => $options,
            ];
            return true;
        }

        /**
         * Stub migrationOneTable method.
         *
         * @param string $table Table name
         * @return void
         */
        public function migrationOneTable(string $table): void {
            $this->migrationOneTableCalls[] = $table;
        }

        /**
         * Stub addKey method.
         *
         * @param string $table Table name
         * @param array $fields Fields list
         * @param string $keyname Key name
         * @param string $keytype Key type
         * @return bool
         */
        public function addKey(string $table, array $fields, string $keyname, string $keytype = ''): bool {
            $this->addKeyCalls[] = [
                'table'   => $table,
                'fields'  => $fields,
                'keyname' => $keyname,
                'keytype' => $keytype,
            ];
            return true;
        }
    }

    /**
     * Simple mock DB implementation to support running migration checks.
     */
    class SimpleMockDB {
        /**
         * Map of existing fields per table.
         *
         * @var array
         */
        public array $existingFields = [];

        /**
         * Check if table exists in mock DB.
         *
         * @param string $table Table name.
         * @return bool
         */
        public function tableExists(string $table): bool {
            return true;
        }

        /**
         * Mock query execution.
         *
         * @param string $query Query string.
         * @return bool
         */
        public function doQuery(string $query): bool {
            return true;
        }

        /**
         * Check if field exists in mock DB.
         *
         * @param string $table Table name.
         * @param string $field Field name.
         * @param bool $cache Cache option.
         * @return bool
         */
        public function fieldExists(string $table, string $field, bool $cache = true): bool {
            return in_array($field, $this->existingFields[$table] ?? [], true);
        }

        /**
         * Mock request.
         *
         * @param array $params Params.
         * @return object
         */
        public function request(array $params): object {
            return new class implements \Iterator, \Countable {
                public function count(): int { return 0; }
                public function current(): mixed { return null; }
                public function key(): mixed { return null; }
                public function next(): void {}
                public function rewind(): void {}
                public function valid(): bool { return false; }
            };
        }

        /**
         * Mock error message.
         *
         * @return string
         */
        public function error(): string {
            return '';
        }

        /**
         * Mock dbdefault.
         *
         * @var string
         */
        public string $dbdefault = 'glpi_test';
    }

    /**
     * DatabaseUpdateTest class.
     */
    class DatabaseUpdateTest {

        /**
         * Verifies that no addField calls in the update procedures use the 'after' or 'before' keys.
         *
         * @throws \Exception if an 'after' or 'before' option is detected in any addField call.
         */
        public function testUpdateProcedureNoAfterBefore(): void {
            global $DB;
            $DB = new SimpleMockDB();

            $migration = new InterceptingMigration('1.3.2');

            // 1. Test Config update/install
            Config::install($migration);

            // 2. Test ClaimMap update/install
            ClaimMap::install($migration);

            // 3. Test LoginState update/install
            LoginState::install($migration);

            // Assertions
            if (empty($migration->addFieldCalls)) {
                throw new \Exception("Expected addField calls to be intercepted, but none were captured.");
            }

            foreach ($migration->addFieldCalls as $call) {
                $opts = $call['options'];
                if (array_key_exists('after', $opts)) {
                    throw new \Exception("Detected forbidden 'after' option in field addition: Table={$call['table']}, Field={$call['field']}, after={$opts['after']}");
                }
                if (array_key_exists('before', $opts)) {
                    throw new \Exception("Detected forbidden 'before' option in field addition: Table={$call['table']}, Field={$call['field']}, before={$opts['before']}");
                }
            }

            echo "✅ Database Migrations: Verified all addField calls are order-independent (no 'after' or 'before' options found in " . count($migration->addFieldCalls) . " field updates)\n";
        }

        /**
         * Verifies that the phase column is converted to tinyint unsigned and indexed (Issue #160),
         * and redirect is migrated or added as TEXT (Issue #165).
         *
         * @throws \Exception if migration calls do not match expectations.
         */
        public function testLoginStatePhaseAndRedirectMigration(): void {
            global $DB;

            /**
             * Case 1: Fresh upgrade where redirect does not yet exist.
             */
            $DB = new SimpleMockDB();
            $migration = new InterceptingMigration('1.3.2');
            LoginState::install($migration);

            $table = getTableForItemType(LoginState::class);

            $phaseChanged = false;
            foreach ($migration->changeFieldCalls as $call) {
                if ($call['table'] === $table && $call['field'] === 'phase') {
                    if (str_contains($call['type'], 'tinyint') && str_contains($call['type'], 'DEFAULT 1')) {
                        $phaseChanged = true;
                        break;
                    }
                }
            }
            if (!$phaseChanged) {
                throw new \Exception("Expected phase column to be changed to tinyint unsigned DEFAULT 1 (Issue #160).");
            }

            $phaseKeyAdded = false;
            foreach ($migration->addKeyCalls as $call) {
                if ($call['table'] === $table && $call['keyname'] === 'phase') {
                    $phaseKeyAdded = true;
                    break;
                }
            }
            if (!$phaseKeyAdded) {
                throw new \Exception("Expected phase index to be added to LoginState (Issue #160).");
            }

            $redirectAdded = false;
            foreach ($migration->addFieldCalls as $call) {
                if ($call['table'] === $table && $call['field'] === LoginState::REDIRECT) {
                    if (strtolower($call['type']) === 'text') {
                        $redirectAdded = true;
                        break;
                    }
                }
            }
            if (!$redirectAdded) {
                throw new \Exception("Expected redirect column to be added as TEXT when not existing (Issue #165).");
            }

            /**
             * Case 2: Existing upgrade where redirect was previously varchar(255).
             */
            $DB = new SimpleMockDB();
            $DB->existingFields[$table] = [LoginState::REDIRECT];
            $migrationExisting = new InterceptingMigration('1.3.2');
            LoginState::install($migrationExisting);

            $redirectChanged = false;
            foreach ($migrationExisting->changeFieldCalls as $call) {
                if ($call['table'] === $table && $call['field'] === LoginState::REDIRECT) {
                    if (strtolower($call['type']) === 'text') {
                        $redirectChanged = true;
                        break;
                    }
                }
            }
            if (!$redirectChanged) {
                throw new \Exception("Expected existing redirect column to be changed to TEXT (Issue #165).");
            }

            echo "✅ Database Migrations: Verified phase is tinyint with index (#160) and redirect is TEXT (#165)\n";
        }
    }
}

namespace {
    /**
     * Run the test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\DatabaseUpdateTest();
    try {
        $test->testUpdateProcedureNoAfterBefore();
        $test->testLoginStatePhaseAndRedirectMigration();
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
