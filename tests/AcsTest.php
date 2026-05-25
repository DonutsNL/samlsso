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
            /** @var ?\Throwable Static throwable mock trigger. */
            public static ?\Throwable $mockThrow = null;

            /**
             * Settings constructor.
             *
             * @param array $settings Configuration options.
             */
            public function __construct(array $settings) {
                if (self::$mockThrow !== null) {
                    throw self::$mockThrow;
                }
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

        /**
         * Test Exception Path 1: Missing Response or Invalid IDP ID.
         */
        public function testMissingResponseOrIdpId(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => '',
                'idpId' => 5
            ]);
            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed due to missing SAMLResponse.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'did not contain the required samlResponse')) {
                    throw new \Exception("Unexpected failure message on missing response check: " . $e->getMessage());
                }
            }
            echo "✅ ACS: missing response or idpId exception handling\n";
        }

        /**
         * Test Exception Path 2: ConfigEntity throws an exception during initialization.
         */
        public function testConfigEntityThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockThrow = new \Exception("Database error config");
            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because ConfigEntity threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Unable to fetch idp configuration')) {
                    throw new \Exception("Unexpected failure message on ConfigEntity error: " . $e->getMessage());
                }
            } finally {
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockThrow = null;
            }
            echo "✅ ACS: ConfigEntity exception handling\n";
        }

        /**
         * Test Exception Path 3: setProxyVars throws an exception during proxy setup.
         */
        public function testProxyVarsThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::PROXIED] = true;
            \OneLogin\Saml2\Utils::$mockThrow = new \Exception("Proxy failure exception");
            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because setProxyVars threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Proxy failure exception')) {
                    throw new \Exception("Unexpected failure message on proxy error: " . $e->getMessage());
                }
            } finally {
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [];
                \OneLogin\Saml2\Utils::$mockThrow = null;
            }
            echo "✅ ACS: proxy variables exception handling\n";
        }

        /**
         * Test Exception Path 4: Settings initialization throws.
         */
        public function testSettingsInitializationThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Settings::$mockThrow = new \Exception("Settings init error");
            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because Settings initialization threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'PHP-SAML could not initialize')) {
                    throw new \Exception("Unexpected failure message on Settings error: " . $e->getMessage());
                }
            } finally {
                \OneLogin\Saml2\Settings::$mockThrow = null;
            }
            echo "✅ ACS: Settings initialization exception handling\n";
        }

        /**
         * Test Exception Path 5: Response parsing/initialization throws.
         */
        public function testResponseProcessingThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockThrow = new \Exception("Response parsing error");
            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because Response processing threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'PHP-SAML library could not process samlResponse')) {
                    throw new \Exception("Unexpected failure message on Response error: " . $e->getMessage());
                }
            } finally {
                \OneLogin\Saml2\Response::$mockThrow = null;
            }
            echo "✅ ACS: Response processing exception handling\n";
        }

        /**
         * Test Exception Path 6: LoginState retrieval throws.
         */
        public function testLoginStateLookupThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockInResponseTo = 'REQ_ID_MOCK';
            \GlpiPlugin\Samlsso\Loginstate::$mockConstructorThrow = new \Exception("DB failure state construct");
            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because Loginstate lookup threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Could not fetch loginState from database')) {
                    throw new \Exception("Unexpected failure message on Loginstate error: " . $e->getMessage());
                }
            } finally {
                \GlpiPlugin\Samlsso\Loginstate::$mockConstructorThrow = null;
            }
            echo "✅ ACS: LoginState lookup exception handling\n";
        }

        /**
         * Test Exception Path 8: Critical error thrown by isValid() during validation.
         */
        public function testValidationCriticalError(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockId = 'UNIQUE_RESPONSE_ID_444';
            \OneLogin\Saml2\Response::$mockInResponseTo = 'REQ_ID_444';
            \OneLogin\Saml2\Response::$mockIsValidThrow = new \Exception("Critical XML signature fail");

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_SAML_ACS);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_444');

            \GlpiPlugin\Samlsso\Loginstate::$lastInstance = $state;

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed validation due to critical error throwing in isValid.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Validation of the samlResponse document failed with a critical error')) {
                    throw new \Exception("Unexpected failure message on validation critical error: " . $e->getMessage());
                }
            } finally {
                \OneLogin\Saml2\Response::$mockIsValidThrow = null;
            }
            echo "✅ ACS: XML validation critical error exception handling\n";
        }

        /**
         * Test Exception Path 10: setSamlResponseId throws an exception.
         */
        public function testSetSamlResponseIdThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockId = 'UNIQUE_RESPONSE_ID_555';
            \OneLogin\Saml2\Response::$mockInResponseTo = 'REQ_ID_555';

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_SAML_ACS);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_555');

            \GlpiPlugin\Samlsso\Loginstate::$lastInstance = $state;
            \GlpiPlugin\Samlsso\Loginstate::$mockSetSamlResponseIdThrow = new \Exception("Database write crash");

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because setSamlResponseId threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'update the samlResponseId into the LoginState database')) {
                    throw new \Exception("Unexpected failure message on setSamlResponseId error: " . $e->getMessage());
                }
            } finally {
                \GlpiPlugin\Samlsso\Loginstate::$mockSetSamlResponseIdThrow = null;
            }
            echo "✅ ACS: setSamlResponseId exception handling\n";
        }

        public function testPhaseMismatchAfterRegistration(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockId = 'UNIQUE_RESPONSE_ID_666';
            \OneLogin\Saml2\Response::$mockInResponseTo = 'REQ_ID_666';

            $state = new Loginstate();
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_666');

            \GlpiPlugin\Samlsso\Loginstate::$lastInstance = $state;
            \GlpiPlugin\Samlsso\Loginstate::$mockPhases = [
                Loginstate::PHASE_SAML_ACS,
                Loginstate::PHASE_INITIAL
            ];

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because phase was altered after registration.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'GLPI did not expect an assertion from this Idp')) {
                    throw new \Exception("Unexpected failure message on phase mismatched after registration: " . $e->getMessage());
                }
            } finally {
                \GlpiPlugin\Samlsso\Loginstate::$mockPhases = null;
            }
            echo "✅ ACS: phase mismatch after registration exception handling\n";
        }

        /**
         * Test Exception Path 12: setPhase (PHASE_SAML_AUTH) throws.
         */
        public function testSetPhaseThrows(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockId = 'UNIQUE_RESPONSE_ID_777';
            \OneLogin\Saml2\Response::$mockInResponseTo = 'REQ_ID_777';

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_SAML_ACS);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_777');

            \GlpiPlugin\Samlsso\Loginstate::$lastInstance = $state;
            \GlpiPlugin\Samlsso\Loginstate::$mockSetPhaseThrow = new \Exception("Failed setting state phase");

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have failed because setPhase threw exception.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'update the login phase to LoginState::PHASE_SAML_AUTH')) {
                    throw new \Exception("Unexpected failure message on setPhase error: " . $e->getMessage());
                }
            } finally {
                \GlpiPlugin\Samlsso\Loginstate::$mockSetPhaseThrow = null;
            }
            echo "✅ ACS: setPhase (PHASE_SAML_AUTH) exception handling\n";
        }

        /**
         * Test Happy Path: Successful ACS login triggers browser redirection.
         */
        public function testSuccessfulAcsLogin(): void {
            $request = Request::create('/plugins/samlsso/front/acs.php', 'POST', [
                'SAMLResponse' => 'MOCK_SAML_RESPONSE_ASSERTION',
                'idpId' => 5
            ]);
            \OneLogin\Saml2\Response::$mockId = 'UNIQUE_RESPONSE_ID_888';
            \OneLogin\Saml2\Response::$mockInResponseTo = 'REQ_ID_888';

            $state = new Loginstate();
            $state->setPhase(Loginstate::PHASE_SAML_ACS);
            $state->setIdpId(5);
            $state->setRequestId('REQ_ID_888');

            \GlpiPlugin\Samlsso\Loginstate::$lastInstance = $state;

            $acs = new TestableAcs();
            TestableAcs::$lastError = [];
            try {
                $acs->init($request);
                throw new \Exception("Acs should have redirected the user upon successful login.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Redirect to:')) {
                    throw new \Exception("Successful login did not initiate redirect. Instead failed with: " . $e->getMessage());
                }
            }
            echo "✅ ACS: Successful auth data path with redirection\n";
        }
    }
}

namespace {
    /**
     * Executes the AcsTest test suite directly if executed via CLI.
     */
    $test = new GlpiPlugin\Samlsso\Tests\AcsTest();
    try {
        $runTest = function(string $name) use ($test) {
            \GlpiPlugin\Samlsso\Loginstate::$lastInstance = null;
            \GlpiPlugin\Samlsso\Loginstate::$mockConstructorThrow = null;
            \GlpiPlugin\Samlsso\Loginstate::$mockSetSamlResponseIdThrow = null;
            \GlpiPlugin\Samlsso\Loginstate::$mockSetPhaseThrow = null;
            \GlpiPlugin\Samlsso\Loginstate::$mockPhases = null;
            \OneLogin\Saml2\Response::$mockThrow = null;
            \OneLogin\Saml2\Response::$mockIsValidThrow = null;
            \OneLogin\Saml2\Response::$mockValid = true;
            \OneLogin\Saml2\Settings::$mockThrow = null;
            \OneLogin\Saml2\Utils::$mockThrow = null;
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockThrow = null;
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [];

            $test->$name();
        };

        $runTest('testReplayProtection');
        $runTest('testInvalidPhaseValidation');
        $runTest('testMalformedResponseAssertion');
        $runTest('testMissingResponseOrIdpId');
        $runTest('testConfigEntityThrows');
        $runTest('testProxyVarsThrows');
        $runTest('testSettingsInitializationThrows');
        $runTest('testResponseProcessingThrows');
        $runTest('testLoginStateLookupThrows');
        $runTest('testValidationCriticalError');
        $runTest('testSetSamlResponseIdThrows');
        $runTest('testPhaseMismatchAfterRegistration');
        $runTest('testSetPhaseThrows');
        $runTest('testSuccessfulAcsLogin');
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
