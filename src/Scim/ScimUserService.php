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

use GlpiPlugin\Samlsso\Config\ConfigEntity;
use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;
use GlpiPlugin\Samlsso\LoginState;
use GlpiPlugin\Samlsso\RuleSamlCollection;
use GlpiPlugin\Samlsso\ScimLink;
use Symfony\Component\HttpFoundation\Response;
use Toolbox;
use User as GlpiUser;

/**
 * ScimUserService implements fail-closed RFC 7644 /Users provisioning, adoption,
 * querying, patching, immediate session revocation, and non-destructive offboarding
 * strictly scoped to (authtype = Auth::EXTERNAL, auths_id = User::toAuthsId($idpId)).
 *
 * @since 1.4.0
 */
final class ScimUserService
{
    /** @var array<int, string> Protected GLPI user columns that SCIM updates must never mutate. */
    private const PROTECTED_USER_COLUMNS = [
        SamlUser::AUTHTYPE,
        SamlUser::AUTHS_ID,
        SamlUser::PASSWORD,
        SamlUser::PASSWORDN,
        SamlUser::PASSWORD_MD5,
        SamlUser::USER_DN,
        SamlUser::USER_DN_HASH,
        SamlUser::API_TOKEN,
        SamlUser::PERSONAL_TOKEN,
        SamlUser::PASSWORD_FORGET_TOKEN,
    ];

    /**
     * List and filter SCIM User resources scoped strictly to this IdP.
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param array<string, mixed> $query        HTTP query parameters (filter, startIndex, count).
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array{resources: array<int, array<string, mixed>>, total: int, startIndex: int}
     */
    public function listUsers(ConfigEntity $configEntity, array $query, string $baseScimUrl): array
    {
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $filterRaw = isset($query['filter']) && is_string($query['filter']) ? $query['filter'] : '';
        $clauses = ScimFilterParser::parse($filterRaw, 'User');
        $hints = ScimFilterParser::buildPushdownHints($clauses);

        $startIndex = isset($query['startIndex']) ? max(1, (int) $query['startIndex']) : 1;
        $count = isset($query['count'])
            ? min(ScimDiscoveryService::MAX_RESULTS, max(0, (int) $query['count']))
            : 100;

        $candidateRows = $this->fetchIdpOwnedUserCandidates($idpId, $hints);
        $linksByUserId = ScimLink::findAllForConfigAndType($idpId, ScimLink::TYPE_USER);

        $matched = [];
        foreach ($candidateRows as $row) {
            $uid = (int) ($row[SamlUser::USERID] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $externalId = isset($linksByUserId[$uid]['external_id'])
                ? (string) $linksByUserId[$uid]['external_id']
                : null;
            $resource = $this->buildUserResourceFromRow($idpId, $row, $externalId, $baseScimUrl);
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
     * Fetch a single SCIM User resource owned by this IdP.
     *
     * @param ConfigEntity $configEntity Verified active SCIM IdP configuration.
     * @param int          $userId       Requested GLPI user ID.
     * @param string       $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException With HTTP 404 when not found or not owned by this IdP.
     */
    public function getUser(ConfigEntity $configEntity, int $userId, string $baseScimUrl): array
    {
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $row = $this->requireIdpOwnedUserRow($idpId, $userId);
        $link = ScimLink::findByGlpiItem($idpId, ScimLink::TYPE_USER, $userId);
        $externalId = isset($link['external_id']) ? (string) $link['external_id'] : null;

        return $this->buildUserResourceFromRow($idpId, $row, $externalId, $baseScimUrl);
    }

    /**
     * Create a new Auth::EXTERNAL GLPI user or seamlessly adopt a pre-existing unlinked
     * SAML JIT user belonging to the exact same IdP.
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param array<string, mixed> $scimBody     Decoded SCIM User JSON payload.
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException On schema validation or account collision errors.
     */
    public function createUser(ConfigEntity $configEntity, array $scimBody, string $baseScimUrl): array
    {
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $idpName = (string) ($configEntity->getField(ConfigEntity::NAME) ?: ('IdP #' . $idpId));
        $extracted = ScimAttributeMapper::extractUserPayload($scimBody, true);

        $userName = (string) $extracted['user_fields'][SamlUser::NAME];
        if (strtolower($userName) === 'glpi') {
            throw new ScimException(
                Response::HTTP_CONFLICT,
                'Reserved GLPI super-admin username cannot be provisioned via SCIM.',
                'uniqueness'
            );
        }

        if ($extracted['external_id'] !== null && $extracted['external_id'] !== '') {
            $existingExtLink = ScimLink::findByExternalId($idpId, ScimLink::TYPE_USER, $extracted['external_id']);
            if ($existingExtLink !== null) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'A SCIM User with the specified externalId already exists for this Identity Provider.',
                    'uniqueness'
                );
            }
        }

        $expectedAuthsId = SamlUser::toAuthsId($idpId);

        $adoptableRow = $this->inspectCollisionsAndFindAdoptableJitUser(
            $idpId,
            $userName,
            $extracted['emails'],
            null
        );

        if ($adoptableRow !== null) {
            $adoptedId = (int) $adoptableRow[SamlUser::USERID];
            if ((int) ($adoptableRow[SamlUser::AUTHS_ID] ?? 0) !== $expectedAuthsId) {
                global $DB;
                if ($DB !== null && method_exists($DB, 'update')) {
                    $DB->update('glpi_users', [SamlUser::AUTHS_ID => $expectedAuthsId], [SamlUser::USERID => $adoptedId]);
                }
            }
            $this->applyExtractedUpdateToOwnedUser($idpId, $adoptedId, $extracted);
            $this->logScimAudit(sprintf(
                'SCIM adopted existing SAML JIT user #%d (%s) for %s (IdP #%d)',
                $adoptedId,
                $userName,
                $idpName,
                $idpId
            ));
            return $this->getUser($configEntity, $adoptedId, $baseScimUrl);
        }

        $addPayload = $extracted['user_fields'];
        $addPayload[SamlUser::AUTHTYPE] = self::externalAuthType();
        $addPayload[SamlUser::AUTHS_ID] = $expectedAuthsId;
        $addPayload[SamlUser::EXTAUTH] = 1;
        $addPayload[SamlUser::ACTIVE] = ($extracted['active'] ?? true) ? 1 : 0;
        $addPayload[SamlUser::DELETED] = 0;
        $addPayload[SamlUser::SYNCDATE] = date('Y-m-d H:i:s');
        $addPayload[SamlUser::COMMENT] = sprintf('Provisioned via samlSSO SCIM 2.0 (%s) on %s', $idpName, date('Y-m-d H:i:s'));

        if (!empty($extracted['emails'])) {
            $addPayload[SamlUser::EMAIL] = $extracted['emails'];
        }

        $titleId = $this->resolveExistingUserTitleId($extracted['jobtitle']);
        if ($titleId > 0) {
            $addPayload[SamlUser::USERTITLES_ID] = $titleId;
        }
        $locationId = $this->resolveExistingLocationId($extracted['address']);
        if ($locationId > 0) {
            $addPayload[SamlUser::LOCATIONS_ID] = $locationId;
        }

        $glpiUser = new GlpiUser();
        $newId = (int) $glpiUser->add(self::sanitizeInput($addPayload));
        if ($newId <= 0) {
            throw new ScimException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'GLPI failed to create external SCIM user record.'
            );
        }

        $this->syncEmailsFallback($newId, $extracted['emails']);
        ScimLink::upsertLink($idpId, ScimLink::TYPE_USER, $newId, $extracted['external_id']);
        $this->runRulesEngine($idpId, $newId, $extracted['rule_input']);

        if ($extracted['active'] === false) {
            $this->revokeUserSessions($idpId, $newId);
        }

        $this->logScimAudit(sprintf(
            'SCIM provisioned user #%d (%s) for %s (IdP #%d)',
            $newId,
            $userName,
            $idpName,
            $idpId
        ));

        return $this->getUser($configEntity, $newId, $baseScimUrl);
    }

    /**
     * Replace mutable attributes on an IdP-owned SCIM User resource (PUT /Users/{id}).
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param int                  $userId       Target GLPI user ID.
     * @param array<string, mixed> $scimBody     Decoded SCIM User JSON payload.
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException On missing user, collision, or validation error.
     */
    public function replaceUser(
        ConfigEntity $configEntity,
        int $userId,
        array $scimBody,
        string $baseScimUrl
    ): array {
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $this->requireIdpOwnedUserRow($idpId, $userId);

        $extracted = ScimAttributeMapper::extractUserPayload($scimBody, true);
        $userName = (string) $extracted['user_fields'][SamlUser::NAME];
        if (strtolower($userName) === 'glpi') {
            throw new ScimException(Response::HTTP_CONFLICT, 'Reserved username is not permitted.', 'uniqueness');
        }

        if ($extracted['external_id'] !== null && $extracted['external_id'] !== '') {
            $extOwner = ScimLink::findByExternalId($idpId, ScimLink::TYPE_USER, $extracted['external_id']);
            if ($extOwner !== null && (int) $extOwner['glpi_item_id'] !== $userId) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'externalId is already assigned to another SCIM User on this Identity Provider.',
                    'uniqueness'
                );
            }
        }

        $this->inspectCollisionsAndFindAdoptableJitUser($idpId, $userName, $extracted['emails'], $userId);
        $this->applyExtractedUpdateToOwnedUser($idpId, $userId, $extracted);

        return $this->getUser($configEntity, $userId, $baseScimUrl);
    }

    /**
     * Apply an RFC 7644 PatchOp payload to an IdP-owned SCIM User resource (PATCH /Users/{id}).
     *
     * @param ConfigEntity         $configEntity Verified active SCIM IdP configuration.
     * @param int                  $userId       Target GLPI user ID.
     * @param array<string, mixed> $patchBody    Decoded PatchOp JSON payload.
     * @param string               $baseScimUrl  Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     * @throws ScimException On missing user, invalid PatchOp, or collision.
     */
    public function patchUser(
        ConfigEntity $configEntity,
        int $userId,
        array $patchBody,
        string $baseScimUrl
    ): array {
        $currentResource = $this->getUser($configEntity, $userId, $baseScimUrl);
        $patchedState = ScimPatchProcessor::applyToUserResource($currentResource, $patchBody);

        return $this->replaceUser($configEntity, $userId, $patchedState, $baseScimUrl);
    }

    /**
     * Deprovision an IdP-owned SCIM User resource (DELETE /Users/{id}) according to
     * scim_deprovision ('disable' or 'trashbin'), revoking active sessions without hard-deleting history.
     *
     * @param ConfigEntity $configEntity Verified active SCIM IdP configuration.
     * @param int          $userId       Target GLPI user ID.
     * @return void
     * @throws ScimException With HTTP 404 when not owned by this IdP.
     */
    public function deleteUser(ConfigEntity $configEntity, int $userId): void
    {
        $idpId = (int) $configEntity->getField(ConfigEntity::ID);
        $row = $this->requireIdpOwnedUserRow($idpId, $userId);
        $mode = (string) ($configEntity->getField(ConfigEntity::SCIM_DEPROVISION) ?: 'disable');

        $updatePayload = [
            SamlUser::USERID   => $userId,
            SamlUser::ACTIVE   => 0,
            SamlUser::SYNCDATE => date('Y-m-d H:i:s'),
        ];
        if ($mode === 'trashbin') {
            $updatePayload[SamlUser::DELETED] = 1;
        }

        $glpiUser = new GlpiUser();
        $glpiUser->update(self::sanitizeInput($updatePayload));

        $this->revokeUserSessions($idpId, $userId);
        ScimLink::deleteLink($idpId, ScimLink::TYPE_USER, $userId);

        $this->logScimAudit(sprintf(
            'SCIM deprovisioned user #%d (%s) via mode=%s for IdP #%d',
            $userId,
            (string) ($row[SamlUser::NAME] ?? ''),
            $mode,
            $idpId
        ));
    }

    /**
     * Re-run RuleSamlCollection for an IdP-owned user after group membership changes.
     *
     * @param int $idpId  Target IdP configuration ID.
     * @param int $userId Target GLPI user ID.
     * @return void
     */
    public function reevaluateRulesForUser(int $idpId, int $userId): void
    {
        try {
            $row = $this->requireIdpOwnedUserRow($idpId, $userId);
        } catch (ScimException $e) {
            return;
        }

        $emails = $this->fetchUserEmails($userId);
        $ruleInput = [
            SamlUser::NAME      => (string) ($row[SamlUser::NAME] ?? ''),
            SamlUser::FIRSTNAME => (string) ($row[SamlUser::FIRSTNAME] ?? ''),
            SamlUser::REALNAME  => (string) ($row[SamlUser::REALNAME] ?? ''),
            SamlUser::PHONE     => (string) ($row[SamlUser::PHONE] ?? ''),
            SamlUser::MOBILE    => (string) ($row[SamlUser::MOBILE] ?? ''),
            SamlUser::EMAIL     => $emails[0] ?? '',
        ];
        if (!empty($row[SamlUser::REGISTRATION_NUMBER])) {
            $ruleInput[SamlUser::SCIM_EMPLOYEE_NUMBER] = (string) $row[SamlUser::REGISTRATION_NUMBER];
        }

        $this->runRulesEngine($idpId, $userId, $ruleInput);
    }

    /**
     * Verify that $userId exists and is strictly bound to (Auth::EXTERNAL, $idpId).
     *
     * @param int $idpId  Target IdP configuration ID.
     * @param int $userId Target GLPI user ID.
     * @return array<string, mixed>
     * @throws ScimException With HTTP 404 when absent or bound to another source.
     */
    public function requireIdpOwnedUserRow(int $idpId, int $userId): array
    {
        global $DB;

        if ($idpId <= 0 || $userId <= 0 || $DB === null || !method_exists($DB, 'request')) {
            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM User resource not found.');
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => [SamlUser::USERID => $userId],
        ]);

        foreach ($iterator as $row) {
            if ($this->isRowOwnedByIdp($row, $idpId)) {
                return $row;
            }
        }

        throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM User resource not found.');
    }

    /**
     * Check whether a glpi_users row is strictly bound to (Auth::EXTERNAL, User::toAuthsId($idpId))
     * (or an unmigrated legacy pre-offset row without user_dn) and is not 'glpi'.
     *
     * @param array<string, mixed> $row   Database user row.
     * @param int                  $idpId Target IdP configuration ID.
     * @return bool
     */
    public function isRowOwnedByIdp(array $row, int $idpId): bool
    {
        if ($idpId <= 0) {
            return false;
        }
        $name = strtolower(trim((string) ($row[SamlUser::NAME] ?? '')));
        if ($name === 'glpi') {
            return false;
        }
        if ((int) ($row[SamlUser::AUTHTYPE] ?? 0) !== self::externalAuthType()) {
            return false;
        }

        $authsId = (int) ($row[SamlUser::AUTHS_ID] ?? 0);
        if ($authsId === SamlUser::toAuthsId($idpId)) {
            return true;
        }

        return $authsId === $idpId && trim((string) ($row[SamlUser::USER_DN] ?? '')) === '';
    }

    /**
     * Check whether a candidate glpi_users row can be adopted during POST /Users creation:
     * either already owned by $idpId (without a ScimLink) or an unbound (Auth::EXTERNAL, 0)
     * row with empty user_dn and no foreign ScimLink.
     *
     * @param array<string, mixed> $row   Database user row.
     * @param int                  $idpId Target IdP configuration ID.
     * @return bool
     */
    private function isRowAdoptableOnCreate(array $row, int $idpId): bool
    {
        if ($this->isRowOwnedByIdp($row, $idpId)) {
            return true;
        }
        if ($idpId <= 0) {
            return false;
        }
        $name = strtolower(trim((string) ($row[SamlUser::NAME] ?? '')));
        if ($name === 'glpi') {
            return false;
        }
        if (
            (int) ($row[SamlUser::AUTHTYPE] ?? 0) !== self::externalAuthType()
            || (int) ($row[SamlUser::AUTHS_ID] ?? -1) !== 0
            || trim((string) ($row[SamlUser::USER_DN] ?? '')) !== ''
        ) {
            return false;
        }

        $candId = (int) ($row[SamlUser::USERID] ?? 0);
        return $candId > 0 && ScimLink::findForeignOwner($idpId, ScimLink::TYPE_USER, $candId) === null;
    }

    /**
     * Apply extracted SCIM User fields, emails, lookups, ScimLink, rules, and revocation to an owned user.
     *
     * @param int                  $idpId     Target IdP configuration ID.
     * @param int                  $userId    Owned GLPI user ID.
     * @param array<string, mixed> $extracted Output from ScimAttributeMapper::extractUserPayload().
     * @return void
     */
    private function applyExtractedUpdateToOwnedUser(int $idpId, int $userId, array $extracted): void
    {
        $updatePayload = $extracted['user_fields'];
        foreach (self::PROTECTED_USER_COLUMNS as $protected) {
            unset($updatePayload[$protected]);
        }

        $updatePayload[SamlUser::USERID] = $userId;
        $updatePayload[SamlUser::SYNCDATE] = date('Y-m-d H:i:s');
        if ($extracted['active'] === true) {
            $updatePayload[SamlUser::DELETED] = 0;
        }
        if (!empty($extracted['emails'])) {
            $updatePayload[SamlUser::EMAIL] = $extracted['emails'];
        }

        $titleId = $this->resolveExistingUserTitleId($extracted['jobtitle']);
        if ($titleId > 0) {
            $updatePayload[SamlUser::USERTITLES_ID] = $titleId;
        }
        $locationId = $this->resolveExistingLocationId($extracted['address']);
        if ($locationId > 0) {
            $updatePayload[SamlUser::LOCATIONS_ID] = $locationId;
        }

        $glpiUser = new GlpiUser();
        $glpiUser->update(self::sanitizeInput($updatePayload));

        $this->syncEmailsFallback($userId, $extracted['emails']);
        ScimLink::upsertLink($idpId, ScimLink::TYPE_USER, $userId, $extracted['external_id']);
        $this->runRulesEngine($idpId, $userId, $extracted['rule_input']);

        if ($extracted['active'] === false) {
            $this->revokeUserSessions($idpId, $userId);
        }
    }

    /**
     * Inspect username and email collisions across glpi_users and glpi_useremails.
     * Fails closed with HTTP 409 uniqueness on any local, LDAP, 'glpi', foreign-IdP,
     * soft-deleted, or already-SCIM-linked collision. Returns an unlinked active SAML JIT
     * or unbound external row for ($idpId) when $updatingUserId is null.
     *
     * @param int                $idpId          Target IdP configuration ID.
     * @param string             $userName       Target SCIM userName.
     * @param array<int, string> $emails         Target SCIM email addresses.
     * @param int|null           $updatingUserId Current user ID on PUT/PATCH, or null on POST.
     * @return array<string, mixed>|null
     * @throws ScimException With HTTP 409 scimType='uniqueness' on collision.
     */
    private function inspectCollisionsAndFindAdoptableJitUser(
        int $idpId,
        string $userName,
        array $emails,
        ?int $updatingUserId
    ): ?array {
        global $DB;

        $candidates = [];
        $nameIter = $DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => [SamlUser::NAME => $userName],
        ]);
        foreach ($nameIter as $row) {
            $candidates[(int) $row[SamlUser::USERID]] = $row;
        }

        $lookupEmails = array_values(array_unique(array_filter(
            array_merge($emails, filter_var($userName, FILTER_VALIDATE_EMAIL) !== false ? [strtolower($userName)] : []),
            static fn(string $v): bool => $v !== ''
        )));
        if (!empty($lookupEmails)) {
            $emailUserIds = [];
            $emailIter = $DB->request([
                'SELECT' => [SamlUser::USERSID],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => ['email' => $lookupEmails],
            ]);
            foreach ($emailIter as $eRow) {
                $uid = (int) ($eRow[SamlUser::USERSID] ?? 0);
                if ($uid > 0 && !isset($candidates[$uid])) {
                    $emailUserIds[] = $uid;
                }
            }
            if (!empty($emailUserIds)) {
                $extraUsers = $DB->request([
                    'FROM'  => 'glpi_users',
                    'WHERE' => [SamlUser::USERID => array_values(array_unique($emailUserIds))],
                ]);
                foreach ($extraUsers as $uRow) {
                    $candidates[(int) $uRow[SamlUser::USERID]] = $uRow;
                }
            }
        }

        $adoptable = null;
        foreach ($candidates as $candId => $candRow) {
            if ($updatingUserId !== null && $candId === $updatingUserId) {
                continue;
            }

            if ($updatingUserId !== null || !$this->isRowAdoptableOnCreate($candRow, $idpId)) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'Username or email conflicts with an existing GLPI user account.',
                    'uniqueness'
                );
            }

            if ((int) ($candRow[SamlUser::DELETED] ?? 0) === 1) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'Matching external GLPI user is in the trashbin and cannot be recreated via POST.',
                    'uniqueness'
                );
            }

            if (ScimLink::findByGlpiItem($idpId, ScimLink::TYPE_USER, $candId) !== null) {
                throw new ScimException(
                    Response::HTTP_CONFLICT,
                    'SCIM User already exists for this Identity Provider.',
                    'uniqueness'
                );
            }

            if ($adoptable === null) {
                $adoptable = $candRow;
            }
        }

        return $adoptable;
    }

    /**
     * Fetch candidate glpi_users rows owned by $idpId, applying indexed pushdown hints when present.
     *
     * @param int                   $idpId Target IdP configuration ID.
     * @param array<string, string> $hints Exact-match pushdown hints from ScimFilterParser.
     * @return array<int, array<string, mixed>>
     */
    private function fetchIdpOwnedUserCandidates(int $idpId, array $hints): array
    {
        global $DB;

        if ($idpId <= 0) {
            return [];
        }

        $where = [
            SamlUser::AUTHTYPE => self::externalAuthType(),
            SamlUser::AUTHS_ID => [SamlUser::toAuthsId($idpId), $idpId],
        ];

        if (isset($hints['id'])) {
            if (preg_match('/^\d+$/', $hints['id']) !== 1) {
                return [];
            }
            $where[SamlUser::USERID] = (int) $hints['id'];
        }

        if (isset($hints['externalId'])) {
            $link = ScimLink::findByExternalId($idpId, ScimLink::TYPE_USER, $hints['externalId']);
            if ($link === null) {
                return [];
            }
            $where[SamlUser::USERID] = (int) $link['glpi_item_id'];
        }

        if (isset($hints['userName'])) {
            $where[SamlUser::NAME] = $hints['userName'];
        }

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => $where,
        ]);
        foreach ($iterator as $row) {
            if ($this->isRowOwnedByIdp($row, $idpId)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Serialize a single IdP-owned GLPI user row into an RFC 7643 SCIM User resource.
     *
     * @param int                  $idpId       Target IdP configuration ID.
     * @param array<string, mixed> $row         Row from glpi_users.
     * @param string|null          $externalId  Optional ScimLink externalId.
     * @param string               $baseScimUrl Canonical SCIM v2 base URL.
     * @return array<string, mixed>
     */
    private function buildUserResourceFromRow(
        int $idpId,
        array $row,
        ?string $externalId,
        string $baseScimUrl
    ): array {
        $userId = (int) ($row[SamlUser::USERID] ?? 0);
        $emails = $this->fetchUserEmails($userId);
        $groups = $this->fetchUserGroupSummaries($userId);
        $jobTitle = $this->fetchUserTitleName((int) ($row[SamlUser::USERTITLES_ID] ?? 0));

        return ScimAttributeMapper::toScimUserResource(
            $row,
            $emails,
            $externalId,
            $groups,
            $jobTitle,
            $baseScimUrl
        );
    }

    /**
     * Run RuleSamlCollection against normalized SCIM user attributes and dynamic group memberships.
     *
     * @param int                  $idpId     Target IdP configuration ID.
     * @param int                  $userId    Target GLPI user ID.
     * @param array<string, mixed> $ruleInput Rule criteria extracted by ScimAttributeMapper.
     * @return void
     */
    private function runRulesEngine(int $idpId, int $userId, array $ruleInput): void
    {
        $dynamicGroupNames = $this->fetchUserDynamicGroupNames($userId);
        $matchInput = [
            SamlUser::NAME         => $ruleInput[SamlUser::NAME] ?? false,
            SamlUser::REALNAME     => $ruleInput[SamlUser::REALNAME] ?? false,
            SamlUser::FIRSTNAME    => $ruleInput[SamlUser::FIRSTNAME] ?? false,
            SamlUser::EMAIL        => $ruleInput[SamlUser::EMAIL] ?? false,
            SamlUser::MOBILE       => $ruleInput[SamlUser::MOBILE] ?? false,
            SamlUser::PHONE        => $ruleInput[SamlUser::PHONE] ?? false,
            SamlUser::SAMLGROUPS   => !empty($dynamicGroupNames) ? $dynamicGroupNames : false,
            'groups'               => !empty($dynamicGroupNames) ? $dynamicGroupNames : false,
            SamlUser::SAMLJOBTITLE => $ruleInput[SamlUser::SAMLJOBTITLE] ?? false,
            SamlUser::SAMLCOUNTRY  => $ruleInput[SamlUser::SAMLCOUNTRY] ?? false,
            SamlUser::SAMLCITY     => $ruleInput[SamlUser::SAMLCITY] ?? false,
            SamlUser::SAMLSTREET   => $ruleInput[SamlUser::SAMLSTREET] ?? false,
        ];

        $scimCriteriaKeys = [
            SamlUser::SCIM_DEPARTMENT,
            SamlUser::SCIM_ORGANIZATION,
            SamlUser::SCIM_DIVISION,
            SamlUser::SCIM_COST_CENTER,
            SamlUser::SCIM_EMPLOYEE_NUMBER,
        ];
        foreach ($scimCriteriaKeys as $scimCrit) {
            if (isset($ruleInput[$scimCrit]) && $ruleInput[$scimCrit] !== '') {
                $matchInput[$scimCrit] = $ruleInput[$scimCrit];
            }
        }

        $collection = new RuleSamlCollection();
        $collection->processAllRules($matchInput, [SamlUser::USERSID => $userId], []);
    }

    /**
     * Immediately invalidate GLPI remember-me tokens (GLPI 12 glpi_usertokens & GLPI 11 cookie_token)
     * and transition active samlSSO LoginState sessions for ($idpId, $userId) to PHASE_FORCE_LOG.
     *
     * @param int $idpId  Target IdP configuration ID.
     * @param int $userId Target GLPI user ID.
     * @return void
     */
    private function revokeUserSessions(int $idpId, int $userId): void
    {
        global $DB;

        if ($DB !== null) {
            if (method_exists($DB, 'tableExists') && $DB->tableExists('glpi_usertokens')) {
                $DB->delete('glpi_usertokens', [
                    SamlUser::USERSID => $userId,
                    'type'            => 'rememberme',
                ]);
            } elseif (method_exists($DB, 'fieldExists') && $DB->fieldExists('glpi_users', SamlUser::COOKIE_TOKEN, false)) {
                $DB->update('glpi_users', [SamlUser::COOKIE_TOKEN => null], [SamlUser::USERID => $userId]);
            }
        }

        LoginState::forceLogoffForUser($idpId, $userId);
    }

    /**
     * Ensure glpi_useremails rows exist when running against lightweight shims that do not execute User::post_addItem().
     *
     * @param int                $userId Target GLPI user ID.
     * @param array<int, string> $emails Ordered email addresses.
     * @return void
     */
    private function syncEmailsFallback(int $userId, array $emails): void
    {
        global $DB;

        if (empty($emails) || $DB === null || !method_exists($DB, 'request')) {
            return;
        }

        $existing = $this->fetchUserEmails($userId);
        if (!empty($existing)) {
            return;
        }

        foreach (array_values($emails) as $idx => $email) {
            $DB->insert('glpi_useremails', [
                SamlUser::USERSID => $userId,
                'email'           => $email,
                'is_default'      => $idx === 0 ? 1 : 0,
            ]);
        }
    }

    /**
     * Fetch ordered email addresses for a GLPI user (default email first).
     *
     * @param int $userId Target GLPI user ID.
     * @return array<int, string>
     */
    private function fetchUserEmails(int $userId): array
    {
        global $DB;

        $primary = [];
        $others = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_useremails',
            'WHERE' => [SamlUser::USERSID => $userId],
        ]);
        foreach ($iterator as $row) {
            $addr = trim((string) ($row['email'] ?? ''));
            if ($addr === '') {
                continue;
            }
            if ((int) ($row['is_default'] ?? 0) === 1) {
                $primary[] = $addr;
            } else {
                $others[] = $addr;
            }
        }

        return array_values(array_unique(array_merge($primary, $others)));
    }

    /**
     * Fetch read-only group descriptors ([value, display]) for a GLPI user.
     *
     * @param int $userId Target GLPI user ID.
     * @return array<int, array<string, string>>
     */
    private function fetchUserGroupSummaries(int $userId): array
    {
        global $DB;

        $groupIds = [];
        $guIter = $DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [SamlUser::USERSID => $userId],
        ]);
        foreach ($guIter as $gu) {
            $gid = (int) ($gu[SamlUser::GROUPID] ?? 0);
            if ($gid > 0) {
                $groupIds[$gid] = $gid;
            }
        }
        if (empty($groupIds)) {
            return [];
        }

        $out = [];
        $gIter = $DB->request([
            'FROM'  => 'glpi_groups',
            'WHERE' => ['id' => array_values($groupIds)],
        ]);
        foreach ($gIter as $gRow) {
            $out[] = [
                'value'   => (string) $gRow['id'],
                'display' => (string) ($gRow['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Fetch names of dynamically assigned GLPI groups (is_dynamic = 1) for RuleSamlCollection matching.
     *
     * @param int $userId Target GLPI user ID.
     * @return array<int, string>
     */
    private function fetchUserDynamicGroupNames(int $userId): array
    {
        global $DB;

        $groupIds = [];
        $guIter = $DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [
                SamlUser::USERSID    => $userId,
                SamlUser::IS_DYNAMIC => 1,
            ],
        ]);
        foreach ($guIter as $gu) {
            $gid = (int) ($gu[SamlUser::GROUPID] ?? 0);
            if ($gid > 0) {
                $groupIds[$gid] = $gid;
            }
        }
        if (empty($groupIds)) {
            return [];
        }

        $names = [];
        $gIter = $DB->request([
            'FROM'  => 'glpi_groups',
            'WHERE' => ['id' => array_values($groupIds)],
        ]);
        foreach ($gIter as $gRow) {
            $name = trim((string) ($gRow['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Resolve an existing GLPI UserTitle ID by exact name without creating unapproved dropdown entries.
     *
     * @param string $title Job title string.
     * @return int
     */
    private function resolveExistingUserTitleId(string $title): int
    {
        global $DB;

        if ($title === '' || $DB === null || !method_exists($DB, 'request')) {
            return 0;
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_usertitles',
            'WHERE' => ['name' => $title],
        ]);
        foreach ($iterator as $row) {
            return (int) ($row['id'] ?? 0);
        }

        return 0;
    }

    /**
     * Resolve an existing GLPI Location ID by exact locality or street name without creating dropdown sprawl.
     *
     * @param array{street: string, city: string, country: string} $address Extracted work address.
     * @return int
     */
    private function resolveExistingLocationId(array $address): int
    {
        global $DB;

        if ($DB === null || !method_exists($DB, 'request')) {
            return 0;
        }

        foreach ([$address['city'], $address['street']] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $iterator = $DB->request([
                'FROM'  => 'glpi_locations',
                'WHERE' => ['name' => $candidate],
            ]);
            foreach ($iterator as $row) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Fetch a GLPI UserTitle label by ID.
     *
     * @param int $titleId GLPI usertitles_id.
     * @return string
     */
    private function fetchUserTitleName(int $titleId): string
    {
        global $DB;

        if ($titleId <= 0 || $DB === null || !method_exists($DB, 'request')) {
            return '';
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_usertitles',
            'WHERE' => ['id' => $titleId],
        ]);
        foreach ($iterator as $row) {
            return trim((string) ($row['name'] ?? ''));
        }

        return '';
    }

    /**
     * Return GLPI's Auth::EXTERNAL integer constant (4).
     *
     * @return int
     */
    private static function externalAuthType(): int
    {
        return class_exists('\Auth') && defined('\Auth::EXTERNAL') ? (int) \Auth::EXTERNAL : 4;
    }

    /**
     * Version-selected payload sanitizer for GLPI 11 (Sanitizer::sanitize) and GLPI 12 (raw).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function sanitizeInput(array $input): array
    {
        if (class_exists('Glpi\Toolbox\Sanitizer')) {
            return \Glpi\Toolbox\Sanitizer::sanitize($input);
        }

        return $input;
    }

    /**
     * Record a sanitized SCIM provisioning event to the plugin events log.
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
