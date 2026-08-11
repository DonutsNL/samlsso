<?php
declare(strict_types=1);
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
 *  @author     Denis Ollier
 *  @copyright  Copyright (c) 2025 by Denis Ollier
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.2.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso;

use CommonDBTM;
// https://glpi-developer-documentation.readthedocs.io/en/master/devapi/crontasks.html
use CronTask as glpiCronTask;
use Migration;
use QueryExpression;
use GlpiPlugin\Samlsso\LoginState;

class CronTask extends CommonDBTM
{
    /**
    /**
     * Give cron information
     * @param $name : automatic action's name
     * @return array of information
     */
    public static function cronInfo(string $name): array
    {
        if ($name == 'cleanSessionSAML') {
                return ['description' => __("Clean old SAML sessions", PLUGIN_NAME),
                        'parameter'   => __("SAML sessions retention period (in days, 0 for infinite)", PLUGIN_NAME)];
        } else if ($name == 'updateGeoIP') {
                return ['description' => __("Update GeoIP offline database", PLUGIN_NAME)];
        } else {
            return [];
        }
    }

    /**
     * Cron action to cleanup sessions older than $task->param (30 days by default)
     * @param CronTask $task for log
     * @return integer 0 : nothing to do, 1 : done with success
     */
    public static function cronCleanSessionSAML(glpiCronTask $task): int
    {
        global $DB;

        $days = (float)$task->fields['param'];
        $cron_status = 0;
        $volume = 0;

        // 1. Process inactivity timeout for active sessions
        try {
            $configsTable = Config::getTable();
            $configs = $DB->request([
                'FROM'  => $configsTable,
                'WHERE' => [
                    'inactivity_timeout' => ['>', 0],
                    'is_deleted' => 0
                ]
            ]);

            foreach ($configs as $cfg) {
                $idpId = (int)$cfg['id'];
                $timeoutMinutes = (int)$cfg['inactivity_timeout'];

                $DB->update(
                    LoginState::getTable(),
                    [
                        LoginState::PHASE => LoginState::PHASE_TIMED_OUT
                    ],
                    [
                        LoginState::IDP_ID => $idpId,
                        LoginState::PHASE  => LoginState::PHASE_GLPI_AUTH,
                        LoginState::LAST_ACTIVITY => ['<', new QueryExpression('NOW() - INTERVAL ' . $timeoutMinutes . ' MINUTE')]
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Keep going to clean up old sessions
        }

        // 2. Cleanup sessions older than retention period (days)
        if ($days > 0) {
            $delete_where = [];
            if ($days < 1) {
                // Silently default to 1 working day (8 hours)
                $delete_where[LoginState::LAST_ACTIVITY] = ['<', new QueryExpression('NOW() - INTERVAL 8 HOUR')];
            } else {
                $delete_where[LoginState::LAST_ACTIVITY] = ['<', new QueryExpression('NOW() - INTERVAL ' . (int)$days . ' DAY')];
            }

            // Retrieve count of rows before deletion
            try {
                $countIterator = $DB->request([
                    'SELECT' => 'COUNT(*) AS cnt',
                    'FROM'   => LoginState::getTable(),
                    'WHERE'  => $delete_where
                ]);
                foreach ($countIterator as $row) {
                    $volume = (int)$row['cnt'];
                }
            } catch (\Throwable $e) {
                $volume = 0;
            }

            if ($volume > 0) {
                $result = $DB->delete(LoginState::getTable(), $delete_where);
                if ($result) {
                    $cron_status = 1;
                } else {
                    $volume = 0;
                }
            }
        }

        /** @var \CronTask $task */
        $task->setVolume($volume);

        // File logging
        \Toolbox::logInFile(
            PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
            sprintf("SAML session cleanup: Purged %d rows.\n", $volume)
        );

        // If manual run, display toast/popup
        if (!isCommandLine()) {
            \Session::addMessageAfterRedirect(
                sprintf(__("SAML session cleanup completed. Purged %d rows.", PLUGIN_NAME), $volume)
            );
        }

        return $cron_status;
    }

    /**
     * Cron action to update the GeoIP database.
     * @param glpiCronTask $task for log
     * @return integer 0 : nothing to do, 1 : done with success
     */
    public static function cronUpdateGeoIP(glpiCronTask $task): int
    {
        $success = \GlpiPlugin\Samlsso\Utility\GeoIPUpdater::run($task);
        return $success ? 1 : 0;
    }

    /**
     * Register GlpiSAML plugin CronTasks
     * @param   Migration $migration    - Plugin migration information;
     * @return  void
     * @see                             - GLPISaml/hook.php
     */
    public static function install(Migration $migration): void
    {
        global $DB;
        $cronTable = glpiCronTask::getTable();
        $class = get_called_class();
        $legacyClass = 'GlpiPlugin\\Glpisaml\\CronTask';

        $tasksToDeduplicate = ["cleanSessionSAML", "updateGeoIP"];
        foreach ($tasksToDeduplicate as $taskName) {
            $iterator = $DB->request([
                'FROM'  => $cronTable,
                'WHERE' => [
                    'name'     => $taskName,
                    'itemtype' => [$class, $legacyClass]
                ],
                'ORDER' => 'id ASC'
            ]);

            $existing = [];
            foreach ($iterator as $row) {
                $existing[] = $row;
            }

            if (count($existing) > 0) {
                $keptId = null;
                // Prefer keeping the one matching actual class name
                foreach ($existing as $row) {
                    if ($row['itemtype'] === $class) {
                        $keptId = (int)$row['id'];
                        break;
                    }
                }

                // If none matches the actual class, migrate the first legacy one
                if ($keptId === null && !empty($existing)) {
                    $first = $existing[0];
                    $keptId = (int)$first['id'];
                    $DB->update($cronTable, ['itemtype' => $class], ['id' => $keptId]);
                }

                // Delete duplicates
                foreach ($existing as $row) {
                    $rowId = (int)$row['id'];
                    if ($rowId !== $keptId) {
                        $DB->delete($cronTable, ['id' => $rowId]);
                    }
                }
            }
        }

        $cron = new glpiCronTask();
        $task = "cleanSessionSAML";

        if (!$cron->getFromDBbyName($class, $task)) {
            glpiCronTask::register($class, $task, DAY_TIMESTAMP, [
                'state' => glpiCronTask::STATE_WAITING,
                'mode'  => glpiCronTask::MODE_EXTERNAL,
                'param' => 30,
            ]);
        }

        $task2 = "updateGeoIP";
        if (!$cron->getFromDBbyName($class, $task2)) {
            glpiCronTask::register($class, $task2, 30 * DAY_TIMESTAMP, [
                'state' => glpiCronTask::STATE_WAITING,
                'mode'  => glpiCronTask::MODE_EXTERNAL,
                'param' => 0,
            ]);
        }
    }

    /**
     * Unregister GlpiSAML plugin CronTasks
     * @param   Migration $migration    - Plugin migration information;
     * @return  void
     * @see                             - GLPISaml/hook.php
     */
    public static function uninstall(Migration $migration): void
    {
        glpiCronTask::unregister(get_called_class());
    }
}
