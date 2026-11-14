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
 * ScimPatchProcessor enforces strict JSON size/depth limits and applies RFC 7644 §3.5.2
 * PatchOp mutations across User and Group resources, accommodating Microsoft Entra ID,
 * Okta, Keycloak, and Microsoft SCIM Validator interoperability quirks.
 *
 * @since 1.4.0
 */
final class ScimPatchProcessor
{
    /** @var string RFC 7644 §3.5.2 PatchOp schema URN. */
    public const SCHEMA_PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    /** @var int Maximum raw JSON request body size (256 KB). */
    public const MAX_BODY_BYTES = 262144;

    /** @var int Maximum JSON nesting depth. */
    public const MAX_JSON_DEPTH = 16;

    /** @var int Maximum PatchOp operations per request. */
    public const MAX_OPERATIONS = 100;

    /**
     * Decode a SCIM JSON request body enforcing 256 KB size and depth-16 bounds.
     *
     * @param string $rawBody Raw HTTP request body.
     * @return array<string, mixed>
     * @throws ScimException On empty, oversized (413), or malformed (400) JSON payloads.
     */
    public static function decodeJsonBody(string $rawBody): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new ScimException(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                'SCIM request body exceeds maximum allowed size of 256 KB.'
            );
        }

        if (trim($rawBody) === '') {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM request body must not be empty.',
                'invalidSyntax'
            );
        }

        try {
            $decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'Malformed or overly nested JSON payload.',
                'invalidSyntax'
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM payload root must be a JSON object.',
                'invalidSyntax'
            );
        }

        return $decoded;
    }

    /**
     * Apply an RFC 7644 PatchOp payload onto an existing serialized SCIM User resource.
     *
     * @param array<string, mixed> $currentUser Current serialized SCIM User state.
     * @param array<string, mixed> $patchBody   Decoded PatchOp JSON object.
     * @return array<string, mixed> Updated SCIM User JSON state ready for ScimAttributeMapper::extractUserPayload().
     * @throws ScimException On invalid PatchOp schema, immutable path, or unsupported path.
     */
    public static function applyToUserResource(array $currentUser, array $patchBody): array
    {
        $operations = self::validatePatchEnvelope($patchBody);
        $updated = $currentUser;

        foreach ($operations as $operation) {
            $op = strtolower(trim((string) ($operation['op'] ?? '')));
            if (!in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    sprintf('Unsupported SCIM PATCH op "%s".', (string) ($operation['op'] ?? '')),
                    'invalidSyntax'
                );
            }

            $path = trim((string) ($operation['path'] ?? ''));
            $value = $operation['value'] ?? null;

            if ($path === '') {
                if ($op === 'remove' || !is_array($value) || array_is_list($value)) {
                    throw new ScimException(
                        Response::HTTP_BAD_REQUEST,
                        'Pathless SCIM PATCH operation requires an associative object "value".',
                        'invalidSyntax'
                    );
                }
                foreach ($value as $subPath => $subVal) {
                    self::applySingleUserPath($updated, $op, (string) $subPath, $subVal);
                }
                continue;
            }

            self::applySingleUserPath($updated, $op, $path, $value);
        }

        return $updated;
    }

    /**
     * Extract structured Group mutations (displayName, externalId, and dynamic member deltas)
     * from an RFC 7644 PatchOp payload.
     *
     * @param array<string, mixed> $patchBody Decoded PatchOp JSON object.
     * @return array{
     *     displayName: string|null,
     *     externalId: string|null,
     *     externalIdSet: bool,
     *     replaceAllMembers: bool,
     *     clearAllMembers: bool,
     *     addMemberIds: array<int, int>,
     *     removeMemberIds: array<int, int>
     * }
     * @throws ScimException On invalid PatchOp envelope, immutable path, or invalid member reference.
     */
    public static function extractGroupOperations(array $patchBody): array
    {
        $operations = self::validatePatchEnvelope($patchBody);

        $displayName = null;
        $externalId = null;
        $externalIdSet = false;
        $replaceAllMembers = false;
        $clearAllMembers = false;
        $addMembers = [];
        $removeMembers = [];

        foreach ($operations as $operation) {
            $op = strtolower(trim((string) ($operation['op'] ?? '')));
            if (!in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Unsupported SCIM Group PATCH op.', 'invalidSyntax');
            }

            $rawPath = trim((string) ($operation['path'] ?? ''));
            $value = $operation['value'] ?? null;

            if ($rawPath === '' && is_array($value) && !array_is_list($value)) {
                foreach ($value as $k => $v) {
                    self::applySingleGroupOperation(
                        $op,
                        (string) $k,
                        $v,
                        $displayName,
                        $externalId,
                        $externalIdSet,
                        $replaceAllMembers,
                        $clearAllMembers,
                        $addMembers,
                        $removeMembers
                    );
                }
                continue;
            }

            self::applySingleGroupOperation(
                $op,
                $rawPath,
                $value,
                $displayName,
                $externalId,
                $externalIdSet,
                $replaceAllMembers,
                $clearAllMembers,
                $addMembers,
                $removeMembers
            );
        }

        return [
            'displayName'       => $displayName,
            'externalId'        => $externalId,
            'externalIdSet'     => $externalIdSet,
            'replaceAllMembers' => $replaceAllMembers,
            'clearAllMembers'   => $clearAllMembers,
            'addMemberIds'      => array_values($addMembers),
            'removeMemberIds'   => array_values($removeMembers),
        ];
    }

    /**
     * Validate the outer RFC 7644 PatchOp schema and Operations array bounds.
     *
     * @param array<string, mixed> $patchBody Decoded PatchOp payload.
     * @return array<int, array<string, mixed>>
     * @throws ScimException On invalid envelope or over-limit operation count.
     */
    private static function validatePatchEnvelope(array $patchBody): array
    {
        $schemas = $patchBody['schemas'] ?? [];
        $hasPatchSchema = false;
        if (is_array($schemas)) {
            foreach ($schemas as $uri) {
                if (is_string($uri) && strcasecmp($uri, self::SCHEMA_PATCH_OP) === 0) {
                    $hasPatchSchema = true;
                    break;
                }
            }
        }
        if (!$hasPatchSchema) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM PATCH request must declare schema ' . self::SCHEMA_PATCH_OP . '.',
                'invalidSyntax'
            );
        }

        $operations = null;
        foreach ($patchBody as $k => $v) {
            if (is_string($k) && strcasecmp($k, 'Operations') === 0 && is_array($v)) {
                $operations = $v;
                break;
            }
        }

        if (!is_array($operations) || empty($operations)) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM PATCH request must contain a non-empty "Operations" array.',
                'invalidSyntax'
            );
        }

        if (count($operations) > self::MAX_OPERATIONS) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM PATCH request exceeds maximum allowed operations count.',
                'tooMany'
            );
        }

        $normalized = [];
        foreach ($operations as $item) {
            if (!is_array($item)) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Each SCIM PATCH operation must be an object.', 'invalidSyntax');
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Apply a single path-targeted User PATCH operation onto $user.
     *
     * @param array<string, mixed> $user  Target SCIM user array (by reference).
     * @param string               $op    Normalized op ('add', 'replace', 'remove').
     * @param string               $path  Attribute path expression.
     * @param mixed                $value Operation value.
     * @return void
     * @throws ScimException On immutable or invalid attribute path.
     */
    private static function applySingleUserPath(array &$user, string $op, string $path, mixed $value): void
    {
        $trimmedPath = trim($path);
        $lowerPath = strtolower($trimmedPath);

        if ($lowerPath === 'id' || str_starts_with($lowerPath, 'meta') || $lowerPath === 'groups') {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                sprintf('SCIM User attribute "%s" is immutable or read-only.', $trimmedPath),
                'mutability'
            );
        }

        $entPrefix = strtolower(ScimDiscoveryService::SCHEMA_ENTERPRISE_USER);
        if ($lowerPath === $entPrefix && is_array($value)) {
            $existingEnt = $user[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER] ?? [];
            $user[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER] = ($op === 'remove')
                ? []
                : array_merge(is_array($existingEnt) ? $existingEnt : [], $value);
            return;
        }
        if (str_starts_with($lowerPath, $entPrefix . ':')) {
            $entAttr = substr($trimmedPath, strlen($entPrefix) + 1);
            $user[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER] = $user[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER] ?? [];
            $user[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER][$entAttr] = ($op === 'remove') ? '' : $value;
            return;
        }

        $corePrefix = strtolower(ScimDiscoveryService::SCHEMA_CORE_USER) . ':';
        if (str_starts_with($lowerPath, $corePrefix)) {
            $trimmedPath = substr($trimmedPath, strlen($corePrefix));
            $lowerPath = strtolower($trimmedPath);
        }

        $compactPath = (string) preg_replace('/\s+/', ' ', $lowerPath);

        if (preg_match('/^emails\[type eq "([^"]+)"\](\.value)?$/i', $compactPath, $m) === 1) {
            self::upsertTypedMultiValue($user, 'emails', strtolower($m[1]), $op, $value, true);
            return;
        }
        if (preg_match('/^phonenumbers\[type eq "([^"]+)"\](\.value)?$/i', $compactPath, $m) === 1) {
            self::upsertTypedMultiValue($user, 'phoneNumbers', strtolower($m[1]), $op, $value, false);
            return;
        }
        if (preg_match('/^addresses\[type eq "([^"]+)"\]\.([a-z]+)$/i', $compactPath, $m) === 1) {
            $addrField = self::canonicalAddressSubField($m[2]);
            self::upsertTypedAddressField($user, strtolower($m[1]), $addrField, $op === 'remove' ? '' : (string) $value);
            return;
        }

        switch ($compactPath) {
            case 'username':
                if ($op === 'remove' || trim((string) $value) === '') {
                    throw new ScimException(Response::HTTP_BAD_REQUEST, 'Cannot remove required attribute "userName".', 'invalidValue');
                }
                $user['userName'] = (string) $value;
                return;
            case 'externalid':
                $user['externalId'] = ($op === 'remove') ? '' : (string) $value;
                return;
            case 'displayname':
                $user['displayName'] = ($op === 'remove') ? '' : (string) $value;
                return;
            case 'title':
                $user['title'] = ($op === 'remove') ? '' : (string) $value;
                return;
            case 'active':
                if ($op === 'remove') {
                    throw new ScimException(Response::HTTP_BAD_REQUEST, 'Cannot remove attribute "active"; set true or false.', 'invalidValue');
                }
                $user['active'] = ScimAttributeMapper::parseBoolean($value);
                return;
            case 'name':
                if ($op === 'remove') {
                    $user['name'] = ['givenName' => '', 'familyName' => ''];
                } elseif (is_array($value)) {
                    $user['name'] = array_merge($user['name'] ?? [], $value);
                }
                return;
            case 'name.givenname':
                $user['name']['givenName'] = ($op === 'remove') ? '' : (string) $value;
                return;
            case 'name.familyname':
                $user['name']['familyName'] = ($op === 'remove') ? '' : (string) $value;
                return;
            case 'name.formatted':
                $user['name']['formatted'] = ($op === 'remove') ? '' : (string) $value;
                return;
            case 'emails':
                $user['emails'] = ($op === 'remove' || !is_array($value)) ? [] : $value;
                return;
            case 'phonenumbers':
                $user['phoneNumbers'] = ($op === 'remove' || !is_array($value)) ? [] : $value;
                return;
            case 'addresses':
                $user['addresses'] = ($op === 'remove' || !is_array($value)) ? [] : $value;
                return;
            case 'addresses.streetaddress':
            case 'addresses.locality':
            case 'addresses.country':
                $sub = substr($compactPath, strlen('addresses.'));
                self::upsertTypedAddressField($user, 'work', self::canonicalAddressSubField($sub), $op === 'remove' ? '' : (string) $value);
                return;
            default:
                /**
                 * Ignore benign unmapped optional RFC 7643 Core User attributes (such as preferredLanguage,
                 * locale, timezone, nickName, profileUrl, roles) rather than failing Entra/Okta sync runs,
                 * while rejecting syntactically invalid paths containing unknown filter brackets.
                 */
                if (str_contains($compactPath, '[')) {
                    throw new ScimException(
                        Response::HTTP_BAD_REQUEST,
                        sprintf('Unsupported SCIM PATCH path expression "%s".', $trimmedPath),
                        'invalidPath'
                    );
                }
                return;
        }
    }

    /**
     * Apply a single Group PATCH operation into the accumulated Group delta references.
     *
     * @param string            $op                Normalized op ('add', 'replace', 'remove').
     * @param string            $rawPath           Raw path string.
     * @param mixed             $value             Operation value.
     * @param string|null       $displayName       Updated displayName ref.
     * @param string|null       $externalId        Updated externalId ref.
     * @param bool              $externalIdSet     Whether externalId was modified ref.
     * @param bool              $replaceAllMembers Whether members list is replaced wholesale ref.
     * @param bool              $clearAllMembers   Whether all dynamic members are cleared ref.
     * @param array<int, int>   $addMembers        Added user IDs keyed by ID ref.
     * @param array<int, int>   $removeMembers     Removed user IDs keyed by ID ref.
     * @return void
     * @throws ScimException On immutable path or invalid member target.
     */
    private static function applySingleGroupOperation(
        string $op,
        string $rawPath,
        mixed $value,
        ?string &$displayName,
        ?string &$externalId,
        bool &$externalIdSet,
        bool &$replaceAllMembers,
        bool &$clearAllMembers,
        array &$addMembers,
        array &$removeMembers
    ): void {
        $path = trim($rawPath);
        $coreGroupPrefix = strtolower(ScimDiscoveryService::SCHEMA_CORE_GROUP) . ':';
        if (str_starts_with(strtolower($path), $coreGroupPrefix)) {
            $path = substr($path, strlen($coreGroupPrefix));
        }
        $compact = (string) preg_replace('/\s+/', ' ', strtolower($path));

        if ($compact === 'id' || str_starts_with($compact, 'meta')) {
            throw new ScimException(Response::HTTP_BAD_REQUEST, 'Group attribute is immutable.', 'mutability');
        }

        if ($compact === 'displayname') {
            if ($op === 'remove' || trim((string) $value) === '') {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Group "displayName" cannot be empty.', 'invalidValue');
            }
            $displayName = trim((string) $value);
            return;
        }

        if ($compact === 'externalid') {
            $externalId = ($op === 'remove') ? '' : trim((string) $value);
            $externalIdSet = true;
            return;
        }

        if ($compact === 'members') {
            if ($op === 'remove' && ($value === null || $value === [])) {
                $clearAllMembers = true;
                $addMembers = [];
                return;
            }
            if (!is_array($value)) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Group "members" value must be an array.', 'invalidValue');
            }
            if ($op === 'replace') {
                $replaceAllMembers = true;
                $addMembers = [];
                foreach ($value as $memberItem) {
                    $uid = ScimAttributeMapper::extractMemberUserId($memberItem);
                    $addMembers[$uid] = $uid;
                }
                return;
            }
            foreach ($value as $memberItem) {
                $uid = ScimAttributeMapper::extractMemberUserId($memberItem);
                if ($op === 'add') {
                    $addMembers[$uid] = $uid;
                    unset($removeMembers[$uid]);
                } elseif ($op === 'remove') {
                    $removeMembers[$uid] = $uid;
                    unset($addMembers[$uid]);
                }
            }
            return;
        }

        if ($op === 'remove' && preg_match('/^members\[value eq "?(\d+)"?\]$/i', $compact, $m) === 1) {
            $uid = (int) $m[1];
            if ($uid <= 0) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Invalid member ID in path filter.', 'noTarget');
            }
            $removeMembers[$uid] = $uid;
            unset($addMembers[$uid]);
            return;
        }

        if ($op === 'remove' && preg_match('#^members\[\$ref eq "[^"]*/users/(\d+)"\]$#i', $compact, $m) === 1) {
            $uid = (int) $m[1];
            if ($uid <= 0) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Invalid member $ref in path filter.', 'noTarget');
            }
            $removeMembers[$uid] = $uid;
            unset($addMembers[$uid]);
            return;
        }

        throw new ScimException(
            Response::HTTP_BAD_REQUEST,
            sprintf('Unsupported SCIM Group PATCH path "%s".', $rawPath),
            'invalidPath'
        );
    }

    /**
     * Upsert or remove an entry in a typed multi-valued SCIM array (emails or phoneNumbers).
     *
     * @param array<string, mixed> $user        Target SCIM user array (by reference).
     * @param string               $collection  Collection key ('emails' or 'phoneNumbers').
     * @param string               $type        Lowercase type ('work', 'mobile', 'other').
     * @param string               $op          Normalized op ('add', 'replace', 'remove').
     * @param mixed                $value       Scalar or entry object value.
     * @param bool                 $markPrimary Whether work entry should default to primary when first.
     * @return void
     */
    private static function upsertTypedMultiValue(
        array &$user,
        string $collection,
        string $type,
        string $op,
        mixed $value,
        bool $markPrimary
    ): void {
        $list = isset($user[$collection]) && is_array($user[$collection]) ? array_values($user[$collection]) : [];
        if ($op === 'remove') {
            $user[$collection] = array_values(array_filter(
                $list,
                static fn($item) => !is_array($item) || strtolower((string) ($item['type'] ?? '')) !== $type
            ));
            return;
        }

        $strVal = is_array($value) ? trim((string) ($value['value'] ?? '')) : trim((string) $value);
        $updated = false;
        foreach ($list as $idx => $item) {
            if (is_array($item) && strtolower((string) ($item['type'] ?? '')) === $type) {
                $list[$idx]['value'] = $strVal;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $newEntry = ['value' => $strVal, 'type' => $type];
            if ($markPrimary && empty($list)) {
                $newEntry['primary'] = true;
            }
            $list[] = $newEntry;
        }

        $user[$collection] = $list;
    }

    /**
     * Upsert a sub-attribute on a typed SCIM address object.
     *
     * @param array<string, mixed> $user      Target SCIM user array (by reference).
     * @param string               $type      Address type (e.g. 'work').
     * @param string               $subField  Canonical sub-attribute ('streetAddress', 'locality', 'country').
     * @param string               $val       New scalar value.
     * @return void
     */
    private static function upsertTypedAddressField(array &$user, string $type, string $subField, string $val): void
    {
        $list = isset($user['addresses']) && is_array($user['addresses']) ? array_values($user['addresses']) : [];
        $updated = false;
        foreach ($list as $idx => $addr) {
            if (is_array($addr) && strtolower((string) ($addr['type'] ?? 'work')) === $type) {
                $list[$idx][$subField] = $val;
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $list[] = ['type' => $type, $subField => $val];
        }
        $user['addresses'] = $list;
    }

    /**
     * Map lowercase address sub-attribute names to canonical RFC 7643 camelCase keys.
     *
     * @param string $lower Lowercase sub-attribute name.
     * @return string
     */
    private static function canonicalAddressSubField(string $lower): string
    {
        return match (strtolower($lower)) {
            'streetaddress' => 'streetAddress',
            'locality'      => 'locality',
            'country'       => 'country',
            default         => $lower,
        };
    }
}
