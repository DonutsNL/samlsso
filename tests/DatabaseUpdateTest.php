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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/../src/ClaimMap.php';
    require_once __DIR__ . '/../src/LoginState.php';
    if (file_exists(__DIR__ . '/../src/ScimLink.php')) {
        require_once __DIR__ . '/../src/ScimLink.php';
    }

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
         * @var array
         */
        public array $dropFieldCalls = [];

        /**
         * Intercept call to dropField.
         *
         * @param string $table Table name
         * @param string $field Field name
         * @return bool
         */
        public function dropField(string $table, string $field): bool {
            $this->dropFieldCalls[] = [
                'table' => $table,
                'field' => $field,
            ];
            return true;
        }

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
         * Optional mocked rows keyed by table name.
         *
         * @var array<string, array<int, array<string, mixed>>>
         */
        public array $mockResponses = [];

        /**
         * Recorded update calls.
         *
         * @var array<int, array<string, mixed>>
         */
        public array $updatedRows = [];

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
            $table = (string)($params['FROM'] ?? '');
            $rows = $this->mockResponses[$table] ?? [];
            return new class($rows) implements \Iterator, \Countable {
                private array $rows;
                private int $pos = 0;
                public function __construct(array $rows) { $this->rows = array_values($rows); }
                public function count(): int { return count($this->rows); }
                public function current(): mixed { return $this->rows[$this->pos]; }
                public function key(): mixed { return $this->pos; }
                public function next(): void { $this->pos++; }
                public function rewind(): void { $this->pos = 0; }
                public function valid(): bool { return isset($this->rows[$this->pos]); }
            };
        }

        /**
         * Record mock DB update payloads.
         *
         * @param string $table Table name.
         * @param array $data Columns to update.
         * @param array $where Where condition.
         * @return bool
         */
        public function update(string $table, array $data, array $where): bool {
            $this->updatedRows[] = [
                'table' => $table,
                'data'  => $data,
                'where' => $where,
            ];
            return true;
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

            $migration = new InterceptingMigration('1.4.0');

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
                if (strtolower($call['type']) === 'varchar') {
                    throw new \Exception("Detected invalid bare 'varchar' type in addField (Issue #173): Table={$call['table']}, Field={$call['field']}. Use 'string' instead.");
                }
                if ($call['field'] === 'force_authn' && !empty($opts['update']) && $opts['update'] === true) {
                    throw new \Exception("Detected 'update' => true on force_authn in addField (Issue #173), which causes accidental activation on upgrade.");
                }
            }

            echo "✅ Database Migrations: Verified all addField calls are order-independent and use valid types (no bare 'varchar' or forbidden order options found in " . count($migration->addFieldCalls) . " field updates)\n";
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
            $migration = new InterceptingMigration('1.4.0');
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
            $migrationExisting = new InterceptingMigration('1.4.0');
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

        /**
         * Verifies that Config::install() invokes migrationOneTable on the configs table
         * so all queued addField migrations are executed during upgrade (Issue #182).
         *
         * @return void
         * @throws \Exception if migrationOneTable was not invoked for glpi_plugin_samlsso_configs.
         */
        public function testConfigTableMigrationExecution(): void {
            global $DB;
            $DB = new SimpleMockDB();
            $table = Config::getTable();
            $DB->existingTables = [$table];

            $migration = new InterceptingMigration('1.4.0');
            Config::install($migration);

            if (!in_array($table, $migration->migrationOneTableCalls, true)) {
                throw new \Exception("Expected migrationOneTable to be called for $table in Config::install() (Issue #182).");
            }

            $claimTable = getTableForItemType(ClaimMap::class);
            $migrationClaim = new InterceptingMigration('1.4.0');
            ClaimMap::install($migrationClaim);
            $claimFlushCount = count(array_keys($migrationClaim->migrationOneTableCalls, $claimTable, true));
            if ($claimFlushCount < 2) {
                throw new \Exception("Expected ClaimMap::install() upgrade branch to flush migrationOneTable after addField/addKey (Issue #196), got $claimFlushCount calls.");
            }

            echo "✅ Database Migrations: Verified migrationOneTable is called for $table in Config::install() (#182) and $claimTable in ClaimMap::install() (#196)\n";
        }

        /**
         * Verifies v1.4.0 schema migrations: ScimLink table lifecycle, Config SCIM columns,
         * and LoginState legacy column cleanup (serverParams, loggedOff, enforceLogoff).
         *
         * @return void
         * @throws \Exception if v1.4.0 schema migrations are missing.
         */
        public function testV140ScimAndLoginStateLegacyCleanup(): void {
            global $DB;
            if (!class_exists('GlpiPlugin\Samlsso\ScimLink')) {
                throw new \Exception('ScimLink class does not exist yet.');
            }

            $DB = new SimpleMockDB();
            $loginStateTable = getTableForItemType(LoginState::class);
            $DB->existingFields[$loginStateTable] = ['serverParams', 'loggedOff', 'enforceLogoff'];

            $migration = new InterceptingMigration('1.4.0');
            Config::install($migration);
            LoginState::install($migration);
            \GlpiPlugin\Samlsso\ScimLink::install($migration);

            $droppedFields = array_column($migration->dropFieldCalls, 'field');
            foreach (['serverParams', 'loggedOff', 'enforceLogoff'] as $legacyCol) {
                if (!in_array($legacyCol, $droppedFields, true)) {
                    throw new \Exception("Expected LoginState::install() to drop legacy column '$legacyCol' in v1.4.0.");
                }
            }

            $addedConfigFields = array_column($migration->addFieldCalls, 'field');
            foreach ([
                'scim_enabled',
                'scim_token_hash',
                'scim_token_source',
                'scim_allowed_ips',
                'scim_deprovision',
                'scim_group_policy',
            ] as $scimCol) {
                if (!in_array($scimCol, $addedConfigFields, true)) {
                    throw new \Exception("Expected Config::install() upgrade path to add '$scimCol'.");
                }
            }

            $scimTable = getTableForItemType(\GlpiPlugin\Samlsso\ScimLink::class);
            if (!in_array($scimTable, $migration->migrationOneTableCalls, true)) {
                throw new \Exception("Expected ScimLink::install() upgrade path to flush migrationOneTable($scimTable).");
            }

            echo "✅ Database Migrations: Verified v1.4.0 ScimLink lifecycle, SCIM Config columns, and LoginState legacy column cleanup (#199)\n";
        }

        /**
         * Verifies that Config::install() idempotently upgrades legacy SAML users
         * (authtype = EXTERNAL, auths_id matching an active config ID < AUTHS_ID_OFFSET,
         * and empty user_dn) to User::toAuthsId($oldAuthsId) (1,000,000 + $oldAuthsId)
         * while leaving non-SAML external rows with non-empty user_dn untouched (#189).
         *
         * @return void
         * @throws \Exception if legacy SAML rows are not upgraded or non-empty user_dn rows are modified.
         */
        public function testLegacySamlAuthsIdNamespaceUpgrade(): void {
            global $DB;
            $DB = new SimpleMockDB();
            $configTable = Config::getTable();
            $DB->mockResponses[$configTable] = [
                ['id' => 5, 'sp_private_key' => '', 'sp_private_key_source' => 'db'],
            ];
            $DB->mockResponses['glpi_users'] = [
                ['id' => 41, 'authtype' => 4, 'auths_id' => 5, 'user_dn' => ''],
                ['id' => 42, 'authtype' => 4, 'auths_id' => 5, 'user_dn' => null],
                ['id' => 43, 'authtype' => 4, 'auths_id' => 5, 'user_dn' => 'CN=cas,DC=local'],
                ['id' => 44, 'authtype' => 4, 'auths_id' => 1000005, 'user_dn' => ''],
            ];

            $migration = new InterceptingMigration('1.4.0');
            Config::install($migration);

            $userUpdates = [];
            foreach ($DB->updatedRows as $upd) {
                if ($upd['table'] === 'glpi_users' && isset($upd['where']['id'])) {
                    $userUpdates[(int)$upd['where']['id']] = $upd['data'];
                }
            }

            if (
                (int)($userUpdates[41]['auths_id'] ?? 0) !== 1000005 ||
                (int)($userUpdates[42]['auths_id'] ?? 0) !== 1000005 ||
                isset($userUpdates[43]) ||
                isset($userUpdates[44])
            ) {
                throw new \Exception('Config::install() legacy auths_id upgrade payload unexpected: ' . json_encode($userUpdates));
            }

            echo "✅ Database Migrations: Verified legacy SAML auths_id namespace upgrade (5 -> 1000005) leaving non-empty user_dn untouched (#189)\n";
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
        $test->testConfigTableMigrationExecution();
        $test->testV140ScimAndLoginStateLegacyCleanup();
        $test->testLegacySamlAuthsIdNamespaceUpgrade();
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
