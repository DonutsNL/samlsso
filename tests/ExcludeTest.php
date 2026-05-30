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
 * ExcludeTest.php
 * 
 * Unit tests validating Request and Agent exclusion rules from SSO authentication requirements.
 */

namespace GlpiPlugin\Samlsso\Tests {

    define('LOAD_REAL_EXCLUDE', true);

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/Exclude.php';

    use GlpiPlugin\Samlsso\Exclude;

    /**
     * ExcludeTest class.
     * Evaluates path, agent, and database sso bypass checks.
     */
    class ExcludeTest extends TestHarness {

        /**
         * Test that executing from the command line skips authentication checks.
         *
         * @throws \Exception if CLI exclusion logic returns invalid values.
         */
        public function testCliExclusion(): void {
            /**
             * Under CLI, Exclude::isExcluded() should skip auth and return a string containing the executed command
             */
            $result = Exclude::isExcluded();
            if (!is_string($result) || !str_contains($result, 'Saml auth skipped')) {
                throw new \Exception("CLI exclusion failed. Expected skip string, got: " . var_export($result, true));
            }
            echo "✅ Exclude: CLI bypass detection\n";
        }

        /**
         * Test that user agents matching GLPI-Agent patterns bypass SSO authentication.
         *
         * @throws \Exception if agent string matching does not bypass authentication.
         */
        public function testGlpiAgentBypass(): void {
            /**
             * Setup request mimicking a GLPI-Agent inventory post
             */
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_USER_AGENT'] = 'GLPI-Agent_v1.5';
            $_SERVER['REQUEST_URI'] = '/';

            $result = Exclude::ProcessExcludes();
            if ($result !== true) {
                throw new \Exception("GLPI Agent bypass failed. Expected true, got: " . var_export($result, true));
            }
            echo "✅ Exclude: GLPI Agent user-agent bypass\n";
        }

        /**
         * Test database-driven exclusion rules matching paths and user-agents.
         *
         * @throws \Exception if database path bypass validation evaluates incorrectly.
         */
        public function testDatabaseExcludes(): void {
            /**
             * Restore normal headers and clear agent mock
             */
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
            $_SERVER['REQUEST_URI'] = '/plugins/samlsso/api.php';

            /**
             * Configure MockDB excludes response
             */
            $table = Exclude::getTable();
            $this->db->setResponse($table, [
                [
                    Exclude::NAME => 'Bypass API',
                    Exclude::ACTION => 1,
                    Exclude::DATE_CREATION => '2026-05-22 00:00:00',
                    Exclude::DATE_MOD => '2026-05-22 00:00:00',
                    Exclude::CLIENTAGENT => '',
                    Exclude::EXCLUDEPATH => '/api.php'
                ],
                [
                    Exclude::NAME => 'Enforce Auth on Admin',
                    Exclude::ACTION => 0,
                    Exclude::DATE_CREATION => '2026-05-22 00:00:00',
                    Exclude::DATE_MOD => '2026-05-22 00:00:00',
                    Exclude::CLIENTAGENT => 'AdminAgent',
                    Exclude::EXCLUDEPATH => '/admin/'
                ]
            ]);

            /**
             * 1. Validate ProcessExcludes match.
             */
            if (Exclude::ProcessExcludes() !== true) {
                throw new \Exception("Database exclude path matching failed for /api.php");
            }

            /**
             * 2. Validate GetExcludeAction checks.
             */
            if (Exclude::GetExcludeAction('/plugins/samlsso/api.php') !== true) {
                throw new \Exception("GetExcludeAction failed for /api.php");
            }

            /**
             * 3. Client agent check where Agent doesn't match.
             */
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
            if (Exclude::GetExcludeAction('/admin/', $_SERVER['HTTP_USER_AGENT']) !== false) {
                throw new \Exception("GetExcludeAction should have failed for /admin/ with unmatched User-Agent");
            }

            /**
             * 4. Client agent check where Agent matches and action is 0 (enforced auth).
             */
            $_SERVER['HTTP_USER_AGENT'] = 'AdminAgent';
            if (Exclude::GetExcludeAction('/admin/', $_SERVER['HTTP_USER_AGENT']) !== false) {
                throw new \Exception("GetExcludeAction should return false (auth enforced) when action is 0 even if Agent matches");
            }

            /**
             * 5. Unmatched path checks.
             */
            if (Exclude::GetExcludeAction('/front/home.php') !== false) {
                throw new \Exception("GetExcludeAction should return false for unmatched path /front/home.php");
            }

            echo "✅ Exclude: Database configured exclusion rules\n";
        }
    }
}

namespace {
    /**
     * Executes the ExcludeTest test suite.
     * Under testing, PHP_SAPI is cli, so Exclude::isExcluded() skips normal execution.
     * To test HTTP behaviors inside cli environment, we selectively mock server globals.
     */
    $test = new GlpiPlugin\Samlsso\Tests\ExcludeTest();
    try {
        $test->testCliExclusion();
        $test->testGlpiAgentBypass();
        $test->testDatabaseExcludes();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
