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
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
