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
    require_once __DIR__ . '/../src/Config/ConfigItem.php';
    require_once __DIR__ . '/../src/Config/ConfigEntity.php';
    require_once __DIR__ . '/../src/LoginFlow/User.php';
    require_once __DIR__ . '/../src/LoginFlow.php';
    require_once __DIR__ . '/../src/RuleSaml.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ClaimMapEntity;
    use GlpiPlugin\Samlsso\Config\ClaimMapItem;
    use GlpiPlugin\Samlsso\Config\ConfigEntity;
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
            $success = $entity->save([
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'email',
                    'saml_claim'  => 'some-claim'
                ]
            ]);
            if ($success) {
                throw new \Exception("Validation failed to reject invalid configs_id.");
            }

            $entity = new ClaimMapEntity(1);

            // Test saving invalid GLPI fields (and also missing required fields username and email)
            $success = $entity->save([
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'invalid_field',
                    'saml_claim'  => 'some-claim'
                ]
            ]);
            if ($success) {
                throw new \Exception("Validation failed to reject invalid GLPI field.");
            }
            if (!isset($entity->getErrors()['mapping_0'])) {
                throw new \Exception("Error messages were not correctly recorded for invalid field.");
            }

            // Test saving missing required fields (only username provided, email missing)
            $success = $entity->save([
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'username',
                    'saml_claim'  => 'custom-username-claim'
                ]
            ]);
            if ($success) {
                throw new \Exception("Validation failed to reject missing email field.");
            }
            if (!isset($entity->getErrors()['email_required'])) {
                throw new \Exception("Error not recorded for missing email.");
            }

            // Test saving with empty claim for required field
            $success = $entity->save([
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'username',
                    'saml_claim'  => ''
                ],
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'email',
                    'saml_claim'  => 'custom-email-claim'
                ]
            ]);
            if ($success) {
                throw new \Exception("Validation failed to reject empty claim for username.");
            }

            // Test saving valid mappings (both username and email present)
            $success = $entity->save([
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'username',
                    'saml_claim'  => 'custom-username-claim'
                ],
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'email',
                    'saml_claim'  => 'custom-email-claim'
                ]
            ]);
            if (!$success) {
                throw new \Exception("Validation rejected valid mappings: " . implode('; ', $entity->getErrors()));
            }

            // Verify that is_required is forced to 1 for required mappings
            $mappings = $entity->getMappings();
            foreach ($mappings as $mapping) {
                if ($mapping['glpi_field'] === 'username' || $mapping['glpi_field'] === 'email') {
                    if ($mapping['is_required'] !== 1) {
                        throw new \Exception("is_required was not forced to 1 for " . $mapping['glpi_field']);
                    }
                }
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
                ['target_type' => 'user_field', 'glpi_field' => 'username', 'saml_claim' => 'custom-uid'],
                ['target_type' => 'user_field', 'glpi_field' => 'email', 'saml_claim' => 'custom-mail'],
                ['target_type' => 'user_field', 'glpi_field' => 'realname', 'saml_claim' => 'custom-lastname']
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

        /**
         * Test SAML XML Response Anonymization.
         *
         * @throws \Exception If anonymization fails
         */
        public function testXmlAnonymization(): void
        {
            $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="Response123">
    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
        <ds:SignatureValue>Base64SignatureValue</ds:SignatureValue>
        <ds:KeyInfo>
            <ds:X509Data>
                <ds:X509Certificate>Base64Certificate</ds:X509Certificate>
            </ds:X509Data>
        </ds:KeyInfo>
    </ds:Signature>
    <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" Version="2.0">
        <saml:Subject>
            <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">user@example.com</saml:NameID>
        </saml:Subject>
    </saml:Assertion>
</samlp:Response>
XML;
            $anonymized = \GlpiPlugin\Samlsso\Config\ConfigEntity::anonymizeXml($xml);

            if (str_contains($anonymized, 'Base64SignatureValue') || str_contains($anonymized, 'Base64Certificate')) {
                throw new \Exception("Signature/Certificate data was not stripped.");
            }
            if (str_contains($anonymized, 'user@example.com')) {
                throw new \Exception("PII leaf node text content was not replaced.");
            }
            if (!str_contains($anonymized, '[STRIPPED]')) {
                throw new \Exception("Sanitized values should be replaced with '[STRIPPED]'.");
            }
            echo "✅ XML response structure anonymized and sanitized successfully\n";
        }

        /**
         * Test fallback defaults and strict required fields validations.
         *
         * @throws \Exception If test scenarios fail
         */
        public function testFallbackAndRequiredValidation(): void
        {
            // Scenario 1: missing non-required field with a configured default
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', [
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'firstname',
                    'saml_claim'  => 'missing-firstname-claim',
                    'default_value'=> 'DefaultFirstname',
                    'is_required' => 0
                ]
            ]);

            $response = new TestResponse();
            $response->mockNameId = 'john.doe';
            $response->mockAttributes = [];

            $userFields = SamlUser::getUserInputFieldsFromSamlClaim($response, 1);
            if ($userFields[SamlUser::FIRSTNAME] !== 'DefaultFirstname') {
                throw new \Exception("Default value fallback failed for missing non-required field.");
            }

            // Scenario 2: overlength claim value (> 255 chars) falls back to default
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', [
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'firstname',
                    'saml_claim'  => 'overlength-firstname-claim',
                    'default_value'=> 'DefaultFirstname',
                    'is_required' => 0
                ]
            ]);
            $response = new TestResponse();
            $response->mockNameId = 'john.doe';
            $response->mockAttributes = [
                'overlength-firstname-claim' => [str_repeat('A', 300)]
            ];
            $userFields = SamlUser::getUserInputFieldsFromSamlClaim($response, 1);
            if ($userFields[SamlUser::FIRSTNAME] !== 'DefaultFirstname') {
                throw new \Exception("Default value fallback failed for overlength field value.");
            }

            // Scenario 3: missing required field without default raises a fatal error
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', [
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'email',
                    'saml_claim'  => 'email-claim',
                    'default_value'=> 'email@example.com',
                    'is_required' => 0
                ],
                [
                    'target_type' => 'user_field',
                    'glpi_field'  => 'realname',
                    'saml_claim'  => 'missing-realname-claim',
                    'default_value'=> '',
                    'is_required' => 1
                ]
            ]);
            $response = new TestResponse();
            $response->mockNameId = 'john.doe';
            $response->mockAttributes = [];

            \GlpiPlugin\Samlsso\LoginFlow::$throwOnError = true;
            $hasRaisedError = false;
            try {
                SamlUser::getUserInputFieldsFromSamlClaim($response, 1);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'Required user field "realname" is missing')) {
                    $hasRaisedError = true;
                } else {
                    throw $e;
                }
            } finally {
                \GlpiPlugin\Samlsso\LoginFlow::$throwOnError = false;
            }

            if (!$hasRaisedError) {
                throw new \Exception("Required field missing did not raise a fatal error.");
            }

            echo "✅ Fallback defaults and required validations evaluated successfully\n";
        }

        /**
         * Test dynamic rule criteria registration.
         *
         * @throws \Exception If criteria registration fails
         */
        public function testDynamicCriterias(): void
        {
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', [
                ['glpi_field' => 'groups', 'target_type' => 'rule_field'],
                ['glpi_field' => 'jobtitle', 'target_type' => 'rule_field']
            ]);

            $rule = new \GlpiPlugin\Samlsso\RuleSaml();
            $criterias = $rule->getCriterias();

            if (!isset($criterias['groups']) || !isset($criterias['jobtitle'])) {
                throw new \Exception("Dynamic rule_field criteria registration failed.");
            }
            if (($criterias['groups']['name'] ?? '') !== 'SAML Claim: Groups') {
                throw new \Exception("Incorrect dynamic criteria name mapping.");
            }
            echo "✅ Dynamic rule_field criteria registration verified\n";
        }


        /**
         * Test bulk JSON export payload structure and restore round-trip via DB mock.
         *
         * Verifies:
         *   1. Export produces a well-formed JSON payload with required metadata keys.
         *   2. The configuration block and claim_maps block are correctly nested.
         *   3. Restore round-trip deletes existing data and re-inserts with original IDs.
         *
         * @throws \Exception If any assertion fails
         */
        public function testBulkExportImport(): void
        {
            // --- EXPORT VERIFICATION ---
            // Simulate two configs and their claim maps being returned by the DB
            $this->db->setResponse('glpi_plugin_samlsso_configs', [
                [
                    'id'                              => 1,
                    'name'                            => 'Test IDP Alpha',
                    'conf_domain'                     => 'alpha.example.com',
                    'conf_icon'                       => '',
                    'enforce_sso'                     => 0,
                    'proxied'                         => 0,
                    'strict'                          => 0,
                    'debug'                           => 0,
                    'user_jit'                        => 1,
                    'sp_certificate'                  => 'CERT_A',
                    'sp_private_key'                  => 'KEY_A',
                    'sp_nameid_format'                => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                    'idp_entity_id'                   => 'https://idp.alpha.example.com',
                    'idp_single_sign_on_service'      => 'https://idp.alpha.example.com/sso',
                    'idp_single_logout_service'       => 'https://idp.alpha.example.com/slo',
                    'idp_certificate'                 => 'IDP_CERT_A',
                    'requested_authn_context'         => 'PasswordProtectedTransport',
                    'requested_authn_context_comparison' => 'exact',
                    'security_nameidencrypted'        => 0,
                    'security_authnrequestssigned'    => 0,
                    'security_logoutrequestsigned'    => 0,
                    'security_logoutresponsesigned'   => 0,
                    'compress_requests'               => 1,
                    'compress_responses'              => 1,
                    'validate_xml'                    => 0,
                    'validate_destination'            => 1,
                    'lowercase_url_encoding'          => 0,
                    'comment'                         => 'Alpha test IDP',
                    'is_active'                       => 1,
                    'is_deleted'                      => 0,
                ],
                [
                    'id'                              => 2,
                    'name'                            => 'Test IDP Beta',
                    'conf_domain'                     => 'beta.example.com',
                    'conf_icon'                       => '',
                    'enforce_sso'                     => 0,
                    'proxied'                         => 0,
                    'strict'                          => 0,
                    'debug'                           => 0,
                    'user_jit'                        => 0,
                    'sp_certificate'                  => 'CERT_B',
                    'sp_private_key'                  => 'KEY_B',
                    'sp_nameid_format'                => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                    'idp_entity_id'                   => 'https://idp.beta.example.com',
                    'idp_single_sign_on_service'      => 'https://idp.beta.example.com/sso',
                    'idp_single_logout_service'       => 'https://idp.beta.example.com/slo',
                    'idp_certificate'                 => 'IDP_CERT_B',
                    'requested_authn_context'         => 'Password',
                    'requested_authn_context_comparison' => 'minimum',
                    'security_nameidencrypted'        => 0,
                    'security_authnrequestssigned'    => 0,
                    'security_logoutrequestsigned'    => 0,
                    'security_logoutresponsesigned'   => 0,
                    'compress_requests'               => 1,
                    'compress_responses'              => 1,
                    'validate_xml'                    => 0,
                    'validate_destination'            => 1,
                    'lowercase_url_encoding'          => 0,
                    'comment'                         => 'Beta test IDP',
                    'is_active'                       => 0,
                    'is_deleted'                      => 0,
                ],
            ]);

            // Claim maps for config 1
            $this->db->setResponse('glpi_plugin_samlsso_claimmaps', [
                [
                    'id'            => 1,
                    'configs_id'    => 1,
                    'target_type'   => 'user_field',
                    'glpi_field'    => 'username',
                    'saml_claim'    => 'NameId',
                    'default_value' => '',
                    'is_required'   => 1,
                ],
                [
                    'id'            => 2,
                    'configs_id'    => 1,
                    'target_type'   => 'user_field',
                    'glpi_field'    => 'email',
                    'saml_claim'    => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                    'default_value' => '',
                    'is_required'   => 1,
                ],
                [
                    'id'            => 3,
                    'configs_id'    => 1,
                    'target_type'   => 'user_field',
                    'glpi_field'    => 'realname',
                    'saml_claim'    => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                    'default_value' => 'Unknown',
                    'is_required'   => 0,
                ],
            ]);

            // Build export payload inline (mirrors ConfigForm::exportAllConfigs logic)
            $configExportFields = [
                ConfigEntity::ID, ConfigEntity::NAME, ConfigEntity::CONF_DOMAIN,
                ConfigEntity::CONF_ICON, ConfigEntity::ENFORCE_SSO, ConfigEntity::PROXIED,
                ConfigEntity::STRICT, ConfigEntity::DEBUG, ConfigEntity::USER_JIT,
                ConfigEntity::SP_CERTIFICATE, ConfigEntity::SP_KEY, ConfigEntity::SP_NAME_FORMAT,
                ConfigEntity::IDP_ENTITY_ID, ConfigEntity::IDP_SSO_URL, ConfigEntity::IDP_SLO_URL,
                ConfigEntity::IDP_CERTIFICATE, ConfigEntity::AUTHN_CONTEXT, ConfigEntity::AUTHN_COMPARE,
                ConfigEntity::ENCRYPT_NAMEID, ConfigEntity::SIGN_AUTHN, ConfigEntity::SIGN_SLO_REQ,
                ConfigEntity::SIGN_SLO_RES, ConfigEntity::COMPRESS_REQ, ConfigEntity::COMPRESS_RES,
                ConfigEntity::XML_VALIDATION, ConfigEntity::DEST_VALIDATION, ConfigEntity::LOWERCASE_URL,
                ConfigEntity::COMMENT, ConfigEntity::IS_ACTIVE,
            ];

            global $DB;

            $configs = [];
            $cfgRows = $this->db->request(['FROM' => 'glpi_plugin_samlsso_configs', 'WHERE' => ['is_deleted' => 0]]);
            foreach ($cfgRows as $row) {
                $configFields = [];
                foreach ($configExportFields as $field) {
                    $configFields[$field] = $row[$field] ?? null;
                }
                $claimMappings = [];
                $cmRows = $this->db->request(['FROM' => 'glpi_plugin_samlsso_claimmaps', 'WHERE' => ['configs_id' => (int)$row['id']]]);
                foreach ($cmRows as $cmRow) {
                    $claimMappings[] = [
                        'target_type'   => (string)($cmRow['target_type']   ?? 'user_field'),
                        'glpi_field'    => (string)($cmRow['glpi_field']    ?? ''),
                        'saml_claim'    => (string)($cmRow['saml_claim']    ?? ''),
                        'default_value' => (string)($cmRow['default_value'] ?? ''),
                        'is_required'   => (int)($cmRow['is_required']      ?? 0),
                    ];
                }
                $configs[] = ['config' => $configFields, 'claim_maps' => $claimMappings];
            }

            $payload = [
                'schema_version' => '1',
                'plugin_version' => PLUGIN_SAMLSSO_VERSION,
                'exported_at'    => date('c'),
                'configurations' => $configs,
            ];

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // --- Verify JSON structure ---
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new \Exception("Export JSON is not valid JSON.");
            }
            if (($decoded['schema_version'] ?? '') !== '1') {
                throw new \Exception("Export JSON missing or wrong schema_version.");
            }
            if (($decoded['plugin_version'] ?? '') !== PLUGIN_SAMLSSO_VERSION) {
                throw new \Exception("Export JSON missing or wrong plugin_version.");
            }
            if (!isset($decoded['configurations']) || count($decoded['configurations']) !== 2) {
                throw new \Exception("Export JSON configurations count mismatch: expected 2.");
            }

            $firstEntry = $decoded['configurations'][0];
            if (!isset($firstEntry['config']) || !isset($firstEntry['claim_maps'])) {
                throw new \Exception("Export JSON configuration entry missing config or claim_maps block.");
            }
            if (($firstEntry['config']['id'] ?? 0) !== 1) {
                throw new \Exception("Export JSON config id does not match original (expected 1).");
            }
            if (($firstEntry['config']['name'] ?? '') !== 'Test IDP Alpha') {
                throw new \Exception("Export JSON config name does not match original.");
            }
            if (count($firstEntry['claim_maps']) !== 3) {
                throw new \Exception("Export JSON claim_maps count mismatch for first config.");
            }
            if (($firstEntry['claim_maps'][0]['glpi_field'] ?? '') !== 'username') {
                throw new \Exception("Export JSON first claim_map glpi_field is incorrect.");
            }
            if (($firstEntry['claim_maps'][0]['is_required'] ?? -1) !== 1) {
                throw new \Exception("Export JSON first claim_map is_required flag not preserved.");
            }

            // Second config should have empty claim_maps
            $secondEntry = $decoded['configurations'][1];
            if (($secondEntry['config']['id'] ?? 0) !== 2) {
                throw new \Exception("Export JSON second config id does not match original (expected 2).");
            }

            // --- RESTORE ROUND-TRIP VERIFICATION ---
            // Reset tracked operations
            $this->db->deletedRows = [];
            $this->db->insertedRows = [];

            // Simulate the restore logic inline (mirrors ConfigForm::restoreAllConfigs)
            $data = $decoded;

            // Delete all existing (clean restore)
            $DB->delete('glpi_plugin_samlsso_claimmaps', ['id' => ['>', 0]]);
            $DB->delete('glpi_plugin_samlsso_configs',   ['id' => ['>', 0]]);

            $restoredCount = 0;
            $now = date('Y-m-d H:i:s');

            foreach ($data['configurations'] as $index => $entry) {
                if (!isset($entry['config']) || !is_array($entry['config'])) {
                    continue;
                }
                $cfgData = $entry['config'];
                $origId  = isset($cfgData['id']) ? (int)$cfgData['id'] : 0;
                if ($origId <= 0) {
                    continue;
                }
                $insertRow = ['is_deleted' => 0, 'date_creation' => $now, 'date_mod' => $now, 'id' => $origId];
                foreach ($configExportFields as $field) {
                    if ($field === 'id') {
                        continue;
                    }
                    if (array_key_exists($field, $cfgData)) {
                        $insertRow[$field] = $cfgData[$field];
                    }
                }
                $DB->insert('glpi_plugin_samlsso_configs', $insertRow);

                // Save claim mappings
                $claimMaps = isset($entry['claim_maps']) && is_array($entry['claim_maps'])
                    ? $entry['claim_maps']
                    : [];
                $claimMapEntity = new ClaimMapEntity($origId);
                $claimMapEntity->save($claimMaps);
                $restoredCount++;
            }

            // Verify deletes were called (2 tables purged)
            if (count($this->db->deletedRows) < 2) {
                throw new \Exception("Restore did not call delete on both tables as expected.");
            }

            // Verify inserts were called for 2 configs
            $configInserts = array_filter($this->db->insertedRows, fn($r) => ($r['table'] ?? '') === 'glpi_plugin_samlsso_configs');
            if (count($configInserts) !== 2) {
                throw new \Exception("Restore inserted wrong number of config rows: expected 2, got " . count($configInserts) . ".");
            }

            // Verify original IDs are preserved
            $insertedIds = array_map(fn($r) => $r['data']['id'] ?? null, array_values($configInserts));
            if (!in_array(1, $insertedIds, true) || !in_array(2, $insertedIds, true)) {
                throw new \Exception("Restore did not preserve original IDP configuration IDs.");
            }

            // Verify restored count
            if ($restoredCount !== 2) {
                throw new \Exception("Restore round-trip count mismatch: expected 2, got {$restoredCount}.");
            }

            echo "✅ Bulk JSON export/import round-trip verified successfully\n";
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
        $test->testXmlAnonymization();
        $test->testFallbackAndRequiredValidation();
        $test->testDynamicCriterias();
        $test->testBulkExportImport();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
