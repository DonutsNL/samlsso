<?php

namespace GlpiPlugin\Glpisaml\Config;

use OneLogin\Saml2\Constants as Saml2Const;
use GlpiPlugin\Glpisaml\Config\ConfigEntity;

/**
 * Provides a default template for new configurations
 * Future: use ConfigEntities database to store additional templates
 */
class ConfigDefaultTpl {
    public static function template(): array
    {
        // Do not define the 'id' field, this will break the ConfigEntity logic.
        return [ ConfigEntity::NAME             => 'DonutsExample',
                 ConfigEntity::CONF_DOMAIN      => 'youruserdomain.tld',
                 ConfigEntity::CONF_ICON        => 'fa-brands fa-microsoft',
                 ConfigEntity::ENFORCE_SSO      => false,
                 ConfigEntity::PROXIED          => false,
                 ConfigEntity::STRICT           => false,
                 ConfigEntity::DEBUG            => false,
                 ConfigEntity::USER_JIT         => true,
                 ConfigEntity::SP_CERTIFICATE   => '-----BEGIN CERTIFICATE-----
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
2ugr8xBuPSrH
-----END CERTIFICATE-----',
                ConfigEntity::SP_KEY           => '-----BEGIN PRIVATE KEY-----
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
0XUXpCF8X54pkpVzc5ic82KX5+YAC6I=
-----END PRIVATE KEY-----',
                 ConfigEntity::SP_NAME_FORMAT   =>  Saml2Const::NAMEID_EMAIL_ADDRESS,
                 ConfigEntity::IDP_ENTITY_ID    => 'https://sts.windows.net/[EntityID]/',
                 ConfigEntity::IDP_SSO_URL      => 'https://login.microsoftonline.com/[APPID]/saml2',
                 ConfigEntity::IDP_SLO_URL      => 'https://login.microsoftonline.com/[APPID]/saml2',
                 ConfigEntity::IDP_CERTIFICATE  => '-----BEGIN CERTIFICATE-----
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
2ugr8xBuPSrH
-----END CERTIFICATE-----',
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
                 ConfigEntity::IS_ACTIVE        => false
        ];
    }
}
