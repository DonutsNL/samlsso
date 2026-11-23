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

/**
 * ScimException represents a structured RFC 7644 §3.12 protocol error with
 * an HTTP status code, optional scimType, and optional response headers.
 *
 * @since 1.4.0
 */
final class ScimException extends \RuntimeException
{
    /** @var int HTTP status code (e.g. 400, 401, 403, 404, 409, 413, 429). */
    private int $httpStatus;

    /** @var string|null Optional RFC 7644 Table 9 scimType keyword. */
    private ?string $scimType;

    /** @var array<string, string> Optional HTTP headers to attach to the error response. */
    private array $extraHeaders;

    /**
     * Construct a structured SCIM protocol exception.
     *
     * @param int                   $httpStatus   HTTP status code.
     * @param string                $detail       Human-readable RFC 7644 detail string.
     * @param string|null           $scimType     Optional RFC 7644 scimType keyword.
     * @param array<string, string> $extraHeaders Optional HTTP headers (e.g. WWW-Authenticate, Retry-After).
     */
    public function __construct(
        int $httpStatus,
        string $detail,
        ?string $scimType = null,
        array $extraHeaders = []
    ) {
        parent::__construct($detail, $httpStatus);
        $this->httpStatus   = $httpStatus;
        $this->scimType     = $scimType;
        $this->extraHeaders = $extraHeaders;
    }

    /**
     * Return the HTTP status code associated with this SCIM exception.
     *
     * @return int
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Return the optional RFC 7644 scimType keyword.
     *
     * @return string|null
     */
    public function getScimType(): ?string
    {
        return $this->scimType;
    }

    /**
     * Return any extra HTTP headers associated with this SCIM exception.
     *
     * @return array<string, string>
     */
    public function getExtraHeaders(): array
    {
        return $this->extraHeaders;
    }
}
