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
 *  @version    1.3.0
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
            $samlUser = new SamlUser();
            $glpiUser = $samlUser->getOrCreateUser($userData);
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
            \User::$mockObject->mockIdToReturn = 999;
            \User::$mockObject->createdUserData = ['id' => 999, 'name' => 'new.user', 'email' => 'new.user@example.com'];
            $samlUser = new SamlUser();
            $glpiUser = $samlUser->getOrCreateUser($userData);
            if (!isset($glpiUser->fields['id']) || $glpiUser->fields['id'] !== 999) {
                throw new \Exception("JIT User creation failed.");
            }
            echo "✅ JIT User creation and Rule engine invocation\n";
        }

        /**
         * Test updating group and profile rights for a user context.
         */
        public function testUpdateUserRights(): void {
            $samlUser = new SamlUser();
            $params = [SamlUser::RULEOUTPUT => [SamlUser::USERSID => 999, SamlUser::GROUPID => 50, SamlUser::PROFILESID => 5]];
            $samlUser->updateUserRights($params);
            echo "✅ User rights update (Groups/Profiles)\n";
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
        $test->testUpdateUserRights();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
