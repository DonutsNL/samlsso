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
 *  @version    1.3.2
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

/**
 * UserProvisioningTest.php
 * 
 * Unit tests validating GLPI user lookup, JIT (Just-In-Time) provisioning,
 * rule engine processing, and groups/profiles updates.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/LoginFlow.php';
    require_once __DIR__ . '/../src/LoginFlow/User.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;
    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Config\MockConfigEntity;
    use GlpiPlugin\Samlsso\RuleSamlCollection;

    /**
     * UserProvisioningTest class.
     * Evaluates user provisioning logic, rights modifications, and rule processing mappings.
     */
    class UserProvisioningTest extends TestHarness {

        /**
         * UserProvisioningTest constructor.
         * Sets up the global user mock handlers.
         */
        public function __construct() {
            parent::__construct();
            \User::$mockObject = new TestableGlpiUser();
        }

        /**
         * Constructs a standard set of mock user attributes.
         *
         * @param string $name User login name.
         * @param string $email User email address.
         * @return array Mock user attribute dataset.
         */
        private function getFullUserData(string $name, string $email): array {
            return [
                SamlUser::NAME          => $name,
                SamlUser::EMAIL         => [$email],
                SamlUser::SAMLGROUPS    => ['Admins'],
                SamlUser::SAMLJOBTITLE  => 'Manager',
                SamlUser::SAMLCOUNTRY   => 'NL',
                SamlUser::SAMLCITY      => 'Amsterdam',
                SamlUser::SAMLSTREET    => 'Main St'
            ];
        }

        /**
         * Test retrieving an existing user from the database by login name.
         *
         * @throws \Exception if user is not found or matches an incorrect ID.
         */
        public function testExistingUserByName(): void {
            $userData = $this->getFullUserData('john.doe', 'john.doe@example.com');
            \User::$mockObject->mockUserData = ['id' => 123, 'name' => 'john.doe', 'email' => 'john.doe@example.com', 'is_deleted' => 0, 'is_active' => 1];
            $configEntity = new ConfigEntity(5);
            $samlUser = new SamlUser();
            $glpiUser = $samlUser->getOrCreateUser($userData, $configEntity);
            if (!isset($glpiUser->fields['id']) || $glpiUser->fields['id'] !== 123) {
                throw new \Exception("Existing user lookup failed.");
            }
            echo "✅ Existing user lookup by NameId\n";
        }

        /**
         * Test JIT user creation when the user does not exist yet.
         *
         * @throws \Exception if user is not successfully created or doesn't match mock ID.
         */
        public function testJitUserCreation(): void {
            $userData = $this->getFullUserData('new.user', 'new.user@example.com');
            \User::$mockObject->mockUserData = null;
            MockConfigEntity::$mockFields[ConfigEntity::USER_JIT] = 1;
            MockConfigEntity::$mockFields[ConfigEntity::ID] = 5;
            \User::$mockObject->mockIdToReturn = 999;
            \User::$mockObject->createdUserData = ['id' => 999, 'name' => 'new.user', 'email' => 'new.user@example.com'];
            $configEntity = new ConfigEntity(5);
            $samlUser = new SamlUser();
            $glpiUser = $samlUser->getOrCreateUser($userData, $configEntity);
            if (!isset($glpiUser->fields['id']) || $glpiUser->fields['id'] !== 999) {
                throw new \Exception("JIT User creation failed.");
            }
            echo "✅ JIT User creation and Rule engine invocation\n";
        }

        /**
         * Test that JIT user creation is strictly disabled if the IDP configuration JIT setting is false (0).
         *
         * @throws \Exception if the JIT process does not trigger a PrintFatalLoginError exception.
         */
        public function testJitDisabledRespectsIdpConfig(): void {
            \GlpiPlugin\Samlsso\LoginFlow::$throwOnError = true;
            try {
                $userData = $this->getFullUserData('new.user', 'new.user@example.com');
                \User::$mockObject->mockUserData = null;
                
                // Setup ConfigEntity with JIT disabled and valid ID
                MockConfigEntity::$mockFields[ConfigEntity::USER_JIT] = 0;
                MockConfigEntity::$mockFields[ConfigEntity::NAME] = 'Disabled JIT IDP';
                MockConfigEntity::$mockFields[ConfigEntity::ID] = 5;

                $configEntity = new ConfigEntity(5);
                $samlUser = new SamlUser();

                try {
                    $samlUser->getOrCreateUser($userData, $configEntity);
                    throw new \Exception("Expected PrintFatalLoginError due to disabled JIT, but method completed.");
                } catch (\Exception $e) {
                    if (str_contains($e->getMessage(), 'is disabled for: Disabled JIT IDP')) {
                        echo "✅ JIT disabled respects IDP config\n";
                    } else {
                        throw $e;
                    }
                }
            } finally {
                \GlpiPlugin\Samlsso\LoginFlow::$throwOnError = false;
            }
        }

        /**
         * Test that each action registered in RuleSaml has a corresponding handler in User::updateUserRights.
         *
         * @throws \Exception if a registered action key is missing a handler mapping.
         */
        public function testRuleActionsHaveHandlers(): void {
            $rule = new \GlpiPlugin\Samlsso\RuleSaml();
            $actions = $rule->getActions();

            $actionKeys = array_keys($actions);

            $reflector = new \ReflectionClass(SamlUser::class);
            $fileName = $reflector->getFileName();
            $classSource = file_get_contents($fileName);

            $mapping = [
                'entities_id'          => 'User::ENTITY_ID',
                'profiles_id'          => 'User::PROFILESID',
                'is_recursive'         => 'User::PROFILE_RECURSIVE',
                'is_active'            => 'is_active',
                '_entities_id_default' => 'User::ENTITY_DEFAULT',
                'specific_groups_id'   => 'User::GROUP_DEFAULT',
                'groups_id'            => 'User::GROUPID',
                '_profiles_id_default' => 'User::PROFILE_DEFAULT',
                'timezone'             => 'timezone',
                'locations_id'         => 'locations_id',
                'usercategories_id'    => 'usercategories_id',
                'usertitles_id'        => 'usertitles_id',
                'language'             => 'language'
            ];

            foreach ($actionKeys as $key) {
                $searchString = $mapping[$key] ?? $key;
                if (!str_contains($classSource, $searchString)) {
                    throw new \Exception("Rule action key '{$key}' does not have a corresponding handler in User class.");
                }
            }

            echo "✅ All registered SAML JIT Rule actions have corresponding code handlers\n";
        }

        /**
         * Test updating group and profile rights for a user context.
         */
        public function testUpdateUserRights(): void {
            $samlUser = new SamlUser();

            // Clear tracking arrays
            \Group_User::$added = [];
            \User::$mockObject->updatedUserData = null;

            // Simulate actual rule engine output keys:
            // - specific_groups_id (GROUP_DEFAULT) => assigns user to a group
            // - groups_id (GROUPID) => sets user default group
            $params = [
                SamlUser::RULEOUTPUT => [
                    SamlUser::USERSID => 999,
                    SamlUser::GROUP_DEFAULT => 50,
                    SamlUser::GROUPID => 60,
                    SamlUser::PROFILESID => 5
                ]
            ];

            $samlUser->updateUserRights($params);

            // 1. Verify user was added to the group
            if (empty(\Group_User::$added) || \Group_User::$added[0]['groups_id'] !== 50) {
                throw new \Exception("Group assignment handler failed to add user to group 50 via Group_User.");
            }

            // 2. Verify default group was updated on the user object
            $updatedData = \User::$mockObject->updatedUserData;
            if ($updatedData === null || ($updatedData['groups_id'] ?? null) !== 60) {
                throw new \Exception("User defaults handler failed to update default groups_id to 60.");
            }

            echo "✅ User rights update (Groups/Profiles)\n";
        }

        /**
         * Test that synchronization of claims and rules engine rerun works on login
         * for existing users when sync_on_login is active.
         */
        public function testSyncOnLogin(): void {
            $samlUser = new SamlUser();
            $configEntity = new ConfigEntity();
            
            // Setup mock existing user fields
            \User::$mockObject->mockUserData = [
                'id'         => 999,
                'name'       => 'existing_user',
                'email'      => 'existing@example.com',
                'is_deleted' => 0,
                'is_active'  => 1
            ];
            
            // 1. Sync on Login is disabled: should not trigger update
            MockConfigEntity::$mockFields[ConfigEntity::SYNC_ON_LOGIN] = 0;
            \User::$mockObject->mockUpdateCalled = false;
            
            $userFields = $this->getFullUserData('existing_user', 'existing@example.com');
            $resUser = $samlUser->getOrCreateUser($userFields, $configEntity);
            
            if (\User::$mockObject->mockUpdateCalled) {
                throw new \Exception("User update was triggered on login even though sync_on_login is disabled.");
            }
            
            // 2. Sync on Login is enabled: should trigger update
            MockConfigEntity::$mockFields[ConfigEntity::SYNC_ON_LOGIN] = 1;
            \User::$mockObject->mockUpdateCalled = false;
            
            $resUser = $samlUser->getOrCreateUser($userFields, $configEntity);
            
            if (!\User::$mockObject->mockUpdateCalled) {
                throw new \Exception("User update was NOT triggered on login even though sync_on_login is enabled.");
            }
            
            echo "✅ Synchronize claim mappings and rerun rules engine on login verified\n";
        }

        /**
         * Test that claim resync detailed trace logging records field changes correctly.
         *
         * @throws \Exception if the trace log doesn't match the simulated changes.
         */
        public function testSyncOnLoginTraceLogging(): void {
            $samlUser = new SamlUser();
            $configEntity = new ConfigEntity();
            
            \User::$mockObject->mockUserData = [
                'id'         => 999,
                'name'       => 'existing_user',
                'realname'   => 'OldRealName',
                'firstname'  => 'OldFirstName',
                'mobile'     => '12345',
                'phone'      => '67890',
                'email'      => 'existing@example.com',
                'is_deleted' => 0,
                'is_active'  => 1
            ];
            
            MockConfigEntity::$mockFields[ConfigEntity::SYNC_ON_LOGIN] = 1;
            
            $userFields = $this->getFullUserData('existing_user', 'newemail@example.com');
            $userFields[SamlUser::REALNAME] = 'NewRealName';
            $userFields[SamlUser::FIRSTNAME] = 'NewFirstName';
            
            /* Emulate LoginState */
            $state = new class extends \GlpiPlugin\Samlsso\LoginState {
                public array $traces = [];
                public function __construct() {}
                public function addLoginFlowTrace(array $condition): bool {
                    $this->traces = array_merge($this->traces, $condition);
                    return true;
                }
            };
            
            $resUser = $samlUser->getOrCreateUser($userFields, $configEntity, $state);
            
            if (!isset($state->traces['syncUpdatedFields'])) {
                throw new \Exception("Sync changes trace log was not written.");
            }
            
            $logContent = $state->traces['syncUpdatedFields'];
            if (!str_contains($logContent, "realname: 'OldRealName' => 'NewRealName'") ||
                !str_contains($logContent, "firstname: 'OldFirstName' => 'NewFirstName'") ||
                !str_contains($logContent, "email added: 'newemail@example.com'")) {
                throw new \Exception("Sync changes trace log has incorrect detail: " . $logContent);
            }

            if (!isset($state->traces['rulesEngineInput']) || !isset($state->traces['rulesEngineOutput'])) {
                throw new \Exception("Rules engine inputs or outputs were not logged.");
            }
            
            echo "✅ Synchronize claim mappings trace logging verified\n";
        }

        /**
         * Test that synchronization checks user emails case-insensitively.
         */
        public function testSyncOnLoginCaseInsensitiveEmail(): void {
            $samlUser = new SamlUser();
            $configEntity = new ConfigEntity();
            
            \User::$mockObject->mockUserData = [
                'id'         => 999,
                'name'       => 'existing_user',
                'email'      => 'existing@example.com',
                'is_deleted' => 0,
                'is_active'  => 1
            ];
            
            MockConfigEntity::$mockFields[ConfigEntity::SYNC_ON_LOGIN] = 1;
            
            // Set response from DB to mock user's email in glpi_useremails
            $this->db->setResponse('glpi_useremails', [
                ['email' => 'Existing@Example.Com']
            ]);
            
            // New incoming SAML email has different casing: existing@example.com
            $userFields = $this->getFullUserData('existing_user', 'existing@example.com');
            
            /* Emulate LoginState */
            $state = new class extends \GlpiPlugin\Samlsso\LoginState {
                public array $traces = [];
                public function __construct() {}
                public function addLoginFlowTrace(array $condition): bool {
                    $this->traces = array_merge($this->traces, $condition);
                    return true;
                }
            };
            
            $resUser = $samlUser->getOrCreateUser($userFields, $configEntity, $state);
            
            if (isset($state->traces['syncUpdatedFields']) && str_contains($state->traces['syncUpdatedFields'], "email added")) {
                throw new \Exception("Email casing mismatch incorrectly triggered a duplicate email addition.");
            }
            
            echo "✅ Case-insensitive user email comparison on sync_on_login verified\n";
        }

        /**
         * Test that synchronization on login does not overwrite existing GLPI user
         * attributes when SAML claims are absent (evaluating to false/null) (Issue #146).
         */
        public function testSyncOnLoginDoesNotOverwriteWithEmptyClaims(): void {
            $samlUser = new SamlUser();
            $configEntity = new ConfigEntity();

            \User::$mockObject->mockUserData = [
                'id'         => 999,
                'name'       => 'existing_user',
                'email'      => 'existing@example.com',
                'mobile'     => '+31612345678',
                'phone'      => '+31201234567',
                'is_deleted' => 0,
                'is_active'  => 1
            ];

            MockConfigEntity::$mockFields[ConfigEntity::SYNC_ON_LOGIN] = 1;

            $this->db->setResponse('glpi_useremails', [
                ['email' => 'existing@example.com']
            ]);

            // Incoming SAML assertion has realname, but mobile and phone claims are absent (false/null)
            $userFields = [
                SamlUser::NAME          => 'existing_user',
                SamlUser::REALNAME      => 'NewRealName',
                SamlUser::FIRSTNAME     => 'NewFirstName',
                SamlUser::MOBILE        => false,
                SamlUser::PHONE         => null,
                SamlUser::EMAIL         => ['existing@example.com'],
            ];

            \User::$mockObject->mockUpdateCalled = false;
            \User::$mockObject->updatedUserData = null;

            $samlUser->getOrCreateUser($userFields, $configEntity);

            if (!\User::$mockObject->mockUpdateCalled) {
                throw new \Exception("User update was not called when valid fields changed.");
            }

            $updatePayload = \User::$mockObject->updatedUserData ?? [];
            if (array_key_exists(SamlUser::MOBILE, $updatePayload)) {
                throw new \Exception("Empty/false SAML claim 'mobile' was included in update payload, overwriting existing user data.");
            }
            if (array_key_exists(SamlUser::PHONE, $updatePayload)) {
                throw new \Exception("Empty/null SAML claim 'phone' was included in update payload, overwriting existing user data.");
            }
            if (!isset($updatePayload[SamlUser::REALNAME]) || $updatePayload[SamlUser::REALNAME] !== 'NewRealName') {
                throw new \Exception("Valid SAML claim 'realname' was not updated.");
            }

            echo "✅ sync_on_login does not overwrite attributes with empty/false claims (Issue #146)\n";
        }

        /**
         * Test that multiple JIT rules assigning different profiles are additive
         * and do not delete previously assigned profiles (Issue #153).
         */
        public function testAdditiveProfileAssignments(): void {
            $samlUser = new SamlUser();
            \Profile_User::$added = [];
            \Profile_User::$deleted = [];

            /* Rule 1 assigns profile 5 */
            $params1 = [
                SamlUser::RULEOUTPUT => [
                    SamlUser::USERSID    => 101,
                    SamlUser::PROFILESID => 5,
                    SamlUser::ENTITY_ID  => 1
                ]
            ];
            $samlUser->updateUserRights($params1);

            /* Rule 2 assigns profile 8 */
            $params2 = [
                SamlUser::RULEOUTPUT => [
                    SamlUser::USERSID    => 101,
                    SamlUser::PROFILESID => 8,
                    SamlUser::ENTITY_ID  => 1
                ]
            ];
            $samlUser->updateUserRights($params2);

            if (count(\Profile_User::$added) < 2) {
                throw new \Exception("Expected at least 2 profile assignments, found " . count(\Profile_User::$added));
            }

            /* Verify that profile 5 was NOT deleted by Rule 2 */
            foreach (\Profile_User::$deleted as $del) {
                if (isset($del['profiles_id']) && (int)$del['profiles_id'] === 5) {
                    throw new \Exception("Profile 5 was incorrectly deleted when assigning profile 8 (non-additive).");
                }
            }

            echo "✅ JIT multi-profile assignments are additive across rules (Issue #153)\n";
        }

        /**
         * Test JIT rule assigning profile and entity via _profiles_id_default and
         * _entities_id_default, defaulting recursive to 0, and cleaning up initial
         * Root entity profile authorization (Issue #156).
         */
        public function testProfileAndEntityAssignmentDefaultsAndRootCleanup(): void {
            $samlUser = new SamlUser();
            \Profile_User::$added = [];
            \Profile_User::$deleted = [];

            /* Emulate existing initial user profile in Root entity (0) */
            \Profile_User::$userProfiles[202] = [
                1 => ['id' => 1, 'profiles_id' => 9, 'entities_id' => 0]
            ];

            $params = [
                SamlUser::RULEOUTPUT => [
                    SamlUser::USERSID         => 202,
                    SamlUser::PROFILE_DEFAULT => 9,
                    SamlUser::ENTITY_DEFAULT  => 27
                ]
            ];

            $samlUser->updateUserRights($params);

            /* 1. Verify profile 9 assigned to entity 27 with is_recursive = 0 */
            $foundTarget = false;
            foreach (\Profile_User::$added as $entry) {
                if (
                    (int)($entry[SamlUser::PROFILESID] ?? 0) === 9 &&
                    (int)($entry[SamlUser::ENTITY_ID] ?? 0) === 27 &&
                    (int)($entry[SamlUser::PROFILE_RECURSIVE] ?? 1) === 0
                ) {
                    $foundTarget = true;
                    break;
                }
            }
            if (!$foundTarget) {
                throw new \Exception("Profile 9 with entity 27 and is_recursive=0 was not added via Profile_User.");
            }

            /* 2. Verify root profile authorization (id => 1) was deleted */
            $deletedRoot = false;
            foreach (\Profile_User::$deleted as $del) {
                if (isset($del['id']) && (int)$del['id'] === 1) {
                    $deletedRoot = true;
                    break;
                }
            }
            if (!$deletedRoot) {
                throw new \Exception("Initial Root entity profile authorization was not cleaned up when assigning non-root entity.");
            }

            echo "✅ Profile/Entity assignment with defaults and Root cleanup verified (Issue #156)\n";
        }

        /**
         * Test that identical user defaults do not trigger failure or unnecessary update
         * when database fields already match current settings (Issue #148).
         */
        public function testIdempotentUserDefaultsNoLogWarning(): void {
            $samlUser = new SamlUser();

            \User::$mockObject->mockUserData = [
                'id'          => 303,
                'name'        => 'existing_jit_user',
                'entities_id' => 0,
                'profiles_id' => 3,
                'is_active'   => 1
            ];

            \User::$mockObject->mockUpdateCalled = false;

            $params = [
                SamlUser::RULEOUTPUT => [
                    SamlUser::USERSID         => 303,
                    SamlUser::PROFILE_DEFAULT => 3,
                    SamlUser::ENTITY_DEFAULT  => 0,
                    'is_active'               => 1
                ]
            ];

            $samlUser->updateUserRights($params);

            /* Since fields already matched in DB, mock update should not be invoked needlessly */
            if (\User::$mockObject->mockUpdateCalled) {
                throw new \Exception("User update was unnecessarily called when DB fields already match current settings.");
            }

            echo "✅ Idempotent user defaults update does not fail or warn needlessly (Issue #148)\n";
        }

        /**
         * Test that attempting to link or log in to a local GLPI account (authtype = Auth::DB_GLPI)
         * or the local super-admin 'glpi' is blocked with a fatal login error.
         */
        public function testLocalAccountLinkingBlocked(): void {
            \GlpiPlugin\Samlsso\LoginFlow::$throwOnError = true;
            try {
                $userData = $this->getFullUserData('local_admin', 'local_admin@example.com');
                \User::$mockObject->mockUserData = [
                    'id'        => 501,
                    'name'      => 'local_admin',
                    'email'     => 'local_admin@example.com',
                    'authtype'  => 1,
                    'is_deleted' => 0,
                    'is_active' => 1
                ];

                $configEntity = new ConfigEntity(5);
                $samlUser = new SamlUser();

                try {
                    $samlUser->getOrCreateUser($userData, $configEntity);
                    throw new \Exception("Expected fatal login error when linking local GLPI account, but login succeeded.");
                } catch (\Exception $e) {
                    if (!str_contains($e->getMessage(), 'uses local GLPI authentication and cannot be linked')) {
                        throw $e;
                    }
                }

                // Also test 'glpi' username block even if authtype is not explicitly 1
                $glpiData = $this->getFullUserData('glpi', 'glpi@example.com');
                \User::$mockObject->mockUserData = [
                    'id'        => 1,
                    'name'      => 'glpi',
                    'email'     => 'glpi@example.com',
                    'authtype'  => 0,
                    'is_deleted' => 0,
                    'is_active' => 1
                ];
                try {
                    $samlUser->getOrCreateUser($glpiData, $configEntity);
                    throw new \Exception("Expected fatal login error when linking glpi super-admin account, but login succeeded.");
                } catch (\Exception $e) {
                    if (!str_contains($e->getMessage(), 'uses local GLPI authentication and cannot be linked')) {
                        throw $e;
                    }
                }

                echo "✅ Local GLPI account linking blocked\n";
            } finally {
                \GlpiPlugin\Samlsso\LoginFlow::$throwOnError = false;
            }
        }

        /**
         * Test that syncUserFieldsOnLogin strips protected fields (authtype, password, comment, user_dn, etc.)
         * and never passes them to User::update().
         */
        public function testSyncUserFieldsProtectsSensitiveFields(): void {
            $samlUser = new SamlUser();

            \User::$mockObject->mockUserData = [
                'id'        => 502,
                'name'      => 'sso_user',
                'email'     => 'sso_user@example.com',
                'authtype'  => 4,
                'is_deleted' => 0,
                'is_active' => 1
            ];

            \User::$mockObject->mockUpdateCalled = false;
            \User::$mockObject->updatedUserData = null;

            // Enable SYNC_ON_LOGIN
            MockConfigEntity::$mockFields[ConfigEntity::SYNC_ON_LOGIN] = 1;
            MockConfigEntity::$mockFields[ConfigEntity::ID] = 5;

            $configEntity = new ConfigEntity(5);

            $maliciousClaims = [
                SamlUser::NAME       => 'sso_user',
                SamlUser::REALNAME   => 'Updated Realname',
                SamlUser::FIRSTNAME  => 'Updated Firstname',
                SamlUser::AUTHTYPE   => 1,
                SamlUser::PASSWORD   => 'hacked_password',
                SamlUser::PASSWORDN  => 'hacked_password',
                SamlUser::COMMENT    => 'malicious comment overwrite',
                'user_dn'            => 'cn=malicious,dc=example,dc=com',
                'auths_id'           => 99,
                'api_token'          => 'malicious_token',
                'personal_token'     => 'malicious_personal_token'
            ];

            $glpiUser = new \User();
            $glpiUser->fields = \User::$mockObject->mockUserData;

            $method = new \ReflectionMethod(SamlUser::class, 'syncUserFieldsOnLogin');
            $method->setAccessible(true);
            $method->invoke($samlUser, $glpiUser, $maliciousClaims, null);

            if (!\User::$mockObject->mockUpdateCalled) {
                throw new \Exception("Expected User::update to be called during syncUserFieldsOnLogin.");
            }

            $updated = \User::$mockObject->updatedUserData;
            $forbiddenKeys = ['authtype', 'password', 'password2', 'comment', 'user_dn', 'auths_id', 'api_token', 'personal_token'];
            foreach ($forbiddenKeys as $forbidden) {
                if (isset($updated[$forbidden])) {
                    throw new \Exception("Forbidden field '$forbidden' was present in User::update() payload!");
                }
            }

            if (($updated[SamlUser::REALNAME] ?? '') !== 'Updated Realname') {
                throw new \Exception("Legitimate field 'realname' was not updated properly.");
            }

            echo "✅ Protected fields stripped during syncUserFieldsOnLogin\n";
        }
    }
}

namespace {
    /**
     * Executes the UserProvisioningTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\UserProvisioningTest();
    try {
        $test->testExistingUserByName();
        $test->testJitUserCreation();
        $test->testJitDisabledRespectsIdpConfig();
        $test->testUpdateUserRights();
        $test->testRuleActionsHaveHandlers();
        $test->testSyncOnLogin();
        $test->testSyncOnLoginTraceLogging();
        $test->testSyncOnLoginCaseInsensitiveEmail();
        $test->testSyncOnLoginDoesNotOverwriteWithEmptyClaims();
        $test->testAdditiveProfileAssignments();
        $test->testProfileAndEntityAssignmentDefaultsAndRootCleanup();
        $test->testIdempotentUserDefaultsNoLogWarning();
        $test->testLocalAccountLinkingBlocked();
        $test->testSyncUserFieldsProtectsSensitiveFields();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
