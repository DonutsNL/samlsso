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
use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;
use GlpiPlugin\Samlsso\ScimLink;
use Symfony\Component\HttpFoundation\Response;
use Toolbox;

/**
 * ScimGroupService implements RFC 7644 /Groups provisioning, adopting, querying,
 * patching, dynamic membership synchronization (is_dynamic = 1), and non-destructive
 * unlinking governed by the per-IdP scim_group_policy setting.
 *
 * @since 1.4.0
 */
final class ScimGroupService
{
    /** @var ScimUserService Companion SCIM user service for ownership checks and rule re-evaluation. */
    private ScimUserService $userService;

    /**
     * @param ScimUserService|null $userService Optional injected ScimUserService instance.
     */
    public function __construct(?ScimUserService $userService = null)
    {
        $this->userService = $userService ?? new ScimUserService();
    }

    /**
     * List and filter SCIM Group resources linked to this IdP.
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param array<string, mixed> $query        HTTP query parameters (filter, startIndex, count).
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array{resources: array<int, array<string, mixed>>, total: int, startIndex: int}
     * @throws ScimException With HTTP 404 when scim_group_policy is 'disabled'.
     */
    public function listGroups(ConfigEntity $configEntity, array $query, string $baseScimUrl): array
    {
        $this->ensureGroupPolicyEnabled($configEntity);

        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $filterRaw = isset($query['filter']) && is_string($query['filter']) ? $query['filter'] : '';
        $clauses = ScimFilterParser::parse($filterRaw, 'Group');

        $startIndex = isset($query['startIndex']) ? max(1, (int) $query['startIndex']) : 1;
        $count = isset($query['count'])
            ? min(ScimDiscoveryService::MAX_RESULTS, max(0, (int) $query['count']))
            : 100;

        $linksByGroupId = ScimLink::findAllForConfigAndType($idpId, ScimLink::TYPE_GROUP);
        if (empty($linksByGroupId)) {
            return [
                'resources'  => [],
                'total'      => 0,
                'startIndex' => $startIndex,
            ];
        }

        global $DB;
        $iterator = $DB->request([
            'FROM'  => 'glpi_groups',
            'WHERE' => ['id' => array_keys($linksByGroupId)],
        ]);

        $matched = [];
        foreach ($iterator as $groupRow) {
            $gid = (int) ($groupRow['id'] ?? 0);
            if ($gid <= 0 || !isset($linksByGroupId[$gid])) {
                continue;
            }
            $extId = isset($linksByGroupId[$gid]['external_id'])
                ? (string) $linksByGroupId[$gid]['external_id']
                : null;
            $resource = $this->buildGroupResourceFromRow($idpId, $groupRow, $extId, $baseScimUrl);
            if (ScimFilterParser::matchesResource($clauses, $resource)) {
                $matched[] = $resource;
            }
        }

        $total = count($matched);
        $page = $count === 0 ? [] : array_slice($matched, $startIndex - 1, $count);

        return [
            'resources'  => $page,
            'total'      => $total,
            'startIndex' => $startIndex,
        ];
    }

    /**
     * Fetch a single SCIM Group resource owned by this IdP.
     *
     * @param ConfigEntity $configEntity Verified active SCIM IdP configuration.
     * @param int          $groupId      Requested GLPI group ID.
     * @param string       $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException With HTTP 404 when disabled, missing, or unlinked.
     */
    public function getGroup(ConfigEntity $configEntity, int $groupId, string $baseScimUrl): array
    {
        $this->ensureGroupPolicyEnabled($configEntity);

        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        [$groupRow, $link] = $this->requireIdpOwnedGroupRow($idpId, $groupId);
        $externalId = isset($link['external_id']) ? (string) $link['external_id'] : null;

        return $this->buildGroupResourceFromRow($idpId, $groupRow, $externalId, $baseScimUrl);
    }

    /**
     * Create a new GLPI group (when policy is 'create_scoped') or link a pre-existing
     * unowned GLPI group by exact displayName, synchronizing dynamic IdP memberships.
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param array<string, mixed> $scimBody     Decoded SCIM Group JSON payload.
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException On policy violation, foreign ownership conflict, or invalid members.
     */
    public function createGroup(ConfigEntity $configEntity, array $scimBody, string $baseScimUrl): array
    {
        $policy = $this->ensureGroupPolicyEnabled($configEntity);
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $idpName = (string) ($configEntity->getField(ConfigEntity::NAME) ?: ('IdP #' . $idpId));

        $extracted = ScimAttributeMapper::extractGroupPayload($scimBody, true);
        $displayName = $extracted['display_name'];
        $externalId = $extracted['external_id'];
        $memberIds = $extracted['member_ids'];

        if ($externalId !== null && $externalId !== '') {
            if (ScimLink::findByExternalId($idpId, ScimLink::TYPE_GROUP, $externalId) !== null) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'A SCIM Group with the specified externalId already exists for this Identity Provider.',
                    'uniqueness'
                );
            }
        }

        $this->validateMemberOwnership($idpId, $memberIds);

        $existingGroup = $this->findGlpiGroupByName($displayName);
        if ($existingGroup !== null) {
            $groupId = (int) $existingGroup['id'];
            if (ScimLink::findForeignOwner($idpId, ScimLink::TYPE_GROUP, $groupId) !== null) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'GLPI group is already managed via SCIM by a different Identity Provider.',
                    'uniqueness'
                );
            }
            if (ScimLink::findByGlpiItem($idpId, ScimLink::TYPE_GROUP, $groupId) !== null) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'SCIM Group already exists for this Identity Provider.',
                    'uniqueness'
                );
            }
        } else {
            if ($policy === 'link_existing') {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    sprintf(
                        'GLPI group "%s" does not exist and scim_group_policy is set to link_existing.',
                        $displayName
                    ),
                    'invalidValue'
                );
            }
            $groupId = $this->insertLeastPrivilegeGlpiGroup($displayName, $idpName);
        }

        ScimLink::upsertLink($idpId, ScimLink::TYPE_GROUP, $groupId, $externalId);
        $this->replaceDynamicMemberships($idpId, $groupId, $memberIds);

        $this->logScimAudit(sprintf(
            'SCIM linked/created group #%d (%s) with %d dynamic member(s) for %s (IdP #%d)',
            $groupId,
            $displayName,
            count($memberIds),
            $idpName,
            $idpId
        ));

        return $this->getGroup($configEntity, $groupId, $baseScimUrl);
    }

    /**
     * Replace displayName, externalId, and dynamic member list on an IdP-owned SCIM Group (PUT /Groups/{id}).
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param int                  $groupId      Target GLPI group ID.
     * @param array<string, mixed> $scimBody     Decoded SCIM Group JSON payload.
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException On missing group, uniqueness conflict, or invalid members.
     */
    public function replaceGroup(
        ConfigEntity $configEntity,
        int $groupId,
        array $scimBody,
        string $baseScimUrl
    ): array {
        $policy = $this->ensureGroupPolicyEnabled($configEntity);
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        [$groupRow] = $this->requireIdpOwnedGroupRow($idpId, $groupId);

        $extracted = ScimAttributeMapper::extractGroupPayload($scimBody, true);
        $this->validateMemberOwnership($idpId, $extracted['member_ids']);

        if ($extracted['external_id'] !== null && $extracted['external_id'] !== '') {
            $extOwner = ScimLink::findByExternalId($idpId, ScimLink::TYPE_GROUP, $extracted['external_id']);
            if ($extOwner !== null && (int) $extOwner['glpi_item_id'] !== $groupId) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'externalId is already assigned to another SCIM Group on this Identity Provider.',
                    'uniqueness'
                );
            }
        }

        $currentName = (string) ($groupRow['name'] ?? '');
        if ($extracted['display_name'] !== $currentName) {
            if ($policy === 'link_existing') {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    'Renaming GLPI groups via SCIM is not permitted when scim_group_policy is link_existing.',
                    'mutability'
                );
            }
            $this->renameOwnedGlpiGroup($groupId, $extracted['display_name']);
        }

        ScimLink::upsertLink($idpId, ScimLink::TYPE_GROUP, $groupId, $extracted['external_id']);
        $this->replaceDynamicMemberships($idpId, $groupId, $extracted['member_ids']);

        return $this->getGroup($configEntity, $groupId, $baseScimUrl);
    }

    /**
     * Apply an RFC 7644 PatchOp payload to an IdP-owned SCIM Group (PATCH /Groups/{id}).
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param int                  $groupId      Target GLPI group ID.
     * @param array<string, mixed> $patchBody    Decoded PatchOp JSON payload.
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException On invalid PatchOp, policy restriction, or invalid member reference.
     */
    public function patchGroup(
        ConfigEntity $configEntity,
        int $groupId,
        array $patchBody,
        string $baseScimUrl
    ): array {
        $policy = $this->ensureGroupPolicyEnabled($configEntity);
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        [$groupRow, $link] = $this->requireIdpOwnedGroupRow($idpId, $groupId);

        $ops = ScimPatchProcessor::extractGroupOperations($patchBody);
        if (!empty($ops['addMemberIds'])) {
            $this->validateMemberOwnership($idpId, $ops['addMemberIds']);
        }

        if ($ops['displayName'] !== null && $ops['displayName'] !== (string) ($groupRow['name'] ?? '')) {
            if ($policy === 'link_existing') {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    'Renaming GLPI groups via SCIM is not permitted when scim_group_policy is link_existing.',
                    'mutability'
                );
            }
            $this->renameOwnedGlpiGroup($groupId, $ops['displayName']);
        }

        $extIdToPersist = $ops['externalIdSet']
            ? $ops['externalId']
            : (isset($link['external_id']) ? (string) $link['external_id'] : null);
        if ($extIdToPersist !== null && $extIdToPersist !== '') {
            $extOwner = ScimLink::findByExternalId($idpId, ScimLink::TYPE_GROUP, $extIdToPersist);
            if ($extOwner !== null && (int) $extOwner['glpi_item_id'] !== $groupId) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'externalId is already assigned to another SCIM Group on this Identity Provider.',
                    'uniqueness'
                );
            }
        }
        ScimLink::upsertLink($idpId, ScimLink::TYPE_GROUP, $groupId, $extIdToPersist);

        $currentDynamicIds = array_keys($this->fetchDynamicIdpMembersMap($idpId, $groupId));
        if ($ops['clearAllMembers']) {
            $desiredIds = [];
        } elseif ($ops['replaceAllMembers']) {
            $desiredIds = $ops['addMemberIds'];
        } else {
            $set = array_combine($currentDynamicIds, $currentDynamicIds) ?: [];
            foreach ($ops['addMemberIds'] as $addUid) {
                $set[$addUid] = $addUid;
            }
            foreach ($ops['removeMemberIds'] as $remUid) {
                unset($set[$remUid]);
            }
            $desiredIds = array_values($set);
        }

        $this->replaceDynamicMemberships($idpId, $groupId, $desiredIds);

        return $this->getGroup($configEntity, $groupId, $baseScimUrl);
    }

    /**
     * Non-destructively unlink a SCIM Group from this IdP (DELETE /Groups/{id}):
     * removes dynamic (is_dynamic = 1) memberships for IdP users and removes ScimLink,
     * while preserving the glpi_groups row itself and all manual (is_dynamic = 0) memberships.
     *
     * @param ConfigEntity $configEntity Verified active SCIM IdP configuration.
     * @param int          $groupId      Target GLPI group ID.
     * @return void
     * @throws ScimException With HTTP 404 when disabled or unlinked.
     */
    public function deleteGroup(ConfigEntity $configEntity, int $groupId): void
    {
        $this->ensureGroupPolicyEnabled($configEntity);
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        [$groupRow] = $this->requireIdpOwnedGroupRow($idpId, $groupId);

        $this->replaceDynamicMemberships($idpId, $groupId, []);
        ScimLink::deleteLink($idpId, ScimLink::TYPE_GROUP, $groupId);

        $this->logScimAudit(sprintf(
            'SCIM unlinked group #%d (%s) from IdP #%d (GLPI group record preserved)',
            $groupId,
            (string) ($groupRow['name'] ?? ''),
            $idpId
        ));
    }

    /**
     * Ensure scim_group_policy is not 'disabled' and return the active policy string.
     *
     * @param ConfigEntity $configEntity Target IdP configuration.
     * @return string
     * @throws ScimException With HTTP 404 when 'disabled'.
     */
    private function ensureGroupPolicyEnabled(ConfigEntity $configEntity): string
    {
        $policy = (string) ($configEntity->getField(ConfigEntity::SCIM_GROUP_POLICY) ?: 'create_scoped');
        if ($policy === 'disabled') {
            throw new ScimException(
                Response::HTTP_NOT_FOUND,
                'SCIM Group provisioning is disabled for this Identity Provider.'
            );
        }

        return $policy;
    }

    /**
     * Require that $groupId is linked to $idpId in ScimLink and exists in glpi_groups.
     *
     * @param int $idpId   Target IdP configuration ID.
     * @param int $groupId Target GLPI group ID.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     * @throws ScimException With HTTP 404 when missing or unlinked.
     */
    private function requireIdpOwnedGroupRow(int $idpId, int $groupId): array
    {
        global $DB;

        if ($idpId <= 0 || $groupId <= 0) {
            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM Group resource not found.');
        }

        $link = ScimLink::findByGlpiItem($idpId, ScimLink::TYPE_GROUP, $groupId);
        if ($link === null) {
            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM Group resource not found.');
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_groups',
            'WHERE' => ['id' => $groupId],
        ]);
        foreach ($iterator as $row) {
            return [$row, $link];
        }

        throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM Group resource not found.');
    }

    /**
     * Verify every requested member user ID belongs to (Auth::EXTERNAL, $idpId).
     *
     * @param int             $idpId     Target IdP configuration ID.
     * @param array<int, int> $memberIds Candidate GLPI user IDs.
     * @return void
     * @throws ScimException With HTTP 400 invalidValue if any member is missing or foreign.
     */
    private function validateMemberOwnership(int $idpId, array $memberIds): void
    {
        foreach ($memberIds as $uid) {
            try {
                $this->userService->requireIdpOwnedUserRow($idpId, $uid);
            } catch (ScimException $e) {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    sprintf('Group member User ID %d does not exist or is not managed by this Identity Provider.', $uid),
                    'invalidValue'
                );
            }
        }
    }

    /**
     * Synchronize dynamic (is_dynamic = 1) group memberships for IdP-owned users while leaving
     * manual (is_dynamic = 0) memberships untouched, then re-evaluate RuleSamlCollection for affected users.
     *
     * @param int             $idpId            Target IdP configuration ID.
     * @param int             $groupId          Target GLPI group ID.
     * @param array<int, int> $desiredMemberIds Desired dynamic member user IDs.
     * @return void
     */
    private function replaceDynamicMemberships(int $idpId, int $groupId, array $desiredMemberIds): void
    {
        global $DB;

        $desiredSet = [];
        foreach ($desiredMemberIds as $uid) {
            if ($uid > 0) {
                $desiredSet[$uid] = true;
            }
        }

        $manualUserIds = [];
        $currentDynamicRowByUser = [];
        $guIter = $DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [SamlUser::GROUPID => $groupId],
        ]);

        foreach ($guIter as $guRow) {
            $uid = (int) ($guRow[SamlUser::USERSID] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ((int) ($guRow[SamlUser::IS_DYNAMIC] ?? 0) === 0) {
                $manualUserIds[$uid] = true;
                continue;
            }

            try {
                $this->userService->requireIdpOwnedUserRow($idpId, $uid);
                $currentDynamicRowByUser[$uid] = $guRow;
            } catch (ScimException $e) {
                continue;
            }
        }

        $affectedUserIds = [];

        foreach ($currentDynamicRowByUser as $uid => $guRow) {
            if (!isset($desiredSet[$uid])) {
                $DB->delete('glpi_groups_users', [
                    SamlUser::GROUPID    => $groupId,
                    SamlUser::USERSID    => $uid,
                    SamlUser::IS_DYNAMIC => 1,
                ]);
                $affectedUserIds[$uid] = $uid;
            }
        }

        foreach (array_keys($desiredSet) as $uid) {
            if (isset($currentDynamicRowByUser[$uid]) || isset($manualUserIds[$uid])) {
                if (isset($manualUserIds[$uid])) {
                    $affectedUserIds[$uid] = $uid;
                }
                continue;
            }
            $DB->insert('glpi_groups_users', [
                SamlUser::USERSID    => $uid,
                SamlUser::GROUPID    => $groupId,
                SamlUser::IS_DYNAMIC => 1,
                'is_manager'         => 0,
                'is_userdelegate'    => 0,
            ]);
            $affectedUserIds[$uid] = $uid;
        }

        foreach ($affectedUserIds as $uid) {
            $this->userService->reevaluateRulesForUser($idpId, $uid);
        }
    }

    /**
     * Fetch dynamic IdP-owned members for a GLPI group keyed by user ID.
     *
     * @param int $idpId   Target IdP configuration ID.
     * @param int $groupId Target GLPI group ID.
     * @return array<int, array<string, string>>
     */
    private function fetchDynamicIdpMembersMap(int $idpId, int $groupId): array
    {
        global $DB;

        $members = [];
        $guIter = $DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [
                SamlUser::GROUPID    => $groupId,
                SamlUser::IS_DYNAMIC => 1,
            ],
        ]);
        foreach ($guIter as $guRow) {
            $uid = (int) ($guRow[SamlUser::USERSID] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            try {
                $userRow = $this->userService->requireIdpOwnedUserRow($idpId, $uid);
                $members[$uid] = [
                    'value'   => (string) $uid,
                    'display' => (string) ($userRow[SamlUser::NAME] ?? ''),
                ];
            } catch (ScimException $e) {
                continue;
            }
        }

        return $members;
    }

    /**
     * Build an RFC 7643 Group resource from a glpi_groups row and its dynamic IdP members.
     *
     * @param int                  $idpId       Target IdP configuration ID.
     * @param array<string, mixed> $groupRow    Row from glpi_groups.
     * @param string|null          $externalId  Optional ScimLink externalId.
     * @param string               $baseScimUrl Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     */
    private function buildGroupResourceFromRow(
        int $idpId,
        array $groupRow,
        ?string $externalId,
        string $baseScimUrl
    ): array {
        $groupId = (int) ($groupRow['id'] ?? 0);
        $members = array_values($this->fetchDynamicIdpMembersMap($idpId, $groupId));

        return ScimAttributeMapper::toScimGroupResource(
            $groupRow,
            $externalId,
            $members,
            $baseScimUrl
        );
    }

    /**
     * Find an existing GLPI group by exact name.
     *
     * @param string $displayName Target group name.
     * @return array<string, mixed>|null
     */
    private function findGlpiGroupByName(string $displayName): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_groups',
            'WHERE' => ['name' => $displayName],
        ]);
        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Insert a least-privilege Root-Entity recursive GLPI organizational group.
     *
     * @param string $displayName Group name.
     * @param string $idpName     Human-readable IdP name.
     * @return int Inserted GLPI group ID.
     * @throws ScimException On insertion failure or collision.
     */
    private function insertLeastPrivilegeGlpiGroup(string $displayName, string $idpName): int
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $payload = [
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'name'          => $displayName,
            'completename'  => $displayName,
            'comment'       => sprintf('Provisioned via samlSSO SCIM 2.0 (%s)', $idpName),
            'is_requester'  => 1,
            'is_watcher'    => 1,
            'is_notify'     => 1,
            'is_usergroup'  => 1,
            'is_assign'     => 0,
            'is_task'       => 0,
            'is_itemgroup'  => 0,
            'is_manager'    => 0,
            'date_creation' => $now,
            'date_mod'      => $now,
        ];

        if (class_exists('\Group') && method_exists('\Group', 'add')) {
            $groupObj = new \Group();
            $createdId = (int) $groupObj->add($payload);
            if ($createdId > 0) {
                $check = $this->findGlpiGroupByName($displayName);
                if ($check !== null) {
                    return (int) $check['id'];
                }
            }
        }

        if (!$DB->insert('glpi_groups', $payload)) {
            throw new ScimException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'GLPI failed to create SCIM group record.'
            );
        }

        $inserted = $this->findGlpiGroupByName($displayName);
        if ($inserted === null || (int) ($inserted['id'] ?? 0) <= 0) {
            throw new ScimException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'GLPI failed to retrieve newly created SCIM group.'
            );
        }

        return (int) $inserted['id'];
    }

    /**
     * Rename an IdP-owned GLPI group after checking for name collisions.
     *
     * @param int    $groupId     Owned GLPI group ID.
     * @param string $displayName New displayName.
     * @return void
     * @throws ScimException With HTTP 409 uniqueness when another group already uses $displayName.
     */
    private function renameOwnedGlpiGroup(int $groupId, string $displayName): void
    {
        global $DB;

        $existing = $this->findGlpiGroupByName($displayName);
        if ($existing !== null && (int) $existing['id'] !== $groupId) {
            throw new ScimException(
                Response::HTTP_CONFLICT,
                sprintf('Another GLPI group already uses displayName "%s".', $displayName),
                'uniqueness'
            );
        }

        $DB->update(
            'glpi_groups',
            [
                'name'         => $displayName,
                'completename' => $displayName,
                'date_mod'     => date('Y-m-d H:i:s'),
            ],
            ['id' => $groupId]
        );
    }

    /**
     * Record a sanitized SCIM group audit message.
     *
     * @param string $message Audit message.
     * @return void
     */
    private function logScimAudit(string $message): void
    {
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, $message . "\n");
        }
    }
}
