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
 *  @version    1.2.7
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

            $result = CronTask::cronCleanSessionSAML($task);

            if ($result !== 1) {
                throw new \Exception("cronCleanSessionSAML returned unexpected status: " . var_export($result, true));
            }

            if ($task->volume !== 0) {
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
    }
}

namespace {
    /**
     * Executes the CronTaskTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\CronTaskTest();
    try {
        $test->testCronSessionCleanupPositive();
        $test->testCronSessionCleanupZero();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
