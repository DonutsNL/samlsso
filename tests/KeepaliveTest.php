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
 *  @version    1.3.2
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
 * KeepaliveTest.php
 *
 * Validates that the keepalive ping keeps an active session alive under the
 * inactivity timeout, while idle sessions still time out.
 */

namespace {
    // GLPI core message level used by the timeout branch of LoginFlow::doAuth().
    if (!defined('ERROR')) {
        define('ERROR', 1);
    }
}

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/LoginState.php';
    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/LoginFlow.php';

    use GlpiPlugin\Samlsso\Config;
    use GlpiPlugin\Samlsso\LoginFlow;
    use GlpiPlugin\Samlsso\LoginState;
    use GlpiPlugin\Samlsso\Config\MockConfigEntity;

    /**
     * MockDB that remembers the last request parameters.
     */
    class ParamsMockDB extends MockDB
    {
        public array $lastParams = [];

        /**
         * Intercept request and remember its parameters.
         *
         * @param array $params Request parameters.
         * @return object Iterator structure representing query results.
         */
        public function request(array $params): object
        {
            $this->lastParams = $params;
            return parent::request($params);
        }
    }

    /**
     * KeepaliveTest class.
     */
    class KeepaliveTest {

        /**
         * Returns a GLPI_AUTH state row with the given last activity.
         */
        private function stateRow(string $lastActivity): array {
            return [
                LoginState::STATE_ID => 1, LoginState::USER_ID => 42, LoginState::IDP_ID => 1,
                LoginState::USER_NAME => 'test_user', LoginState::SESSION_ID => session_id(),
                LoginState::SESSION_NAME => 'sid', LoginState::GLPI_AUTHED => 1, LoginState::SAML_AUTHED => 1,
                LoginState::LOGIN_DATETIME => $lastActivity, LoginState::LAST_ACTIVITY => $lastActivity,
                LoginState::LOCATION => '/front/ticket.form.php', LoginState::ENFORCE_LOGOFF => 0,
                LoginState::SAML_REQUEST_ID => '', LoginState::SAML_RESPONSE_ID => '',
                LoginState::SAML_UNSOLICITED => 0, LoginState::LOGIN_FLOW_TRACE => serialize([]),
                LoginState::PHASE => LoginState::PHASE_GLPI_AUTH, LoginState::REDIRECT => '',
                LoginState::CLIENT_IP => '127.0.0.1', LoginState::CLIENT_COUNTRY => 'NL',
            ];
        }

        /**
         * Performs an authenticated request through LoginFlow::doAuth().
         *
         * @return string 'continue' or the redirect target if the user was logged out.
         */
        private function request(string $method, string $uri, string $lastActivity): string {
            global $DB, $CFG_GLPI;
            $CFG_GLPI['url_base'] = 'https://glpi.local';
            $DB = new MockDB();
            $DB->setResponse(LoginState::getTable(), [$this->stateRow($lastActivity)]);

            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REQUEST_URI'] = $uri;
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
            \Session::$userId = 42;
            $_SESSION['glpiID'] = 42;
            $_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR] = 'test_user';
            $_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR] = session_id();

            try {
                (new LoginFlow())->doAuth();
                return 'continue';
            } catch (\Exception $e) {
                if (str_starts_with($e->getMessage(), 'Redirect to: ')) {
                    return substr($e->getMessage(), strlen('Redirect to: '));
                }
                throw $e;
            }
        }

        /**
         * Returns the last activity held by the state of the most recent doAuth() call.
         */
        private function lastActivityAfterRequest(): string {
            $refObj = new \ReflectionObject(LoginFlow::$activeState);
            $refProp = $refObj->getProperty('state');
            $refProp->setAccessible(true);
            return (string)$refProp->getValue(LoginFlow::$activeState)[LoginState::LAST_ACTIVITY];
        }

        /**
         * Reset globals touched by the tests.
         */
        private function cleanup(): void {
            \Session::$userId = false;
            unset($_SESSION['glpiID'], $_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR], $_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR]);
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
            MockConfigEntity::$mockFields = [];
        }

        /**
         * Timeline with a 5 minute inactivity timeout: page loaded, user types for 8 minutes
         * and submits. With a keepalive ping in between the submit passes, without it the
         * user is logged out on submit.
         */
        public function testKeepaliveKeepsTypingUserLoggedIn(): void {
            MockConfigEntity::$mockFields[MockConfigEntity::INACTIVITY_TIMEOUT] = 5;

            // Without keepalive: last full request 8 minutes ago, submit logs the user out.
            $stale = date('Y-m-d H:i:s', time() - 8 * 60);
            $result = $this->request('POST', '/front/itilfollowup.form.php', $stale);
            if (!str_contains($result, 'index.php?noAuto=1')) {
                throw new \Exception("Expected logout on submit without keepalive, got: " . $result);
            }

            // With keepalive: a ping 4 minutes after the page load must refresh the activity.
            $fourMinutesAgo = date('Y-m-d H:i:s', time() - 4 * 60);
            $result = $this->request('GET', '/plugins/samlsso/front/keepalive', $fourMinutesAgo);
            if ($result !== 'continue') {
                throw new \Exception("Keepalive ping was not accepted, got: " . $result);
            }
            $refreshed = $this->lastActivityAfterRequest();
            if (strtotime($refreshed) < time() - 5) {
                throw new \Exception("Keepalive ping did not refresh the last activity, got: " . $refreshed);
            }

            // Submit 4 minutes after the ping (8 minutes after the page load) passes.
            $afterPing = date('Y-m-d H:i:s', strtotime($refreshed) - 4 * 60);
            $result = $this->request('POST', '/front/itilfollowup.form.php', $afterPing);
            if ($result !== 'continue') {
                throw new \Exception("Submit after keepalive ping was logged out, got: " . $result);
            }

            // A ping does not revive a session that already timed out (user was idle).
            $result = $this->request('GET', '/plugins/samlsso/front/keepalive', $stale);
            if (!str_contains($result, 'index.php?noAuto=1')) {
                throw new \Exception("Keepalive ping revived an idle session, got: " . $result);
            }

            $this->cleanup();
            echo "✅ Keepalive: ping keeps typing user logged in, idle sessions still time out\n";
        }

        /**
         * The keepalive script is only needed when an active configuration uses an inactivity timeout.
         */
        public function testMinInactivityTimeoutAndInterval(): void {
            global $DB;
            $db = new ParamsMockDB();
            $DB = $db;

            Config::clearCache();
            if (Config::getMinInactivityTimeout() !== 0 || Config::getKeepaliveInterval() !== 0) {
                throw new \Exception("Expected no keepalive without matching configurations");
            }
            $where = $db->lastParams['WHERE'] ?? [];
            if (($where['inactivity_timeout'] ?? null) !== ['>', 0] || ($where['is_active'] ?? null) !== 1 || ($where['is_deleted'] ?? null) !== 0) {
                throw new \Exception("Unexpected WHERE clause: " . json_encode($where));
            }

            // Smallest timeout wins, interval is half the timeout and at most 60 seconds.
            $cases = [
                [[5, 15], 5, 60],
                [[2], 2, 60],
                [[30, 1], 1, 30],
            ];
            foreach ($cases as [$timeouts, $expectedMin, $expectedInterval]) {
                Config::clearCache();
                $db->setResponse(Config::getTable(), array_map(fn($t) => ['inactivity_timeout' => $t], $timeouts));
                if (Config::getMinInactivityTimeout() !== $expectedMin || Config::getKeepaliveInterval() !== $expectedInterval) {
                    throw new \Exception(sprintf(
                        "Timeouts %s: expected min %d / interval %d, got %d / %d",
                        json_encode($timeouts), $expectedMin, $expectedInterval,
                        Config::getMinInactivityTimeout(), Config::getKeepaliveInterval()
                    ));
                }
            }

            // Cached for the rest of the request.
            $db->setResponse(Config::getTable(), []);
            if (Config::getMinInactivityTimeout() !== 1) {
                throw new \Exception("Expected cached result");
            }
            Config::clearCache();

            echo "✅ Keepalive: smallest inactivity timeout, ping interval and caching\n";
        }

        /**
         * Wiring: script registration, authenticated route and no AJAX header in the ping.
         */
        public function testKeepaliveWiring(): void {
            $setup = file_get_contents(__DIR__ . '/../setup.php');
            $pattern = '/\$keepaliveInterval\s*=\s*\(Session::getLoginUserID\(\)\s*!==\s*false\)\s*\?\s*Config::getKeepaliveInterval\(\)\s*:\s*0;\s*'
                     . 'if\s*\(\$keepaliveInterval\s*>\s*0\)\s*\{\s*\$PLUGIN_HOOKS\[\'add_javascript\'\]\[PLUGIN_NAME\]\s*=\s*\[\'js\/keepalive\.js\'\];\s*'
                     . '\$PLUGIN_HOOKS\[\'add_header_tag\'\]\[PLUGIN_NAME\].*\'samlsso-keepalive-interval\'.*\$keepaliveInterval/s';
            if (!preg_match($pattern, $setup)) {
                throw new \Exception("setup.php does not register js/keepalive.js and its interval meta tag for authenticated users with inactivity timeout");
            }

            $controller = file_get_contents(__DIR__ . '/../src/Controller/SamlSsoController.php');
            if (!preg_match('/(#\[[^\n]*\][^\n]*\n\s*)+public function keepalive\(/', $controller, $m)) {
                throw new \Exception("keepalive route not found in SamlSsoController");
            }
            // Route fallback strategy is central_access, helpdesk users must be allowed as well.
            if (!str_contains($m[0], 'Firewall::STRATEGY_AUTHENTICATED') || !str_contains($m[0], 'KEEPALIVE_ROUTE')) {
                throw new \Exception("keepalive route must use Firewall::STRATEGY_AUTHENTICATED");
            }

            $js = file_get_contents(__DIR__ . '/../public/js/keepalive.js');
            if (!str_contains($js, "'/front/keepalive'") || preg_match('/setRequestHeader|[\'"]X-Requested-With[\'"]\s*:/i', $js)) {
                throw new \Exception("keepalive.js must ping front/keepalive without X-Requested-With header");
            }

            echo "✅ Keepalive: script registration, authenticated route and ping request wiring\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\KeepaliveTest();
    try {
        $test->testKeepaliveKeepsTypingUserLoggedIn();
        $test->testMinInactivityTimeoutAndInterval();
        $test->testKeepaliveWiring();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
