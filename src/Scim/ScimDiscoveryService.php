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

/**
 * ScimDiscoveryService builds RFC 7643 §5–§7 / RFC 7644 §4 discovery documents
 * (/ServiceProviderConfig, /ResourceTypes, and /Schemas) per IdP policy.
 *
 * @since 1.4.0
 */
final class ScimDiscoveryService
{
    /** @var string RFC 7643 Core User schema URN. */
    public const SCHEMA_CORE_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /** @var string RFC 7643 Enterprise User extension schema URN. */
    public const SCHEMA_ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** @var string RFC 7643 Core Group schema URN. */
    public const SCHEMA_CORE_GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    /** @var string RFC 7643 ServiceProviderConfig schema URN. */
    public const SCHEMA_SERVICE_PROVIDER_CONFIG = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';

    /** @var string RFC 7643 ResourceType schema URN. */
    public const SCHEMA_RESOURCE_TYPE = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';

    /** @var string RFC 7643 Schema descriptor URN. */
    public const SCHEMA_DESCRIPTOR = 'urn:ietf:params:scim:schemas:core:2.0:Schema';

    /** @var int Maximum items returned per ListResponse page. */
    public const MAX_RESULTS = 200;

    /**
     * Build the RFC 7643 §5 ServiceProviderConfig resource for an IdP SCIM base URL.
     *
     * @param string $baseScimUrl Canonical SCIM v2 base URL without trailing slash.
     * @return array<string, mixed>
     */
    public static function getServiceProviderConfig(string $baseScimUrl): array
    {
        $base = rtrim($baseScimUrl, '/');
        return [
            'schemas'          => [self::SCHEMA_SERVICE_PROVIDER_CONFIG],
            'documentationUri' => 'https://github.com/DonutsNL/samlsso/wiki/Configure-SCIM-Provisioning',
            'patch'            => ['supported' => true],
            'bulk'             => [
                'supported'      => false,
                'maxOperations'  => 0,
                'maxPayloadSize' => 0,
            ],
            'filter'           => [
                'supported'  => true,
                'maxResults' => self::MAX_RESULTS,
            ],
            'changePassword'   => ['supported' => false],
            'sort'             => ['supported' => false],
            'etag'             => ['supported' => false],
            'authenticationSchemes' => [
                [
                    'type'        => 'oauthbearertoken',
                    'name'        => 'OAuth Bearer Token',
                    'description' => 'Per-IdP HTTP Authorization Bearer token over HTTPS (RFC 6750).',
                    'specUri'     => 'https://datatracker.ietf.org/doc/html/rfc6750',
                    'primary'     => true,
                ],
            ],
            'meta' => [
                'location'     => $base . '/ServiceProviderConfig',
                'resourceType' => 'ServiceProviderConfig',
            ],
        ];
    }

    /**
     * Build RFC 7643 §6 ResourceType descriptors, omitting Group when scim_group_policy is 'disabled'.
     *
     * @param string $baseScimUrl Canonical SCIM v2 base URL without trailing slash.
     * @param string $groupPolicy IdP scim_group_policy ('create_scoped', 'link_existing', or 'disabled').
     * @return array<int, array<string, mixed>>
     */
    public static function getResourceTypes(string $baseScimUrl, string $groupPolicy): array
    {
        $base = rtrim($baseScimUrl, '/');
        $types = [
            [
                'schemas'          => [self::SCHEMA_RESOURCE_TYPE],
                'id'               => 'User',
                'name'             => 'User',
                'endpoint'         => '/Users',
                'description'      => 'GLPI External SAML/SCIM User Account',
                'schema'           => self::SCHEMA_CORE_USER,
                'schemaExtensions' => [
                    [
                        'schema'   => self::SCHEMA_ENTERPRISE_USER,
                        'required' => false,
                    ],
                ],
                'meta' => [
                    'location'     => $base . '/ResourceTypes/User',
                    'resourceType' => 'ResourceType',
                ],
            ],
        ];

        if ($groupPolicy !== 'disabled') {
            $types[] = [
                'schemas'          => [self::SCHEMA_RESOURCE_TYPE],
                'id'               => 'Group',
                'name'             => 'Group',
                'endpoint'         => '/Groups',
                'description'      => 'GLPI Organizational Group',
                'schema'           => self::SCHEMA_CORE_GROUP,
                'schemaExtensions' => [],
                'meta'             => [
                    'location'     => $base . '/ResourceTypes/Group',
                    'resourceType' => 'ResourceType',
                ],
            ];
        }

        return $types;
    }

    /**
     * Return a single ResourceType descriptor by ID ('User' or 'Group') or null if unavailable.
     *
     * @param string $resourceTypeId Requested resource type ID.
     * @param string $baseScimUrl    Canonical SCIM v2 base URL.
     * @param string $groupPolicy    IdP scim_group_policy.
     * @return array<string, mixed>|null
     */
    public static function getResourceTypeById(
        string $resourceTypeId,
        string $baseScimUrl,
        string $groupPolicy
    ): ?array {
        foreach (self::getResourceTypes($baseScimUrl, $groupPolicy) as $type) {
            if (strcasecmp((string) $type['id'], $resourceTypeId) === 0) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Build RFC 7643 §7 Schema descriptors, omitting Group when scim_group_policy is 'disabled'.
     *
     * @param string $baseScimUrl Canonical SCIM v2 base URL.
     * @param string $groupPolicy IdP scim_group_policy.
     * @return array<int, array<string, mixed>>
     */
    public static function getSchemas(string $baseScimUrl, string $groupPolicy): array
    {
        $base = rtrim($baseScimUrl, '/');
        $schemas = [
            self::buildCoreUserSchema($base),
            self::buildEnterpriseUserSchema($base),
        ];

        if ($groupPolicy !== 'disabled') {
            $schemas[] = self::buildCoreGroupSchema($base);
        }

        return $schemas;
    }

    /**
     * Return a single Schema descriptor by URN or null if unavailable.
     *
     * @param string $schemaId    Requested schema URN.
     * @param string $baseScimUrl Canonical SCIM v2 base URL.
     * @param string $groupPolicy IdP scim_group_policy.
     * @return array<string, mixed>|null
     */
    public static function getSchemaById(string $schemaId, string $baseScimUrl, string $groupPolicy): ?array
    {
        foreach (self::getSchemas($baseScimUrl, $groupPolicy) as $schema) {
            if (strcasecmp((string) $schema['id'], $schemaId) === 0) {
                return $schema;
            }
        }
        return null;
    }

    /**
     * Build the Core 2.0 User schema descriptor.
     *
     * @param string $base Canonical SCIM base URL.
     * @return array<string, mixed>
     */
    private static function buildCoreUserSchema(string $base): array
    {
        return [
            'schemas'     => [self::SCHEMA_DESCRIPTOR],
            'id'          => self::SCHEMA_CORE_USER,
            'name'        => 'User',
            'description' => 'SCIM 2.0 Core User Account',
            'attributes'  => [
                self::attr('userName', 'string', false, true, false, 'readWrite', 'server'),
                self::attr('externalId', 'string', false, false, true, 'readWrite', 'none'),
                self::attr('displayName', 'string', false, false, false, 'readWrite', 'none'),
                self::attr('title', 'string', false, false, false, 'readWrite', 'none'),
                self::attr('active', 'boolean', false, false, false, 'readWrite', 'none'),
                [
                    'name'          => 'name',
                    'type'          => 'complex',
                    'multiValued'   => false,
                    'required'      => false,
                    'mutability'    => 'readWrite',
                    'returned'      => 'default',
                    'subAttributes' => [
                        self::attr('givenName', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('familyName', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('formatted', 'string', false, false, false, 'readOnly', 'none'),
                    ],
                ],
                [
                    'name'          => 'emails',
                    'type'          => 'complex',
                    'multiValued'   => true,
                    'required'      => false,
                    'mutability'    => 'readWrite',
                    'returned'      => 'default',
                    'subAttributes' => [
                        self::attr('value', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('type', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('primary', 'boolean', false, false, false, 'readWrite', 'none'),
                    ],
                ],
                [
                    'name'          => 'phoneNumbers',
                    'type'          => 'complex',
                    'multiValued'   => true,
                    'required'      => false,
                    'mutability'    => 'readWrite',
                    'returned'      => 'default',
                    'subAttributes' => [
                        self::attr('value', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('type', 'string', false, false, false, 'readWrite', 'none'),
                    ],
                ],
                [
                    'name'          => 'addresses',
                    'type'          => 'complex',
                    'multiValued'   => true,
                    'required'      => false,
                    'mutability'    => 'readWrite',
                    'returned'      => 'default',
                    'subAttributes' => [
                        self::attr('type', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('streetAddress', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('locality', 'string', false, false, false, 'readWrite', 'none'),
                        self::attr('country', 'string', false, false, false, 'readWrite', 'none'),
                    ],
                ],
                [
                    'name'          => 'groups',
                    'type'          => 'complex',
                    'multiValued'   => true,
                    'required'      => false,
                    'mutability'    => 'readOnly',
                    'returned'      => 'default',
                    'subAttributes' => [
                        self::attr('value', 'string', false, false, false, 'readOnly', 'none'),
                        self::attr('display', 'string', false, false, false, 'readOnly', 'none'),
                    ],
                ],
            ],
            'meta' => [
                'resourceType' => 'Schema',
                'location'     => $base . '/Schemas/' . rawurlencode(self::SCHEMA_CORE_USER),
            ],
        ];
    }

    /**
     * Build the Enterprise 2.0 User extension schema descriptor.
     *
     * @param string $base Canonical SCIM base URL.
     * @return array<string, mixed>
     */
    private static function buildEnterpriseUserSchema(string $base): array
    {
        return [
            'schemas'     => [self::SCHEMA_DESCRIPTOR],
            'id'          => self::SCHEMA_ENTERPRISE_USER,
            'name'        => 'EnterpriseUser',
            'description' => 'SCIM 2.0 Enterprise User Extension',
            'attributes'  => [
                self::attr('employeeNumber', 'string', false, false, false, 'readWrite', 'none'),
                self::attr('costCenter', 'string', false, false, false, 'readWrite', 'none'),
                self::attr('organization', 'string', false, false, false, 'readWrite', 'none'),
                self::attr('division', 'string', false, false, false, 'readWrite', 'none'),
                self::attr('department', 'string', false, false, false, 'readWrite', 'none'),
            ],
            'meta' => [
                'resourceType' => 'Schema',
                'location'     => $base . '/Schemas/' . rawurlencode(self::SCHEMA_ENTERPRISE_USER),
            ],
        ];
    }

    /**
     * Build the Core 2.0 Group schema descriptor.
     *
     * @param string $base Canonical SCIM base URL.
     * @return array<string, mixed>
     */
    private static function buildCoreGroupSchema(string $base): array
    {
        return [
            'schemas'     => [self::SCHEMA_DESCRIPTOR],
            'id'          => self::SCHEMA_CORE_GROUP,
            'name'        => 'Group',
            'description' => 'SCIM 2.0 Core Group',
            'attributes'  => [
                self::attr('displayName', 'string', false, true, false, 'readWrite', 'server'),
                self::attr('externalId', 'string', false, false, true, 'readWrite', 'none'),
                [
                    'name'          => 'members',
                    'type'          => 'complex',
                    'multiValued'   => true,
                    'required'      => false,
                    'mutability'    => 'readWrite',
                    'returned'      => 'default',
                    'subAttributes' => [
                        self::attr('value', 'string', false, false, false, 'immutable', 'none'),
                        self::attr('display', 'string', false, false, false, 'readOnly', 'none'),
                    ],
                ],
            ],
            'meta' => [
                'resourceType' => 'Schema',
                'location'     => $base . '/Schemas/' . rawurlencode(self::SCHEMA_CORE_GROUP),
            ],
        ];
    }

    /**
     * Build a compact RFC 7643 §7 attribute descriptor entry.
     *
     * @param string $name       Attribute name.
     * @param string $type       Attribute primitive type.
     * @param bool   $multi      Multi-valued flag.
     * @param bool   $required   Required flag.
     * @param bool   $caseExact  Case-exact flag.
     * @param string $mutability Mutability keyword.
     * @param string $uniqueness Uniqueness keyword.
     * @return array<string, mixed>
     */
    private static function attr(
        string $name,
        string $type,
        bool $multi,
        bool $required,
        bool $caseExact,
        string $mutability,
        string $uniqueness
    ): array {
        return [
            'name'        => $name,
            'type'        => $type,
            'multiValued' => $multi,
            'required'    => $required,
            'caseExact'   => $caseExact,
            'mutability'  => $mutability,
            'returned'    => 'default',
            'uniqueness'  => $uniqueness,
        ];
    }
}
