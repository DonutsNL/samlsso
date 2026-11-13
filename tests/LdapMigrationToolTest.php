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
    require_once __DIR__ . '/../tools/migrate_ldap_users.php';

    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Config\MockConfigEntity;
    use GlpiPlugin\Samlsso\Tools\LdapUserMigrator;

    /**
     * Automated TDD test suite for tools/migrate_ldap_users.php (Issue #189).
     *
     * Verifies non-destructive in-place LDAP-to-SAML user migration, active
     * glpi_authldaps collision guards, 0600 GLPIKey HMAC-signed migrated-user-ID
     * register files, --list-registers, tamper/path-traversal rejection, and --revert.
     */
    class LdapMigrationToolTest extends TestHarness
    {
        private string $tempRegisterDir;

        /**
         * Initializes an isolated temporary register directory per test run.
         */
        public function __construct()
        {
            parent::__construct();
            $this->tempRegisterDir = sys_get_temp_dir() . '/samlsso_ldap_mig_test_' . bin2hex(random_bytes(6));
            mkdir($this->tempRegisterDir, 0700, true);
        }

        /**
         * Cleans up temporary migration register artifacts.
         */
        public function __destruct()
        {
            if (is_dir($this->tempRegisterDir)) {
                $files = glob($this->tempRegisterDir . '/*') ?: [];
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                @rmdir($this->tempRegisterDir);
            }
            parent::__destruct();
        }

        /**
         * Helper executing LdapUserMigrator with in-memory stdout/stderr capture.
         *
         * @param array<int, string> $args CLI arguments (excluding script name).
         * @return array{code: int, stdout: string, stderr: string}
         */
        private function runTool(array $args): array
        {
            $out = fopen('php://temp', 'w+');
            $err = fopen('php://temp', 'w+');
            $migrator = new LdapUserMigrator($this->tempRegisterDir);
            $code = $migrator->run(array_merge(['migrate_ldap_users.php'], $args), $out, $err);
            rewind($out);
            rewind($err);
            $stdout = (string)stream_get_contents($out);
            $stderr = (string)stream_get_contents($err);
            fclose($out);
            fclose($err);
            return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        /**
         * Seeds mock LDAP users, emails, local collisions, and LDAP directory servers.
         *
         * @param int $ldapActive Whether glpi_authldaps id=2 is active.
         * @return void
         */
        private function seedEnvironment(int $ldapActive = 1): void
        {
            MockConfigEntity::$mockIsValid = true;
            MockConfigEntity::$mockIsActive = true;
            MockConfigEntity::$mockIsDeleted = false;
            MockConfigEntity::$mockFields = [
                ConfigEntity::ID        => 1,
                ConfigEntity::NAME      => 'Corporate Entra SAML',
                ConfigEntity::IS_ACTIVE => 1,
            ];

            $this->db->updatedRows = [];
            $this->db->deletedRows = [];
            \Log::$entries = [];

            $this->db->setResponse('glpi_authldaps', [
                ['id' => 1, 'name' => 'Legacy SSO LDAP #1', 'is_active' => 0],
                ['id' => 2, 'name' => 'Corporate AD LDAP #2', 'is_active' => $ldapActive],
            ]);

            $this->db->setResponse('glpi_users', [
                [
                    'id'              => 1,
                    'name'            => 'glpi',
                    'authtype'        => 1,
                    'auths_id'        => 0,
                    'user_dn'         => null,
                    'user_dn_hash'    => null,
                    'sync_field'      => null,
                    'is_deleted'      => 0,
                    'is_deleted_ldap' => 0,
                    'is_active'       => 1,
                ],
                [
                    'id'              => 10,
                    'name'            => 'jdoe',
                    'authtype'        => 3,
                    'auths_id'        => 2,
                    'user_dn'         => 'CN=John Doe,OU=Users,DC=corp,DC=local',
                    'user_dn_hash'    => 'abcd1234',
                    'sync_field'      => 'objectguid-10',
                    'is_deleted'      => 0,
                    'is_deleted_ldap' => 0,
                    'is_active'       => 1,
                ],
                [
                    'id'              => 14,
                    'name'            => 'asmith',
                    'authtype'        => 3,
                    'auths_id'        => 2,
                    'user_dn'         => 'CN=Alice Smith,OU=Users,DC=corp,DC=local',
                    'user_dn_hash'    => 'efgh5678',
                    'sync_field'      => 'objectguid-14',
                    'is_deleted'      => 0,
                    'is_deleted_ldap' => 1,
                    'is_active'       => 1,
                ],
                [
                    'id'              => 20,
                    'name'            => 'already_migrated',
                    'authtype'        => 3,
                    'auths_id'        => 2,
                    'user_dn'         => 'CN=Dup,DC=corp,DC=local',
                    'user_dn_hash'    => 'dup999',
                    'sync_field'      => 'objectguid-20',
                    'is_deleted'      => 0,
                    'is_deleted_ldap' => 0,
                    'is_active'       => 1,
                ],
                [
                    'id'              => 21,
                    'name'            => 'already_migrated',
                    'authtype'        => 4,
                    'auths_id'        => 1,
                    'user_dn'         => null,
                    'user_dn_hash'    => null,
                    'sync_field'      => null,
                    'is_deleted'      => 0,
                    'is_deleted_ldap' => 0,
                    'is_active'       => 1,
                ],
            ]);

            $this->db->setResponse('glpi_useremails', [
                ['users_id' => 10, 'email' => 'jdoe@corp.example.com', 'is_default' => 1],
                ['users_id' => 14, 'email' => 'asmith@corp.example.com', 'is_default' => 1],
                ['users_id' => 20, 'email' => 'already@corp.example.com', 'is_default' => 1],
            ]);
        }

        /**
         * Test 1: --help and default --dry-run output Wiki manual URL, planned user IDs,
         * and collision warnings without writing any DB updates or register files.
         */
        public function testHelpAndDryRunDefault(): void
        {
            $this->seedEnvironment(1);

            $help = $this->runTool(['--help']);
            if ($help['code'] !== 0 || !str_contains($help['stdout'], 'https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML')) {
                throw new \Exception('--help failed or omitted Wiki manual URL.');
            }

            $dry = $this->runTool(['--ldap-id=2', '--saml-idp-id=1']);
            if ($dry['code'] !== 0) {
                throw new \Exception('Expected --dry-run default to exit 0, got ' . $dry['code'] . ' (' . $dry['stderr'] . ')');
            }
            if (!str_contains($dry['stdout'], 'DRY-RUN') || !str_contains($dry['stdout'], 'jdoe') || !str_contains($dry['stdout'], 'already_migrated')) {
                throw new \Exception('Dry-run output missing planned transitions or collision notice: ' . $dry['stdout']);
            }
            if (!empty($this->db->updatedRows)) {
                throw new \Exception('Dry-run MUST NOT mutate database rows.');
            }
            $registers = glob($this->tempRegisterDir . '/*.json') ?: [];
            if (!empty($registers)) {
                throw new \Exception('Dry-run MUST NOT create migration register files.');
            }

            echo "✅ LDAP Migration CLI: --help and default --dry-run verified\n";
        }

        /**
         * Test 2: Active glpi_authldaps guard blocks --apply unless --deactivate-ldap-server is given.
         */
        public function testActiveLdapServerGuard(): void
        {
            $this->seedEnvironment(1);

            $blocked = $this->runTool(['--apply', '--ldap-id=2', '--saml-idp-id=1']);
            if ($blocked['code'] === 0) {
                throw new \Exception('Expected --apply to fail closed while glpi_authldaps id=2 is still active.');
            }
            if (!str_contains($blocked['stderr'], '--deactivate-ldap-server')) {
                throw new \Exception('Expected actionable --deactivate-ldap-server error, got: ' . $blocked['stderr']);
            }
            if (!empty($this->db->updatedRows)) {
                throw new \Exception('Blocked --apply MUST NOT mutate database rows.');
            }

            echo "✅ LDAP Migration CLI: active glpi_authldaps collision guard verified\n";
        }

        /**
         * Test 3, 4 & 5: --apply writes 0600 HMAC-signed user-ID register and re-binds in-place;
         * --list-registers inspects registers; --revert rejects tampering/traversal and restores exact state.
         */
        public function testApplySignedRegisterListAndRevert(): void
        {
            $this->seedEnvironment(1);

            $apply = $this->runTool([
                '--apply',
                '--ldap-id=2',
                '--saml-idp-id=1',
                '--login-format=email',
                '--deactivate-ldap-server',
            ]);
            if ($apply['code'] !== 0) {
                throw new \Exception('--apply failed unexpectedly: ' . $apply['stderr'] . ' / ' . $apply['stdout']);
            }

            /** Verify zero rows deleted anywhere (non-destructive). */
            if (!empty($this->db->deletedRows)) {
                throw new \Exception('Migration MUST NEVER delete rows from glpi_users or relational tables.');
            }

            /** Verify signed register file exists with 0600 permissions and explicit migrated_user_ids array. */
            $registers = glob($this->tempRegisterDir . '/*.json') ?: [];
            if (count($registers) !== 1) {
                throw new \Exception('Expected exactly 1 migration register JSON file, found ' . count($registers));
            }
            $registerPath = $registers[0];
            $perms = fileperms($registerPath) & 0777;
            if ($perms !== 0600) {
                throw new \Exception(sprintf('Expected register file permissions 0600, got %04o', $perms));
            }

            $registerData = json_decode((string)file_get_contents($registerPath), true);
            if (!is_array($registerData) || ($registerData['migrated_user_ids'] ?? []) !== [10, 14]) {
                throw new \Exception('Register file missing exact migrated_user_ids [10, 14]: ' . json_encode($registerData));
            }
            if (empty($registerData['hmac_sha256']) || strlen((string)$registerData['hmac_sha256']) !== 64) {
                throw new \Exception('Register file missing 64-char hmac_sha256 signature.');
            }

            /** Verify in-place glpi_users updates preserved user IDs 10 & 14, cleared LDAP fields, and set email login. */
            $userUpdatesById = [];
            $ldapServerDeactivated = false;
            foreach ($this->db->updatedRows as $upd) {
                if ($upd['table'] === 'glpi_users' && isset($upd['where']['id'])) {
                    $userUpdatesById[(int)$upd['where']['id']] = $upd['data'];
                }
                if ($upd['table'] === 'glpi_authldaps' && (int)($upd['where']['id'] ?? 0) === 2 && (int)($upd['data']['is_active'] ?? 1) === 0) {
                    $ldapServerDeactivated = true;
                }
            }

            if (!$ldapServerDeactivated) {
                throw new \Exception('Expected glpi_authldaps id=2 to be deactivated.');
            }
            if (!isset($userUpdatesById[10]) || !isset($userUpdatesById[14]) || isset($userUpdatesById[20]) || isset($userUpdatesById[1])) {
                throw new \Exception('Expected in-place updates strictly for IDs 10 and 14 (skipping collision 20 and super-admin 1).');
            }
            if (
                $userUpdatesById[10]['name'] !== 'jdoe@corp.example.com' ||
                (int)$userUpdatesById[10]['authtype'] !== 4 ||
                (int)$userUpdatesById[10]['auths_id'] !== 1 ||
                $userUpdatesById[10]['password'] !== '' ||
                $userUpdatesById[10]['user_dn'] !== null ||
                $userUpdatesById[10]['user_dn_hash'] !== null ||
                $userUpdatesById[10]['sync_field'] !== null
            ) {
                throw new \Exception('In-place user update payload for ID 10 did not match expected SAML binding: ' . json_encode($userUpdatesById[10]));
            }
            if (count(\Log::$entries) < 2) {
                throw new \Exception('Expected Log::history() entries for migrated users.');
            }

            /** Test 4: --list-registers displays register metadata and VALID HMAC status. */
            $list = $this->runTool(['--list-registers']);
            if ($list['code'] !== 0 || !str_contains($list['stdout'], basename($registerPath)) || !str_contains($list['stdout'], 'VALID')) {
                throw new \Exception('--list-registers failed to list valid register: ' . $list['stdout']);
            }

            /** Test 5a: Path traversal in --revert is rejected. */
            $traversal = $this->runTool(['--revert=../../etc/passwd', '--apply']);
            if ($traversal['code'] === 0) {
                throw new \Exception('Expected path traversal in --revert to fail closed.');
            }

            /** Test 5b: Tampering with migrated_user_ids invalidates GLPIKey HMAC and aborts --revert. */
            $originalRaw = (string)file_get_contents($registerPath);
            $tampered = $registerData;
            $tampered['migrated_user_ids'][] = 1;
            file_put_contents($registerPath, json_encode($tampered, JSON_PRETTY_PRINT));
            $this->db->updatedRows = [];

            $tamperedRevert = $this->runTool(['--revert=' . basename($registerPath), '--apply']);
            if ($tamperedRevert['code'] === 0 || !empty($this->db->updatedRows)) {
                throw new \Exception('Tampered register file MUST fail HMAC verification with zero DB updates.');
            }

            /** Restore untampered register and verify --revert restores exact pre-migration state. */
            file_put_contents($registerPath, $originalRaw);
            $revert = $this->runTool(['--revert=' . basename($registerPath), '--apply']);
            if ($revert['code'] !== 0) {
                throw new \Exception('Valid --revert failed: ' . $revert['stderr']);
            }

            $revertedUsers = [];
            $revertedLdap = false;
            foreach ($this->db->updatedRows as $upd) {
                if ($upd['table'] === 'glpi_users' && isset($upd['where']['id'])) {
                    $revertedUsers[(int)$upd['where']['id']] = $upd['data'];
                }
                if ($upd['table'] === 'glpi_authldaps' && (int)($upd['where']['id'] ?? 0) === 2 && (int)($upd['data']['is_active'] ?? 0) === 1) {
                    $revertedLdap = true;
                }
            }

            if (
                !$revertedLdap ||
                ($revertedUsers[10]['name'] ?? '') !== 'jdoe' ||
                (int)($revertedUsers[10]['authtype'] ?? 0) !== 3 ||
                (int)($revertedUsers[10]['auths_id'] ?? 0) !== 2 ||
                ($revertedUsers[10]['user_dn'] ?? '') !== 'CN=John Doe,OU=Users,DC=corp,DC=local' ||
                ($revertedUsers[14]['sync_field'] ?? '') !== 'objectguid-14' ||
                (int)($revertedUsers[14]['is_deleted_ldap'] ?? 0) !== 1
            ) {
                throw new \Exception('Revert did not deterministically restore original LDAP snapshot: ' . json_encode($revertedUsers));
            }

            echo "✅ LDAP Migration CLI: 0600 HMAC register, in-place apply, --list-registers, tamper guard & --revert verified\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\LdapMigrationToolTest();
    try {
        $test->testHelpAndDryRunDefault();
        $test->testActiveLdapServerGuard();
        $test->testApplySignedRegisterListAndRevert();
        $test = null;
    } catch (\Throwable $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
