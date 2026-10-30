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

namespace GlpiPlugin\Samlsso\Config;

use OneLogin\Saml2\Constants as Saml2Const;
use GlpiPlugin\Samlsso\Config\ConfigEntity;

/**
 * Provides a default template for new configurations
 * Future: use ConfigEntities database to store additional templates
 */
class ConfigDefaultTpl {
    /**
     * Returns the default configuration template for new Identity Provider records.
     *
     * @return array<string, mixed>
     */
    public static function template(): array
    {
        return [ ConfigEntity::NAME             => 'DonutsExample',
                 ConfigEntity::CONF_DOMAIN      => 'youruserdomain.tld',
                 ConfigEntity::CONF_ICON        => 'fa-brands fa-microsoft',
                 ConfigEntity::ENFORCE_SSO      => false,
                 ConfigEntity::PROXIED          => false,
                 ConfigEntity::STRICT           => true,
                 ConfigEntity::DEBUG            => false,
                 ConfigEntity::SP_KEY_SOURCE    => 'db',
                 ConfigEntity::USER_JIT         => true,
                 ConfigEntity::SP_CERTIFICATE   => '',
                 ConfigEntity::SP_KEY           => '',
                 ConfigEntity::SP_NAME_FORMAT   => Saml2Const::NAMEID_EMAIL_ADDRESS,
                 ConfigEntity::IDP_ENTITY_ID    => 'https://sts.windows.net/[EntityID]/',
                 ConfigEntity::IDP_SSO_URL      => 'https://login.microsoftonline.com/[APPID]/saml2',
                 ConfigEntity::IDP_SLO_URL      => 'https://login.microsoftonline.com/[APPID]/saml2',
                 ConfigEntity::IDP_CERTIFICATE  => '',
                 ConfigEntity::AUTHN_CONTEXT    => 'none',
                 ConfigEntity::AUTHN_COMPARE    => 'exact',
                 ConfigEntity::ENCRYPT_NAMEID   => false,
                 ConfigEntity::SIGN_AUTHN       => false,
                 ConfigEntity::SIGN_SLO_REQ     => false,
                 ConfigEntity::SIGN_SLO_RES     => false,
                 ConfigEntity::COMPRESS_REQ     => true,
                 ConfigEntity::COMPRESS_RES     => true,
                 ConfigEntity::XML_VALIDATION   => true,
                 ConfigEntity::LOWERCASE_URL    => true,
                 ConfigEntity::COMMENT          => 'Azure example configuration, see: https://learn.microsoft.com/en-us/entra/identity/saas-apps/saml-toolkit-tutorial',
                 ConfigEntity::IS_ACTIVE        => false,
                 ConfigEntity::SYNC_ON_LOGIN    => false,
                 ConfigEntity::SECURITY_WANTMESSAGESSIGNED => false,
                 ConfigEntity::SECURITY_WANTASSERTIONSSIGNED => true,
                 ConfigEntity::SECURITY_WANTASSERTIONSENCRYPTED => false,
                 ConfigEntity::SECURITY_SIGNMETADATA => false,
                 ConfigEntity::SECURITY_WANTNAMEID => true,
                 ConfigEntity::REQUEST_TIMEOUT => 15,
                 ConfigEntity::INACTIVITY_TIMEOUT => 0
        ];
    }
}
