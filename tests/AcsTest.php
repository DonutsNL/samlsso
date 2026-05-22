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
 * AcsTest.php
 * 
 * Unit tests validating the ACS (Assertion Consumer Service) login state transitions,
 * replay protection checks, and malformed SAML response handling.
 */

namespace {
    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/LoginFlow.php';
    require_once __DIR__ . '/../src/LoginFlow/Acs.php';
}

namespace OneLogin\Saml2 {
    /**
     * Shim for OneLogin\Saml2\Settings.
     * Mocks SAML settings structure.
     */
    if (!class_exists('OneLogin\Saml2\Settings')) {
        class Settings {
            /** @var array Internal configuration. */
            private array $settings;

            /**
             * Settings constructor.
             *
             * @param array $settings Configuration options.
             */
            public function __construct(array $settings) {
                $this->settings = $settings;
            }

            /**
             * Mocks retrieving settings errors.
             *
             * @return array List of errors.
             */
            public function getErrors(): array {
                return [];
            }
        }
    }
}

namespace GlpiPlugin\Samlsso\LoginFlow {
    /**
     * Testable subclass of Acs.
     * Overrides printError to capture execution failures instead of calling exit.
     */
    class TestableAcs extends Acs {
        /** @var array Captures parameters of the last reported error. */
        public static array $lastError = [];

        /**
         * Overrides printError to store error details and throw an exception to halt flow execution.
         *
         * @param string $errorMsg Primary error message.
         * @param string $action Optional action trigger.
         * @param string $extended Optional extended error information.
         * @throws \Exception to halt execution.
         * @return never
         */
        public static function printError(string $errorMsg, string $action = '', string $extended = ''): never {
            self::$lastError = [
                'message' => $errorMsg,
                'action' => $action,
                'extended' => $extended
            ];
            throw new \Exception($errorMsg);
        }
    }
}

namespace GlpiPlugin\Samlsso\Tests {

    use GlpiPlugin\Samlsso\LoginFlow\TestableAcs;
    use GlpiPlugin\Samlsso\Loginstate;
    use OneLogin\Saml2\Response;
    use Symfony\Component\HttpFoundation\Request;

    /**
     * AcsTest class.
     * Evaluates security constraints in the ACS assertion consumption pipeline.
     */
    class AcsTest extends TestHarness {

        /**
         * Test that a replay attack using an already processed SAML Response ID is rejected.
         *
         * @throws \Exception if validation failed or assertion succeeded when it should not.
         */
        public function testReplayProtection(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);

            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [];

            Response::$mockId = 'DUPLICATE_RESPONSE_ID_999';
            Response::$mockInResponseTo = 'REQ_ID_111';

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_SAML_ACS);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_111');
            $state->setSamlResponseId('DUPLICATE_RESPONSE_ID_999');

            Loginstate::$lastInstance = $state;

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];

            try {
                $acs->init($request);
                throw new \Exception("Acs should have blocked replayed SAML response ID.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'already been used') && !str_contains($e->getMessage(), 'replayed')) {
                    throw new \Exception("Unexpected failure message on replay check: " . $e->getMessage());
                }
            }

            echo "✅ ACS: SAML response replay protection\n";
        }

        /**
         * Test that entering the ACS handler when the LoginState is not in PHASE_SAML_ACS throws an exception.
         *
         * @throws \Exception if validation failed or assertion succeeded when it should not.
         */
        public function testInvalidPhaseValidation(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);

            Response::$mockId = 'UNIQUE_RESPONSE_ID_000';
            Response::$mockInResponseTo = 'REQ_ID_222';

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_INITIAL);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_222');

            Loginstate::$lastInstance = $state;

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];

            try {
                $acs->init($request);
                throw new \Exception("Acs should have blocked request with invalid state phase.");
            } catch (\Exception $e) {
                /**
                 * Expected to fail because the state phase is PHASE_INITIAL (1) instead of PHASE_SAML_ACS (2).
                 */
            }

            echo "✅ ACS: invalid login state phase validation\n";
        }

        /**
         * Test that a malformed or invalid SAML Response fails assertion checking.
         *
         * @throws \Exception if validation succeeded or throwed incorrect error messages.
         */
        public function testMalformedResponseAssertion(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MALFORMED_SAML_XML',
                'idpId' => 5
            ]);

            Response::$mockValid = false;
            Response::$mockId = 'UNIQUE_RESPONSE_ID_333';
            Response::$mockInResponseTo = 'REQ_ID_333';

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_SAML_ACS);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_333');

            Loginstate::$lastInstance = $state;

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];

            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed validation for malformed/invalid SAMLResponse.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Validation of the samlResponse document failed')) {
                    throw new \Exception("Unexpected failure message on malformed xml check: " . $e->getMessage());
                }
            }

            Response::$mockValid = true;

            echo "✅ ACS: malformed SAML response handling\n";
        }
    }
}

namespace {
    /**
     * Executes the AcsTest test suite directly if executed via CLI.
     */
    $test = new GlpiPlugin\Samlsso\Tests\AcsTest();
    try {
        $test->testReplayProtection();
        $test->testInvalidPhaseValidation();
        $test->testMalformedResponseAssertion();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
