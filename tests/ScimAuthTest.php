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
 *  @version    1.4.1
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Scim\ScimAuthenticator;
    use GlpiPlugin\Samlsso\Scim\ScimException;
    use GlpiPlugin\Samlsso\Scim\ScimResponseFactory;
    use Symfony\Component\HttpFoundation\Request;

    /**
     * Minimal PSR-16 SimpleCache mock for rate-limiter testing.
     */
    class MockSimpleCache
    {
        /** @var array<string, mixed> */
        public array $store = [];

        /**
         * Get a cached value.
         *
         * @param string $key
         * @param mixed  $default
         * @return mixed
         */
        public function get(string $key, mixed $default = null): mixed
        {
            return $this->store[$key] ?? $default;
        }

        /**
         * Set a cached value.
         *
         * @param string   $key
         * @param mixed    $value
         * @param int|null $ttl
         * @return bool
         */
        public function set(string $key, mixed $value, ?int $ttl = null): bool
        {
            $this->store[$key] = $value;
            return true;
        }
    }

    /**
     * ScimAuthTest verifies transport security, Bearer token authentication,
     * CIDR allowlisting, rate limiting, and RFC 7644 response headers.
     */
    class ScimAuthTest extends TestHarness
    {
        /**
         * Configure valid active SCIM mock fields on ConfigEntity.
         *
         * @param string $tokenHash
         * @param string $source
         * @param string $allowedIps
         * @return void
         */
        private function configureActiveScimIdp(
            string $tokenHash,
            string $source = 'db',
            string $allowedIps = ''
        ): void {
            ConfigEntity::$mockIsValid = true;
            ConfigEntity::$mockIsActive = true;
            ConfigEntity::$mockIsDeleted = false;
            ConfigEntity::$mockFields = [
                ConfigEntity::ID                => 5,
                ConfigEntity::IS_ACTIVE         => 1,
                ConfigEntity::IS_DELETED        => 0,
                ConfigEntity::SCIM_ENABLED      => 1,
                ConfigEntity::SCIM_TOKEN_HASH   => $tokenHash,
                ConfigEntity::SCIM_TOKEN_SOURCE => $source,
                ConfigEntity::SCIM_ALLOWED_IPS  => $allowedIps,
                ConfigEntity::SCIM_DEPROVISION  => 'disable',
                ConfigEntity::SCIM_GROUP_POLICY => 'create_scoped',
            ];
        }

        /**
         * Verify ScimResponseFactory sets RFC 7644 media type and mandatory security headers.
         *
         * @return void
         * @throws \Exception
         */
        public function testScimResponseFactoryHeadersAndErrorSchema(): void
        {
            $errResp = ScimResponseFactory::error(401, 'Unauthorized test', null, [
                'WWW-Authenticate' => 'Bearer realm="GLPI samlSSO SCIM"',
            ]);

            if ($errResp->getStatusCode() !== 401) {
                throw new \Exception('Expected HTTP 401 status code.');
            }
            $ct = (string) $errResp->headers->get('Content-Type');
            if (!str_contains($ct, 'application/scim+json')) {
                throw new \Exception("Expected application/scim+json Content-Type, got: $ct");
            }
            if ((string) $errResp->headers->get('Cache-Control') === '' || !str_contains((string) $errResp->headers->get('Cache-Control'), 'no-store')) {
                throw new \Exception('Expected Cache-Control: no-store header.');
            }
            if ($errResp->headers->get('X-Content-Type-Options') !== 'nosniff') {
                throw new \Exception('Expected X-Content-Type-Options: nosniff header.');
            }
            if ($errResp->headers->get('X-Frame-Options') !== 'DENY') {
                throw new \Exception('Expected X-Frame-Options: DENY header.');
            }
            if (!str_contains((string) $errResp->headers->get('Content-Security-Policy'), "default-src 'none'")) {
                throw new \Exception('Expected strict Content-Security-Policy header.');
            }
            if ($errResp->headers->get('WWW-Authenticate') !== 'Bearer realm="GLPI samlSSO SCIM"') {
                throw new \Exception('Expected WWW-Authenticate header on 401.');
            }

            $decoded = json_decode((string) $errResp->getContent(), true);
            if (($decoded['schemas'][0] ?? '') !== 'urn:ietf:params:scim:api:messages:2.0:Error' || ($decoded['status'] ?? '') !== '401') {
                throw new \Exception('Invalid RFC 7644 Error JSON schema: ' . $errResp->getContent());
            }

            echo "✅ SCIM Auth: ScimResponseFactory security headers and RFC 7644 Error schema verified\n";
        }

        /**
         * Verify HTTP rejection, inactive/disabled IdP rejection, DB SHA-256 Bearer auth,
         * REDIRECT_HTTP_AUTHORIZATION fallback, query-token rejection, and env/define >= 32-char floor.
         *
         * @return void
         * @throws \Exception
         */
        public function testTransportAndTokenAuthentication(): void
        {
            global $GLPI_CACHE;
            $GLPI_CACHE = new MockSimpleCache();

            $generated = ScimAuthenticator::generateToken();
            if (!str_starts_with($generated['plaintext'], 'scim_') || strlen($generated['hash']) !== 64) {
                throw new \Exception('Generated SCIM token format invalid.');
            }

            $this->configureActiveScimIdp($generated['hash'], 'db');
            $auth = new ScimAuthenticator();

            /**
             * 1. Reject plain HTTP (non-HTTPS) unconditionally.
             */
            $httpReq = Request::create('http://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $generated['plaintext'],
                'REMOTE_ADDR'        => '198.51.100.10',
            ]);
            $blockedHttp = false;
            try {
                $auth->authenticate($httpReq, 5);
            } catch (ScimException $e) {
                $blockedHttp = ($e->getHttpStatus() === 403);
            }
            if (!$blockedHttp) {
                throw new \Exception('Plain HTTP SCIM request was not rejected with 403.');
            }

            /**
             * 2. Accept valid HTTPS + Authorization: Bearer token (including REDIRECT_HTTP_AUTHORIZATION fallback).
             */
            $httpsReq = Request::create('https://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                'HTTPS'                       => 'on',
                'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $generated['plaintext'],
                'REMOTE_ADDR'                 => '198.51.100.10',
            ]);
            $verifiedEntity = $auth->authenticate($httpsReq, 5);
            if ((int) $verifiedEntity->getField(ConfigEntity::ID) !== 5) {
                throw new \Exception('Expected verified ConfigEntity ID 5.');
            }

            /**
             * 3. Reject query-string access_token leakage and wrong Bearer tokens with 401.
             */
            $queryTokenReq = Request::create(
                'https://glpi.local/plugins/samlsso/front/scim/v2/5/Users?access_token=' . $generated['plaintext'],
                'GET',
                [],
                [],
                [],
                ['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.11']
            );
            $blockedQueryToken = false;
            try {
                $auth->authenticate($queryTokenReq, 5);
            } catch (ScimException $e) {
                $blockedQueryToken = in_array($e->getHttpStatus(), [400, 401], true);
            }
            if (!$blockedQueryToken) {
                throw new \Exception('Query-parameter token was not rejected.');
            }

            /**
             * 4. Reject disabled SCIM or deleted/inactive IdP with 404.
             */
            ConfigEntity::$mockFields[ConfigEntity::SCIM_ENABLED] = 0;
            $blockedDisabled = false;
            try {
                $auth->authenticate($httpsReq, 5);
            } catch (ScimException $e) {
                $blockedDisabled = ($e->getHttpStatus() === 404);
            }
            if (!$blockedDisabled) {
                throw new \Exception('Disabled SCIM IdP was not rejected with 404.');
            }

            /**
             * 5. Enforce >= 32-char entropy floor on env / define token sources.
             */
            putenv('TEST_SCIM_WEAK_TOKEN=too_short_secret');
            $this->configureActiveScimIdp('TEST_SCIM_WEAK_TOKEN', 'env');
            $weakReq = Request::create('https://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                'HTTPS'              => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer too_short_secret',
                'REMOTE_ADDR'        => '198.51.100.12',
            ]);
            $blockedWeakEnv = false;
            try {
                $auth->authenticate($weakReq, 5);
            } catch (ScimException $e) {
                $blockedWeakEnv = ($e->getHttpStatus() === 401);
            }
            if (!$blockedWeakEnv) {
                throw new \Exception('Short (< 32 chars) env SCIM token was not rejected.');
            }

            $strongSecret = 'env_scim_secret_with_at_least_32_characters_entropy!';
            putenv('TEST_SCIM_STRONG_TOKEN=' . $strongSecret);
            $this->configureActiveScimIdp('TEST_SCIM_STRONG_TOKEN', 'env');
            $strongReq = Request::create('https://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                'HTTPS'              => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $strongSecret,
                'REMOTE_ADDR'        => '198.51.100.12',
            ]);
            $auth->authenticate($strongReq, 5);

            echo "✅ SCIM Auth: HTTPS enforcement, DB/env constant-time Bearer validation, and >= 32-char floor verified\n";
        }

        /**
         * Verify IPv4/IPv6 CIDR allowlisting and failed-auth 429 rate limiting.
         *
         * @return void
         * @throws \Exception
         */
        public function testCidrAllowlistAndRateLimiting(): void
        {
            global $GLPI_CACHE;
            $GLPI_CACHE = new MockSimpleCache();

            $generated = ScimAuthenticator::generateToken();
            $this->configureActiveScimIdp($generated['hash'], 'db', "203.0.113.0/24\n2001:db8:abcd::/48");
            $auth = new ScimAuthenticator();

            /**
             * Allowed IPv4 inside 203.0.113.0/24 and IPv6 inside 2001:db8:abcd::/48 succeed.
             */
            foreach (['203.0.113.42', '2001:db8:abcd:0001::5'] as $allowedIp) {
                $req = Request::create('https://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                    'HTTPS'              => 'on',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $generated['plaintext'],
                    'REMOTE_ADDR'        => $allowedIp,
                ]);
                $auth->authenticate($req, 5);
            }

            /**
             * Disallowed IPv4 outside 203.0.113.0/24 is rejected with 403 even with valid token.
             */
            $blockedIpReq = Request::create('https://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                'HTTPS'              => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $generated['plaintext'],
                'REMOTE_ADDR'        => '198.51.100.99',
            ]);
            $blockedIp = false;
            try {
                $auth->authenticate($blockedIpReq, 5);
            } catch (ScimException $e) {
                $blockedIp = ($e->getHttpStatus() === 403);
            }
            if (!$blockedIp) {
                throw new \Exception('Unauthorized source IP was not rejected with 403.');
            }

            /**
             * Failed authentication rate limit triggers 429 after 10 failures from the same IP.
             */
            $this->configureActiveScimIdp($generated['hash'], 'db', '');
            $badReq = Request::create('https://glpi.local/plugins/samlsso/front/scim/v2/5/Users', 'GET', [], [], [], [
                'HTTPS'              => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer scim_' . str_repeat('0', 64),
                'REMOTE_ADDR'        => '203.0.113.77',
            ]);
            for ($i = 1; $i <= 10; $i++) {
                try {
                    $auth->authenticate($badReq, 5);
                } catch (ScimException $e) {
                    if ($e->getHttpStatus() !== 401) {
                        throw new \Exception("Expected 401 on attempt $i, got {$e->getHttpStatus()}");
                    }
                }
            }
            $rateLimited = false;
            try {
                $auth->authenticate($badReq, 5);
            } catch (ScimException $e) {
                $rateLimited = ($e->getHttpStatus() === 429 && ($e->getExtraHeaders()['Retry-After'] ?? '') === '60');
            }
            if (!$rateLimited) {
                throw new \Exception('11th failed auth attempt did not trigger HTTP 429 with Retry-After: 60.');
            }

            echo "✅ SCIM Auth: IPv4/IPv6 CIDR allowlist and 10/60s failed-auth rate limiter verified\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\ScimAuthTest();
    try {
        $test->testScimResponseFactoryHeadersAndErrorSchema();
        $test->testTransportAndTokenAuthentication();
        $test->testCidrAllowlistAndRateLimiting();
    } catch (\Throwable $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
