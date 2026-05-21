<?php
declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/LoginFlow.php';

    use GlpiPlugin\Samlsso\LoginFlow;
    use GlpiPlugin\Samlsso\Loginstate;
    use GlpiPlugin\Samlsso\MockConfig;

    class FlowLogicTest extends TestHarness {

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

        public function testDomainSelection(): void {
            MockConfig::$mockConfig['enforced'] = false;
            MockConfig::$mockConfig['domain_map']['user@example.com'] = 3;
            $_POST['user_name'] = 'user@example.com';
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

        public function testBypassParameter(): void {
            $_GET[LoginFlow::SAMLBYPASS] = 1;
            $flow = new LoginFlow();
            $flow->doAuth();
            $state = Loginstate::$lastInstance;
            $this->assertTraceContains($state, 'msg', 'Bypassing SAML');
            echo "✅ Bypass parameter handling\n";
        }

        public function testLoginPageRendering(): void {
            unset($_POST['user_name']);
            unset($_GET[LoginFlow::SAMLBYPASS]);
            MockConfig::$mockConfig['enforced'] = false;
            MockConfig::$mockConfig['login_buttons'] = [['id' => 1, 'name' => 'Test IDP']];
            $flow = new LoginFlow();
            ob_start();
            $flow->doAuth();
            $output = ob_get_clean();
            if (!str_contains($output, 'Displayed: @samlsso/login.html.twig')) {
                throw new \Exception("Login page template not rendered.\nOutput: " . $output);
            }
            echo "✅ Login page rendering\n";
        }

        public function testAcsProcessing(): void {
            $_SERVER['REQUEST_URI'] = '/plugins/samlsso/front/acs.php';
            $flow = new LoginFlow();
            $flow->doAuth();
            $state = Loginstate::$lastInstance;
            $this->assertTraceContains($state, 'msg', 'Loading login state');
            echo "✅ ACS endpoint entry detection\n";
        }
    }
}

namespace {
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
