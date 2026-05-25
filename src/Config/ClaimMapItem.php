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

/**
 * Class ClaimMapItem handles field-level validation and explanation for Claim Mappings.
 */
class ClaimMapItem
{
    public const ALLOWED_GLPI_FIELDS = [
        'username',
        'email',
        'realname',
        'firstname',
        'phone',
        'mobile',
        'jobtitle',
        'country',
        'city',
        'street',
        'groups'
    ];

    /**
     * Validate configs_id.
     *
     * @param mixed $value The configs_id value
     * @return array Validation result
     */
    protected function validateConfigsId(mixed $value): array
    {
        $error = false;
        if (!is_numeric($value) || (int)$value <= 0) {
            $error = __('IDP configuration ID must be a positive integer', PLUGIN_NAME);
        }

        return [
            'valid' => !$error,
            'value' => (int)$value,
            'error' => $error
        ];
    }

    /**
     * Validate glpi_field.
     *
     * @param mixed $value The glpi_field value
     * @return array Validation result
     */
    protected function validateGlpiField(mixed $value): array
    {
        $error = false;
        if (!is_string($value) || !in_array($value, self::ALLOWED_GLPI_FIELDS, true)) {
            $error = __('Invalid GLPI user field selected', PLUGIN_NAME);
        }

        return [
            'valid' => !$error,
            'value' => (string)$value,
            'error' => $error
        ];
    }

    /**
     * Validate saml_claim.
     *
     * @param mixed $value The saml_claim value
     * @return array Validation result
     */
    protected function validateSamlClaim(mixed $value): array
    {
        $error = false;
        if (!is_string($value) || trim($value) === '') {
            $error = __('SAML Claim key cannot be empty', PLUGIN_NAME);
        } elseif (strlen($value) > 255) {
            $error = __('SAML Claim key cannot exceed 255 characters', PLUGIN_NAME);
        }

        return [
            'valid' => !$error,
            'value' => trim((string)$value),
            'error' => $error
        ];
    }
}
