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
    /**
     * Target types for claim mappings.
     */
    public const TARGET_TYPE_USER_FIELD = 'user_field';
    public const TARGET_TYPE_RULE_FIELD = 'rule_field';

    /**
     * Allowed target fields for claim mapping.
     */
    public const FIELD_USERNAME      = 'username';
    public const FIELD_EMAIL         = 'email';
    public const FIELD_REALNAME      = 'realname';
    public const FIELD_FIRSTNAME     = 'firstname';
    public const FIELD_PHONE         = 'phone';
    public const FIELD_MOBILE        = 'mobile';
    public const FIELD_JOBTITLE      = 'jobtitle';
    public const FIELD_COUNTRY       = 'country';
    public const FIELD_CITY          = 'city';
    public const FIELD_STREET        = 'street';
    public const FIELD_GROUPS        = 'groups';
    public const FIELD_DEPARTMENT    = 'department';
    public const FIELD_COMPANY       = 'company';
    public const FIELD_EMPLOYEE_TYPE = 'employee_type';
    public const FIELD_LOCATION      = 'location';
    public const FIELD_LOCALE        = 'locale';
    public const FIELD_MANAGER       = 'manager';

    public const ALLOWED_USER_FIELDS = [
        self::FIELD_USERNAME,
        self::FIELD_EMAIL,
        self::FIELD_REALNAME,
        self::FIELD_FIRSTNAME,
        self::FIELD_PHONE,
        self::FIELD_MOBILE,
        self::FIELD_JOBTITLE,
        self::FIELD_COUNTRY,
        self::FIELD_CITY,
        self::FIELD_STREET
    ];

    public const ALLOWED_RULE_FIELDS = [
        self::FIELD_GROUPS,
        self::FIELD_USERNAME,
        self::FIELD_EMAIL,
        self::FIELD_REALNAME,
        self::FIELD_FIRSTNAME,
        self::FIELD_JOBTITLE,
        self::FIELD_DEPARTMENT,
        self::FIELD_COMPANY,
        self::FIELD_EMPLOYEE_TYPE,
        self::FIELD_LOCATION,
        self::FIELD_LOCALE,
        self::FIELD_MANAGER
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
     * Validate target_type.
     *
     * @param mixed $value The target_type value
     * @return array Validation result
     */
    protected function validateTargetType(mixed $value): array
    {
        $error = false;
        if (!is_string($value) || !in_array($value, ['user_field', 'rule_field'], true)) {
            $error = __('Invalid mapping target type', PLUGIN_NAME);
        }

        return [
            'valid' => !$error,
            'value' => (string)$value,
            'error' => $error
        ];
    }

    /**
     * Validate glpi_field based on target type.
     *
     * @param mixed $value The glpi_field value
     * @param string $targetType The target_type value
     * @return array Validation result
     */
    protected function validateGlpiField(mixed $value, string $targetType = 'user_field'): array
    {
        $error = false;
        $allowed = ($targetType === 'rule_field') ? self::ALLOWED_RULE_FIELDS : self::ALLOWED_USER_FIELDS;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $error = __('Invalid GLPI field selected for target type', PLUGIN_NAME);
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

    /**
     * Validate default_value.
     *
     * @param mixed $value The default_value value
     * @return array Validation result
     */
    protected function validateDefaultValue(mixed $value): array
    {
        $error = false;
        if (!is_string($value)) {
            $error = __('Default value must be a string', PLUGIN_NAME);
        } elseif (strlen($value) > 255) {
            $error = __('Default value cannot exceed 255 characters', PLUGIN_NAME);
        }

        return [
            'valid' => !$error,
            'value' => (string)$value,
            'error' => $error
        ];
    }

    /**
     * Validate is_required.
     *
     * @param mixed $value The is_required value
     * @return array Validation result
     */
    protected function validateIsRequired(mixed $value): array
    {
        return [
            'valid' => true,
            'value' => empty($value) ? 0 : 1,
            'error' => false
        ];
    }
}
