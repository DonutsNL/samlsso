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
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.3.0
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
                'configs_id' => $configs_id
            ]
        ]);

        $this->mappings = [];
        foreach ($iterator as $row) {
            $this->mappings[] = [
                'id'            => (int)($row['id'] ?? 0),
                'target_type'   => (string)($row['target_type'] ?? 'user_field'),
                'glpi_field'    => (string)($row['glpi_field'] ?? ''),
                'saml_claim'    => (string)($row['saml_claim'] ?? ''),
                'default_value' => (string)($row['default_value'] ?? ''),
                'is_required'   => (int)($row['is_required'] ?? 0)
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
    public function getMapping(string $glpiField, string $targetType = 'user_field'): ?string
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping['glpi_field'] === $glpiField && $mapping['target_type'] === $targetType) {
                return $mapping['saml_claim'];
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
    public function getDefault(string $glpiField, string $targetType = 'user_field'): string
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping['glpi_field'] === $glpiField && $mapping['target_type'] === $targetType) {
                return $mapping['default_value'];
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
    public function isRequired(string $glpiField, string $targetType = 'user_field'): bool
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping['glpi_field'] === $glpiField && $mapping['target_type'] === $targetType) {
                return $mapping['is_required'] === 1;
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
            if ($mapping['target_type'] === 'rule_field') {
                $ruleFields[] = $mapping['glpi_field'];
            }
        }

        if (empty($ruleFields)) {
            return [];
        }

        if (isset($DB) && method_exists($DB, 'tableExists') && $DB->tableExists('glpi_rulecriterias')) {
            $iterator = $DB->request([
                'SELECT'   => ['criteria'],
                'DISTINCT' => true,
                'FROM'     => 'glpi_rulecriterias',
                'WHERE'    => [
                    'criteria' => $ruleFields
                ]
            ]);
            $usedFields = [];
            foreach ($iterator as $row) {
                $usedFields[] = (string)$row['criteria'];
            }

            foreach ($ruleFields as $field) {
                if (!in_array($field, $usedFields, true)) {
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
            $targetType = $mapping['target_type'] ?? '';
            $glpiField  = $mapping['glpi_field'] ?? '';

            $isEnforced = ($targetType === 'user_field' && in_array($glpiField, ['username', 'email'], true));

            if ($isEnforced) {
                if ($glpiField === 'username') {
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
            $targetType   = $mapping['target_type'] ?? '';
            $glpiField    = $mapping['glpi_field'] ?? '';
            $samlClaim    = $mapping['saml_claim'] ?? '';
            $defaultValue = $mapping['default_value'] ?? '';
            $isRequired   = $mapping['is_required'] ?? 0;

            if ($targetType === 'user_field') {
                if ($glpiField === 'username') {
                    if ($samlClaim === null || trim((string)$samlClaim) === '') {
                        $this->isValid = false;
                        $this->errors['mapping_' . $index] = __('SAML Claim key cannot be empty for Username mapping.', PLUGIN_NAME);
                    }
                    $hasUsername = true;
                    $isRequired = 1;
                }
                if ($glpiField === 'email') {
                    if ($samlClaim === null || trim((string)$samlClaim) === '') {
                        $this->isValid = false;
                        $this->errors['mapping_' . $index] = __('SAML Claim key cannot be empty for Email mapping.', PLUGIN_NAME);
                    }
                    $hasEmail = true;
                    $isRequired = 1;
                }
            }

            if ($samlClaim === null || trim((string)$samlClaim) === '') {
                if ($glpiField !== 'username' && $glpiField !== 'email') {
                    continue;
                }
            }

            $targetTypeVal = $this->validateTargetType($targetType);
            $fieldVal      = $this->validateGlpiField($glpiField, $targetTypeVal['value'] ?? 'user_field');
            $claimVal      = $this->validateSamlClaim($samlClaim);
            $defaultVal    = $this->validateDefaultValue($defaultValue);
            $requiredVal   = $this->validateIsRequired($isRequired);

            if (!$targetTypeVal['valid'] || !$fieldVal['valid'] || !$claimVal['valid'] || !$defaultVal['valid']) {
                $this->isValid = false;
                $rowError = '';
                if (!$targetTypeVal['valid']) {
                    $rowError .= $targetTypeVal['error'] . '; ';
                }
                if (!$fieldVal['valid']) {
                    $rowError .= $fieldVal['error'] . '; ';
                }
                if (!$claimVal['valid']) {
                    $rowError .= $claimVal['error'] . '; ';
                }
                if (!$defaultVal['valid']) {
                    $rowError .= $defaultVal['error'] . '; ';
                }
                $this->errors['mapping_' . $index] = rtrim($rowError, '; ');
            } else {
                $uniqueKey = $targetTypeVal['value'] . ':' . $fieldVal['value'];
                if (isset($seen[$uniqueKey])) {
                    $this->isValid = false;
                    $this->errors['mapping_' . $index] = sprintf(__('Duplicate mapping for %s: %s', PLUGIN_NAME), $targetTypeVal['value'], $fieldVal['value']);
                } else {
                    $seen[$uniqueKey] = true;
                    $validatedMappings[] = [
                        'target_type'   => $targetTypeVal['value'],
                        'glpi_field'    => $fieldVal['value'],
                        'saml_claim'    => $claimVal['value'],
                        'default_value' => $defaultVal['value'],
                        'is_required'   => $requiredVal['value']
                    ];
                }
            }
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
            $this->errors['configs_id'] = $configsIdVal['error'];
            return false;
        }

        if (!$this->validate($newMappings)) {
            return false;
        }

        $claimMapTable = ClaimMap::getTable();
        $DB->delete(
            $claimMapTable,
            [
                'configs_id' => $this->configs_id
            ]
        );

        $claimMap = new ClaimMap();
        foreach ($this->mappings as $mapping) {
            $input = [
                'configs_id'    => $this->configs_id,
                'target_type'   => $mapping['target_type'],
                'glpi_field'    => $mapping['glpi_field'],
                'saml_claim'    => $mapping['saml_claim'],
                'default_value' => $mapping['default_value'],
                'is_required'   => $mapping['is_required']
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
                'configs_id' => $this->configs_id
            ],
            'ORDER' => 'saml_claim ASC'
        ]);

        $claims = [];
        foreach ($iterator as $row) {
            $claims[] = (string)$row['saml_claim'];
        }
        return $claims;
    }

    /**
     * Record a new observed claim.
     *
     * @param string $claim The observed SAML claim
     * @return void
     */
    public function trackObservedClaim(string $claim): void
    {
        if ($this->configs_id > 0) {
            ObservedClaim::trackClaim($this->configs_id, $claim);
        }
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
