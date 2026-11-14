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

use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * ScimAttributeMapper translates deterministically between standardized
 * RFC 7643 Core / Enterprise 2.0 User & Group JSON payloads and GLPI User,
 * Group, and RuleSamlCollection input dictionaries without coupling to SAML ClaimMap.
 *
 * @since 1.4.0
 */
final class ScimAttributeMapper
{
    /**
     * Extract normalized GLPI user columns, emails, externalId, active flag,
     * lookup fields (jobtitle, address), and RuleSaml criteria from a SCIM User payload.
     *
     * @param array<string, mixed> $scimBody        Decoded SCIM User JSON object.
     * @param bool                 $requireUserName Whether userName must be present and non-empty.
     * @return array{
     *     user_fields: array<string, mixed>,
     *     emails: array<int, string>,
     *     primary_email: string,
     *     external_id: string|null,
     *     active: bool|null,
     *     jobtitle: string,
     *     address: array{street: string, city: string, country: string},
     *     rule_input: array<string, mixed>
     * }
     * @throws ScimException On missing required userName or malformed schema types.
     */
    public static function extractUserPayload(array $scimBody, bool $requireUserName = true): array
    {
        if (isset($scimBody['schemas'])) {
            if (!is_array($scimBody['schemas'])) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'SCIM schemas attribute must be an array.', 'invalidSyntax');
            }
            $hasCoreUser = false;
            foreach ($scimBody['schemas'] as $schemaUri) {
                if (is_string($schemaUri) && strcasecmp($schemaUri, ScimDiscoveryService::SCHEMA_CORE_USER) === 0) {
                    $hasCoreUser = true;
                    break;
                }
            }
            if (!$hasCoreUser) {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    'SCIM User payload must declare schema ' . ScimDiscoveryService::SCHEMA_CORE_USER . '.',
                    'invalidSyntax'
                );
            }
        }

        $userFields = [];
        $ruleInput = [];

        $userName = self::cleanString($scimBody['userName'] ?? '', 255);
        if ($requireUserName && $userName === '') {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM User attribute "userName" is required and must be non-empty.',
                'invalidValue'
            );
        }
        if ($userName !== '') {
            $userFields[SamlUser::NAME] = $userName;
            $ruleInput[SamlUser::NAME] = $userName;
        }

        $externalId = null;
        if (array_key_exists('externalId', $scimBody)) {
            $cleanedExt = self::cleanString($scimBody['externalId'], 255);
            $externalId = $cleanedExt !== '' ? $cleanedExt : '';
        }

        $active = null;
        if (array_key_exists('active', $scimBody)) {
            $active = self::parseBoolean($scimBody['active']);
            $userFields[SamlUser::ACTIVE] = $active ? 1 : 0;
        }

        $nameBlock = isset($scimBody['name']) && is_array($scimBody['name']) ? $scimBody['name'] : [];
        $givenName = self::cleanString($nameBlock['givenName'] ?? '', 255);
        $familyName = self::cleanString($nameBlock['familyName'] ?? '', 255);
        $displayName = self::cleanString($scimBody['displayName'] ?? ($nameBlock['formatted'] ?? ''), 255);

        if ($givenName !== '') {
            $userFields[SamlUser::FIRSTNAME] = $givenName;
        }
        if ($familyName !== '') {
            $userFields[SamlUser::REALNAME] = $familyName;
        } elseif ($givenName === '' && $displayName !== '') {
            $userFields[SamlUser::REALNAME] = $displayName;
        }

        [$primaryEmail, $allEmails] = self::extractEmails($scimBody['emails'] ?? null, $userName);
        if ($primaryEmail !== '') {
            $ruleInput[SamlUser::EMAIL] = $primaryEmail;
        }

        $phones = self::extractPhones($scimBody['phoneNumbers'] ?? null);
        foreach ($phones as $col => $val) {
            $userFields[$col] = $val;
        }

        $jobTitle = self::cleanString($scimBody['title'] ?? '', 255);
        if ($jobTitle !== '') {
            $ruleInput[SamlUser::SAMLJOBTITLE] = $jobTitle;
        }

        $address = self::extractWorkAddress($scimBody['addresses'] ?? null);
        if ($address['street'] !== '') {
            $ruleInput[SamlUser::SAMLSTREET] = $address['street'];
        }
        if ($address['city'] !== '') {
            $ruleInput[SamlUser::SAMLCITY] = $address['city'];
        }
        if ($address['country'] !== '') {
            $ruleInput[SamlUser::SAMLCOUNTRY] = $address['country'];
        }

        $enterprise = self::findEnterpriseExtension($scimBody);
        $employeeNumber = self::cleanString($enterprise['employeeNumber'] ?? '', 255);
        if ($employeeNumber !== '') {
            $userFields[SamlUser::REGISTRATION_NUMBER] = $employeeNumber;
            $ruleInput[SamlUser::SCIM_EMPLOYEE_NUMBER] = $employeeNumber;
        }

        $enterpriseCriteriaMap = [
            'department'   => SamlUser::SCIM_DEPARTMENT,
            'organization' => SamlUser::SCIM_ORGANIZATION,
            'division'     => SamlUser::SCIM_DIVISION,
            'costCenter'   => SamlUser::SCIM_COST_CENTER,
        ];
        foreach ($enterpriseCriteriaMap as $scimKey => $ruleKey) {
            $val = self::cleanString($enterprise[$scimKey] ?? '', 255);
            if ($val !== '') {
                $ruleInput[$ruleKey] = $val;
            }
        }

        return [
            'user_fields'   => $userFields,
            'emails'        => $allEmails,
            'primary_email' => $primaryEmail,
            'external_id'   => $externalId,
            'active'        => $active,
            'jobtitle'      => $jobTitle,
            'address'       => $address,
            'rule_input'    => $ruleInput,
        ];
    }

    /**
     * Serialize a GLPI user record and its auxiliary links into an RFC 7643 User resource.
     *
     * @param array<string, mixed>             $glpiUser    Row from glpi_users.
     * @param array<int, string>               $emails      Ordered list of user email addresses (primary first).
     * @param string|null                      $externalId  Optional ScimLink externalId.
     * @param array<int, array<string, mixed>> $groups      Read-only group membership entries ([value, display]).
     * @param string                           $jobTitle    Resolved UserTitle name if present.
     * @param string                           $baseScimUrl Canonical SCIM v2 base URL without trailing slash.
     * @return array<string, mixed>
     */
    public static function toScimUserResource(
        array $glpiUser,
        array $emails,
        ?string $externalId,
        array $groups,
        string $jobTitle,
        string $baseScimUrl
    ): array {
        $base = rtrim($baseScimUrl, '/');
        $id = (string) ($glpiUser[SamlUser::USERID] ?? '');
        $givenName = trim((string) ($glpiUser[SamlUser::FIRSTNAME] ?? ''));
        $familyName = trim((string) ($glpiUser[SamlUser::REALNAME] ?? ''));
        $formattedName = trim($givenName . ' ' . $familyName);
        if ($formattedName === '') {
            $formattedName = (string) ($glpiUser[SamlUser::NAME] ?? '');
        }

        $employeeNumber = trim((string) ($glpiUser[SamlUser::REGISTRATION_NUMBER] ?? ''));
        $schemas = [ScimDiscoveryService::SCHEMA_CORE_USER];
        if ($employeeNumber !== '') {
            $schemas[] = ScimDiscoveryService::SCHEMA_ENTERPRISE_USER;
        }

        $resource = [
            'schemas'  => $schemas,
            'id'       => $id,
            'userName' => (string) ($glpiUser[SamlUser::NAME] ?? ''),
        ];

        if ($externalId !== null && $externalId !== '') {
            $resource['externalId'] = $externalId;
        }

        $resource['name'] = [
            'givenName'  => $givenName,
            'familyName' => $familyName,
            'formatted'  => $formattedName,
        ];
        $resource['displayName'] = $formattedName;
        $resource['active'] = ((int) ($glpiUser[SamlUser::ACTIVE] ?? 0) === 1) && ((int) ($glpiUser[SamlUser::DELETED] ?? 0) === 0);

        $emailEntries = [];
        foreach (array_values($emails) as $idx => $addr) {
            if ($addr !== '') {
                $emailEntries[] = [
                    'value'   => $addr,
                    'type'    => 'work',
                    'primary' => ($idx === 0),
                ];
            }
        }
        $resource['emails'] = $emailEntries;

        $phoneEntries = [];
        if (!empty($glpiUser[SamlUser::PHONE])) {
            $phoneEntries[] = ['value' => (string) $glpiUser[SamlUser::PHONE], 'type' => 'work'];
        }
        if (!empty($glpiUser[SamlUser::MOBILE])) {
            $phoneEntries[] = ['value' => (string) $glpiUser[SamlUser::MOBILE], 'type' => 'mobile'];
        }
        if (!empty($glpiUser[SamlUser::PHONE2])) {
            $phoneEntries[] = ['value' => (string) $glpiUser[SamlUser::PHONE2], 'type' => 'other'];
        }
        if (!empty($phoneEntries)) {
            $resource['phoneNumbers'] = $phoneEntries;
        }

        if ($jobTitle !== '') {
            $resource['title'] = $jobTitle;
        }

        $resource['groups'] = array_values($groups);

        if ($employeeNumber !== '') {
            $resource[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER] = [
                'employeeNumber' => $employeeNumber,
            ];
        }

        $resource['meta'] = [
            'resourceType' => 'User',
            'created'      => self::toIso8601((string) ($glpiUser['date_creation'] ?? '')),
            'lastModified' => self::toIso8601((string) ($glpiUser['date_mod'] ?? '')),
            'location'     => $base . '/Users/' . rawurlencode($id),
        ];

        return $resource;
    }

    /**
     * Extract displayName, externalId, and unique member user IDs from a SCIM Group payload.
     *
     * @param array<string, mixed> $scimBody           Decoded SCIM Group JSON object.
     * @param bool                 $requireDisplayName Whether displayName is mandatory.
     * @return array{display_name: string, external_id: string|null, member_ids: array<int, int>}
     * @throws ScimException On missing required displayName or malformed members array.
     */
    public static function extractGroupPayload(array $scimBody, bool $requireDisplayName = true): array
    {
        if (isset($scimBody['schemas']) && is_array($scimBody['schemas'])) {
            $hasCoreGroup = false;
            foreach ($scimBody['schemas'] as $schemaUri) {
                if (is_string($schemaUri) && strcasecmp($schemaUri, ScimDiscoveryService::SCHEMA_CORE_GROUP) === 0) {
                    $hasCoreGroup = true;
                    break;
                }
            }
            if (!$hasCoreGroup) {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    'SCIM Group payload must declare schema ' . ScimDiscoveryService::SCHEMA_CORE_GROUP . '.',
                    'invalidSyntax'
                );
            }
        }

        $displayName = self::cleanString($scimBody['displayName'] ?? '', 255);
        if ($requireDisplayName && $displayName === '') {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM Group attribute "displayName" is required and must be non-empty.',
                'invalidValue'
            );
        }

        $externalId = null;
        if (array_key_exists('externalId', $scimBody)) {
            $externalId = self::cleanString($scimBody['externalId'], 255);
        }

        $memberIds = [];
        if (isset($scimBody['members'])) {
            if (!is_array($scimBody['members'])) {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'SCIM Group "members" must be an array.', 'invalidSyntax');
            }
            foreach ($scimBody['members'] as $member) {
                $uid = self::extractMemberUserId($member);
                if ($uid > 0) {
                    $memberIds[$uid] = $uid;
                }
            }
        }

        return [
            'display_name' => $displayName,
            'external_id'  => $externalId,
            'member_ids'   => array_values($memberIds),
        ];
    }

    /**
     * Serialize a GLPI Group row and its dynamic IdP-owned members into an RFC 7643 Group resource.
     *
     * @param array<string, mixed>             $glpiGroup   Row from glpi_groups.
     * @param string|null                      $externalId  Optional ScimLink externalId.
     * @param array<int, array<string, mixed>> $members     Member entries ([value, display]).
     * @param string                           $baseScimUrl Canonical SCIM v2 base URL without trailing slash.
     * @return array<string, mixed>
     */
    public static function toScimGroupResource(
        array $glpiGroup,
        ?string $externalId,
        array $members,
        string $baseScimUrl
    ): array {
        $base = rtrim($baseScimUrl, '/');
        $id = (string) ($glpiGroup['id'] ?? '');

        $resource = [
            'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_GROUP],
            'id'          => $id,
            'displayName' => (string) ($glpiGroup['name'] ?? ''),
        ];

        if ($externalId !== null && $externalId !== '') {
            $resource['externalId'] = $externalId;
        }

        $mergedMembers = [];
        foreach ($members as $member) {
            $val = (string) ($member['value'] ?? '');
            if ($val !== '') {
                $entry = [
                    'value' => $val,
                    '$ref'  => $base . '/Users/' . rawurlencode($val),
                ];
                if (!empty($member['display'])) {
                    $entry['display'] = (string) $member['display'];
                }
                $mergedMembers[] = $entry;
            }
        }
        $resource['members'] = $mergedMembers;

        $resource['meta'] = [
            'resourceType' => 'Group',
            'created'      => self::toIso8601((string) ($glpiGroup['date_creation'] ?? '')),
            'lastModified' => self::toIso8601((string) ($glpiGroup['date_mod'] ?? '')),
            'location'     => $base . '/Groups/' . rawurlencode($id),
        ];

        return $resource;
    }

    /**
     * Parse a SCIM boolean value, accepting Entra ID string booleans ("True" / "False").
     *
     * @param mixed $raw Raw JSON boolean or string.
     * @return bool
     * @throws ScimException On invalid boolean value.
     */
    public static function parseBoolean(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $lower = strtolower(trim($raw));
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0') {
                return false;
            }
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }

        throw new ScimException(
            Response::HTTP_BAD_REQUEST,
            'Invalid boolean attribute value in SCIM payload.',
            'invalidValue'
        );
    }

    /**
     * Extract a positive integer user ID from a SCIM Group member descriptor.
     *
     * @param mixed $member Member array or scalar value.
     * @return int
     * @throws ScimException On invalid member reference.
     */
    public static function extractMemberUserId(mixed $member): int
    {
        $rawVal = '';
        if (is_array($member)) {
            if (isset($member['value'])) {
                $rawVal = trim((string) $member['value']);
            } elseif (isset($member['$ref']) && is_string($member['$ref'])) {
                if (preg_match('#/Users/(\d+)\s*$#i', $member['$ref'], $m) === 1) {
                    $rawVal = $m[1];
                }
            }
        } elseif (is_string($member) || is_int($member)) {
            $rawVal = trim((string) $member);
        }

        if ($rawVal === '' || preg_match('/^\d+$/', $rawVal) !== 1 || (int) $rawVal <= 0) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM Group member "value" must reference a valid positive User ID.',
                'invalidValue'
            );
        }

        return (int) $rawVal;
    }

    /**
     * Extract ordered unique valid email addresses and identify the primary email.
     *
     * @param mixed  $emailsRaw Raw SCIM emails array.
     * @param string $userName  Fallback userName when formatted as email.
     * @return array{0: string, 1: array<int, string>}
     */
    private static function extractEmails(mixed $emailsRaw, string $userName): array
    {
        $primary = '';
        $workFallback = '';
        $all = [];

        if (is_array($emailsRaw)) {
            foreach ($emailsRaw as $entry) {
                $candidate = '';
                $isPrimary = false;
                $type = '';
                if (is_array($entry)) {
                    $candidate = strtolower(trim((string) ($entry['value'] ?? '')));
                    $isPrimary = !empty($entry['primary']) && self::parseBoolean($entry['primary']);
                    $type = strtolower(trim((string) ($entry['type'] ?? '')));
                } elseif (is_string($entry)) {
                    $candidate = strtolower(trim($entry));
                }

                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $all[$candidate] = $candidate;
                    if ($isPrimary && $primary === '') {
                        $primary = $candidate;
                    }
                    if ($type === 'work' && $workFallback === '') {
                        $workFallback = $candidate;
                    }
                }
            }
        }

        if ($primary === '') {
            $primary = $workFallback !== '' ? $workFallback : (reset($all) ?: '');
        }

        if ($primary === '' && filter_var($userName, FILTER_VALIDATE_EMAIL) !== false) {
            $primary = strtolower($userName);
            $all[$primary] = $primary;
        }

        $ordered = [];
        if ($primary !== '') {
            $ordered[] = $primary;
        }
        foreach ($all as $email) {
            if ($email !== $primary) {
                $ordered[] = $email;
            }
        }

        return [$primary, $ordered];
    }

    /**
     * Extract phone, mobile, and phone2 columns from SCIM phoneNumbers array.
     *
     * @param mixed $phonesRaw Raw SCIM phoneNumbers array.
     * @return array<string, string>
     */
    private static function extractPhones(mixed $phonesRaw): array
    {
        $result = [];
        if (!is_array($phonesRaw)) {
            return $result;
        }

        foreach ($phonesRaw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $val = self::cleanString($entry['value'] ?? '', 64);
            if ($val === '') {
                continue;
            }
            $type = strtolower(trim((string) ($entry['type'] ?? 'work')));
            if ($type === 'mobile' && !isset($result[SamlUser::MOBILE])) {
                $result[SamlUser::MOBILE] = $val;
            } elseif ($type === 'work' && !isset($result[SamlUser::PHONE])) {
                $result[SamlUser::PHONE] = $val;
            } elseif (!isset($result[SamlUser::PHONE2])) {
                $result[SamlUser::PHONE2] = $val;
            }
        }

        return $result;
    }

    /**
     * Extract work/primary street, city, and country from SCIM addresses array.
     *
     * @param mixed $addressesRaw Raw SCIM addresses array.
     * @return array{street: string, city: string, country: string}
     */
    private static function extractWorkAddress(mixed $addressesRaw): array
    {
        $empty = ['street' => '', 'city' => '', 'country' => ''];
        if (!is_array($addressesRaw) || empty($addressesRaw)) {
            return $empty;
        }

        $chosen = null;
        foreach ($addressesRaw as $addr) {
            if (!is_array($addr)) {
                continue;
            }
            if (!empty($addr['primary']) || strtolower((string) ($addr['type'] ?? '')) === 'work') {
                $chosen = $addr;
                break;
            }
            if ($chosen === null) {
                $chosen = $addr;
            }
        }

        if (!is_array($chosen)) {
            return $empty;
        }

        return [
            'street'  => self::cleanString($chosen['streetAddress'] ?? '', 255),
            'city'    => self::cleanString($chosen['locality'] ?? '', 255),
            'country' => self::cleanString($chosen['country'] ?? '', 255),
        ];
    }

    /**
     * Locate the RFC 7643 Enterprise 2.0 User extension block case-insensitively.
     *
     * @param array<string, mixed> $scimBody Decoded SCIM User JSON object.
     * @return array<string, mixed>
     */
    private static function findEnterpriseExtension(array $scimBody): array
    {
        foreach ($scimBody as $key => $value) {
            if (is_string($key) && is_array($value) && strcasecmp($key, ScimDiscoveryService::SCHEMA_ENTERPRISE_USER) === 0) {
                return $value;
            }
        }

        return [];
    }

    /**
     * Sanitize a scalar attribute string, stripping ASCII control characters and bounding length.
     *
     * @param mixed $value     Raw input scalar.
     * @param int   $maxLength Maximum UTF-8 character length.
     * @return string
     */
    private static function cleanString(mixed $value, int $maxLength): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value));
        if (!is_string($cleaned) || $cleaned === '') {
            return '';
        }

        return mb_substr($cleaned, 0, $maxLength);
    }

    /**
     * Convert a GLPI SQL datetime string into an RFC 3339 / ISO-8601 timestamp.
     *
     * @param string $sqlDateTime Datetime string from GLPI row.
     * @return string
     */
    private static function toIso8601(string $sqlDateTime): string
    {
        if ($sqlDateTime !== '') {
            $ts = strtotime($sqlDateTime);
            if ($ts !== false) {
                return gmdate('Y-m-d\TH:i:s\Z', $ts);
            }
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
