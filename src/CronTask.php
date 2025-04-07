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
 *  @version    1.2.0
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
     * Give cron information
     * @param $name : automatic action's name
     * @return array of information
     */
    public static function cronInfo(string $name): array
    {
        if ($name == 'cleanSessionSAML') {
                return ['description' => __("Clean old SAML sessions", PLUGIN_NAME),
                        'parameter'   => __("SAML sessions retention period (in days, 0 for infinite)", PLUGIN_NAME)];
        }else{
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

        $days = $task->fields['param'];
        $cron_status = 0;
        $volume = 0;

        if ($days > 0) {
            $result = $DB->delete(
                LoginState::getTable(),
                [LoginState::LAST_ACTIVITY => ['<', new QueryExpression('NOW() - INTERVAL ' . $days . ' DAY')]]
            );

            if ($result) {
                //$vol = $DB->affectedRows(); unsure this is required..
                $cron_status = 1;
            }
        }

        $task->setVolume($volume);

        return $cron_status;
   }

    /**
     * Register GlpiSAML plugin CronTasks
     * @param   Migration $migration    - Plugin migration information;
     * @return  void
     * @see                             - GLPISaml/hook.php
     */
    public static function install(Migration $migration): void
    {
        $cron = new glpiCronTask();
        $class = get_called_class();
        $task = "cleanSessionSAML";

        if (!$cron->getFromDBbyName($class, $task)) {
            glpiCronTask::Register($class, $task, DAY_TIMESTAMP, [
                'state' => glpiCronTask::STATE_WAITING,
                'mode'  => glpiCronTask::MODE_EXTERNAL,
                'param' => 30,
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
