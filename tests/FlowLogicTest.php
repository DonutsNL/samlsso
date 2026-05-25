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
 * FlowLogicTest.php
 * 
 * Unit tests validating LoginFlow execution paths, such as enforced redirects,
 * domain-based IDP selection, bypass parameter handling, login screen template rendering,
 * and ACS entrypoint processing logic.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/LoginFlow.php';

    use GlpiPlugin\Samlsso\LoginFlow;
    use GlpiPlugin\Samlsso\Loginstate;
    use GlpiPlugin\Samlsso\MockConfig;

    /**
     * FlowLogicTest class.
     * Evaluates LoginFlow routing rules and redirects.
     */
    class FlowLogicTest extends TestHarness {

        /**
         * Test that the login flow redirects directly to the configured single IDP when SSO is enforced.
         *
         * @throws \Exception if flow executes without redirection or redirects to the wrong URL.
         */
        public function testEnforcedRedirect(): void {
            MockConfig::$mockConfig['enforced'] = true;
            MockConfig::$mockConfig['only_one_id'] = 5;
            $flow = new LoginFlow();
            try {
                $flow->doAuth();
                throw new \Exception("Flow should have exited with a redirect.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Redirect to: /plugins/samlsso/front/sso.php?idp=5')) {
                    throw new \Exception("Unexpected redirect.\nResult: " . $e->getMessage());
                }
            }
            echo "✅ Enforced IDP redirect logic\n";
        }

        /**
         * Test that the login flow redirects to the correct IDP based on the domain of the entered email.
         *
         * @throws \Exception if flow does not redirect or redirects to an incorrect domain-mapped IDP.
         */
        public function testDomainSelection(): void {
            MockConfig::$mockConfig['enforced'] = false;
            MockConfig::$mockConfig['domain_map']['user@example.com'] = 3;
            $_POST['login_name'] = 'user@example.com';
            $flow = new LoginFlow();
            try {
                $flow->doAuth();
                throw new \Exception("Flow should have redirected based on domain.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Redirect to: /plugins/samlsso/front/sso.php?idp=3')) {
                    throw new \Exception("Domain redirect failed.\nResult: " . $e->getMessage());
                }
            }
            echo "✅ Domain-based IDP selection\n";
        }

        /**
         * Test that providing the bypass query parameter successfully triggers SSO bypass.
         *
         * @throws \Exception if bypass redirection or trace logging assertions fail.
         */
        public function testBypassParameter(): void {
            $_GET[LoginFlow::SAMLBYPASS] = 1;
            $flow = new LoginFlow();
            try {
                $flow->doAuth();
                throw new \Exception("Flow should have redirected for bypass.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Redirect to: http://glpi.local/?bypass=1&noAUTO=1')) {
                    throw new \Exception("Bypass redirect failed.\nResult: " . $e->getMessage());
                }
            }
            $state = Loginstate::$lastInstance;
            $this->assertTraceContains($state, 'bypassUsed', '1');
            echo "✅ Bypass parameter handling\n";
        }

        /**
         * Test that the login screen is correctly rendered when SSO is not enforced and no bypass is set.
         *
         * @throws \Exception if the login page template name is not found in the output buffer.
         */
        public function testLoginPageRendering(): void {
            unset($_POST['login_name']);
            unset($_GET[LoginFlow::SAMLBYPASS]);
            MockConfig::$mockConfig['enforced'] = false;
            MockConfig::$mockConfig['login_buttons'] = [['id' => 1, 'name' => 'Test IDP']];
            $flow = new LoginFlow();
            ob_start();
            $flow->showLoginScreen();
            $output = ob_get_clean();
            if (!str_contains($output, 'Displayed: @samlsso/loginScreen.html.twig')) {
                throw new \Exception("Login page template not rendered.\nOutput: " . $output);
            }
            echo "✅ Login page rendering\n";
        }

        /**
         * Test that calls directly addressing the ACS endpoint do not trigger the login flow.
         *
         * @throws \Exception if a login state is initialized when entering the ACS page directly.
         */
        public function testAcsProcessing(): void {
            $_SERVER['REQUEST_URI'] = '/plugins/samlsso/front/acs.php';
            Loginstate::$lastInstance = null;
            $flow = new LoginFlow();
            $flow->doAuth();
            if (Loginstate::$lastInstance !== null) {
                throw new \Exception("ACS endpoint entry should bypass initial auth flow.");
            }
            echo "✅ ACS endpoint entry detection\n";
        }

        /**
         * Test that the SLO logout flow correctly redirects to the IDP SLO URL when triggered.
         *
         * @throws \Exception if the flow does not redirect or redirects to an incorrect SLO URL.
         */
        public function testSloLogoutRedirect(): void {
            $_SERVER['REQUEST_URI'] = '/';
            $_GET[LoginFlow::SLOLOGOUT] = '1';
            $state = new Loginstate();
            $state->idpId = 5;
            $state->samlAuthed = true;
            $flow = new LoginFlow();
            try {
                $flow->doAuth();
                throw new \Exception("Flow should have redirected to the IDP SLO URL.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Redirect to: /plugins/samlsso/front/slo.php')) {
                    throw new \Exception("SLO redirect failed.\nResult: " . $e->getMessage());
                }
            } finally {
                unset($_GET[LoginFlow::SLOLOGOUT]);
            }
            echo "✅ SLO logout redirect logic\n";
        }

        /**
         * Test that the SLO logout flow redirects to the home page if the config or IDP ID is invalid.
         *
         * @throws \Exception if the fallback redirect does not go to the GLPI homepage.
         */
        public function testSloLogoutFallback(): void {
            $_SERVER['REQUEST_URI'] = '/';
            $_GET[LoginFlow::SLOLOGOUT] = '1';
            $state = new Loginstate();
            $state->idpId = 0;
            $flow = new LoginFlow();
            try {
                $flow->doAuth();
                throw new \Exception("Flow should have redirected to the homepage on invalid IDP ID.");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Redirect to: http://glpi.local/')) {
                    throw new \Exception("SLO fallback redirect failed.\nResult: " . $e->getMessage());
                }
            } finally {
                unset($_GET[LoginFlow::SLOLOGOUT]);
            }
            echo "✅ SLO logout fallback logic\n";
        }

        /**
         * Test that local logout triggers the transition of the database state phase to PHASE_LOGOFF
         * and then lets the request pass through to GLPI's native logout code.
         *
         * @throws \Exception if the phase transition fails or if the flow throws an unexpected exception.
         */
        public function testLocalLogoutPassThrough(): void {
            $_SERVER['REQUEST_URI'] = '/';
            $_GET[LoginFlow::LOCALLOGOUT] = '1';
            $state = new Loginstate();
            $state->idpId = 5;
            $state->samlAuthed = true;
            $state->phase = Loginstate::PHASE_GLPI_AUTH;
            $flow = new LoginFlow();
            
            // Should complete normally without throwing redirect exceptions
            $flow->doAuth();
            
            $lastState = Loginstate::$lastInstance;
            if ($lastState->phase !== Loginstate::PHASE_LOGOFF) {
                throw new \Exception("Database session phase should have been updated to PHASE_LOGOFF. Got: " . $lastState->phase);
            }
            
            unset($_GET[LoginFlow::LOCALLOGOUT]);
            echo "✅ Local logout pass-through and DB state transition logic\n";
        }

        /**
         * Test that the logout page renders the normal local logout options when SSO is not enforced.
         *
         * @throws \Exception if the output buffer does not contain the expected twig template output or messages.
         */
        public function testLogoutPageRenderingNormal(): void {
            $tempFile = __DIR__ . '/logout_test_normal.php';
            $code = '<?php
                global $GLPI_IS_COMMAND_LINE, $CFG_GLPI;
                $GLPI_IS_COMMAND_LINE = false;
                require_once "' . __DIR__ . '/TestHarness.php";
                $CFG_GLPI = ["url_base" => "http://glpi.local"];
                require_once "' . __DIR__ . '/../src/LoginFlow.php";
                $_SESSION["glpiID"] = 1;
                $_SERVER["REQUEST_URI"] = "/front/logout.php";
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::IDP_SLO_URL] = "http://idp.local/slo";
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::ENFORCE_SSO] = false;
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::NAME] = "Normal IDP";
                
                $state = new \GlpiPlugin\Samlsso\Loginstate();
                $state->idpId = 5;
                $state->samlAuthed = true;
                
                $flow = new \GlpiPlugin\Samlsso\LoginFlow();
                $flow->doAuth();
            ';
            file_put_contents($tempFile, $code);
            
            $output = [];
            $status = 0;
            exec("php " . escapeshellarg($tempFile) . " 2>&1", $output, $status);
            $outputStr = implode("\n", $output);
            unlink($tempFile);
            
            if ($status !== 0) {
                throw new \Exception("Subprocess failed with status: $status. Output: $outputStr");
            }
            
            if (!str_contains($outputStr, 'Continue with local logout')) {
                throw new \Exception("Normal logout page should contain 'Continue with local logout'. Got: " . $outputStr);
            }
            if (str_contains($outputStr, 'Go back to GLPI')) {
                throw new \Exception("Normal logout page should NOT contain 'Go back to GLPI'. Got: " . $outputStr);
            }
            echo "✅ Normal logout screen rendering logic\n";
        }

        /**
         * Test that the logout page renders the enforced logout options when SSO is enforced.
         *
         * @throws \Exception if the output buffer does not contain the expected close and return buttons.
         */
        public function testLogoutPageRenderingEnforced(): void {
            $tempFile = __DIR__ . '/logout_test_enforced.php';
            $code = '<?php
                global $GLPI_IS_COMMAND_LINE, $CFG_GLPI;
                $GLPI_IS_COMMAND_LINE = false;
                require_once "' . __DIR__ . '/TestHarness.php";
                $CFG_GLPI = ["url_base" => "http://glpi.local"];
                require_once "' . __DIR__ . '/../src/LoginFlow.php";
                $_SESSION["glpiID"] = 1;
                $_SERVER["REQUEST_URI"] = "/front/logout.php";
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::IDP_SLO_URL] = "http://idp.local/slo";
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::ENFORCE_SSO] = true;
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::NAME] = "Enforced IDP";
                
                $state = new \GlpiPlugin\Samlsso\Loginstate();
                $state->idpId = 5;
                $state->samlAuthed = true;
                
                $flow = new \GlpiPlugin\Samlsso\LoginFlow();
                $flow->doAuth();
            ';
            file_put_contents($tempFile, $code);
            
            $output = [];
            $status = 0;
            exec("php " . escapeshellarg($tempFile) . " 2>&1", $output, $status);
            $outputStr = implode("\n", $output);
            unlink($tempFile);
            
            if ($status !== 0) {
                throw new \Exception("Subprocess failed with status: $status. Output: $outputStr");
            }
            
            if (!str_contains($outputStr, 'Go back to GLPI')) {
                throw new \Exception("Enforced logout page should contain 'Go back to GLPI'. Got: " . $outputStr);
            }
            if (!str_contains($outputStr, 'Close tab')) {
                throw new \Exception("Enforced logout page should contain 'Close tab'. Got: " . $outputStr);
            }
            if (str_contains($outputStr, 'Continue with local logout')) {
                throw new \Exception("Enforced logout page should NOT contain 'Continue with local logout'. Got: " . $outputStr);
            }
            echo "✅ Enforced logout screen rendering logic\n";
        }
    }
}

namespace {
    /**
     * Executes the FlowLogicTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\FlowLogicTest();
    try {
        $test->testEnforcedRedirect();
        $test->testDomainSelection();
        $test->testBypassParameter();
        $test->testLoginPageRendering();
        $test->testAcsProcessing();
        $test->testSloLogoutRedirect();
        $test->testSloLogoutFallback();
        $test->testLocalLogoutPassThrough();
        $test->testLogoutPageRenderingNormal();
        $test->testLogoutPageRenderingEnforced();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
