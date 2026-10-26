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
    /**
     * GLPI core message level used by the timeout branch of LoginFlow::doAuth().
     * The test shims do not define it, GLPI core defines ERROR as 1.
     */
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
        /** @var array Parameters of the last request() call. */
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

        /** URI of the keepalive route as requested by public/js/keepalive.js. */
        private const KEEPALIVE_URI = '/plugins/samlsso/front/keepalive';

        /** URI of a ticket follow-up submit, the request that lost the typed text. */
        private const SUBMIT_URI = '/front/itilfollowup.form.php';

        /** Redirect target of LoginFlow::doAuth() when a session timed out. */
        private const LOGOUT_TARGET = 'index.php?noAuto=1';

        /** Prefix of the exception thrown by the header() shim on a Location header. */
        private const REDIRECT_PREFIX = 'Redirect to: ';

        /**
         * Returns a GLPI_AUTH state row with the given last activity.
         *
         * @param string $lastActivity Datetime of the last activity (Y-m-d H:i:s).
         * @return array State row as stored in the login state table.
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
         * Performs an authenticated, non-AJAX request through LoginFlow::doAuth().
         *
         * @param string $method HTTP method.
         * @param string $uri Request URI.
         * @param string $lastActivity Last activity stored in the login state before the request.
         * @return string 'continue' or the redirect target if the user was logged out.
         * @throws \Exception If doAuth() fails for another reason than a redirect.
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
                if (str_starts_with($e->getMessage(), self::REDIRECT_PREFIX)) {
                    return substr($e->getMessage(), strlen(self::REDIRECT_PREFIX));
                }
                throw $e;
            }
        }

        /**
         * Returns the last activity held by the state of the most recent doAuth() call.
         *
         * @return string Datetime of the last activity (Y-m-d H:i:s).
         */
        private function lastActivityAfterRequest(): string {
            $refObj = new \ReflectionObject(LoginFlow::$activeState);
            $refProp = $refObj->getProperty('state');
            $refProp->setAccessible(true);
            return (string)$refProp->getValue(LoginFlow::$activeState)[LoginState::LAST_ACTIVITY];
        }

        /**
         * Reset globals touched by the tests.
         *
         * @return void
         */
        private function cleanup(): void {
            \Session::$userId = false;
            unset($_SESSION['glpiID']);
            unset($_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR], $_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR]);
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
            MockConfigEntity::$mockFields = [];
        }

        /**
         * Timeline with a 5 minute inactivity timeout: page loaded, user types for 8 minutes
         * and submits. With a keepalive ping in between the submit passes, without it the
         * user is logged out on submit. A ping does not revive a session that already timed out.
         *
         * @return void
         * @throws \Exception If a step of the timeline behaves unexpectedly.
         */
        public function testKeepaliveKeepsTypingUserLoggedIn(): void {
            MockConfigEntity::$mockFields[MockConfigEntity::INACTIVITY_TIMEOUT] = 5;

            /** Without keepalive: last full request 8 minutes ago, the submit logs the user out. */
            $stale = date('Y-m-d H:i:s', time() - 8 * 60);
            $result = $this->request('POST', self::SUBMIT_URI, $stale);
            if (!str_contains($result, self::LOGOUT_TARGET)) {
                throw new \Exception("Expected logout on submit without keepalive, got: " . $result);
            }

            /** With keepalive: a ping 4 minutes after the page load must refresh the activity. */
            $fourMinutesAgo = date('Y-m-d H:i:s', time() - 4 * 60);
            $result = $this->request('GET', self::KEEPALIVE_URI, $fourMinutesAgo);
            if ($result !== 'continue') {
                throw new \Exception("Keepalive ping was not accepted, got: " . $result);
            }
            $refreshed = $this->lastActivityAfterRequest();
            if (strtotime($refreshed) < time() - 5) {
                throw new \Exception("Keepalive ping did not refresh the last activity, got: " . $refreshed);
            }

            /** Submit 4 minutes after the ping (8 minutes after the page load) passes. */
            $afterPing = date('Y-m-d H:i:s', strtotime($refreshed) - 4 * 60);
            $result = $this->request('POST', self::SUBMIT_URI, $afterPing);
            if ($result !== 'continue') {
                throw new \Exception("Submit after keepalive ping was logged out, got: " . $result);
            }

            /** A ping does not revive a session that already timed out (user was idle). */
            $result = $this->request('GET', self::KEEPALIVE_URI, $stale);
            if (!str_contains($result, self::LOGOUT_TARGET)) {
                throw new \Exception("Keepalive ping revived an idle session, got: " . $result);
            }

            $this->cleanup();
            echo "✅ Keepalive: ping keeps typing user logged in, idle sessions still time out\n";
        }

        /**
         * The smallest active inactivity timeout determines the ping interval: half the
         * timeout, at most Config::KEEPALIVE_MAX_INTERVAL seconds, 0 without timeout.
         *
         * @return void
         * @throws \Exception If lookup, interval or caching behave unexpectedly.
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
            $expectedWhere = [
                MockConfigEntity::IS_ACTIVE          => 1,
                MockConfigEntity::IS_DELETED         => 0,
                MockConfigEntity::INACTIVITY_TIMEOUT => ['>', 0],
            ];
            if ($where !== $expectedWhere) {
                throw new \Exception("Unexpected WHERE clause: " . json_encode($where));
            }

            /** Each case: configured timeouts (minutes), expected minimum, expected interval (seconds). */
            $cases = [
                [[5, 15], 5, Config::KEEPALIVE_MAX_INTERVAL],
                [[2], 2, Config::KEEPALIVE_MAX_INTERVAL],
                [[30, 1], 1, 30],
            ];
            foreach ($cases as [$timeouts, $expectedMin, $expectedInterval]) {
                Config::clearCache();
                $rows = array_map(fn($timeout) => [MockConfigEntity::INACTIVITY_TIMEOUT => $timeout], $timeouts);
                $db->setResponse(Config::getTable(), $rows);
                $min = Config::getMinInactivityTimeout();
                $interval = Config::getKeepaliveInterval();
                if ($min !== $expectedMin || $interval !== $expectedInterval) {
                    throw new \Exception(sprintf(
                        "Timeouts %s: expected min %d / interval %d, got %d / %d",
                        json_encode($timeouts),
                        $expectedMin,
                        $expectedInterval,
                        $min,
                        $interval
                    ));
                }
            }

            /** The result is cached for the rest of the request. */
            $db->setResponse(Config::getTable(), []);
            if (Config::getMinInactivityTimeout() !== 1) {
                throw new \Exception("Expected cached result");
            }
            Config::clearCache();

            echo "✅ Keepalive: smallest inactivity timeout, ping interval and caching\n";
        }

        /**
         * Returns the string value of a public constant declared in the controller source.
         * The controller cannot be loaded in the test environment (Symfony is not available).
         *
         * @param string $source Controller source code.
         * @param string $name Constant name.
         * @return string Constant value.
         * @throws \Exception If the constant is not declared.
         */
        private function controllerConstant(string $source, string $name): string {
            if (!preg_match('/public const ' . $name . '\s*=\s*\'([^\']+)\';/', $source, $match)) {
                throw new \Exception("Constant SamlSsoController::$name not found");
            }
            return $match[1];
        }

        /**
         * Wiring: script and meta tag registration, authenticated route with login check,
         * and a ping without AJAX header to the same route and meta tag the PHP side uses.
         *
         * @return void
         * @throws \Exception If a part of the wiring is missing or inconsistent.
         */
        public function testKeepaliveWiring(): void {
            $setup = file_get_contents(__DIR__ . '/../setup.php');
            $registration = '/\$keepaliveInterval\s*=\s*\(Session::getLoginUserID\(\)\s*!==\s*false\)'
                . '\s*\?\s*Config::getKeepaliveInterval\(\)\s*:\s*0;\s*if\s*\(\$keepaliveInterval\s*>\s*0\)\s*\{'
                . '\s*\$PLUGIN_HOOKS\[Hooks::ADD_JAVASCRIPT\]\[PLUGIN_NAME\]\s*='
                . '\s*\[SamlSsoController::KEEPALIVE_SCRIPT\];'
                . '\s*\$PLUGIN_HOOKS\[Hooks::ADD_HEADER_TAG\]\[PLUGIN_NAME\].*SamlSsoController::KEEPALIVE_META'
                . '.*\(string\)\$keepaliveInterval/s';
            if (!preg_match($registration, $setup)) {
                throw new \Exception("setup.php does not register keepalive script and interval meta tag as expected");
            }

            $controller = file_get_contents(__DIR__ . '/../src/Controller/SamlSsoController.php');
            /** Group 1: attributes of the keepalive method, group 2: its body. */
            $routeMethod = '/((?:#\[[^\n]*\]\s*\n\s*)+)public function keepalive\([^{]*\{([^}]*)\}/';
            if (!preg_match($routeMethod, $controller, $m)) {
                throw new \Exception("keepalive route not found in SamlSsoController");
            }
            /** The fallback for controller routes is central_access, helpdesk users must be allowed as well. */
            if (!str_contains($m[1], 'Firewall::STRATEGY_AUTHENTICATED') || !str_contains($m[1], 'KEEPALIVE_ROUTE')) {
                throw new \Exception("keepalive route must use Firewall::STRATEGY_AUTHENTICATED");
            }
            if (!str_contains($m[2], 'Session::checkLoginUser()')) {
                throw new \Exception("keepalive route must verify the login with Session::checkLoginUser()");
            }

            $route = $this->controllerConstant($controller, 'KEEPALIVE_ROUTE');
            $script = $this->controllerConstant($controller, 'KEEPALIVE_SCRIPT');
            $meta = $this->controllerConstant($controller, 'KEEPALIVE_META');
            $jsFile = __DIR__ . '/../public/' . $script;
            if (!is_file($jsFile)) {
                throw new \Exception("Keepalive script public/$script not found");
            }
            $js = file_get_contents($jsFile);
            if (!str_contains($js, "'/" . $route . "'") || !str_contains($js, 'meta[name="' . $meta . '"]')) {
                throw new \Exception("keepalive.js must use route $route and meta tag $meta");
            }
            if (preg_match('/setRequestHeader|[\'"]X-Requested-With[\'"]\s*:/i', $js)) {
                throw new \Exception("keepalive.js must not send an X-Requested-With header");
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
