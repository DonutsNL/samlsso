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
 * TimezoneConversionTest.php
 *
 * Validates that all displayed/logged database timestamps (which are UTC in DB)
 * are properly converted/formatted into localized timezones for presentation.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/LoginState.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\LoginState;

    /**
     * TimezoneConversionTest class.
     */
    class TimezoneConversionTest {

        /**
         * Test that LoginState::getLoggingEntries correctly formats database raw UTC timestamps.
         */
        public function testLoginStateLoggingTimezones(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();

            // Mock database response with raw database timestamps
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => 'abcdef123456',
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 1,
                    LoginState::SAML_AUTHED => 1,
                    LoginState::LOGIN_DATETIME => '2026-05-30 14:30:00',
                    LoginState::LAST_ACTIVITY => '2026-05-30 14:31:00',
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_GLPI_AUTH
                ]
            ]);

            $entries = LoginState::getLoggingEntries(2);

            if (empty($entries)) {
                throw new \Exception("getLoggingEntries failed to return mocked database rows.");
            }

            $firstEntry = reset($entries);

            // Shims.php mocks Html::convDateTime to append ' (LOCAL)'
            $expectedLoginTime = '2026-05-30 14:30:00 (LOCAL)';
            $expectedLastClick = '2026-05-30 14:31:00 (LOCAL)';

            if ($firstEntry[LoginState::LOGIN_DATETIME] !== $expectedLoginTime) {
                throw new \Exception("loginTime was not formatted. Got: " . $firstEntry[LoginState::LOGIN_DATETIME]);
            }

            if ($firstEntry[LoginState::LAST_ACTIVITY] !== $expectedLastClick) {
                throw new \Exception("lastClickTime was not formatted. Got: " . $firstEntry[LoginState::LAST_ACTIVITY]);
            }

            echo "✅ Timezones: LoginState logging entries conversion\n";
        }

        /**
         * Test that the Twig template converts date_creation and date_mod values.
         */
        public function testTwigTemplateFormatting(): void {
            $templateFile = dirname(__DIR__) . '/templates/configForm.html.twig';
            if (!file_exists($templateFile)) {
                throw new \Exception("Twig template file not found at path: $templateFile");
            }

            $templateContent = file_get_contents($templateFile);

            // Check that we format the creation/mod dates in the warning bar at the bottom
            if (!str_contains($templateContent, 'date_creation.value|formatted_datetime')) {
                throw new \Exception("Twig template does not apply formatted_datetime filter to date_creation.");
            }

            if (!str_contains($templateContent, 'date_mod.value|formatted_datetime')) {
                throw new \Exception("Twig template does not apply formatted_datetime filter to date_mod.");
            }

            echo "✅ Timezones: Twig templates format config metadata\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\TimezoneConversionTest();
    try {
        $test->testLoginStateLoggingTimezones();
        $test->testTwigTemplateFormatting();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
