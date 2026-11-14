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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Scim;

use Symfony\Component\HttpFoundation\Response;

/**
 * ScimResponseFactory constructs RFC 7644 compliant HTTP responses carrying
 * application/scim+json media types and strict security response headers.
 *
 * @since 1.4.0
 */
final class ScimResponseFactory
{
    /** @var string RFC 7644 media type. */
    public const MEDIA_TYPE = 'application/scim+json; charset=utf-8';

    /** @var string RFC 7644 §3.12 Error schema URN. */
    public const SCHEMA_ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';

    /** @var string RFC 7644 §3.4.2 ListResponse schema URN. */
    public const SCHEMA_LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    /**
     * Build a single SCIM resource HTTP response (200 OK or 201 Created).
     *
     * @param array<string, mixed> $resource Serialized SCIM resource payload.
     * @param int                  $status   HTTP status code (default 200).
     * @param string|null          $location Optional canonical resource URL for Location header.
     * @return Response
     */
    public static function resource(array $resource, int $status = Response::HTTP_OK, ?string $location = null): Response
    {
        $headers = self::buildBaseHeaders(true);
        if ($location !== null && $location !== '') {
            $headers['Location'] = $location;
        }

        $body = (string) json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new Response($body, $status, $headers);
    }

    /**
     * Build an RFC 7644 §3.4.2 ListResponse HTTP payload.
     *
     * @param array<int, array<string, mixed>> $resources    Matched page of SCIM resources.
     * @param int                              $totalResults Total matching count prior to pagination.
     * @param int                              $startIndex   1-based start index.
     * @param int|null                         $itemsPerPage Optional page count override.
     * @return Response
     */
    public static function listResponse(
        array $resources,
        int $totalResults,
        int $startIndex = 1,
        ?int $itemsPerPage = null
    ): Response {
        $payload = [
            'schemas'      => [self::SCHEMA_LIST_RESPONSE],
            'totalResults' => max(0, $totalResults),
            'startIndex'   => max(1, $startIndex),
            'itemsPerPage' => $itemsPerPage ?? count($resources),
            'Resources'    => array_values($resources),
        ];

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new Response($body, Response::HTTP_OK, self::buildBaseHeaders(true));
    }

    /**
     * Build an empty HTTP 204 No Content response with mandatory security headers.
     *
     * @return Response
     */
    public static function noContent(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT, self::buildBaseHeaders(false));
    }

    /**
     * Build an RFC 7644 §3.12 Error HTTP response.
     *
     * @param int                   $status       HTTP status code.
     * @param string                $detail       Human-readable error detail.
     * @param string|null           $scimType     Optional RFC 7644 scimType keyword.
     * @param array<string, string> $extraHeaders Optional extra headers (e.g. WWW-Authenticate, Retry-After).
     * @return Response
     */
    public static function error(
        int $status,
        string $detail,
        ?string $scimType = null,
        array $extraHeaders = []
    ): Response {
        $payload = [
            'schemas' => [self::SCHEMA_ERROR],
            'status'  => (string) $status,
            'detail'  => $detail,
        ];
        if ($scimType !== null && $scimType !== '') {
            $payload['scimType'] = $scimType;
        }

        $headers = array_merge(self::buildBaseHeaders(true), $extraHeaders);
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new Response($body, $status, $headers);
    }

    /**
     * Convert a ScimException into an RFC 7644 Error response.
     *
     * @param ScimException $exception Caught SCIM protocol exception.
     * @return Response
     */
    public static function fromException(ScimException $exception): Response
    {
        return self::error(
            $exception->getHttpStatus(),
            $exception->getMessage(),
            $exception->getScimType(),
            $exception->getExtraHeaders()
        );
    }

    /**
     * Return mandatory security headers for all SCIM responses.
     *
     * @param bool $includeMediaType Whether to include Content-Type: application/scim+json.
     * @return array<string, string>
     */
    private static function buildBaseHeaders(bool $includeMediaType): array
    {
        $headers = [
            'Cache-Control'           => 'no-store, no-cache, must-revalidate',
            'Pragma'                  => 'no-cache',
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];

        if ($includeMediaType) {
            $headers['Content-Type'] = self::MEDIA_TYPE;
        }

        return $headers;
    }
}
