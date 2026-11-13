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
    require_once __DIR__ . '/../src/RuleSaml.php';
    require_once __DIR__ . '/../src/LoginState.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Controller\SamlSsoController;
    use GlpiPlugin\Samlsso\Exclude;
    use GlpiPlugin\Samlsso\LoginFlow;
    use GlpiPlugin\Samlsso\LoginState;
    use GlpiPlugin\Samlsso\RuleSaml;
    use GlpiPlugin\Samlsso\RuleSamlCollection;
    use GlpiPlugin\Samlsso\Scim\ScimAuthenticator;
    use GlpiPlugin\Samlsso\Scim\ScimDiscoveryService;
    use GlpiPlugin\Samlsso\Scim\ScimPatchProcessor;
    use GlpiPlugin\Samlsso\ScimLink;
    use Symfony\Component\HttpFoundation\Request;

    /**
     * Stateful in-memory MockDB subclass supporting multi-step SCIM CRUD round-trips.
     */
    class StatefulScimMockDB extends MockDB
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $tables = [];

        /** @var int */
        private int $autoId = 500;

        /**
         * Seed a table and synchronize parent MockDB response view.
         *
         * @param string                           $table
         * @param array<int, array<string, mixed>> $data
         * @return void
         */
        public function setResponse(string $table, array $data): void
        {
            $this->tables[$table] = array_values($data);
            parent::setResponse($table, $this->tables[$table]);
        }

        /**
         * Insert a row into the in-memory table and assign an auto-increment id when absent.
         *
         * @param string               $table
         * @param array<string, mixed> $data
         * @return bool
         */
        public function insert(string $table, array $data): bool
        {
            parent::insert($table, $data);
            if (!isset($data['id'])) {
                $data['id'] = ++$this->autoId;
            }
            $this->tables[$table] = $this->tables[$table] ?? [];
            $this->tables[$table][] = $data;
            parent::setResponse($table, $this->tables[$table]);
            return true;
        }

        /**
         * Update matching rows in the in-memory table.
         *
         * @param string               $table
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         * @return bool
         */
        public function update(string $table, array $data, array $where): bool
        {
            parent::update($table, $data, $where);
            if (!isset($this->tables[$table])) {
                return true;
            }
            $matchingIds = [];
            foreach ($this->request(['FROM' => $table, 'WHERE' => $where]) as $matched) {
                if (isset($matched['id'])) {
                    $matchingIds[(int) $matched['id']] = true;
                }
            }
            foreach ($this->tables[$table] as $idx => $row) {
                $rowId = isset($row['id']) ? (int) $row['id'] : -1;
                if (isset($matchingIds[$rowId])) {
                    $this->tables[$table][$idx] = array_merge($row, $data);
                }
            }
            parent::setResponse($table, $this->tables[$table]);
            return true;
        }

        /**
         * Delete matching rows from the in-memory table.
         *
         * @param string               $table
         * @param array<string, mixed> $where
         * @return bool
         */
        public function delete(string $table, array $where): bool
        {
            parent::delete($table, $where);
            if (!isset($this->tables[$table])) {
                return true;
            }
            $matchingIds = [];
            foreach ($this->request(['FROM' => $table, 'WHERE' => $where]) as $matched) {
                if (isset($matched['id'])) {
                    $matchingIds[(int) $matched['id']] = true;
                }
            }
            $remaining = [];
            foreach ($this->tables[$table] as $row) {
                $rowId = isset($row['id']) ? (int) $row['id'] : -1;
                if (!isset($matchingIds[$rowId])) {
                    $remaining[] = $row;
                }
            }
            $this->tables[$table] = $remaining;
            parent::setResponse($table, $this->tables[$table]);
            return true;
        }
    }

    /**
     * Stateful User mock adapter wired to StatefulScimMockDB for realistic User::add/update/getFromDB.
     */
    class StatefulUserAdapter
    {
        /** @var StatefulScimMockDB */
        private StatefulScimMockDB $db;

        /** @var int */
        private int $nextUserId = 100;

        /** @var array<int, array<string, mixed>> */
        public array $lastAddPayloads = [];

        /**
         * @param StatefulScimMockDB $db
         */
        public function __construct(StatefulScimMockDB $db)
        {
            $this->db = $db;
        }

        /**
         * Fetch user row from StatefulScimMockDB.
         *
         * @param int    $id
         * @param object $instance
         * @return bool
         */
        public function getFromDB(int $id, object $instance): bool
        {
            $iter = $this->db->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $id]]);
            if (count($iter) > 0) {
                $instance->fields = $iter->current();
                return true;
            }
            return false;
        }

        /**
         * Emulate GLPI User::add() with _extauth + _useremails synchronization.
         *
         * @param array<string, mixed> $input
         * @return int
         */
        public function add(array $input): int
        {
            $this->lastAddPayloads[] = $input;
            $newId = ++$this->nextUserId;
            $emails = isset($input['_useremails']) && is_array($input['_useremails']) ? $input['_useremails'] : [];
            unset($input['_extauth'], $input['_useremails']);

            $now = date('Y-m-d H:i:s');
            $row = array_merge([
                'id'                  => $newId,
                'name'                => '',
                'firstname'           => '',
                'realname'            => '',
                'phone'               => '',
                'phone2'              => '',
                'mobile'              => '',
                'registration_number' => '',
                'usertitles_id'       => 0,
                'locations_id'        => 0,
                'authtype'            => 0,
                'auths_id'            => 0,
                'is_active'           => 1,
                'is_deleted'          => 0,
                'date_creation'       => $now,
                'date_mod'            => $now,
            ], $input);

            $this->db->insert('glpi_users', $row);
            foreach (array_values($emails) as $idx => $email) {
                if (is_string($email) && $email !== '') {
                    $this->db->insert('glpi_useremails', [
                        'users_id'   => $newId,
                        'email'      => $email,
                        'is_default' => $idx === 0 ? 1 : 0,
                    ]);
                }
            }

            return $newId;
        }

        /**
         * Emulate GLPI User::update() with _useremails synchronization.
         *
         * @param array<string, mixed> $input
         * @return bool
         */
        public function update(array $input): bool
        {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                return false;
            }
            if (isset($input['_useremails']) && is_array($input['_useremails'])) {
                $this->db->delete('glpi_useremails', ['users_id' => $id]);
                foreach (array_values($input['_useremails']) as $idx => $email) {
                    if (is_string($email) && $email !== '') {
                        $this->db->insert('glpi_useremails', [
                            'users_id'   => $id,
                            'email'      => $email,
                            'is_default' => $idx === 0 ? 1 : 0,
                        ]);
                    }
                }
            }
            unset($input['id'], $input['_extauth'], $input['_useremails']);
            $input['date_mod'] = date('Y-m-d H:i:s');
            return $this->db->update('glpi_users', $input, ['id' => $id]);
        }
    }

    /**
     * ScimProvisioningTest verifies end-to-end SCIM 2.0 User & Group lifecycle,
     * ADR 0004 fail-closed account isolation, immediate session revocation,
     * RuleSamlCollection integration, and controller routing.
     */
    class ScimProvisioningTest extends TestHarness
    {
        /** @var StatefulScimMockDB */
        private StatefulScimMockDB $stateDb;

        /** @var StatefulUserAdapter */
        private StatefulUserAdapter $userAdapter;

        /** @var string */
        private string $bearerToken = '';

        /**
         * Assert boolean condition or throw descriptive Exception.
         *
         * @param bool   $condition
         * @param string $message
         * @return void
         */
        private function assertTrue(bool $condition, string $message): void
        {
            if (!$condition) {
                throw new \Exception($message);
            }
        }

        /**
         * Assert strict equality or throw descriptive Exception.
         *
         * @param mixed  $expected
         * @param mixed  $actual
         * @param string $message
         * @return void
         */
        private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                throw new \Exception(sprintf(
                    '%s Expected %s, got %s.',
                    $message,
                    var_export($expected, true),
                    var_export($actual, true)
                ));
            }
        }

        /**
         * Set up stateful mock DB, active SCIM IdP #5, and seeded reference records.
         *
         * @param string $groupPolicy
         * @param string $deprovisionMode
         * @return void
         */
        private function bootScimWorld(
            string $groupPolicy = 'create_scoped',
            string $deprovisionMode = 'disable'
        ): void {
            global $DB, $CFG_GLPI;

            $this->stateDb = new StatefulScimMockDB();
            $DB = $this->stateDb;
            $this->db = $this->stateDb;
            $CFG_GLPI = ['url_base' => 'https://glpi.example.org', 'root_doc' => ''];

            $this->userAdapter = new StatefulUserAdapter($this->stateDb);
            \User::$mockObject = $this->userAdapter;
            RuleSamlCollection::$lastMatchInput = null;

            $pair = ScimAuthenticator::generateToken();
            $this->bearerToken = $pair['plaintext'];

            ConfigEntity::$mockIsValid = true;
            ConfigEntity::$mockIsActive = true;
            ConfigEntity::$mockIsDeleted = false;
            ConfigEntity::$mockFields = [
                ConfigEntity::ID                => 5,
                ConfigEntity::NAME              => 'Corporate Entra ID',
                ConfigEntity::IS_ACTIVE         => 1,
                ConfigEntity::IS_DELETED        => 0,
                ConfigEntity::SCIM_ENABLED      => 1,
                ConfigEntity::SCIM_TOKEN_SOURCE => 'db',
                ConfigEntity::SCIM_TOKEN_HASH   => $pair['hash'],
                ConfigEntity::SCIM_ALLOWED_IPS  => '',
                ConfigEntity::SCIM_GROUP_POLICY => $groupPolicy,
                ConfigEntity::SCIM_DEPROVISION  => $deprovisionMode,
            ];

            $this->stateDb->setResponse('glpi_users', [
                [
                    'id'          => 1,
                    'name'        => 'glpi',
                    'firstname'   => 'Super',
                    'realname'    => 'Admin',
                    'authtype'    => 1,
                    'auths_id'    => 0,
                    'is_active'   => 1,
                    'is_deleted'  => 0,
                ],
                [
                    'id'          => 2,
                    'name'        => 'local.admin@example.org',
                    'firstname'   => 'Local',
                    'realname'    => 'Admin',
                    'authtype'    => 1,
                    'auths_id'    => 0,
                    'is_active'   => 1,
                    'is_deleted'  => 0,
                ],
                [
                    'id'          => 3,
                    'name'        => 'ldap.user@example.org',
                    'firstname'   => 'Ldap',
                    'realname'    => 'User',
                    'authtype'    => 3,
                    'auths_id'    => 2,
                    'is_active'   => 1,
                    'is_deleted'  => 0,
                ],
                [
                    'id'          => 4,
                    'name'        => 'foreign.idp@example.org',
                    'firstname'   => 'Foreign',
                    'realname'    => 'IdpUser',
                    'authtype'    => 4,
                    'auths_id'    => 9,
                    'is_active'   => 1,
                    'is_deleted'  => 0,
                ],
                [
                    'id'          => 50,
                    'name'        => 'jit.existing@example.org',
                    'firstname'   => 'Jit',
                    'realname'    => 'Existing',
                    'authtype'    => 4,
                    'auths_id'    => 5,
                    'is_active'   => 1,
                    'is_deleted'  => 0,
                ],
            ]);

            $this->stateDb->setResponse('glpi_useremails', [
                ['id' => 1, 'users_id' => 2, 'email' => 'local.admin@example.org', 'is_default' => 1],
                ['id' => 2, 'users_id' => 3, 'email' => 'ldap.user@example.org', 'is_default' => 1],
                ['id' => 3, 'users_id' => 4, 'email' => 'foreign.idp@example.org', 'is_default' => 1],
                ['id' => 4, 'users_id' => 50, 'email' => 'jit.existing@example.org', 'is_default' => 1],
            ]);

            $this->stateDb->setResponse('glpi_usertitles', [
                ['id' => 7, 'name' => 'Principal Engineer'],
            ]);
            $this->stateDb->setResponse('glpi_locations', [
                ['id' => 11, 'name' => 'Amsterdam'],
            ]);
            $this->stateDb->setResponse('glpi_groups', [
                [
                    'id'            => 20,
                    'name'          => 'PreExisting-IT',
                    'entities_id'   => 0,
                    'is_recursive'  => 1,
                    'date_creation' => '2026-01-01 00:00:00',
                    'date_mod'      => '2026-01-01 00:00:00',
                ],
                [
                    'id'            => 21,
                    'name'          => 'ForeignOwnedGroup',
                    'entities_id'   => 0,
                    'is_recursive'  => 1,
                    'date_creation' => '2026-01-01 00:00:00',
                    'date_mod'      => '2026-01-01 00:00:00',
                ],
            ]);
            $this->stateDb->setResponse('glpi_groups_users', [
                ['id' => 1, 'users_id' => 50, 'groups_id' => 20, 'is_dynamic' => 0],
            ]);
            $this->stateDb->setResponse('glpi_plugin_samlsso_scimlinks', [
                [
                    'id'             => 1,
                    'configs_id'     => 9,
                    'item_type'      => 'Group',
                    'glpi_item_id'   => 21,
                    'external_id'    => 'foreign-grp-21',
                    'last_sync_date' => '2026-01-01 00:00:00',
                ],
            ]);
            $this->stateDb->setResponse('glpi_plugin_samlsso_loginstates', [
                [
                    'id'        => 301,
                    'userId'    => 50,
                    'idpId'     => 5,
                    'phase'     => LoginState::PHASE_GLPI_AUTH,
                    'sessionId' => 'sess_jit_50',
                ],
                [
                    'id'        => 302,
                    'userId'    => 4,
                    'idpId'     => 9,
                    'phase'     => LoginState::PHASE_GLPI_AUTH,
                    'sessionId' => 'sess_foreign_4',
                ],
            ]);
            $this->stateDb->setResponse('glpi_usertokens', [
                ['id' => 401, 'users_id' => 50, 'type' => 'rememberme', 'token_uid' => 'rm_50'],
            ]);
        }

        /**
         * Build an authenticated HTTPS SCIM Request and invoke SamlSsoController::scim().
         *
         * @param string                    $method
         * @param string                    $scimPath
         * @param array<string, mixed>|null $jsonBody
         * @param array<string, mixed>      $query
         * @param int                       $idpId
         * @return \Symfony\Component\HttpFoundation\Response
         */
        private function callScim(
            string $method,
            string $scimPath,
            ?array $jsonBody = null,
            array $query = [],
            int $idpId = 5
        ): \Symfony\Component\HttpFoundation\Response {
            $raw = $jsonBody !== null ? (string) json_encode($jsonBody) : '';
            $request = Request::create(
                'https://glpi.example.org/plugins/samlsso/front/scim/v2/' . $idpId . $scimPath,
                $method,
                $query,
                [],
                [],
                [
                    'HTTPS'              => 'on',
                    'REMOTE_ADDR'        => '198.51.100.25',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearerToken,
                    'CONTENT_TYPE'       => 'application/scim+json',
                ],
                $raw
            );

            $controller = new SamlSsoController();
            return $controller->scim($request, $idpId, $scimPath);
        }

        /**
         * Test 1: RuleSaml exposes SCIM Enterprise criteria and LoginFlow/Exclude skip stateless SCIM routes.
         *
         * @return void
         */
        public function testRuleCriteriaAndStatelessRouteExclusion(): void
        {
            $this->bootScimWorld();

            $rule = new RuleSaml();
            $criterias = $rule->getCriterias();
            foreach (['scimDepartment', 'scimOrganization', 'scimDivision', 'scimCostCenter', 'scimEmployeeNumber'] as $key) {
                $this->assertTrue(isset($criterias[$key]), "RuleSaml::getCriterias() must expose $key.");
            }

            $_SERVER['REQUEST_URI'] = '/plugins/samlsso/front/scim/v2/5/Users';
            $flow = new LoginFlow();
            $result = $flow->doAuth();
            $this->assertTrue($result === null, 'LoginFlow::doAuth() must fast-return null on stateless SCIM routes.');
            unset($_SERVER['REQUEST_URI']);

            echo "✅ SCIM Provisioning: RuleSaml criteria and stateless LoginFlow fast-exit verified\n";
        }

        /**
         * Test 2: POST /Users creates an external user, links ScimLink, resolves lookups, and runs RuleSamlCollection.
         *
         * @return void
         */
        public function testUserProvisioningAndRulesEngineIntegration(): void
        {
            $this->bootScimWorld();

            $res = $this->callScim('POST', '/Users', [
                'schemas'    => [
                    ScimDiscoveryService::SCHEMA_CORE_USER,
                    ScimDiscoveryService::SCHEMA_ENTERPRISE_USER,
                ],
                'userName'   => 'claire.novak@example.org',
                'externalId' => 'entra-oid-claire-1',
                'active'     => true,
                'name'       => ['givenName' => 'Claire', 'familyName' => 'Novak'],
                'title'      => 'Principal Engineer',
                'emails'     => [['value' => 'claire.novak@example.org', 'type' => 'work', 'primary' => true]],
                'addresses'  => [['type' => 'work', 'locality' => 'Amsterdam', 'country' => 'NL']],
                ScimDiscoveryService::SCHEMA_ENTERPRISE_USER => [
                    'employeeNumber' => 'EMP-7788',
                    'department'     => 'Platform Security',
                    'organization'   => 'QuinQuies',
                ],
            ]);

            $this->assertEquals(201, $res->getStatusCode(), 'POST /Users must return 201 Created.');
            $created = json_decode((string) $res->getContent(), true);
            $newId = (int) ($created['id'] ?? 0);
            $this->assertTrue($newId > 0, 'Created SCIM User must have positive GLPI ID.');
            $this->assertEquals('entra-oid-claire-1', $created['externalId'] ?? '');
            $this->assertEquals('Principal Engineer', $created['title'] ?? '');
            $this->assertTrue(str_contains((string) $res->headers->get('Location'), '/Users/' . $newId), '201 must set Location header.');

            $lastAdd = end($this->userAdapter->lastAddPayloads);
            $this->assertEquals(4, (int) ($lastAdd['authtype'] ?? 0), 'Provisioned user must use Auth::EXTERNAL (4).');
            $this->assertEquals(5, (int) ($lastAdd['auths_id'] ?? 0), 'Provisioned user must bind auths_id = 5.');
            $this->assertEquals(7, (int) ($lastAdd['usertitles_id'] ?? 0), 'Existing UserTitle must be resolved.');
            $this->assertEquals(11, (int) ($lastAdd['locations_id'] ?? 0), 'Existing Location must be resolved.');

            $link = ScimLink::findByGlpiItem(5, ScimLink::TYPE_USER, $newId);
            $this->assertTrue($link !== null && $link['external_id'] === 'entra-oid-claire-1', 'ScimLink must be persisted.');

            $this->assertTrue(is_array(RuleSamlCollection::$lastMatchInput), 'RuleSamlCollection must run on SCIM provisioning.');
            $this->assertEquals('Platform Security', RuleSamlCollection::$lastMatchInput['scimDepartment'] ?? '');
            $this->assertEquals('QuinQuies', RuleSamlCollection::$lastMatchInput['scimOrganization'] ?? '');

            echo "✅ SCIM Provisioning: POST /Users Auth::EXTERNAL binding, lookup resolution, and RuleSamlCollection verified\n";
        }

        /**
         * Test 3: Cycle-1 SAML JIT user adoption vs fail-closed collision isolation (ADR 0004).
         *
         * @return void
         */
        public function testJitAdoptionAndFailClosedAccountIsolation(): void
        {
            $this->bootScimWorld();

            /** 1. GET filter finds existing SAML JIT user #50 for IdP #5 even before ScimLink exists. */
            $listRes = $this->callScim('GET', '/Users', null, ['filter' => 'userName eq "jit.existing@example.org"']);
            $this->assertEquals(200, $listRes->getStatusCode(), 'GET /Users filter must succeed.');
            $listBody = json_decode((string) $listRes->getContent(), true);
            $this->assertEquals(1, $listBody['totalResults'] ?? 0, 'Existing IdP #5 JIT user must be discoverable.');
            $this->assertEquals('50', $listBody['Resources'][0]['id'] ?? '');

            /** 2. POST /Users seamlessly adopts existing IdP #5 JIT user #50 and creates ScimLink. */
            $adoptRes = $this->callScim('POST', '/Users', [
                'schemas'    => [ScimDiscoveryService::SCHEMA_CORE_USER],
                'userName'   => 'jit.existing@example.org',
                'externalId' => 'adopted-oid-50',
                'name'       => ['givenName' => 'JitAdopted', 'familyName' => 'Existing'],
            ]);
            $this->assertEquals(201, $adoptRes->getStatusCode(), 'Adopting existing IdP #5 JIT user via POST must return 201.');
            $adoptBody = json_decode((string) $adoptRes->getContent(), true);
            $this->assertEquals('50', $adoptBody['id'] ?? '', 'Must adopt user #50 in-place rather than duplicating.');
            $this->assertTrue(ScimLink::findByExternalId(5, ScimLink::TYPE_USER, 'adopted-oid-50') !== null, 'ScimLink created on adoption.');

            /** 3. Collisions with glpi, local DB_GLPI, LDAP, and foreign IdP #9 MUST fail closed with 409 uniqueness. */
            foreach (['glpi', 'local.admin@example.org', 'ldap.user@example.org', 'foreign.idp@example.org'] as $forbiddenName) {
                $collisionRes = $this->callScim('POST', '/Users', [
                    'schemas'  => [ScimDiscoveryService::SCHEMA_CORE_USER],
                    'userName' => $forbiddenName,
                ]);
                $this->assertEquals(409, $collisionRes->getStatusCode(), "POST collision with '$forbiddenName' must return 409 Conflict.");
                $errBody = json_decode((string) $collisionRes->getContent(), true);
                $this->assertEquals('uniqueness', $errBody['scimType'] ?? '');
            }

            /** 4. Direct GET/PATCH/DELETE against local (#1, #2), LDAP (#3), or foreign IdP (#4) users MUST return 404. */
            foreach ([1, 2, 3, 4] as $forbiddenId) {
                $getRes = $this->callScim('GET', '/Users/' . $forbiddenId);
                $this->assertEquals(404, $getRes->getStatusCode(), "GET /Users/$forbiddenId must return 404.");
                $delRes = $this->callScim('DELETE', '/Users/' . $forbiddenId);
                $this->assertEquals(404, $delRes->getStatusCode(), "DELETE /Users/$forbiddenId must return 404.");
            }

            echo "✅ SCIM Provisioning: Cycle-1 JIT adoption and ADR 0004 fail-closed collision/tenant isolation verified\n";
        }

        /**
         * Test 4: Immediate session revocation on PATCH active=false and DELETE offboarding modes (disable vs trashbin).
         *
         * @return void
         */
        public function testImmediateDeactivationRevocationAndDeleteModes(): void
        {
            $this->bootScimWorld('create_scoped', 'disable');

            /** 1. Entra-style PATCH active="False" on user #50. */
            $patchRes = $this->callScim('PATCH', '/Users/50', [
                'schemas'    => [ScimPatchProcessor::SCHEMA_PATCH_OP],
                'Operations' => [
                    ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
                ],
            ]);
            $this->assertEquals(200, $patchRes->getStatusCode(), 'PATCH /Users/50 must return 200 OK.');
            $patchedBody = json_decode((string) $patchRes->getContent(), true);
            $this->assertTrue($patchedBody['active'] === false, 'Patched user active flag must be false.');

            /** Verify remember-me token purged and LoginState #301 transitioned to PHASE_FORCE_LOG (6), while foreign #302 is untouched. */
            $tokens50 = $this->stateDb->request(['FROM' => 'glpi_usertokens', 'WHERE' => ['users_id' => 50]]);
            $this->assertEquals(0, count($tokens50), 'Remember-me tokens in glpi_usertokens must be deleted immediately.');

            $state301 = $this->stateDb->request(['FROM' => 'glpi_plugin_samlsso_loginstates', 'WHERE' => ['id' => 301]])->current();
            $state302 = $this->stateDb->request(['FROM' => 'glpi_plugin_samlsso_loginstates', 'WHERE' => ['id' => 302]])->current();
            $this->assertEquals(LoginState::PHASE_FORCE_LOG, (int) $state301['phase'], 'Active LoginState for IdP #5 user #50 must become PHASE_FORCE_LOG.');
            $this->assertEquals(LoginState::PHASE_GLPI_AUTH, (int) $state302['phase'], 'Foreign IdP #9 LoginState must remain untouched.');

            /** 2. DELETE with scim_deprovision = 'trashbin' marks is_deleted = 1 and is_active = 0 without purging row. */
            $this->bootScimWorld('create_scoped', 'trashbin');
            ScimLink::upsertLink(5, ScimLink::TYPE_USER, 50, 'oid-50');
            $delRes = $this->callScim('DELETE', '/Users/50');
            $this->assertEquals(204, $delRes->getStatusCode(), 'DELETE /Users/50 must return 204 No Content.');

            $user50 = $this->stateDb->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => 50]])->current();
            $this->assertEquals(0, (int) $user50['is_active'], 'Trashbin offboarding must disable user.');
            $this->assertEquals(1, (int) $user50['is_deleted'], 'Trashbin offboarding must set is_deleted = 1.');
            $this->assertTrue(ScimLink::findByGlpiItem(5, ScimLink::TYPE_USER, 50) === null, 'ScimLink must be removed on DELETE.');

            echo "✅ SCIM Provisioning: Immediate session/token revocation and disable/trashbin offboarding verified\n";
        }

        /**
         * Test 5: /Groups lifecycle across create_scoped, link_existing, and disabled policies.
         *
         * @return void
         */
        public function testGroupLifecyclePoliciesAndDynamicMembership(): void
        {
            /** Mode A: create_scoped */
            $this->bootScimWorld('create_scoped');
            $postGrp = $this->callScim('POST', '/Groups', [
                'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_GROUP],
                'displayName' => 'Cloud-SecOps',
                'externalId'  => 'grp-secops-1',
                'members'     => [['value' => '50']],
            ]);
            $this->assertEquals(201, $postGrp->getStatusCode(), 'POST /Groups in create_scoped must return 201.');
            $grpBody = json_decode((string) $postGrp->getContent(), true);
            $grpId = (int) ($grpBody['id'] ?? 0);
            $this->assertTrue($grpId > 0, 'Created group must return positive ID.');
            $this->assertEquals(1, count($grpBody['members'] ?? []), 'Member #50 must be dynamically linked.');
            $this->assertTrue(
                in_array('Cloud-SecOps', RuleSamlCollection::$lastMatchInput['samlClaimedGroups'] ?? [], true),
                'Group sync must re-evaluate RuleSamlCollection with samlClaimedGroups.'
            );

            /** Reject adding local/foreign user #2 to SCIM group. */
            $badMemberRes = $this->callScim('PATCH', '/Groups/' . $grpId, [
                'schemas'    => [ScimPatchProcessor::SCHEMA_PATCH_OP],
                'Operations' => [
                    ['op' => 'Add', 'path' => 'members', 'value' => [['value' => '2']]],
                ],
            ]);
            $this->assertEquals(400, $badMemberRes->getStatusCode(), 'Adding non-IdP user to SCIM group must fail closed with 400.');

            /** Adopt PreExisting-IT (#20) which already has manual is_dynamic = 0 membership for user #50, then DELETE it. */
            $adoptGrp = $this->callScim('POST', '/Groups', [
                'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_GROUP],
                'displayName' => 'PreExisting-IT',
                'externalId'  => 'grp-it-20',
                'members'     => [['value' => '50']],
            ]);
            $this->assertEquals(201, $adoptGrp->getStatusCode(), 'Adopting unowned existing group must return 201.');
            $delGrp = $this->callScim('DELETE', '/Groups/20');
            $this->assertEquals(204, $delGrp->getStatusCode(), 'DELETE /Groups/20 must return 204.');

            $grp20StillExists = $this->stateDb->request(['FROM' => 'glpi_groups', 'WHERE' => ['id' => 20]]);
            $this->assertEquals(1, count($grp20StillExists), 'DELETE /Groups must NEVER delete the glpi_groups row itself.');
            $manualMemberStillExists = $this->stateDb->request([
                'FROM'  => 'glpi_groups_users',
                'WHERE' => ['groups_id' => 20, 'users_id' => 50, 'is_dynamic' => 0],
            ]);
            $this->assertEquals(1, count($manualMemberStillExists), 'Manual is_dynamic = 0 membership must be preserved.');

            /** Mode B: link_existing */
            $this->bootScimWorld('link_existing');
            $missingGrp = $this->callScim('POST', '/Groups', [
                'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_GROUP],
                'displayName' => 'NonExistent-Group',
            ]);
            $this->assertEquals(400, $missingGrp->getStatusCode(), 'link_existing must reject creating non-existent GLPI groups with 400.');

            $foreignGrp = $this->callScim('POST', '/Groups', [
                'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_GROUP],
                'displayName' => 'ForeignOwnedGroup',
            ]);
            $this->assertEquals(409, $foreignGrp->getStatusCode(), 'Group already owned by foreign IdP #9 must return 409 uniqueness.');

            $linkedGrp = $this->callScim('POST', '/Groups', [
                'schemas'     => [ScimDiscoveryService::SCHEMA_CORE_GROUP],
                'displayName' => 'PreExisting-IT',
                'externalId'  => 'linked-20',
            ]);
            $this->assertEquals(201, $linkedGrp->getStatusCode(), 'link_existing must link pre-existing unowned GLPI group.');

            /** Mode C: disabled */
            $this->bootScimWorld('disabled');
            $disabledGet = $this->callScim('GET', '/Groups');
            $this->assertEquals(404, $disabledGet->getStatusCode(), 'GET /Groups when disabled must return 404.');

            /** /Bulk must return 501 Not Implemented */
            $bulkRes = $this->callScim('POST', '/Bulk', ['Operations' => []]);
            $this->assertEquals(501, $bulkRes->getStatusCode(), 'POST /Bulk must return 501 Not Implemented.');

            echo "✅ SCIM Provisioning: Group policies (create_scoped, link_existing, disabled), dynamic membership preservation, and /Bulk 501 verified\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\ScimProvisioningTest();
    try {
        $test->testRuleCriteriaAndStatelessRouteExclusion();
        $test->testUserProvisioningAndRulesEngineIntegration();
        $test->testJitAdoptionAndFailClosedAccountIsolation();
        $test->testImmediateDeactivationRevocationAndDeleteModes();
        $test->testGroupLifecyclePoliciesAndDynamicMembership();
    } catch (\Throwable $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
