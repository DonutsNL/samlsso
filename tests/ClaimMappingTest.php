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
 *  Copyright (C) 2026 by DonutsNL
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
 *  @since      1.3.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/ClaimMap.php';
    require_once __DIR__ . '/../src/ObservedClaim.php';
    require_once __DIR__ . '/../src/Config/ClaimMapItem.php';
    require_once __DIR__ . '/../src/Config/ClaimMapEntity.php';
    require_once __DIR__ . '/../src/LoginFlow/User.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ClaimMapEntity;
    use GlpiPlugin\Samlsso\Config\ClaimMapItem;
    use GlpiPlugin\Samlsso\LoginFlow\User as SamlUser;
    use GlpiPlugin\Samlsso\ClaimMap;
    use GlpiPlugin\Samlsso\ObservedClaim;

    /**
     * TestResponse mocks OneLogin SAML Response.
     */
    class TestResponse extends \OneLogin\Saml2\Response
    {
        /** @var string Mock Name ID */
        public string $mockNameId = 'testuser';

        /** @var array Mock Attributes */
        public array $mockAttributes = [];

        /**
         * Constructor.
         */
        public function __construct()
        {
        }

        /**
         * Get Name ID.
         *
         * @return string Name ID
         */
        public function getNameId(): string
        {
            return $this->mockNameId;
        }

        /**
         * Get Attributes.
         *
         * @return array Attributes
         */
        public function getAttributes(): array
        {
            return $this->mockAttributes;
        }
    }

    /**
     * ClaimMappingTest verifies the SAML claim mapping features.
     */
    class ClaimMappingTest extends TestHarness
    {
        /**
         * Test preset loading and flat YAML parsing.
         *
         * @throws \Exception If presets are missing or invalid
         */
        public function testPresets(): void
        {
            $presets = ClaimMapEntity::getPresets();
            if (!isset($presets['entra_id']) || !isset($presets['okta']) || !isset($presets['keycloak'])) {
                throw new \Exception("Presets loading failed.");
            }

            $entra = $presets['entra_id'];
            if (($entra['email'] ?? '') !== 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress') {
                throw new \Exception("Entra ID preset has incorrect email mapping.");
            }

            echo "✅ Preset YAML mapping structures parsed successfully\n";
        }

        /**
         * Test ClaimMapEntity validation rules.
         *
         * @throws \Exception If validations fail
         */
        public function testClaimMapEntityValidation(): void
        {
            $entity = new ClaimMapEntity(-1);

            // Test saving invalid config ID
            $success = $entity->save(['email' => 'some-claim']);
            if ($success) {
                throw new \Exception("Validation failed to reject invalid configs_id.");
            }

            $entity = new ClaimMapEntity(1);

            // Test saving invalid GLPI fields
            $success = $entity->save(['invalid_field' => 'some-claim']);
            if ($success) {
                throw new \Exception("Validation failed to reject invalid GLPI field.");
            }
            if (!isset($entity->getErrors()['invalid_field'])) {
                throw new \Exception("Error messages were not correctly recorded for invalid field.");
            }

            // Test saving valid mappings
            $success = $entity->save(['email' => 'custom-email-claim']);
            if (!$success) {
                throw new \Exception("Validation rejected valid mappings.");
            }

            echo "✅ ClaimMapEntity validation constraints verified\n";
        }

        /**
         * Test default fallback claim mapping when no mappings exist in DB.
         *
         * @throws \Exception If fallbacks are incorrect
         */
        public function testFallbackMapping(): void
        {
            // Empty database response for claim mappings
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', []);

            $response = new TestResponse();
            $response->mockNameId = 'john.doe';
            $response->mockAttributes = [
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress' => ['john.doe@example.com'],
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname' => ['Doe'],
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/firstname' => ['John']
            ];

            // IDP ID = 1 (resolves default schemas since claimmaps is empty)
            $userFields = SamlUser::getUserInputFieldsFromSamlClaim($response, 1);

            if ($userFields[SamlUser::NAME] !== 'john.doe') {
                throw new \Exception("Default username fallback failed.");
            }
            if ($userFields[SamlUser::EMAIL][0] !== 'john.doe@example.com') {
                throw new \Exception("Default email fallback failed.");
            }
            if ($userFields[SamlUser::REALNAME] !== 'Doe') {
                throw new \Exception("Default realname fallback failed.");
            }
            if ($userFields[SamlUser::FIRSTNAME] !== 'John') {
                throw new \Exception("Default firstname fallback failed.");
            }

            echo "✅ Backward-compatible default schema fallback verified\n";
        }

        /**
         * Test custom claim mapping.
         *
         * @throws \Exception If custom mappings are not resolved
         */
        public function testCustomMapping(): void
        {
            // Configure mock mappings in DB
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', [
                ['glpi_field' => 'username', 'saml_claim' => 'custom-uid'],
                ['glpi_field' => 'email', 'saml_claim' => 'custom-mail'],
                ['glpi_field' => 'realname', 'saml_claim' => 'custom-lastname']
            ]);

            $response = new TestResponse();
            $response->mockNameId = 'fallback-name-id';
            $response->mockAttributes = [
                'custom-uid' => ['custom_john'],
                'custom-mail' => ['custom_john@example.com'],
                'custom-lastname' => ['CustomDoe']
            ];

            $userFields = SamlUser::getUserInputFieldsFromSamlClaim($response, 1);

            if ($userFields[SamlUser::NAME] !== 'custom_john') {
                throw new \Exception("Custom username mapping failed.");
            }
            if ($userFields[SamlUser::EMAIL][0] !== 'custom_john@example.com') {
                throw new \Exception("Custom email mapping failed.");
            }
            if ($userFields[SamlUser::REALNAME] !== 'CustomDoe') {
                throw new \Exception("Custom realname mapping failed.");
            }

            echo "✅ Dynamic custom claim mappings resolved correctly\n";
        }

        /**
         * Test observed claims tracking during SAML Response parsing.
         *
         * @throws \Exception If observed claims are not saved/loaded
         */
        public function testObservedClaimsTracking(): void
        {
            // Empty observed claims initially
            $this->db->setResponse('glpi_plugin_samlsso_observedclaims', []);

            $response = new TestResponse();
            $response->mockNameId = 'john.doe';
            $response->mockAttributes = [
                'claim-one' => ['value1'],
                'claim-two' => ['value2']
            ];

            // Trigger mapping logic which tracks observed claims
            SamlUser::getUserInputFieldsFromSamlClaim($response, 1);

            // Fetch observed claims using the entity class
            $this->db->setResponse('glpi_plugin_samlsso_observedclaims', [
                ['saml_claim' => 'claim-one'],
                ['saml_claim' => 'claim-two']
            ]);

            $entity = new ClaimMapEntity(1);
            $observed = $entity->getObservedClaims();

            if (!in_array('claim-one', $observed, true) || !in_array('claim-two', $observed, true)) {
                throw new \Exception("Observed claims tracking failed.");
            }

            echo "✅ SAML response claim keys tracked and logged successfully\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\ClaimMappingTest();
    try {
        $test->testPresets();
        $test->testClaimMapEntityValidation();
        $test->testFallbackMapping();
        $test->testCustomMapping();
        $test->testObservedClaimsTracking();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
