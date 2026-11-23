<?php

declare(strict_types=1);

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

namespace GlpiPlugin\Samlsso\Scim;

use GlpiPlugin\Samlsso\Config\ConfigEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Toolbox;

/**
 * ScimAuthenticator enforces fail-closed transport security (HTTPS), per-IdP
 * configuration status, CIDR IP allowlisting, split rate limiting, and
 * constant-time SHA-256 Bearer token verification on every SCIM 2.0 request.
 *
 * @since 1.4.0
 */
final class ScimAuthenticator
{
    /** @var string Constant-time dummy SHA-256 hex digest for invalid/disabled IdP lookups. */
    private const DUMMY_TOKEN_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @var int Maximum failed authentication attempts per (idpId, clientIp) per window. */
    public const MAX_FAILED_AUTH_PER_WINDOW = 10;

    /** @var int Maximum authenticated SCIM requests per idpId per window. */
    public const MAX_AUTHENTICATED_PER_WINDOW = 600;

    /** @var int Sliding window length in seconds. */
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    /** @var int Minimum character length for Bearer secrets across all token sources. */
    public const MIN_SECRET_LENGTH = 32;

    /** @var array<string, array{count: int, expires: int}> In-process fallback rate-limit buckets. */
    private static array $localRateBuckets = [];

    /**
     * Generate a cryptographically random 256-bit SCIM Bearer token and its SHA-256 hex digest.
     *
     * @return array{plaintext: string, hash: string}
     */
    public static function generateToken(): array
    {
        $plaintext = 'scim_' . bin2hex(random_bytes(32));
        return [
            'plaintext' => $plaintext,
            'hash'      => hash('sha256', $plaintext),
        ];
    }

    /**
     * Authenticate an incoming SCIM HTTP request for the given IdP configuration ID.
     *
     * @param Request $request Incoming Symfony HTTP request.
     * @param int     $idpId   Route IdP configuration ID.
     * @return ConfigEntity Verified active SCIM-enabled IdP configuration entity.
     * @throws ScimException On HTTPS, IdP state, IP allowlist, rate-limit, or Bearer auth failure.
     */
    public function authenticate(Request $request, int $idpId): ConfigEntity
    {
        /**
         * 1. Enforce HTTPS unconditionally (respecting Symfony TrustedProxies when configured).
         */
        if (!$request->isSecure()) {
            throw new ScimException(
                Response::HTTP_FORBIDDEN,
                'HTTPS is required for all SCIM 2.0 endpoints.'
            );
        }

        /**
         * 2. Reject tokens passed in URL query parameters to prevent access-log secret leakage.
         */
        if ($request->query->has('access_token') || $request->query->has('bearer_token') || $request->query->has('token')) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'Bearer tokens must be transmitted exclusively in the HTTP Authorization header.',
                'invalidSyntax'
            );
        }

        $clientIp = (string) ($request->getClientIp() ?? '');
        $providedToken = $this->extractBearerToken($request);
        $providedDigest = hash('sha256', $providedToken);

        /**
         * 3. Enforce failed-authentication rate limit before evaluating credentials.
         */
        $failBucketKey = sprintf('samlsso_scim_fail_%d_%s', max(0, $idpId), hash('sha256', $clientIp));
        if ($this->getBucketCount($failBucketKey) >= self::MAX_FAILED_AUTH_PER_WINDOW) {
            throw new ScimException(
                Response::HTTP_TOO_MANY_REQUESTS,
                'Too many failed SCIM authentication attempts. Please retry later.',
                'tooMany',
                ['Retry-After' => (string) self::RATE_LIMIT_WINDOW_SECONDS]
            );
        }

        /**
         * 4. Validate IdP bounds and active SCIM state with constant-time dummy hash check.
         */
        if ($idpId < 1 || $idpId > 999) {
            hash_equals(self::DUMMY_TOKEN_HASH, $providedDigest);
            $this->incrementBucket($failBucketKey);
            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM endpoint not found.');
        }

        $configEntity = new ConfigEntity($idpId);
        $scimEnabled = (bool) $configEntity->getField(ConfigEntity::SCIM_ENABLED);
        if (!$configEntity->isValid() || !$configEntity->isActive() || $configEntity->isDeleted() || !$scimEnabled) {
            hash_equals(self::DUMMY_TOKEN_HASH, $providedDigest);
            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM endpoint not found or disabled.');
        }

        /**
         * 5. Enforce optional per-IdP IPv4/IPv6 CIDR source IP allowlist.
         */
        $allowedIpsRaw = trim((string) ($configEntity->getField(ConfigEntity::SCIM_ALLOWED_IPS) ?: ''));
        if ($allowedIpsRaw !== '' && !$this->isIpAllowed($clientIp, $allowedIpsRaw)) {
            $this->incrementBucket($failBucketKey);
            $this->logSecurityEvent(sprintf(
                'SCIM request blocked by IP allowlist for IdP #%d from source IP %s',
                $idpId,
                $clientIp !== '' ? $clientIp : 'unknown'
            ));
            throw new ScimException(
                Response::HTTP_FORBIDDEN,
                'Source IP address is not authorized for this SCIM endpoint.'
            );
        }

        /**
         * 6. Verify Bearer token in constant time against configured token source.
         */
        if (!$this->verifyBearerToken($configEntity, $providedToken, $providedDigest)) {
            $this->incrementBucket($failBucketKey);
            $this->logSecurityEvent(sprintf(
                'SCIM Bearer authentication failed for IdP #%d from source IP %s',
                $idpId,
                $clientIp !== '' ? $clientIp : 'unknown'
            ));
            throw new ScimException(
                Response::HTTP_UNAUTHORIZED,
                'Invalid or missing SCIM Bearer token.',
                null,
                ['WWW-Authenticate' => 'Bearer realm="GLPI samlSSO SCIM"']
            );
        }

        /**
         * 7. Enforce authenticated burst ceiling per IdP (600 requests / 60s).
         */
        $reqBucketKey = sprintf('samlsso_scim_req_%d', $idpId);
        if ($this->incrementBucket($reqBucketKey) > self::MAX_AUTHENTICATED_PER_WINDOW) {
            throw new ScimException(
                Response::HTTP_TOO_MANY_REQUESTS,
                'SCIM rate limit exceeded for this Identity Provider.',
                'tooMany',
                ['Retry-After' => (string) self::RATE_LIMIT_WINDOW_SECONDS]
            );
        }

        return $configEntity;
    }

    /**
     * Extract the raw Bearer token from HTTP Authorization headers.
     *
     * @param Request $request Incoming Symfony HTTP request.
     * @return string Extracted token string or empty string when absent.
     */
    private function extractBearerToken(Request $request): string
    {
        $header = (string) ($request->headers->get('Authorization') ?? '');
        if ($header === '') {
            $header = (string) ($request->server->get('REDIRECT_HTTP_AUTHORIZATION') ?? '');
        }
        if ($header === '') {
            $header = (string) ($request->server->get('HTTP_AUTHORIZATION') ?? '');
        }

        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Verify the provided Bearer token against the IdP's configured token source in constant time.
     *
     * @param ConfigEntity $configEntity   Target IdP configuration entity.
     * @param string       $providedToken  Raw Bearer token from Authorization header.
     * @param string       $providedDigest Precomputed lowercase SHA-256 hex digest of $providedToken.
     * @return bool
     */
    private function verifyBearerToken(
        ConfigEntity $configEntity,
        string $providedToken,
        string $providedDigest
    ): bool {
        $source = (string) ($configEntity->getField(ConfigEntity::SCIM_TOKEN_SOURCE) ?: 'db');
        $storedRef = trim((string) ($configEntity->getField(ConfigEntity::SCIM_TOKEN_HASH) ?: ''));

        if ($providedToken === '' || strlen($providedToken) < self::MIN_SECRET_LENGTH || $storedRef === '') {
            hash_equals(self::DUMMY_TOKEN_HASH, $providedDigest);
            return false;
        }

        if ($source === 'db') {
            if (preg_match('/^[a-fA-F0-9]{64}$/', $storedRef) !== 1) {
                hash_equals(self::DUMMY_TOKEN_HASH, $providedDigest);
                return false;
            }
            return hash_equals(strtolower($storedRef), $providedDigest);
        }

        $externalSecret = '';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $storedRef) === 1) {
            if ($source === 'env') {
                $envVal = getenv($storedRef);
                $externalSecret = is_string($envVal) ? $envVal : '';
            } elseif ($source === 'define' && defined($storedRef)) {
                $constVal = constant($storedRef);
                $externalSecret = is_string($constVal) ? $constVal : '';
            }
        }

        if (strlen($externalSecret) < self::MIN_SECRET_LENGTH) {
            hash_equals(self::DUMMY_TOKEN_HASH, $providedDigest);
            return false;
        }

        $expectedDigest = hash('sha256', $externalSecret);
        return hash_equals($expectedDigest, $providedDigest);
    }

    /**
     * Check whether a client IPv4 or IPv6 address matches at least one allowlist IP/CIDR entry.
     *
     * @param string $clientIp      Client IP resolved via Request::getClientIp().
     * @param string $allowedIpsRaw Newline- or comma-separated IP/CIDR allowlist.
     * @return bool
     */
    public function isIpAllowed(string $clientIp, string $allowedIpsRaw): bool
    {
        $clientPacked = @inet_pton(trim($clientIp));
        if ($clientPacked === false) {
            return false;
        }

        $entries = preg_split('/[\r\n,;]+/', $allowedIpsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($entries as $entry) {
            $candidate = trim($entry);
            if ($candidate === '') {
                continue;
            }

            $netIp = $candidate;
            $prefixBits = null;
            if (str_contains($candidate, '/')) {
                [$netIp, $prefixStr] = explode('/', $candidate, 2);
                if (preg_match('/^\d+$/', $prefixStr) !== 1) {
                    continue;
                }
                $prefixBits = (int) $prefixStr;
            }

            $netPacked = @inet_pton(trim($netIp));
            if ($netPacked === false || strlen($netPacked) !== strlen($clientPacked)) {
                continue;
            }

            $maxBits = strlen($clientPacked) * 8;
            $bits = $prefixBits ?? $maxBits;
            if ($bits < 0 || $bits > $maxBits) {
                continue;
            }

            if ($this->packedIpMatchesPrefix($clientPacked, $netPacked, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare two equal-length binary inet_pton() buffers across $prefixBits network bits.
     *
     * @param string $clientPacked Binary client IP.
     * @param string $netPacked    Binary network IP.
     * @param int    $prefixBits   Prefix bit length (0..32 or 0..128).
     * @return bool
     */
    private function packedIpMatchesPrefix(string $clientPacked, string $netPacked, int $prefixBits): bool
    {
        if ($prefixBits === 0) {
            return true;
        }

        $fullBytes = intdiv($prefixBits, 8);
        $remainderBits = $prefixBits % 8;

        if ($fullBytes > 0 && substr($clientPacked, 0, $fullBytes) !== substr($netPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits > 0) {
            $mask = (0xFF << (8 - $remainderBits)) & 0xFF;
            $clientByte = ord($clientPacked[$fullBytes]);
            $netByte = ord($netPacked[$fullBytes]);
            if (($clientByte & $mask) !== ($netByte & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read current count for a rate-limit bucket from $GLPI_CACHE or local fallback.
     *
     * @param string $key Cache bucket key.
     * @return int
     */
    private function getBucketCount(string $key): int
    {
        global $GLPI_CACHE;

        $now = time();
        if (is_object($GLPI_CACHE) && method_exists($GLPI_CACHE, 'get')) {
            $entry = $GLPI_CACHE->get($key);
            if (is_array($entry) && isset($entry['count'], $entry['expires']) && (int) $entry['expires'] > $now) {
                return (int) $entry['count'];
            }
            return 0;
        }

        if (isset(self::$localRateBuckets[$key]) && self::$localRateBuckets[$key]['expires'] > $now) {
            return self::$localRateBuckets[$key]['count'];
        }

        return 0;
    }

    /**
     * Increment a rate-limit bucket and return the updated count within the active 60-second window.
     *
     * @param string $key Cache bucket key.
     * @return int
     */
    private function incrementBucket(string $key): int
    {
        global $GLPI_CACHE;

        $now = time();
        if (is_object($GLPI_CACHE) && method_exists($GLPI_CACHE, 'get') && method_exists($GLPI_CACHE, 'set')) {
            $entry = $GLPI_CACHE->get($key);
            if (!is_array($entry) || !isset($entry['count'], $entry['expires']) || (int) $entry['expires'] <= $now) {
                $entry = [
                    'count'   => 1,
                    'expires' => $now + self::RATE_LIMIT_WINDOW_SECONDS,
                ];
            } else {
                $entry['count'] = (int) $entry['count'] + 1;
            }
            $ttl = max(1, (int) $entry['expires'] - $now);
            $GLPI_CACHE->set($key, $entry, $ttl);
            return (int) $entry['count'];
        }

        if (!isset(self::$localRateBuckets[$key]) || self::$localRateBuckets[$key]['expires'] <= $now) {
            self::$localRateBuckets[$key] = [
                'count'   => 1,
                'expires' => $now + self::RATE_LIMIT_WINDOW_SECONDS,
            ];
        } else {
            self::$localRateBuckets[$key]['count']++;
        }

        return self::$localRateBuckets[$key]['count'];
    }

    /**
     * Record a sanitized SCIM security event without ever logging credentials or raw headers.
     *
     * @param string $message Sanitized security event description.
     * @return void
     */
    private function logSecurityEvent(string $message): void
    {
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, $message . "\n");
        }
    }
}
