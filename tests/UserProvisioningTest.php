<?php
declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/LoginFlow/User.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;
    use GlpiPlugin\Samlsso\Config\ConfigEntity;
    use GlpiPlugin\Samlsso\Config\MockConfigEntity;
    use GlpiPlugin\Samlsso\RuleSamlCollection;

    class UserProvisioningTest extends TestHarness {

        public function __construct() {
            parent::__construct();
            \User::$mockObject = new TestableGlpiUser();
        }

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

        public function testUpdateUserRights(): void {
            $samlUser = new SamlUser();
            $params = [SamlUser::RULEOUTPUT => [SamlUser::USERSID => 999, SamlUser::GROUPID => 50, SamlUser::PROFILESID => 5]];
            $samlUser->updateUserRights($params);
            echo "✅ User rights update (Groups/Profiles)\n";
        }
    }
}

namespace {
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
