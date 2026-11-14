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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Config;

use GlpiPlugin\Samlsso\ClaimMap;
use GlpiPlugin\Samlsso\ObservedClaim;

/**
 * Class ClaimMapEntity acts as an entity representing the claim mapping settings for an IDP.
 */
class ClaimMapEntity extends ClaimMapItem
{
    /**
     * IDP Configuration ID.
     */
    private int $configs_id;

    /**
     * Mappings of GLPI field to SAML claim.
     */
    private array $mappings = [];

    /**
     * Validation errors.
     */
    private array $errors = [];

    /**
     * Indicator if the configuration is valid.
     */
    private bool $isValid = true;

    /**
     * Constructor.
     *
     * @param int $configs_id The IDP configuration ID
     */
    public function __construct(int $configs_id = -1)
    {
        $this->configs_id = $configs_id;
        if ($configs_id > 0) {
            $this->loadFromDB($configs_id);
        }
    }

    /**
     * Load the claim mappings from the database for the given configs_id.
     *
     * @param int $configs_id The IDP configuration ID
     * @return void
     */
    private function loadFromDB(int $configs_id): void
    {
        global $DB;
        $claimMapTable = ClaimMap::getTable();
        $iterator = $DB->request([
            'FROM'  => $claimMapTable,
            'WHERE' => [
                ClaimMap::CONFIGS_ID => $configs_id
            ]
        ]);

        $this->mappings = [];
        foreach ($iterator as $row) {
            $this->mappings[] = [
                ClaimMap::ID            => (int)($row[ClaimMap::ID] ?? 0),
                ClaimMap::TARGET_TYPE   => (string)($row[ClaimMap::TARGET_TYPE] ?? ClaimMapItem::TARGET_TYPE_USER_FIELD),
                ClaimMap::GLPI_FIELD    => (string)($row[ClaimMap::GLPI_FIELD] ?? ''),
                ClaimMap::SAML_CLAIM    => (string)($row[ClaimMap::SAML_CLAIM] ?? ''),
                ClaimMap::DEFAULT_VALUE => (string)($row[ClaimMap::DEFAULT_VALUE] ?? ''),
                ClaimMap::IS_REQUIRED   => (int)($row[ClaimMap::IS_REQUIRED] ?? 0)
            ];
        }
    }

    /**
     * Get the mapped claim for a GLPI field, or null if not configured.
     *
     * @param string $glpiField The GLPI user field
     * @param string $targetType The target type ('user_field' or 'rule_field')
     * @return string|null The mapped claim, or null
     */
    public function getMapping(string $glpiField, string $targetType = ClaimMapItem::TARGET_TYPE_USER_FIELD): ?string
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping[ClaimMap::GLPI_FIELD] === $glpiField && $mapping[ClaimMap::TARGET_TYPE] === $targetType) {
                return $mapping[ClaimMap::SAML_CLAIM];
            }
        }
        return null;
    }

    /**
     * Get the configured default value for a mapped GLPI field.
     *
     * @param string $glpiField The GLPI user field
     * @param string $targetType The target type ('user_field' or 'rule_field')
     * @return string The default value, or empty string
     */
    public function getDefault(string $glpiField, string $targetType = ClaimMapItem::TARGET_TYPE_USER_FIELD): string
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping[ClaimMap::GLPI_FIELD] === $glpiField && $mapping[ClaimMap::TARGET_TYPE] === $targetType) {
                return $mapping[ClaimMap::DEFAULT_VALUE];
            }
        }
        return '';
    }

    /**
     * Check if a mapped GLPI field is required.
     *
     * @param string $glpiField The GLPI user field
     * @param string $targetType The target type ('user_field' or 'rule_field')
     * @return bool True if required
     */
    public function isRequired(string $glpiField, string $targetType = ClaimMapItem::TARGET_TYPE_USER_FIELD): bool
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping[ClaimMap::GLPI_FIELD] === $glpiField && $mapping[ClaimMap::TARGET_TYPE] === $targetType) {
                return $mapping[ClaimMap::IS_REQUIRED] === 1;
            }
        }
        return false;
    }

    /**
     * Get all mapped rule fields that are not evaluated by any JIT rules.
     *
     * @return array List of unused rule fields
     */
    public function getUnusedRuleFields(): array
    {
        global $DB;
        $unused = [];
        $ruleFields = [];
        foreach ($this->mappings as $mapping) {
            if ($mapping[ClaimMap::TARGET_TYPE] === ClaimMapItem::TARGET_TYPE_RULE_FIELD) {
                $ruleFields[] = $mapping[ClaimMap::GLPI_FIELD];
            }
        }

        if (empty($ruleFields)) {
            return [];
        }

        if (isset($DB) && method_exists($DB, 'tableExists') && $DB->tableExists('glpi_rulecriterias')) {
            $criterionAliases = [
                self::FIELD_GROUPS   => [\GlpiPlugin\Samlsso\LoginFlow\User::SAMLGROUPS, self::FIELD_GROUPS],
                self::FIELD_JOBTITLE => [\GlpiPlugin\Samlsso\LoginFlow\User::SAMLJOBTITLE, self::FIELD_JOBTITLE],
                self::FIELD_EMAIL    => [\GlpiPlugin\Samlsso\LoginFlow\User::EMAIL, self::FIELD_EMAIL],
                self::FIELD_USERNAME => [\GlpiPlugin\Samlsso\LoginFlow\User::NAME, self::FIELD_USERNAME],
            ];

            $lookupCriteria = [];
            foreach ($ruleFields as $field) {
                foreach ($criterionAliases[$field] ?? [$field] as $alias) {
                    $lookupCriteria[] = $alias;
                }
            }
            $lookupCriteria = array_values(array_unique($lookupCriteria));

            $queryParams = [
                'SELECT'   => ['glpi_rulecriterias.criteria AS criteria'],
                'DISTINCT' => true,
                'FROM'     => 'glpi_rulecriterias',
                'WHERE'    => [
                    'glpi_rulecriterias.criteria' => $lookupCriteria,
                ],
            ];

            if ($DB->tableExists('glpi_rules')) {
                $queryParams['INNER JOIN'] = [
                    'glpi_rules' => [
                        'ON' => [
                            'glpi_rulecriterias' => 'rules_id',
                            'glpi_rules'         => 'id',
                        ],
                    ],
                ];
                $queryParams['WHERE']['glpi_rules.sub_type']  = \GlpiPlugin\Samlsso\RuleSaml::class;
                $queryParams['WHERE']['glpi_rules.is_active'] = 1;
            }

            $iterator = $DB->request($queryParams);
            $usedCriteria = [];
            foreach ($iterator as $row) {
                $usedCriteria[] = (string)$row['criteria'];
            }

            foreach ($ruleFields as $field) {
                $acceptedKeys = $criterionAliases[$field] ?? [$field];
                $isUsed = false;
                foreach ($acceptedKeys as $acceptedKey) {
                    if (in_array($acceptedKey, $usedCriteria, true)) {
                        $isUsed = true;
                        break;
                    }
                }
                if (!$isUsed) {
                    $unused[] = $field;
                }
            }
        }
        return $unused;
    }

    /**
     * Get all active mappings.
     *
     * @return array All mappings
     */
    public function getMappings(): array
    {
        return self::sortMappings($this->mappings);
    }

    /**
     * Sort mappings such that system-required fields (Username and Email)
     * are always at the top, followed by all other manual mappings.
     *
     * @param array $mappings The mappings to sort
     * @return array The sorted mappings
     */
    public static function sortMappings(array $mappings): array
    {
        $enforced = [];
        $others = [];

        foreach ($mappings as $mapping) {
            $targetType = $mapping[ClaimMap::TARGET_TYPE] ?? '';
            $glpiField  = $mapping[ClaimMap::GLPI_FIELD] ?? '';

            $isEnforced = ($targetType === self::TARGET_TYPE_USER_FIELD && in_array($glpiField, [self::FIELD_USERNAME, self::FIELD_EMAIL], true));

            if ($isEnforced) {
                if ($glpiField === self::FIELD_USERNAME) {
                    array_unshift($enforced, $mapping);
                } else {
                    $enforced[] = $mapping;
                }
            } else {
                $others[] = $mapping;
            }
        }

        return array_merge($enforced, $others);
    }

    /**
     * Get the validation errors.
     *
     * @return array Validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if the entity is valid.
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Validate the mappings.
     *
     * @param array $newMappings The mappings to validate
     * @return bool True if valid
     */
    public function validate(array $newMappings): bool
    {
        $this->isValid = true;
        $this->errors = [];
        $this->mappings = [];

        $hasUsername = false;
        $hasEmail = false;
        $validatedMappings = [];
        $seen = [];

        foreach ($newMappings as $index => $mapping) {
            $this->validateSingleMapping($index, $mapping, $seen, $validatedMappings, $hasUsername, $hasEmail);
        }

        if (!$hasUsername) {
            $this->isValid = false;
            $this->errors['username_required'] = __('Username mapping is required and cannot be removed.', PLUGIN_NAME);
        }
        if (!$hasEmail) {
            $this->isValid = false;
            $this->errors['email_required'] = __('Email mapping is required and cannot be removed.', PLUGIN_NAME);
        }

        if ($this->isValid) {
            $this->mappings = self::sortMappings($validatedMappings);
        }

        return $this->isValid;
    }

    /**
     * Helper to validate a single mapping item.
     */
    private function validateSingleMapping(int $index, array $mapping, array &$seen, array &$validatedMappings, bool &$hasUsername, bool &$hasEmail): void
    {
        $targetType   = $mapping[ClaimMap::TARGET_TYPE] ?? '';
        $glpiField    = $mapping[ClaimMap::GLPI_FIELD] ?? '';
        $samlClaim    = $mapping[ClaimMap::SAML_CLAIM] ?? '';
        $defaultValue = $mapping[ClaimMap::DEFAULT_VALUE] ?? '';
        $isRequired   = $this->trackRequiredUserFields($index, (string) $targetType, (string) $glpiField, $samlClaim, $mapping[ClaimMap::IS_REQUIRED] ?? 0, $hasUsername, $hasEmail);

        if ($this->shouldSkipEmptyOptionalClaim($samlClaim, (string) $glpiField)) {
            return;
        }

        $targetTypeVal = $this->validateTargetType($targetType);
        $fieldVal      = $this->validateGlpiField($glpiField, $targetTypeVal['value'] ?? self::TARGET_TYPE_USER_FIELD);
        $claimVal      = $this->validateSamlClaim($samlClaim);
        $defaultVal    = $this->validateDefaultValue($defaultValue);
        $requiredVal   = $this->validateIsRequired($isRequired);

        $rowError = $this->collectMappingRowErrors([$targetTypeVal, $fieldVal, $claimVal, $defaultVal]);
        if ($rowError !== '') {
            $this->isValid = false;
            $this->errors['mapping_' . $index] = $rowError;
            return;
        }

        $uniqueKey = $targetTypeVal['value'] . ':' . $fieldVal['value'];
        if (isset($seen[$uniqueKey])) {
            $this->isValid = false;
            $this->errors['mapping_' . $index] = sprintf(__('Duplicate mapping for %s: %s', PLUGIN_NAME), $targetTypeVal['value'], $fieldVal['value']);
            return;
        }

        $seen[$uniqueKey] = true;
        $validatedMappings[] = [
            ClaimMap::TARGET_TYPE   => $targetTypeVal['value'],
            ClaimMap::GLPI_FIELD    => $fieldVal['value'],
            ClaimMap::SAML_CLAIM    => $claimVal['value'],
            ClaimMap::DEFAULT_VALUE => $defaultVal['value'],
            ClaimMap::IS_REQUIRED   => $requiredVal['value'],
        ];
    }

    /**
     * Track required Username/Email user-field mappings and flag empty claims on them.
     *
     * @param int $index Row index in submitted claim mappings.
     * @param string $targetType Target mapping type.
     * @param string $glpiField Target GLPI field key.
     * @param mixed $samlClaim Submitted SAML claim key.
     * @param mixed $isRequired Existing required flag value.
     * @param bool $hasUsername Output flag tracking Username presence.
     * @param bool $hasEmail Output flag tracking Email presence.
     * @return mixed Effective required flag value (forced 1 for mandatory fields).
     */
    private function trackRequiredUserFields(
        int $index,
        string $targetType,
        string $glpiField,
        $samlClaim,
        $isRequired,
        bool &$hasUsername,
        bool &$hasEmail
    ) {
        if ($targetType !== self::TARGET_TYPE_USER_FIELD) {
            return $isRequired;
        }

        $requiredMessages = [
            self::FIELD_USERNAME => __('SAML Claim key cannot be empty for Username mapping.', PLUGIN_NAME),
            self::FIELD_EMAIL    => __('SAML Claim key cannot be empty for Email mapping.', PLUGIN_NAME),
        ];
        if (!isset($requiredMessages[$glpiField])) {
            return $isRequired;
        }

        if ($samlClaim === null || trim((string) $samlClaim) === '') {
            $this->isValid = false;
            $this->errors['mapping_' . $index] = $requiredMessages[$glpiField];
        }
        if ($glpiField === self::FIELD_USERNAME) {
            $hasUsername = true;
        } else {
            $hasEmail = true;
        }

        return 1;
    }

    /**
     * Check whether an optional claim row has an empty SAML claim and should be skipped.
     *
     * @param mixed $samlClaim Submitted SAML claim key.
     * @param string $glpiField Target GLPI field key.
     * @return bool True when row is optional and blank.
     */
    private function shouldSkipEmptyOptionalClaim($samlClaim, string $glpiField): bool
    {
        $isEmptyClaim = $samlClaim === null || trim((string) $samlClaim) === '';
        return $isEmptyClaim && $glpiField !== self::FIELD_USERNAME && $glpiField !== self::FIELD_EMAIL;
    }

    /**
     * Join individual field validation errors for a mapping row.
     *
     * @param array $results List of validator result arrays containing 'valid' and 'error'.
     * @return string Semicolon-delimited error string, or empty string when all valid.
     */
    private function collectMappingRowErrors(array $results): string
    {
        $errors = [];
        foreach ($results as $result) {
            if (!$result['valid']) {
                $errors[] = $result['error'];
            }
        }
        return implode('; ', $errors);
    }

    /**
     * Save/update the mappings.
     *
     * @param array $newMappings The mappings to save
     * @return bool True on success
     */
    public function save(array $newMappings): bool
    {
        global $DB;

        $configsIdVal = $this->validateConfigsId($this->configs_id);
        if (!$configsIdVal['valid']) {
            $this->isValid = false;
            $this->errors[ClaimMap::CONFIGS_ID] = $configsIdVal['error'];
            return false;
        }

        if (!$this->validate($newMappings)) {
            return false;
        }

        $claimMapTable = ClaimMap::getTable();
        $DB->delete(
            $claimMapTable,
            [
                ClaimMap::CONFIGS_ID => $this->configs_id
            ]
        );

        $claimMap = new ClaimMap();
        foreach ($this->mappings as $mapping) {
            $input = [
                ClaimMap::CONFIGS_ID    => $this->configs_id,
                ClaimMap::TARGET_TYPE   => $mapping[ClaimMap::TARGET_TYPE],
                ClaimMap::GLPI_FIELD    => $mapping[ClaimMap::GLPI_FIELD],
                ClaimMap::SAML_CLAIM    => $mapping[ClaimMap::SAML_CLAIM],
                ClaimMap::DEFAULT_VALUE => $mapping[ClaimMap::DEFAULT_VALUE],
                ClaimMap::IS_REQUIRED   => $mapping[ClaimMap::IS_REQUIRED]
            ];
            $claimMap->add($input);
        }

        return true;
    }

    /**
     * Fetch observed claims for this configurations IDP.
     *
     * @return array List of observed claims
     */
    public function getObservedClaims(): array
    {
        global $DB;
        $observedClaimsTable = ObservedClaim::getTable();
        $iterator = $DB->request([
            'FROM'  => $observedClaimsTable,
            'WHERE' => [
                ObservedClaim::CONFIGS_ID => $this->configs_id
            ],
            'ORDER' => ObservedClaim::SAML_CLAIM . ' ASC'
        ]);

        $claims = [];
        foreach ($iterator as $row) {
            $claims[] = (string)$row[ObservedClaim::SAML_CLAIM];
        }
        return $claims;
    }


    /**
     * Resolve username value from SAML claims or response NameID.
     *
     * @param \OneLogin\Saml2\Response $response SAML Response object
     * @param array $claims Parsed claims
     * @return string Resolved username
     */
    public function resolveUsername(\OneLogin\Saml2\Response $response, array $claims): string
    {
        $usernameClaimKey = $this->getMapping(ClaimMapItem::FIELD_USERNAME, ClaimMapItem::TARGET_TYPE_USER_FIELD);
        $usernameVal = null;
        if ($usernameClaimKey !== null && isset($claims[$usernameClaimKey][0]) && !empty($claims[$usernameClaimKey][0])) {
            $usernameVal = $claims[$usernameClaimKey][0];
        } else {
            $usernameVal = $response->getNameId();
        }

        if (empty($usernameVal)) {
            $usernameVal = $this->getDefault(ClaimMapItem::FIELD_USERNAME, ClaimMapItem::TARGET_TYPE_USER_FIELD);
        }

        $isUsernameRequired = $this->isRequired(ClaimMapItem::FIELD_USERNAME, ClaimMapItem::TARGET_TYPE_USER_FIELD) || ($this->configs_id <= 0);
        if (empty($usernameVal) && $isUsernameRequired) {
            \GlpiPlugin\Samlsso\LoginFlow::printError(__('NameId attribute is missing in samlResponse', PLUGIN_NAME),
                                'getUserInputFieldsFromSamlClaim',
                                sprintf("Response ID: %s", $response->getId()));
        }

        if (!empty($usernameVal) && strstr($usernameVal, '#EXT#@')) {
            \GlpiPlugin\Samlsso\LoginFlow::printError(__('Detected a default guest user in samlResponse, this is not supported by glpiSAML.', PLUGIN_NAME),
                                 'getUserInputFieldsFromSamlClaim',
                                  sprintf("Response ID: %s, guest NameID: %s", $response->getId(), $usernameVal));
        }

        return $usernameVal;
    }

    /**
     * Resolve email value from SAML claims or response NameID/username.
     *
     * @param \OneLogin\Saml2\Response $response SAML Response
     * @param array $claims Parsed claims
     * @param string $usernameVal Resolved username
     * @return string Resolved email
     */
    public function resolveEmail(\OneLogin\Saml2\Response $response, array $claims, string $usernameVal): string
    {
        $emailClaimKey = $this->getMapping(ClaimMapItem::FIELD_EMAIL, ClaimMapItem::TARGET_TYPE_USER_FIELD);
        if ($emailClaimKey === null) {
            $emailClaimKey = \GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_EMAILADDRESS;
        }

        $emailVal = null;
        if (isset($claims[$emailClaimKey][0]) && !empty($claims[$emailClaimKey][0])) {
            $emailVal = $claims[$emailClaimKey][0];
        }

        if ($emailVal !== null && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
            $emailVal = null;
        }

        $nameIdFormat = null;
        try {
            $nameIdFormat = $response->getNameIdFormat();
        } catch (\Throwable $t) {
            // ignore
        }

        $usernameIsEmail = filter_var($usernameVal, FILTER_VALIDATE_EMAIL) !== false;
        if ($nameIdFormat !== null) {
            $emailVal = $this->resolveEmailFromNameIdFormat($usernameVal, $usernameIsEmail, $emailVal, $nameIdFormat);
        } else {
            if ($emailVal === null && $usernameIsEmail) {
                $emailVal = $usernameVal;
            }
        }

        if ($emailVal === null) {
            $defaultEmail = $this->getDefault(ClaimMapItem::FIELD_EMAIL, ClaimMapItem::TARGET_TYPE_USER_FIELD);
            if (filter_var($defaultEmail, FILTER_VALIDATE_EMAIL)) {
                $emailVal = $defaultEmail;
            }
        }

        if (empty($emailVal)) {
            \GlpiPlugin\Samlsso\LoginFlow::printError(__('invalid values where used to identify the user during auth', PLUGIN_NAME),
                                 'getUserInputFieldsFromSamlClaim',
                                  sprintf("Response ID: %s, NameID: %s", $response->getId(), (string)$usernameVal));
        }

        return $emailVal;
    }

    /**
     * Resolve email from NameID format fallback rules.
     */
    private function resolveEmailFromNameIdFormat(string $usernameVal, bool $usernameIsEmail, ?string $emailVal, string $nameIdFormat): ?string
    {
        if ($nameIdFormat === \OneLogin\Saml2\Constants::NAMEID_EMAIL_ADDRESS) {
            if (!$usernameIsEmail) {
                \Session::addMessageAfterRedirect(
                    __('Warning: SAML NameId format was requested as email but observed value is not a valid email. Falling back to configured email field.', PLUGIN_NAME),
                    false,
                    WARNING
                );
            } elseif ($emailVal === null) {
                return $usernameVal;
            }
        } else {
            if ($emailVal === null && $usernameIsEmail) {
                return $usernameVal;
            }
        }
        return $emailVal;
    }



    /**
     * Resolve other user fields like firstname, realname, phone, etc.
     *
     * @param \OneLogin\Saml2\Response $response SAML Response
     * @param array $claims Parsed claims
     * @return array Resolved fields mapped to User class constants
     */
    public function resolveUserFields(\OneLogin\Saml2\Response $response, array $claims): array
    {
        $userFields = [];
        $otherUserFields = [
            ClaimMapItem::FIELD_REALNAME,
            ClaimMapItem::FIELD_FIRSTNAME,
            ClaimMapItem::FIELD_PHONE,
            ClaimMapItem::FIELD_MOBILE,
            ClaimMapItem::FIELD_REGISTRATIONNUMBER,
            ClaimMapItem::FIELD_JOBTITLE,
            ClaimMapItem::FIELD_COUNTRY,
            ClaimMapItem::FIELD_CITY,
            ClaimMapItem::FIELD_STREET
        ];

        foreach ($otherUserFields as $field) {
            $claimKey = $this->getMapping($field, ClaimMapItem::TARGET_TYPE_USER_FIELD);
            if ($claimKey === null) {
                $defaultSchemas = [
                    ClaimMapItem::FIELD_REALNAME  => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_SURNAME],
                    ClaimMapItem::FIELD_FIRSTNAME => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_FIRSTNAME, \GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_GIVENNAME],
                    ClaimMapItem::FIELD_MOBILE    => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_MOBILE],
                    ClaimMapItem::FIELD_PHONE     => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_PHONE],
                    ClaimMapItem::FIELD_JOBTITLE  => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_JOBTITLE],
                    ClaimMapItem::FIELD_COUNTRY   => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_COUNTRY],
                    ClaimMapItem::FIELD_CITY      => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_CITY],
                    ClaimMapItem::FIELD_STREET    => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_STREET]
                ];
                $possibleClaims = $defaultSchemas[$field] ?? [];
                foreach ($possibleClaims as $pc) {
                    if (isset($claims[$pc][0]) && !empty($claims[$pc][0])) {
                        $claimKey = $pc;
                        break;
                    }
                }
            }

            $val = null;
            if ($claimKey !== null && isset($claims[$claimKey][0])) {
                $val = $claims[$claimKey][0];
            }

            if ($val === null || strlen((string)$val) > 255) {
                $val = $this->getDefault($field, ClaimMapItem::TARGET_TYPE_USER_FIELD);
            }

            $isRequired = $this->isRequired($field, ClaimMapItem::TARGET_TYPE_USER_FIELD);
            if (empty($val) && $isRequired) {
                \GlpiPlugin\Samlsso\LoginFlow::printError(sprintf(__('Required user field "%s" is missing or invalid in SAML response', PLUGIN_NAME), $field),
                                     'getUserInputFieldsFromSamlClaim',
                                      sprintf("Response ID: %s, field: %s", $response->getId(), $field));
            }

            $keyMap = [
                ClaimMapItem::FIELD_FIRSTNAME => \GlpiPlugin\Samlsso\LoginFlow\User::FIRSTNAME,
                ClaimMapItem::FIELD_REALNAME  => \GlpiPlugin\Samlsso\LoginFlow\User::REALNAME,
                ClaimMapItem::FIELD_MOBILE    => \GlpiPlugin\Samlsso\LoginFlow\User::MOBILE,
                ClaimMapItem::FIELD_PHONE     => \GlpiPlugin\Samlsso\LoginFlow\User::PHONE,
                ClaimMapItem::FIELD_JOBTITLE  => \GlpiPlugin\Samlsso\LoginFlow\User::SAMLJOBTITLE,
                ClaimMapItem::FIELD_COUNTRY   => \GlpiPlugin\Samlsso\LoginFlow\User::SAMLCOUNTRY,
                ClaimMapItem::FIELD_CITY      => \GlpiPlugin\Samlsso\LoginFlow\User::SAMLCITY,
                ClaimMapItem::FIELD_STREET    => \GlpiPlugin\Samlsso\LoginFlow\User::SAMLSTREET
            ];
            $destKey = $keyMap[$field] ?? $field;
            $userFields[$destKey] = $val !== '' ? $val : false;
        }

        return $userFields;
    }

    /**
     * Resolve rule fields including groups and dynamic custom rule fields.
     *
     * @param \OneLogin\Saml2\Response $response SAML Response
     * @param array $claims Parsed claims
     * @return array Mapped rule fields (e.g. ['samlClaimedGroups' => [...], '_saml_rule_fields' => [...]])
     */
    public function resolveRuleFields(\OneLogin\Saml2\Response $response, array $claims): array
    {
        $groupsClaimKey = $this->getMapping(ClaimMapItem::FIELD_GROUPS, ClaimMapItem::TARGET_TYPE_RULE_FIELD);
        if ($groupsClaimKey === null) {
            $groupsClaimKey = \GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_GROUPS;
        }
        $groupsVal = isset($claims[$groupsClaimKey]) ? $claims[$groupsClaimKey] : null;
        if (empty($groupsVal)) {
            $defaultGroup = $this->getDefault(ClaimMapItem::FIELD_GROUPS, ClaimMapItem::TARGET_TYPE_RULE_FIELD);
            $groupsVal = ($defaultGroup !== '') ? [$defaultGroup] : [];
        }
        $isGroupsRequired = $this->isRequired(ClaimMapItem::FIELD_GROUPS, ClaimMapItem::TARGET_TYPE_RULE_FIELD);
        if (empty($groupsVal) && $isGroupsRequired) {
            \GlpiPlugin\Samlsso\LoginFlow::printError(__('Required rule field "groups" is missing in SAML response', PLUGIN_NAME),
                                 'getUserInputFieldsFromSamlClaim',
                                  sprintf("Response ID: %s, field: groups", $response->getId()));
        }

        $result = [
            \GlpiPlugin\Samlsso\LoginFlow\User::SAMLGROUPS      => is_array($groupsVal) ? $groupsVal : (array)$groupsVal,
            \GlpiPlugin\Samlsso\LoginFlow\User::RULE_FIELDS_KEY => []
        ];

        $ruleFields = [ClaimMapItem::FIELD_JOBTITLE, ClaimMapItem::FIELD_COUNTRY, ClaimMapItem::FIELD_CITY, ClaimMapItem::FIELD_STREET];
        foreach ($this->getMappings() as $mapping) {
            if (
                $mapping[ClaimMap::TARGET_TYPE] === ClaimMapItem::TARGET_TYPE_RULE_FIELD &&
                $mapping[ClaimMap::GLPI_FIELD] !== ClaimMapItem::FIELD_GROUPS
            ) {
                $ruleFields[] = $mapping[ClaimMap::GLPI_FIELD];
            }
        }
        $ruleFields = array_unique($ruleFields);

        foreach ($ruleFields as $field) {
            $result[\GlpiPlugin\Samlsso\LoginFlow\User::RULE_FIELDS_KEY][$field] = $this->resolveSingleRuleField($field, $claims, $response);
        }

        return $result;
    }

    /**
     * Helper to resolve a single rule field.
     */
    private function resolveSingleRuleField(string $field, array $claims, \OneLogin\Saml2\Response $response): ?string
    {
        $claimKey = $this->getMapping($field, ClaimMapItem::TARGET_TYPE_RULE_FIELD);
        if ($claimKey === null) {
            $defaultSchemas = [
                ClaimMapItem::FIELD_JOBTITLE  => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_JOBTITLE],
                ClaimMapItem::FIELD_COUNTRY   => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_COUNTRY],
                ClaimMapItem::FIELD_CITY      => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_CITY],
                ClaimMapItem::FIELD_STREET    => [\GlpiPlugin\Samlsso\LoginFlow\User::SCHEMA_STREET]
            ];
            $possibleClaims = $defaultSchemas[$field] ?? [];
            foreach ($possibleClaims as $pc) {
                if (isset($claims[$pc][0]) && !empty($claims[$pc][0])) {
                    $claimKey = $pc;
                    break;
                }
            }
        }

        $val = null;
        if ($claimKey !== null && isset($claims[$claimKey][0])) {
            $val = $claims[$claimKey][0];
        }

        if ($val === null || strlen((string)$val) > 255) {
            $val = $this->getDefault($field, ClaimMapItem::TARGET_TYPE_RULE_FIELD);
        }

        $isRequired = $this->isRequired($field, ClaimMapItem::TARGET_TYPE_RULE_FIELD);
        if (empty($val) && $isRequired) {
            \GlpiPlugin\Samlsso\LoginFlow::printError(sprintf(__('Required rule field "%s" is missing or invalid in SAML response', PLUGIN_NAME), $field),
                                 'getUserInputFieldsFromSamlClaim',
                                  sprintf("Response ID: %s, field: %s", $response->getId(), $field));
        }

        return $val;
    }




    /**
     * Load presets from config/mapping_presets/ directory.
     *
     * @return array Presets list
     */
    public static function getPresets(): array
    {
        $presetsDir = defined('PLUGIN_SAMLSSO_SRCDIR')
            ? PLUGIN_SAMLSSO_SRCDIR . '/../config/mapping_presets/'
            : dirname(__DIR__, 2) . '/config/mapping_presets/';
        if (!is_dir($presetsDir)) {
            return [];
        }

        $files = glob($presetsDir . '*.yml');
        if (!is_array($files)) {
            return [];
        }

        $presets = [];
        foreach ($files as $file) {
            $name = basename($file, '.yml');
            $content = file_get_contents($file);
            if ($content !== false) {
                $presets[$name] = self::parseFlatYaml($content);
            }
        }
        return $presets;
    }

    /**
     * Parse flat YAML.
     *
     * @param string $yamlContent The content to parse
     * @return array Key-value pairs
     */
    public static function parseFlatYaml(string $yamlContent): array
    {
        $lines = explode("\n", $yamlContent);
        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $value = trim($value, "\"'");
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
