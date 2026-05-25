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

        foreach ($iterator as $row) {
            $field = (string)$row['glpi_field'];
            $claim = (string)$row['saml_claim'];
            $this->mappings[$field] = $claim;
        }
    }

    /**
     * Get the mapped claim for a GLPI field, or null if not configured.
     *
     * @param string $glpiField The GLPI user field
     * @return string|null The mapped claim, or null
     */
    public function getMapping(string $glpiField): ?string
    {
        return isset($this->mappings[$glpiField]) ? $this->mappings[$glpiField] : null;
    }

    /**
     * Get all active mappings.
     *
     * @return array All mappings
     */
    public function getMappings(): array
    {
        return $this->mappings;
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
     * Save/update the mappings.
     *
     * @param array $newMappings The mappings to save
     * @return bool True on success
     */
    public function save(array $newMappings): bool
    {
        global $DB;
        $this->isValid = true;
        $this->errors = [];
        $this->mappings = [];

        $configsIdVal = $this->validateConfigsId($this->configs_id);
        if (!$configsIdVal['valid']) {
            $this->isValid = false;
            $this->errors['configs_id'] = $configsIdVal['error'];
            return false;
        }

        $validatedMappings = [];
        foreach ($newMappings as $field => $claim) {
            if ($claim === null || trim((string)$claim) === '') {
                continue;
            }

            $fieldVal = $this->validateGlpiField($field);
            $claimVal = $this->validateSamlClaim($claim);

            if (!$fieldVal['valid'] || !$claimVal['valid']) {
                $this->isValid = false;
                if (!$fieldVal['valid']) {
                    $this->errors[$field] = $fieldVal['error'];
                }
                if (!$claimVal['valid']) {
                    $this->errors[$field] = $claimVal['error'];
                }
            } else {
                $validatedMappings[$fieldVal['value']] = $claimVal['value'];
            }
        }

        if (!$this->isValid) {
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
        foreach ($validatedMappings as $field => $claim) {
            $input = [
                'configs_id' => $this->configs_id,
                'glpi_field' => $field,
                'saml_claim' => $claim
            ];
            $claimMap->add($input);
            $this->mappings[$field] = $claim;
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
