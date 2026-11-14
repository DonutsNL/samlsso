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
 *  @version    1.4.0
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

    use GlpiPlugin\Samlsso\Scim\ScimAttributeMapper;
    use GlpiPlugin\Samlsso\Scim\ScimDiscoveryService;
    use GlpiPlugin\Samlsso\Scim\ScimException;
    use GlpiPlugin\Samlsso\Scim\ScimFilterParser;
    use GlpiPlugin\Samlsso\Scim\ScimPatchProcessor;

    /**
     * ScimProtocolTest validates local RFC 7643 / RFC 7644 discovery, attribute mapping,
     * bounded whitelist filter parsing, and Entra ID / Okta PATCH quirks.
     */
    class ScimProtocolTest extends TestHarness
    {
        /**
         * Verify ServiceProviderConfig, ResourceTypes, and Schemas respect scim_group_policy.
         *
         * @return void
         * @throws \Exception
         */
        public function testDiscoveryEndpointsRespectGroupPolicy(): void
        {
            $base = 'https://glpi.local/plugins/samlsso/front/scim/v2/5';
            $spc = ScimDiscoveryService::getServiceProviderConfig($base);
            if (($spc['patch']['supported'] ?? false) !== true || ($spc['bulk']['supported'] ?? true) !== false) {
                throw new \Exception('Unexpected ServiceProviderConfig capabilities.');
            }

            $rtEnabled = ScimDiscoveryService::getResourceTypes($base, 'create_scoped');
            if (count($rtEnabled) !== 2) {
                throw new \Exception('Expected both User and Group ResourceTypes when scim_group_policy=create_scoped.');
            }

            $rtDisabled = ScimDiscoveryService::getResourceTypes($base, 'disabled');
            if (count($rtDisabled) !== 1 || ($rtDisabled[0]['id'] ?? '') !== 'User') {
                throw new \Exception('Expected Group ResourceType to be omitted when scim_group_policy=disabled.');
            }

            $schemasDisabled = ScimDiscoveryService::getSchemas($base, 'disabled');
            foreach ($schemasDisabled as $schema) {
                if (($schema['id'] ?? '') === ScimDiscoveryService::SCHEMA_CORE_GROUP) {
                    throw new \Exception('Group schema must be omitted when scim_group_policy=disabled.');
                }
            }

            echo "✅ SCIM Protocol: Discovery endpoints and scim_group_policy gating verified\n";
        }

        /**
         * Verify Core + Enterprise User mapping and round-trip serialization.
         *
         * @return void
         * @throws \Exception
         */
        public function testAttributeMapperCoreAndEnterpriseUser(): void
        {
            $payload = [
                'schemas' => [
                    'urn:ietf:params:scim:schemas:core:2.0:User',
                    'urn:ietf:params:scim:schemas:extension:enterprise:2.0:user',
                ],
                'userName'   => 'jdoe@corp.example.com',
                'externalId' => 'entra-oid-1234',
                'active'     => 'True',
                'name'       => [
                    'givenName'  => 'Jane',
                    'familyName' => 'Doe',
                ],
                'title'      => 'Senior Engineer',
                'emails'     => [
                    ['value' => 'jane.secondary@corp.example.com', 'type' => 'other', 'primary' => false],
                    ['value' => 'jdoe@corp.example.com', 'type' => 'work', 'primary' => true],
                ],
                'phoneNumbers' => [
                    ['value' => '+31201234567', 'type' => 'work'],
                    ['value' => '+31612345678', 'type' => 'mobile'],
                ],
                'addresses' => [
                    [
                        'type'          => 'work',
                        'streetAddress' => 'Keizersgracht 1',
                        'locality'      => 'Amsterdam',
                        'country'       => 'NL',
                    ],
                ],
                'urn:ietf:params:scim:schemas:extension:enterprise:2.0:user' => [
                    'employeeNumber' => 'EMP-9001',
                    'department'     => 'Platform Security',
                    'organization'   => 'QuinQuies',
                    'division'       => 'Cloud',
                    'costCenter'     => 'CC-42',
                ],
            ];

            $mapped = ScimAttributeMapper::extractUserPayload($payload, true);
            if (($mapped['user_fields']['name'] ?? '') !== 'jdoe@corp.example.com') {
                throw new \Exception('Expected userName mapped to name.');
            }
            if (($mapped['user_fields']['firstname'] ?? '') !== 'Jane' || ($mapped['user_fields']['realname'] ?? '') !== 'Doe') {
                throw new \Exception('Expected givenName/familyName mapped to firstname/realname.');
            }
            if (($mapped['user_fields']['registration_number'] ?? '') !== 'EMP-9001') {
                throw new \Exception('Expected Enterprise employeeNumber mapped to registration_number.');
            }
            if (($mapped['primary_email'] ?? '') !== 'jdoe@corp.example.com') {
                throw new \Exception('Expected primary work email preferred.');
            }
            if (($mapped['rule_input']['scimDepartment'] ?? '') !== 'Platform Security') {
                throw new \Exception('Expected Enterprise department mapped to rule_input[scimDepartment].');
            }
            if (($mapped['active'] ?? false) !== true) {
                throw new \Exception('Expected Entra string "True" normalized to boolean true.');
            }

            $serialized = ScimAttributeMapper::toScimUserResource(
                [
                    'id'                  => 42,
                    'name'                => 'jdoe@corp.example.com',
                    'firstname'           => 'Jane',
                    'realname'            => 'Doe',
                    'phone'               => '+31201234567',
                    'mobile'              => '+31612345678',
                    'registration_number' => 'EMP-9001',
                    'is_active'           => 1,
                    'is_deleted'          => 0,
                    'date_creation'       => '2026-11-13 12:00:00',
                    'date_mod'            => '2026-11-13 12:05:00',
                ],
                ['jdoe@corp.example.com'],
                'entra-oid-1234',
                [['value' => '7', 'display' => 'Engineering']],
                'Senior Engineer',
                'https://glpi.local/plugins/samlsso/front/scim/v2/5'
            );

            if (($serialized['id'] ?? '') !== '42' || ($serialized['externalId'] ?? '') !== 'entra-oid-1234') {
                throw new \Exception('Unexpected serialized SCIM User id/externalId.');
            }
            if (($serialized[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER]['employeeNumber'] ?? '') !== 'EMP-9001') {
                throw new \Exception('Expected Enterprise employeeNumber in serialized SCIM User.');
            }

            echo "✅ SCIM Protocol: Core + Enterprise 2.0 User bidirectional mapping verified\n";
        }

        /**
         * Verify bounded whitelist ScimFilterParser handles Entra/Okta filters, JSON escapes,
         * case-insensitive operators, and rejects unindexed attributes or excessive token counts.
         *
         * @return void
         * @throws \Exception
         */
        public function testBoundedWhitelistFilterParser(): void
        {
            $sampleResource = [
                'id'          => '42',
                'externalId'  => 'oid-abc',
                'userName'    => 'o\'connor@corp.example.com',
                'displayName' => 'Jane O\'Connor',
                'active'      => true,
                'name'        => ['givenName' => 'Jane', 'familyName' => 'O\'Connor'],
                'emails'      => [['value' => 'o\'connor@corp.example.com', 'type' => 'work', 'primary' => true]],
            ];

            $ast1 = ScimFilterParser::parse('userName EQ "o\'connor@corp.example.com" AND active eq true', 'User');
            if (!ScimFilterParser::matchesResource($ast1, $sampleResource)) {
                throw new \Exception('Expected compound case-insensitive EQ/AND filter to match.');
            }
            $hints = ScimFilterParser::buildPushdownHints($ast1);
            if (($hints['userName'] ?? '') !== "o'connor@corp.example.com") {
                throw new \Exception('Expected pushdown hint for userName.');
            }

            $ast2 = ScimFilterParser::parse('emails[type eq "work"].value sw "o\'connor@" and externalId pr', 'User');
            if (!ScimFilterParser::matchesResource($ast2, $sampleResource)) {
                throw new \Exception('Expected value-path emails[type eq "work"].value + pr filter to match.');
            }

            /**
             * Reject unindexed attribute with HTTP 400 scimType=invalidFilter.
             */
            $rejectedAttr = false;
            try {
                ScimFilterParser::parse('nickName eq "hacker"', 'User');
            } catch (ScimException $e) {
                $rejectedAttr = ($e->getHttpStatus() === 400 && $e->getScimType() === 'invalidFilter');
            }
            if (!$rejectedAttr) {
                throw new \Exception('Unwhitelisted filter attribute did not throw 400 invalidFilter.');
            }

            /**
             * Reject excessive token count (> 32 tokens) to prevent parser DoS.
             */
            $hugeFilter = implode(' and ', array_fill(0, 12, 'userName co "a"'));
            $rejectedHuge = false;
            try {
                ScimFilterParser::parse($hugeFilter, 'User');
            } catch (ScimException $e) {
                $rejectedHuge = ($e->getHttpStatus() === 400 && $e->getScimType() === 'invalidFilter');
            }
            if (!$rejectedHuge) {
                throw new \Exception('Over-limit token filter did not throw 400 invalidFilter.');
            }

            echo "✅ SCIM Protocol: Bounded whitelist ScimFilterParser and DoS bounds verified\n";
        }

        /**
         * Verify ScimPatchProcessor handles Entra ID & Okta User/Group PATCH quirks and payload bounds.
         *
         * @return void
         * @throws \Exception
         */
        public function testPatchProcessorEntraAndOktaQuirks(): void
        {
            $currentUser = [
                'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_USER],
                'id'          => '42',
                'userName'    => 'jdoe@corp.example.com',
                'active'      => true,
                'name'        => ['givenName' => 'Jane', 'familyName' => 'Doe'],
                'emails'      => [['value' => 'jdoe@corp.example.com', 'type' => 'work', 'primary' => true]],
                'phoneNumbers' => [],
            ];

            $entraPatch = [
                'schemas'    => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                'Operations' => [
                    ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
                    ['op' => 'Add', 'path' => 'emails[type eq "work"].value', 'value' => 'jane.new@corp.example.com'],
                    ['op' => 'Replace', 'path' => 'name.givenName', 'value' => 'Janet'],
                    [
                        'op'    => 'Replace',
                        'value' => [
                            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department' => 'ZeroTrust',
                        ],
                    ],
                ],
            ];

            $patched = ScimPatchProcessor::applyToUserResource($currentUser, $entraPatch);
            if (($patched['active'] ?? true) !== false) {
                throw new \Exception('Expected Entra "Replace" active="False" to set active=false.');
            }
            if (($patched['name']['givenName'] ?? '') !== 'Janet') {
                throw new \Exception('Expected dotted path name.givenName updated to Janet.');
            }
            if (($patched['emails'][0]['value'] ?? '') !== 'jane.new@corp.example.com') {
                throw new \Exception('Expected emails[type eq "work"].value updated.');
            }
            if (($patched[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER]['department'] ?? '') !== 'ZeroTrust') {
                throw new \Exception('Expected URN key inside pathless Replace value applied to Enterprise extension.');
            }

            /**
             * Group PATCH quirks: Entra filter path members[value eq "12"], $ref path, and Okta array value remove.
             */
            $groupPatch = [
                'schemas'    => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                'Operations' => [
                    ['op' => 'Replace', 'path' => 'displayName', 'value' => 'Platform Admins'],
                    ['op' => 'Add', 'path' => 'members', 'value' => [['value' => '10'], ['value' => '11']]],
                    ['op' => 'Remove', 'path' => 'members[value eq "12"]'],
                    ['op' => 'Remove', 'path' => 'members[$ref eq "https://glpi.local/scim/v2/5/Users/13"]'],
                    ['op' => 'remove', 'path' => 'members', 'value' => [['value' => '14']]],
                ],
            ];

            $groupOps = ScimPatchProcessor::extractGroupOperations($groupPatch);
            if ($groupOps['displayName'] !== 'Platform Admins') {
                throw new \Exception('Expected Group displayName mutation extracted.');
            }
            if ($groupOps['addMemberIds'] !== [10, 11]) {
                throw new \Exception('Expected Group addMemberIds [10, 11].');
            }
            if ($groupOps['removeMemberIds'] !== [12, 13, 14]) {
                throw new \Exception('Expected Group removeMemberIds [12, 13, 14], got: ' . json_encode($groupOps['removeMemberIds']));
            }

            /**
             * Enforce 256 KB body size limit (HTTP 413).
             */
            $oversizedJson = '{"a":"' . str_repeat('x', 270000) . '"}';
            $blocked413 = false;
            try {
                ScimPatchProcessor::decodeJsonBody($oversizedJson);
            } catch (ScimException $e) {
                $blocked413 = ($e->getHttpStatus() === 413);
            }
            if (!$blocked413) {
                throw new \Exception('Oversized >256KB JSON payload did not throw HTTP 413.');
            }

            /**
             * Reject immutable 'id' modification (HTTP 400 mutability).
             */
            $blockedImmutable = false;
            try {
                ScimPatchProcessor::applyToUserResource($currentUser, [
                    'schemas'    => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                    'Operations' => [['op' => 'replace', 'path' => 'id', 'value' => '999']],
                ]);
            } catch (ScimException $e) {
                $blockedImmutable = ($e->getHttpStatus() === 400 && $e->getScimType() === 'mutability');
            }
            if (!$blockedImmutable) {
                throw new \Exception('Immutable id modification did not throw 400 mutability.');
            }

            echo "✅ SCIM Protocol: ScimPatchProcessor Entra/Okta User & Group quirks and 256KB/mutability bounds verified\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\ScimProtocolTest();
    try {
        $test->testDiscoveryEndpointsRespectGroupPolicy();
        $test->testAttributeMapperCoreAndEnterpriseUser();
        $test->testBoundedWhitelistFilterParser();
        $test->testPatchProcessorEntraAndOktaQuirks();
    } catch (\Throwable $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
