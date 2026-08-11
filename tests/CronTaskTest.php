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
 * CronTaskTest.php
 * 
 * Unit tests validating Cron clean session logic, verifying that the automatic SAML
 * session cleanup jobs execute correctly with configured database retention periods.
 */

namespace {
    /**
     * Shim for GLPI's CronTask class.
     */
    if (!class_exists('CronTask')) {
        class CronTask {
            public const STATE_WAITING = 1;
            public const MODE_EXTERNAL = 2;

            /** @var array Task fields. */
            public array $fields = [];
            /** @var int Task volume processed. */
            public int $volume = -1;

            /**
             * Set the volume of records processed by the task.
             *
             * @param int $volume Records count.
             */
            public function setVolume(int $volume): void {
                $this->volume = $volume;
            }

            public static function getTable(): string {
                return 'glpi_crontasks';
            }

            public function getFromDBbyName(string $itemtype, string $name): bool {
                return false;
            }

            public static function register(string $itemtype, string $name, int $frequency, array $options = []): bool {
                return true;
            }
        }
    }
}

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/CronTask.php';

    use GlpiPlugin\Samlsso\CronTask;
    use GlpiPlugin\Samlsso\Loginstate;

    /**
     * CronTaskTest class.
     * Checks database cleanup execution rules.
     */
    class CronTaskTest extends TestHarness {

        /**
         * Test that SAML session cleanup works correctly when a positive retention period is configured.
         *
         * @throws \Exception if the cleanup result or database query structure is unexpected.
         */
        public function testCronSessionCleanupPositive(): void {
            $task = new \CronTask();
            $task->fields['param'] = 30;

            $this->db->deletedRows = [];
            // Mock the count query to return 5 rows
            $this->db->setResponse(Loginstate::getTable(), [
                ['cnt' => 5]
            ]);

            $result = CronTask::cronCleanSessionSAML($task);

            if ($result !== 1) {
                throw new \Exception("cronCleanSessionSAML returned unexpected status: " . var_export($result, true));
            }

            if ($task->volume !== 5) {
                throw new \Exception("cronCleanSessionSAML set unexpected volume: " . $task->volume);
            }

            if (count($this->db->deletedRows) !== 1) {
                throw new \Exception("Expected 1 delete operation, got: " . count($this->db->deletedRows));
            }

            $deleted = $this->db->deletedRows[0];
            if ($deleted['table'] !== Loginstate::getTable()) {
                throw new \Exception("Unexpected deleted table: " . $deleted['table']);
            }

            $where = $deleted['where'];
            if (!isset($where[Loginstate::LAST_ACTIVITY])) {
                throw new \Exception("Missing LAST_ACTIVITY condition in delete clause.");
            }

            $expr = $where[Loginstate::LAST_ACTIVITY];
            if (!is_array($expr) || $expr[0] !== '<') {
                throw new \Exception("Unexpected operator in delete expression: " . var_export($expr, true));
            }
            
            $qExpr = $expr[1];
            if (!str_contains((string)$qExpr, 'INTERVAL 30 DAY')) {
                throw new \Exception("Unexpected QueryExpression in delete clause: " . var_export($qExpr, true));
            }

            echo "✅ CronTask: session cleanup with positive retention\n";
        }

        /**
         * Test that SAML session cleanup clamps retention periods under 1 day to 8 hours.
         *
         * @throws \Exception
         */
        public function testCronSessionCleanupClamping(): void {
            $task = new \CronTask();
            $task->fields['param'] = 0.5; // less than 1 day

            $this->db->deletedRows = [];
            $this->db->setResponse(Loginstate::getTable(), [
                ['cnt' => 3]
            ]);

            $result = CronTask::cronCleanSessionSAML($task);

            if ($result !== 1) {
                throw new \Exception("cronCleanSessionSAML returned unexpected status: " . var_export($result, true));
            }

            if ($task->volume !== 3) {
                throw new \Exception("cronCleanSessionSAML set unexpected volume: " . $task->volume);
            }

            if (count($this->db->deletedRows) !== 1) {
                throw new \Exception("Expected 1 delete operation, got: " . count($this->db->deletedRows));
            }

            $deleted = $this->db->deletedRows[0];
            $where = $deleted['where'];
            $expr = $where[Loginstate::LAST_ACTIVITY];
            $qExpr = $expr[1];

            if (!str_contains((string)$qExpr, 'INTERVAL 8 HOUR')) {
                throw new \Exception("Expected 8-hour interval clamping for < 1 day param. Got: " . var_export($qExpr, true));
            }

            echo "✅ CronTask: session cleanup clamped to 8 hours for < 1 day retention\n";
        }

        /**
         * Test that SAML session cleanup is skipped when the retention period is set to 0 days.
         *
         * @throws \Exception if deleted rows are recorded when retention is zero.
         */
        public function testCronSessionCleanupZero(): void {
            $task = new \CronTask();
            $task->fields['param'] = 0;

            $this->db->deletedRows = [];

            $result = CronTask::cronCleanSessionSAML($task);

            if ($result !== 0) {
                throw new \Exception("cronCleanSessionSAML returned unexpected status for 0 days: " . $result);
            }

            if (count($this->db->deletedRows) !== 0) {
                throw new \Exception("Unexpected deletions performed when retention is set to 0.");
            }

            echo "✅ CronTask: session cleanup skipped when retention is 0\n";
        }

        /**
         * Test cronInfo returns correct details for both tasks.
         *
         * @throws \Exception
         */
        public function testCronInfo(): void {
            $infoClean = CronTask::cronInfo('cleanSessionSAML');
            if (empty($infoClean['description'])) {
                throw new \Exception("Missing description for cleanSessionSAML");
            }

            $infoGeoIP = CronTask::cronInfo('updateGeoIP');
            if (empty($infoGeoIP['description'])) {
                throw new \Exception("Missing description for updateGeoIP");
            }

            $infoInvalid = CronTask::cronInfo('invalidTask');
            if (!empty($infoInvalid)) {
                throw new \Exception("Expected empty array for invalid task info");
            }

            echo "✅ CronTask: cronInfo metadata verified\n";
        }

        /**
         * Test that cronCleanSessionSAML correctly processes inactivity timeouts
         * using the correct LoginState constant fields.
         */
        public function testCronCleanSessionInactivityTimeout(): void {
            $task = new \CronTask();
            $task->fields['param'] = 30;

            $this->db->updatedRows = [];

            // Mock active identity providers in Config table response
            $this->db->setResponse(\GlpiPlugin\Samlsso\Config::getTable(), [
                [
                    'id' => 2,
                    'inactivity_timeout' => 15,
                    'is_deleted' => 0
                ]
            ]);

            $result = CronTask::cronCleanSessionSAML($task);

            if ($result !== 1) {
                throw new \Exception("cronCleanSessionSAML returned unexpected status: " . $result);
            }

            // Assert that the database update query was performed
            if (empty($this->db->updatedRows)) {
                throw new \Exception("Expected update operation to transition inactive sessions, but none occurred.");
            }

            $updateCall = $this->db->updatedRows[0];
            if ($updateCall['table'] !== Loginstate::getTable()) {
                throw new \Exception("Unexpected updated table: " . $updateCall['table']);
            }

            // Verify that constants are used in the query rather than hardcoded wrong field names
            $data = $updateCall['data'];
            $where = $updateCall['where'];

            if (!isset($data[Loginstate::PHASE]) || $data[Loginstate::PHASE] !== Loginstate::PHASE_TIMED_OUT) {
                throw new \Exception("Unexpected update payload: " . var_export($data, true));
            }

            if (!isset($where[Loginstate::IDP_ID]) || $where[Loginstate::IDP_ID] !== 2) {
                throw new \Exception("Unexpected IDP_ID in where clause: " . var_export($where, true));
            }

            if (!isset($where[Loginstate::PHASE]) || $where[Loginstate::PHASE] !== Loginstate::PHASE_GLPI_AUTH) {
                throw new \Exception("Unexpected PHASE in where clause: " . var_export($where, true));
            }

            if (!isset($where[Loginstate::LAST_ACTIVITY])) {
                throw new \Exception("Missing LAST_ACTIVITY in where clause: " . var_export($where, true));
            }

            echo "✅ CronTask: inactivity timeout update using LoginState constants verified\n";
        }

        /**
         * Test that CronTask::install correctly cleans up duplicates and legacy itemtype records.
         */
        public function testCronTaskInstallationDeduplication(): void {
            $cronTable = \CronTask::getTable();
            
            // Mock a database response with 1 legacy task and 2 actual task entries (duplicates)
            $this->db->setResponse($cronTable, [
                ['id' => 10, 'name' => 'cleanSessionSAML', 'itemtype' => 'GlpiPlugin\\Glpisaml\\CronTask'],
                ['id' => 11, 'name' => 'cleanSessionSAML', 'itemtype' => 'GlpiPlugin\\Samlsso\\CronTask'],
                ['id' => 12, 'name' => 'cleanSessionSAML', 'itemtype' => 'GlpiPlugin\\Samlsso\\CronTask']
            ]);

            $this->db->deletedRows = [];
            $this->db->updatedRows = [];

            $migration = new class extends \Migration {
                public function __construct() {}
            };

            // Run install
            CronTask::install($migration);

            // We expect ID 11 to be kept, ID 10 and 12 to be deleted
            $deletedIds = [];
            foreach ($this->db->deletedRows as $deleted) {
                if ($deleted['table'] === $cronTable) {
                    $deletedIds[] = $deleted['where']['id'];
                }
            }

            if (!in_array(10, $deletedIds, true) || !in_array(12, $deletedIds, true)) {
                throw new \Exception("Expected duplicate/legacy CronTasks ID 10 and 12 to be deleted. Deleted: " . var_export($deletedIds, true));
            }

            if (in_array(11, $deletedIds, true)) {
                throw new \Exception("Actual kept CronTask ID 11 was incorrectly deleted.");
            }

            echo "✅ CronTask: duplicates and legacy records successfully cleaned up during install/upgrade\n";
        }
    }
}

namespace {
    /**
     * Executes the CronTaskTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\CronTaskTest();
    try {
        $test->testCronSessionCleanupPositive();
        $test->testCronSessionCleanupClamping();
        $test->testCronSessionCleanupZero();
        $test->testCronInfo();
        $test->testCronCleanSessionInactivityTimeout();
        $test->testCronTaskInstallationDeduplication();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
