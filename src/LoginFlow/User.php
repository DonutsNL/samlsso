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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\LoginFlow;

use Session;
use Toolbox;
use Throwable;
use Group_User;
use Profile_User;
use User as glpiUser;
use OneLogin\Saml2\Response;
use GlpiPlugin\Samlsso\LoginFlow;
use GlpiPlugin\Samlsso\LoginState;
use GlpiPlugin\Samlsso\ObservedClaim;
use GlpiPlugin\Samlsso\RuleSamlCollection;
use GlpiPlugin\Samlsso\Config\ConfigEntity;
use GlpiPlugin\Samlsso\Config\ClaimMapEntity;

/**
 * This class is responsible to make sure a corresponding
 * user is returned after successful login. If a user does
 * not exist it will create one if JIT is enabled else it will
 * trigger a human readable error. On Jit creation it will also
 * call the RuleSamlCollection and parse any configured rules.
 */
class User
{
    // Common user/group/profile constants
    public const USERID             = 'id';
    public const NAME               = 'name';
    public const REALNAME           = 'realname';
    public const FIRSTNAME          = 'firstname';
    public const EMAIL              = '_useremails';
    public const MOBILE             = 'mobile';
    public const PHONE              = 'phone';
    public const PHONE2             = 'phone2';
    public const REGISTRATION_NUMBER = 'registration_number';
    public const COMMENT            = 'comment';
    public const PASSWORD           = 'password';
    public const PASSWORDN          = 'password2';
    public const DELETED            = 'is_deleted';
    public const ACTIVE             = 'is_active';
    public const RULEOUTPUT         = 'output';
    public const USERSID            = 'users_id';
    public const GROUPID            = 'groups_id';
    public const GROUP_DEFAULT      = 'specific_groups_id';
    public const IS_DYNAMIC         = 'is_dynamic';
    public const PROFILESID         = 'profiles_id';
    public const PROFILE_DEFAULT    = '_profiles_id_default';
    public const PROFILE_RECURSIVE  = 'is_recursive';
    public const ENTITY_ID          = 'entities_id';
    public const ENTITY_DEFAULT     = '_entities_id_default';
    public const AUTHTYPE              = 'authtype';
    public const AUTHTYPE_LOCAL        = 1;
    public const AUTHS_ID              = 'auths_id';
    public const AUTHS_ID_OFFSET       = 1000000;
    public const USER_DN               = 'user_dn';
    public const USER_DN_HASH          = 'user_dn_hash';
    public const SYNC_FIELD            = 'sync_field';
    public const IS_DELETED_LDAP       = 'is_deleted_ldap';
    public const PASSWORD_MD5          = 'password_md5';
    public const API_TOKEN             = 'api_token';
    public const PERSONAL_TOKEN        = 'personal_token';
    public const PASSWORD_FORGET_TOKEN = 'password_forget_token';
    public const COOKIE_TOKEN          = 'cookie_token';
    public const EXTAUTH               = '_extauth';
    public const DATE_MOD              = 'date_mod';
    public const TIMEZONE              = 'timezone';
    public const LOCATIONS_ID          = 'locations_id';
    public const USERCATEGORIES_ID     = 'usercategories_id';
    public const USERTITLES_ID         = 'usertitles_id';
    public const LANGUAGE              = 'language';
    public const SYNCDATE              = 'date_sync';  //Y-m-d H:i:s
    public const RULE_FIELDS_KEY       = '_saml_rule_fields';
    public const SAMLGROUPS            = 'samlClaimedGroups';
    public const SAMLJOBTITLE          = 'samlClaimedJobTitle';
    public const SAMLCOUNTRY           = 'country';
    public const SAMLCITY              = 'city';
    public const SAMLSTREET            = 'street';
    public const SCIM_DEPARTMENT       = 'scimDepartment';
    public const SCIM_ORGANIZATION     = 'scimOrganization';
    public const SCIM_DIVISION         = 'scimDivision';
    public const SCIM_COST_CENTER      = 'scimCostCenter';
    public const SCIM_EMPLOYEE_NUMBER  = 'scimEmployeeNumber';


    /**
     * samlResponse attributes or claims provided by IdP.
     * @see https://docs.oasis-open.org/security/saml/v2.0/saml-bindings-2.0-os.pdf
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/reference-saml-tokens
     */
    public const SCHEMA_SURNAME              = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname';         // Used in user creation JIT - Optional
    public const SCHEMA_FIRSTNAME            = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/firstname';       // Used in user creation JIT - Optional
    public const SCHEMA_GIVENNAME            = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname';       // Used in user creation JIT - Optional
    public const SCHEMA_EMAILADDRESS         = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';    // Used in user creation JIT - Required
    public const SCHEMA_MOBILE               = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/mobilephone';     // Used in user creation JIT - Optional
    public const SCHEMA_PHONE                = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/telephonenumber'; // Used in user creation JIT - Optional
    public const SCHEMA_JOBTITLE             = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/jobtitle';        // Used in user creation JIT - Optional
    public const SCHEMA_COUNTRY              = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/country';         //
    public const SCHEMA_CITY                 = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/city';            //
    public const SCHEMA_STREET               = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/streetaddress';   //
    public const SCHEMA_GROUPS               = 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups';        // Used in assignment rules - Optional

    /**
     * Maps a samlSSO Identity Provider configuration ID (1..999) into an isolated
     * glpi_users.auths_id namespace (1,000,000 + $idpId) so SAML/SCIM external bindings
     * never collide with GLPI Core's glpi_authldaps.id primary keys.
     *
     * @param int $idpId Active samlSSO configuration ID (> 0).
     * @return int Namespaced glpi_users.auths_id value.
     * @throws \InvalidArgumentException When $idpId <= 0.
     * @since 1.4.0
     */
    public static function toAuthsId(int $idpId): int
    {
        if ($idpId <= 0) {
            throw new \InvalidArgumentException('samlSSO IdP configuration ID must be strictly positive.');
        }
        return self::AUTHS_ID_OFFSET + $idpId;
    }

    /**
     * Gets or creates (if JIT is enabled for IDP) the GLPI user.
     *
     * This method is called after succesfull login by an IDP. Beware that
     * the return value glpiUser of this method will perform the actual login
     * into GLPI. So make sure not to return anything from this method unless
     * you actually intend to allow login.
     *
     * @param   array       $userFields Containing user attributes found in Saml claim
     * @param   ConfigEntity $configEntity Config entity object.
     * @param   LoginState|null $state Optional login state tracker.
     * @return  glpiUser    GlpiUser object with populated fields.
     * @since               1.0.0
     */
    public function getOrCreateUser(array $userFields, ConfigEntity $configEntity, ?LoginState $state = null): glpiUser
    {
        $name  = (array_key_exists(User::NAME, $userFields) && isset($userFields[User::NAME])) ? (string)$userFields[User::NAME] : '';
        $email = (array_key_exists(User::EMAIL, $userFields) && isset($userFields[User::EMAIL][0])) ? (string)$userFields[User::EMAIL][0] : '';

        $idpId = (int)$configEntity->getField(ConfigEntity::ID);
        if ($idpId <= 0) {
            LoginFlow::PrintFatalLoginError(
                __('Your SSO login was successful but the identity provider configuration is invalid or disabled.', PLUGIN_NAME)
            );
        }

        $expectedAuthsId = self::toAuthsId($idpId);
        $allowEmailLinking = (bool)$configEntity->getField(ConfigEntity::SYNC_ON_LOGIN);

        $candidates = $this->findCandidateUsers($name, $email, $allowEmailLinking);
        if (empty($candidates)) {
            return $this->performJIT($userFields, $configEntity, $state);
        }

        $localAuthType = class_exists('\Auth') && defined('\Auth::DB_GLPI') ? \Auth::DB_GLPI : self::AUTHTYPE_LOCAL;
        $externalAuthType = class_exists('\Auth') && defined('\Auth::EXTERNAL') ? \Auth::EXTERNAL : 4;

        /**
         * First pass across all candidate rows: fail closed immediately if any matching
         * username or email belongs to a local DB_GLPI account or the built-in 'glpi' super-admin.
         */
        foreach ($candidates as $candidate) {
            $candAuthtype = (int)($candidate[User::AUTHTYPE] ?? 0);
            $candName = (string)($candidate[User::NAME] ?? '');
            if ($candAuthtype === $localAuthType || strtolower($candName) === 'glpi') {
                LoginFlow::PrintFatalLoginError(
                    sprintf(
                        __("User account '%s' uses local GLPI authentication and cannot be linked or accessed via SAML SSO.", PLUGIN_NAME),
                        $candName !== '' ? $candName : $name
                    )
                );
            }
        }

        /**
         * Second pass: select an exact (Auth::EXTERNAL, 1_000_000 + $idpId) match first,
         * falling back to one-time adoption only for unmigrated (Auth::EXTERNAL, $idpId)
         * or unbound (Auth::EXTERNAL, 0) rows without an LDAP user_dn.
         */
        $exactMatch = null;
        $legacySameIdpMatch = null;
        $unboundExternalMatch = null;
        $firstForeign = null;

        foreach ($candidates as $candidate) {
            $candAuthtype = (int)($candidate[User::AUTHTYPE] ?? 0);
            $candAuthsId = (int)($candidate[User::AUTHS_ID] ?? 0);
            $candUserDn = trim((string)($candidate[User::USER_DN] ?? ''));

            if ($candAuthtype === $externalAuthType && $candAuthsId === $expectedAuthsId) {
                $exactMatch = $candidate;
                break;
            }
            if ($candAuthtype === $externalAuthType && $candAuthsId === $idpId && $candUserDn === '') {
                if ($legacySameIdpMatch === null) {
                    $legacySameIdpMatch = $candidate;
                }
                continue;
            }
            if ($candAuthtype === $externalAuthType && $candAuthsId === 0 && $candUserDn === '') {
                if ($unboundExternalMatch === null) {
                    $unboundExternalMatch = $candidate;
                }
                continue;
            }
            if ($firstForeign === null) {
                $firstForeign = $candidate;
            }
        }

        $selectedRow = $exactMatch ?? $legacySameIdpMatch ?? $unboundExternalMatch;
        if ($selectedRow === null) {
            $foreignName = is_array($firstForeign) && !empty($firstForeign[User::NAME])
                ? (string)$firstForeign[User::NAME]
                : $name;
            LoginFlow::PrintFatalLoginError(
                sprintf(
                    __("User account '%s' is bound to a different authentication source and cannot be accessed via this SAML Identity Provider.", PLUGIN_NAME),
                    $foreignName
                )
            );
        }

        $matchedUserId = (int)($selectedRow[User::USERID] ?? 0);
        if ($exactMatch === null && $matchedUserId > 0) {
            global $DB;
            if ($DB !== null && method_exists($DB, 'update')) {
                $DB->update(
                    'glpi_users',
                    [User::AUTHS_ID => $expectedAuthsId],
                    [User::USERID => $matchedUserId]
                );
            }
            $selectedRow[User::AUTHS_ID] = $expectedAuthsId;
        }

        $user = new glpiUser();
        if ($matchedUserId <= 0 || !$user->getFromDB($matchedUserId)) {
            $user->fields = $selectedRow;
        }
        $user->fields[User::AUTHS_ID] = $expectedAuthsId;

        if ($user->fields[User::DELETED] == 1) {
            LoginFlow::PrintFatalLoginError(sprintf(__("User with GlpiUserid: %s is marked deleted but still exists in the GLPI database. Because of this we cannot log you in as this would violate GLPI its security policies. Please contact the GLPI administrator to restore the user with provided ID or purge the user to allow the Just in Time (JIT) user creation to create a new user with the idp provided claims.", PLUGIN_NAME), $user->fields[User::USERID]));
        }

        /** Verify the found user is not disabled by the administrator. */
        if ($user->fields[User::ACTIVE] == 0) {
            LoginFlow::PrintFatalLoginError(sprintf(__("User with GlpiUserid: %s is disabled. Please contact your GLPI administrator and request him to reactivate your account.", PLUGIN_NAME), $user->fields[User::USERID]));
        }

        /**
         * If synchronization on login is active, update the user fields mapped
         * from the SAML claims and rerun the rules engine to apply updated assignments.
         */
        if ($configEntity->getField(ConfigEntity::SYNC_ON_LOGIN)) {
            $this->syncUserFieldsOnLogin($user, $userFields, $state);
        }

        /** Check if the user has any profiles assigned. */
        if (count(Profile_User::getForUser((int)$user->fields[User::USERID])) === 0) {
            LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but no GLPI profile was assigned to your account. Please contact your GLPI administrator to assign a profile to your account.", PLUGIN_NAME));
        }

        return $user;
    }

    /**
     * Queries candidate GLPI user rows by username and (when enabled) email via
     * parameterized $DB->request() queries so multi-source username/email collisions
     * never trigger CommonDBTM::TooManyResultsException.
     *
     * @param string $name Mapped SAML username / NameID.
     * @param string $email Mapped primary SAML email address.
     * @param bool $allowEmailLinking Whether email-based candidate lookup is enabled.
     * @return array<int, array<string, mixed>> Candidate user rows keyed by user ID.
     */
    private function findCandidateUsers(string $name, string $email, bool $allowEmailLinking): array
    {
        global $DB;

        $candidates = [];
        if ($DB === null || !method_exists($DB, 'request')) {
            return $candidates;
        }

        if ($name !== '') {
            $nameRows = $DB->request([
                'FROM'  => 'glpi_users',
                'WHERE' => [User::NAME => $name],
            ]);
            foreach ($nameRows as $row) {
                if (is_array($row) && isset($row[User::USERID])) {
                    $candidates[(int)$row[User::USERID]] = $row;
                }
            }
        }

        if ($allowEmailLinking) {
            $lookupEmails = array_values(array_unique(array_filter(
                [$email, $name],
                static fn(string $val): bool => $val !== ''
            )));
            if (!empty($lookupEmails)) {
                $missingUserIds = [];
                $emailRows = $DB->request([
                    'SELECT' => [User::USERSID, 'email'],
                    'FROM'   => 'glpi_useremails',
                    'WHERE'  => ['email' => $lookupEmails],
                ]);
                foreach ($emailRows as $emailRow) {
                    $uid = (int)($emailRow[User::USERSID] ?? 0);
                    if ($uid > 0 && !isset($candidates[$uid])) {
                        $missingUserIds[] = $uid;
                    }
                }
                if (!empty($missingUserIds)) {
                    $userRows = $DB->request([
                        'FROM'  => 'glpi_users',
                        'WHERE' => [User::USERID => array_values(array_unique($missingUserIds))],
                    ]);
                    foreach ($userRows as $row) {
                        if (is_array($row) && isset($row[User::USERID])) {
                            $candidates[(int)$row[User::USERID]] = $row;
                        }
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Sanitize an input array for the running GLPI core.
     *
     * GLPI 11 expects plugin-supplied add()/update() payloads to be
     * sanitized through Glpi\Toolbox\Sanitizer. GLPI 12 removed that class
     * from core (payloads stay raw; the core escapes at query time), so the
     * call is version-selected here instead of at the call sites.
     *
     * @param array $input Raw or sanitized payload, depending on core version.
     * @return array Payload shaped the way the running core expects.
     * @since 1.4.0
     */
    private static function sanitizeInput(array $input): array
    {
        if (class_exists('Glpi\Toolbox\Sanitizer')) {
            return \Glpi\Toolbox\Sanitizer::sanitize($input);
        }
        return $input;
    }

    /**
     * Performs Just-In-Time (JIT) user creation dynamically.
     *
     * @param array $userFields User attributes from SAML claims.
     * @param ConfigEntity $configEntity The config entity.
     * @param LoginState|null $state Optional login state.
     * @return glpiUser Freshly created or fetched GLPI user.
     * @since 1.0.0
     */
    private function performJIT(array $userFields, ConfigEntity $configEntity, ?LoginState $state = null): glpiUser
    {
        $user = new glpiUser();

        // Strictly validate configuration and database origin
        $dbId = $configEntity->getField(ConfigEntity::ID);
        if (!$configEntity->isValid() || !$configEntity->isActive() || empty($dbId) || (int)$dbId <= 0) {
            LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but the identity provider configuration is invalid or disabled.", PLUGIN_NAME));
        }

        if ($configEntity->getField(ConfigEntity::USER_JIT)) {
            $userFields[User::AUTHTYPE] = class_exists('\Auth') && defined('\Auth::EXTERNAL') ? \Auth::EXTERNAL : 4;
            $userFields[User::AUTHS_ID] = self::toAuthsId((int)$dbId);
            $addPayload = self::stripSyntheticRuleFields($userFields);
            $id = $user->add(self::sanitizeInput($addPayload));
            if (!$id) {
                LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but there is no matching GLPI user account and we failed to create one dynamically using Just In Time user creation. Please request a GLPI administrator to review the logs and correct the problem or request the administrator to create a GLPI user manually.", PLUGIN_NAME));
            } else {
                $this->runRulesEngine((int)$id, $userFields, $state);
            }

            $user = new glpiUser();
            if ($user->getFromDB($id)) {
                if (count(Profile_User::getForUser($id)) === 0) {
                    LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but no GLPI profile was assigned to your account and we failed to assign one dynamically using Just In Time user creation. Please request a GLPI administrator to review the logs and correct the problem or request the administrator to assign a GLPI profile manually.", PLUGIN_NAME));
                }
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Dynamically created GLPI user for: %s', PLUGIN_NAME),
                        htmlspecialchars((string)($userFields[User::EMAIL][0] ?? ''), ENT_QUOTES, 'UTF-8')
                    )
                );
                return $user;
            } else {
                LoginFlow::PrintFatalLoginError(__("Critical error: samlSSO was unable to fetch newly created user from the database!", PLUGIN_NAME));
            }
        } else {
            $idpName = (string)$configEntity->getField(ConfigEntity::NAME);
            $email   = (string)($userFields[User::EMAIL][0] ?? '');
            LoginFlow::PrintFatalLoginError(
                sprintf(
                    __("Your SSO login was successful but there is no matching GLPI user account. In addition the Just-in-time user creation is disabled for: %1\$s. Please contact your GLPI administrator and request an account to be created matching the provided email claim: %2\$s or login using a local user account.", PLUGIN_NAME),
                    $idpName,
                    $email
                )
            );
        }
    }

    /**
     * Updates user rights, groups, and profiles based on rule matching output.
     *
     * @param array $params Contains the rule output mapping details.
     * @return void
     * @since 1.0.0
     */
    public function updateUserRights(array $params): void       /* NOSONAR - Complexity by design */
    {
        /* Log that we are applying JIT. */
        Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, __('JIT was called with params:', PLUGIN_NAME) . ' ' . var_export($params, true) . "\n\n\n");

        $state = null;
        try {
            $state = new LoginState();
        } catch (\Throwable $e) {
            /* ignore if not available */
        }

        /* We are working on the output only. */
        $update = $params[User::RULEOUTPUT];

        $this->assignGroupRights($update, $state);
        $this->assignProfileRights($update, $state);
        $this->updateUserDefaults($update);
    }

    /**
     * This function figures out what the samlResponse provided claims are and
     * evaluates the values and assigns them to the UserArray that will be
     * passed to the Auth object in the loginFlow object. If a critical error
     * is found, processing is stopped and an error shown.
     *
     * @param    Response  $response Response object with the samlResponse attributes.
     * @return   array     user->add input fields array with properties.
     * @since    1.0.0
     */
    public static function getUserInputFieldsFromSamlClaim(Response $response, int $idpId = -1): array
    {
        try {
            $claims = $response->getAttributes();
        } catch (Throwable $e) {
            LoginFlow::PrintFatalLoginError($e);
        }

        if (!is_array($claims)) {
            $claims = [];
        }

        if ($idpId > 0) {
            foreach (array_keys($claims) as $claimKey) {
                ObservedClaim::trackClaim($idpId, (string)$claimKey);
            }
            ObservedClaim::trackClaim($idpId, 'NameId');
        }

        $claimMapEntity = new ClaimMapEntity($idpId);
        $user = [];

        // 1. Resolve username
        $usernameVal = $claimMapEntity->resolveUsername($response, $claims);
        $user[User::NAME] = $usernameVal;

        // 2. Resolve email
        $emailVal = $claimMapEntity->resolveEmail($response, $claims, $usernameVal);
        $user[User::EMAIL] = [$emailVal];

        // 3. Resolve other dynamic user fields
        $userFields = $claimMapEntity->resolveUserFields($response, $claims);
        foreach ($userFields as $key => $val) {
            $user[$key] = $val;
        }

        // 4. Resolve dynamic rule fields
        $ruleFields = $claimMapEntity->resolveRuleFields($response, $claims);
        foreach ($ruleFields as $key => $val) {
            $user[$key] = $val;
        }

        $user[User::COMMENT]    = __('Created by phpSaml Just-In-Time user creation on:', PLUGIN_NAME) . ' ' . date('Y-m-d H:i:s');
        $password = bin2hex(random_bytes(20));
        $user[User::PASSWORD]   = $password;
        $user[User::PASSWORDN]  = $password;
        $user[User::AUTHTYPE]   = 4;
        $user[User::SYNCDATE]   = date('Y-m-d H:i:s');

        return $user;
    }

    /**
     * Run the rules engine using the collection of SAML-based assignment rules
     * to dynamically match groups and profiles for the authenticated user.
     *
     * @param  int   $userId      The ID of the GLPI user to update
     * @param  array $userFields  The parsed user attributes from SAML claims
     * @param  LoginState|null $state Optional login state.
     * @return void
     */
    private function runRulesEngine(int $userId, array $userFields, ?LoginState $state = null): void
    {
        $ruleCollection = new RuleSamlCollection();
        $matchInput = [
            User::NAME           => $userFields[User::NAME] ?? false,
            User::REALNAME       => $userFields[User::REALNAME] ?? false,
            User::FIRSTNAME      => $userFields[User::FIRSTNAME] ?? false,
            User::EMAIL          => $userFields[User::EMAIL] ?? false,
            User::MOBILE         => $userFields[User::MOBILE] ?? false,
            User::PHONE          => $userFields[User::PHONE] ?? false,
            User::SAMLGROUPS     => $userFields[User::SAMLGROUPS] ?? false,
            User::SAMLJOBTITLE   => $userFields[User::SAMLJOBTITLE] ?? false,
            User::SAMLCOUNTRY    => $userFields[User::SAMLCOUNTRY] ?? false,
            User::SAMLCITY       => $userFields[User::SAMLCITY] ?? false,
            User::SAMLSTREET     => $userFields[User::SAMLSTREET] ?? false,
            'groups'             => $userFields[User::SAMLGROUPS] ?? false,
        ];

        if (isset($userFields[User::RULE_FIELDS_KEY]) && is_array($userFields[User::RULE_FIELDS_KEY])) {
            foreach ($userFields[User::RULE_FIELDS_KEY] as $field => $val) {
                $matchInput[$field] = $val;
                if ($field === 'jobtitle') {
                    $matchInput[User::SAMLJOBTITLE] = $val;
                } elseif ($field === User::SAMLCOUNTRY) {
                    $matchInput[User::SAMLCOUNTRY] = $val;
                } elseif ($field === User::SAMLCITY) {
                    $matchInput[User::SAMLCITY] = $val;
                } elseif ($field === User::SAMLSTREET) {
                    $matchInput[User::SAMLSTREET] = $val;
                }
            }
        }

        /* Uses a hook to call updateUserRights() if a rule was matched */
        $output = $ruleCollection->processAllRules($matchInput, [User::USERSID => $userId], []);

        if ($state !== null) {
            $cleanInput = [];
            foreach ($matchInput as $k => $v) {
                if ($v !== false && !empty($v)) {
                    $cleanInput[$k] = is_array($v) ? implode(', ', $v) : (string)$v;
                }
            }
            $state->addLoginFlowTrace([
                'rulesEngineInput' => json_encode($cleanInput),
                'rulesEngineOutput' => json_encode($output)
            ]);
        }
    }

    /**
     * Synchronize user fields from SAML claims to GLPI user on login.
     *
     * @param glpiUser $user The GLPI user object to update
     * @param array $userFields Mapped user fields from SAML claims
     * @param LoginState|null $state Optional login state
     * @return void
     */
    private function syncUserFieldsOnLogin(glpiUser $user, array $userFields, ?LoginState $state): void
    {
        $changes = [];
        $fieldsToCompare = [
            User::NAME                => 'username',
            User::REALNAME            => 'realname',
            User::FIRSTNAME           => 'firstname',
            User::MOBILE              => 'mobile',
            User::PHONE               => 'phone',
            User::REGISTRATION_NUMBER => 'registration_number',
        ];

        $syncFieldsEvaluated = [];
        foreach ($fieldsToCompare as $dbKey => $displayName) {
            if (isset($userFields[$dbKey]) && $userFields[$dbKey] !== false) {
                $syncFieldsEvaluated['user_field: ' . $displayName] = (string)$userFields[$dbKey];
                $oldVal = $user->fields[$dbKey] ?? '';
                $newVal = (string)$userFields[$dbKey];
                if ((string)$oldVal !== $newVal) {
                    $changes[] = "$displayName: '$oldVal' => '$newVal'";
                }
            }
        }

        $emailExists = false;
        if (isset($userFields[User::EMAIL][0])) {
            $newEmail = $userFields[User::EMAIL][0];
            $syncFieldsEvaluated['user_field: email'] = $newEmail;
            $existingEmails = [];
            global $DB;
            $emailsFromDb = $DB->request([
                'FROM'  => 'glpi_useremails',
                'WHERE' => [User::USERSID => (int)$user->fields[User::USERID]]
            ]);
            foreach ($emailsFromDb as $emailRow) {
                if (isset($emailRow['email'])) {
                    $existingEmails[] = strtolower($emailRow['email']);
                }
            }
            if (!in_array(strtolower($newEmail), $existingEmails)) {
                $changes[] = "email added: '$newEmail'";
            } else {
                $emailExists = true;
            }
        }

        if ($state !== null) {
            if (!empty($changes)) {
                $syncFieldsEvaluated['Changes Applied'] = implode(', ', $changes);
                $state->addLoginFlowTrace(['syncUpdatedFields' => json_encode($syncFieldsEvaluated)]);
            } else {
                $state->addLoginFlowTrace(['syncNoChanges' => json_encode($syncFieldsEvaluated)]);
            }
        }

        $protectedFields = [
            User::AUTHTYPE,
            User::PASSWORD,
            User::PASSWORDN,
            User::COMMENT,
            User::USER_DN,
            User::AUTHS_ID,
            User::PASSWORD_MD5,
            User::API_TOKEN,
            User::PERSONAL_TOKEN,
        ];
        foreach ($protectedFields as $protectedField) {
            unset($userFields[$protectedField]);
        }

        $userFields[User::USERID] = $user->fields[User::USERID];

        $updateFields = array_filter($userFields, static function ($value) {
            return $value !== false && $value !== null;
        });

        foreach ($protectedFields as $protectedField) {
            unset($updateFields[$protectedField]);
        }

        if ($emailExists) {
            unset($updateFields[User::EMAIL]);
        }

        $updateFields = self::stripSyntheticRuleFields($updateFields);

        if (count($updateFields) > 1) {
            $user->update(self::sanitizeInput($updateFields));
        }
        $this->runRulesEngine((int)$user->fields[User::USERID], $userFields, $state);
    }

    /**
     * Strips synthetic SAML claim/rule evaluation keys that do not correspond to columns on glpi_users.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function stripSyntheticRuleFields(array $fields): array
    {
        $syntheticKeys = [
            User::RULE_FIELDS_KEY,
            User::SAMLGROUPS,
            User::SAMLJOBTITLE,
            User::SAMLCOUNTRY,
            User::SAMLCITY,
            User::SAMLSTREET,
        ];
        foreach ($syntheticKeys as $syntheticKey) {
            unset($fields[$syntheticKey]);
        }
        return $fields;
    }

    /**
     * Assign JIT group relations for the user.
     *
     * @param array $update Containing the group update criteria
     * @param LoginState|null $state
     * @return void
     */
    private function assignGroupRights(array $update, ?LoginState $state): void
    {
        $groupId = $update[User::GROUP_DEFAULT] ?? $update[User::GROUPID] ?? null;
        if (
            $groupId !== null &&
            isset($update[User::USERSID])
        ) {
            if (Group_User::isUserInGroup((int)$update[User::USERSID], (int)$groupId)) {
                Toolbox::logInFile(
                    PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                    sprintf(
                        __('JIT group already assigned: %s to userID %s', PLUGIN_NAME),
                        (string)$groupId,
                        (string)$update[User::USERSID]
                    ) . "\n"
                );
                if ($state && $state->getStateId() > 0) {
                    $state->addLoginFlowTrace(['JIT group already assigned' => 'groupID:' . $groupId]);
                }
            } else {
                /* Get the Group_User object to update the user group relation. */
                $groupuser = new Group_User();
                if (!$groupuser->add([
                    User::USERSID => $update[User::USERSID],
                    User::GROUPID => (int)$groupId,
                ])) {
                    Toolbox::logInFile(
                        PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                        sprintf(
                            __('JIT failed to assign groupID: %s to userID %s', PLUGIN_NAME),
                            (string)$groupId,
                            (string)$update[User::USERSID]
                        ) . "\n"
                    );
                    if ($state && $state->getStateId() > 0) {
                        $state->addLoginFlowTrace(['JIT group assignment failed' => 'groupID:' . $groupId]);
                    }
                } else {
                    Toolbox::logInFile(
                        PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                        sprintf(
                            __('JIT assigned groupID: %s to userID %s', PLUGIN_NAME),
                            (string)$groupId,
                            (string)$update[User::USERSID]
                        ) . "\n"
                    );
                    if ($state && $state->getStateId() > 0) {
                        $state->addLoginFlowTrace(['JIT group assigned' => 'groupID:' . $groupId]);
                    }
                }
            }
        } else {
            Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found no groupId to add.', PLUGIN_NAME) . "\n");
        }
    }

    /**
     * Assign JIT profile and entity rights for the user.
     *
     * @param array $update Containing the profile update criteria
     * @param LoginState|null $state
     * @return void
     */
    private function assignProfileRights(array $update, ?LoginState $state): void
    {
        $profileId = $update[User::PROFILESID] ?? $update[User::PROFILE_DEFAULT] ?? null;
        if ($profileId === null || !isset($update[User::USERSID])) {
            return;
        }

        $rights = $this->buildProfileRightsPayload($update, (int) $profileId);
        $profileUser = new Profile_User();
        $profileCriteria = $this->buildProfileCriteria($rights);

        if (count($profileUser->find($profileCriteria)) > 0) {
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                __('JIT profile already assigned with config:', PLUGIN_NAME) . ' ' . var_export($rights, true) . "\n\n\n",
                true
            );
            $this->recordProfileTrace($state, 'JIT profile already assigned', $rights[User::PROFILESID]);
            return;
        }

        if (!$profileUser->add($rights)) {
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                __('JIT was not able to assign profile with config:', PLUGIN_NAME) . ' ' . var_export($rights, true) . "\n\n\n",
                true
            );
            $this->recordProfileTrace($state, 'JIT profile assignment failed', $rights[User::PROFILESID]);
            return;
        }

        if (isset($rights[User::ENTITY_ID]) && (int) $rights[User::ENTITY_ID] > 0) {
            $this->replaceRootProfileAuthorization($profileUser, (int) $update[User::USERSID], (int) $rights[User::PROFILESID]);
        }

        Toolbox::logInFile(
            PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
            __('JIT assigned profile with config:', PLUGIN_NAME) . ' ' . var_export($rights, true) . "\n\n\n",
            true
        );
        $this->recordProfileTrace($state, 'JIT profile assigned', $rights[User::PROFILESID]);
    }

    /**
     * Build the Profile_User payload array from JIT rule actions and defaults.
     *
     * @param array $update JIT rule output array.
     * @param int $profileId Resolved profile ID.
     * @return array Profile_User payload array.
     */
    private function buildProfileRightsPayload(array $update, int $profileId): array
    {
        $rights = [
            User::USERSID    => $update[User::USERSID],
            User::PROFILESID => $profileId,
        ];

        $entityId = $update[User::ENTITY_ID] ?? $update[User::ENTITY_DEFAULT] ?? null;
        if ($entityId !== null) {
            $rights[User::ENTITY_ID] = (int) $entityId;
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                sprintf(__('JIT found entity for profile assignment: %s', PLUGIN_NAME), (string) $entityId) . "\n"
            );
        } else {
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                __('JIT didnt find a profile for entity assignment. Profile asignment might not work.', PLUGIN_NAME) . "\n"
            );
        }

        if (isset($update[User::PROFILE_RECURSIVE])) {
            $rights[User::PROFILE_RECURSIVE] = ((int) $update[User::PROFILE_RECURSIVE] === 1 || $update[User::PROFILE_RECURSIVE] === '1') ? 1 : 0;
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                sprintf(__('JIT profile recursive set to: %s', PLUGIN_NAME), (string) $rights[User::PROFILE_RECURSIVE]) . "\n"
            );
        } else {
            $rights[User::PROFILE_RECURSIVE] = 0;
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                __('JIT didnt find to be assigned profile(s) to be recursive.', PLUGIN_NAME) . "\n"
            );
        }

        return $rights;
    }

    /**
     * Build search criteria to check whether an equivalent Profile_User authorization exists.
     *
     * @param array $rights Profile_User payload.
     * @return array Criteria array for Profile_User::find().
     */
    private function buildProfileCriteria(array $rights): array
    {
        $criteria = [
            User::USERSID    => $rights[User::USERSID],
            User::PROFILESID => $rights[User::PROFILESID],
        ];
        if (isset($rights[User::ENTITY_ID])) {
            $criteria[User::ENTITY_ID] = $rights[User::ENTITY_ID];
        }
        if (isset($rights[User::PROFILE_RECURSIVE])) {
            $criteria[User::PROFILE_RECURSIVE] = $rights[User::PROFILE_RECURSIVE];
        }

        return $criteria;
    }

    /**
     * Record a profile assignment trace step if an active persisted state exists.
     *
     * @param LoginState|null $state Login state instance.
     * @param string $eventKey Trace step label.
     * @param int $profileId Assigned profile ID.
     * @return void
     */
    private function recordProfileTrace(?LoginState $state, string $eventKey, int $profileId): void
    {
        if ($state && $state->getStateId() > 0) {
            $state->addLoginFlowTrace([$eventKey => 'profileID:' . $profileId]);
        }
    }

    /**
     * Remove auto-created Root entity (0) authorizations for the same profile after non-root assignment.
     *
     * @param Profile_User $profileUser Profile_User model instance.
     * @param int $userId GLPI user ID.
     * @param int $profileId Assigned profile ID.
     * @return void
     */
    private function replaceRootProfileAuthorization(Profile_User $profileUser, int $userId, int $profileId): void
    {
        $existingRights = $profileUser->getForUser($userId);
        if (!$existingRights) {
            return;
        }

        foreach ($existingRights as $key => $data) {
            if ((int) $data[User::PROFILESID] === $profileId && (int) $data[User::ENTITY_ID] === 0) {
                $profileUser->delete([User::USERID => $key]);
            }
        }
    }

    /**
     * Update user profile default settings.
     *
     * @param array $update Containing default settings updates
     * @return void
     */
    private function updateUserDefaults(array $update): void
    {
        $userDefaults = $this->buildUserDefaultsPayload($update);
        if (count($userDefaults) <= 1) {
            return;
        }

        $user = new glpiUser();
        if ($user->getFromDB((int) $userDefaults[User::USERID]) && $this->userDefaultsMatchCurrent($user, $userDefaults)) {
            Toolbox::logInFile(
                PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                __('Jit user defaults already match current settings', PLUGIN_NAME) . "\n"
            );
            return;
        }

        $resultMsg = $user->update($userDefaults)
            ? __('Jit updated user defaults', PLUGIN_NAME)
            : __('Jit didnt update user defaults', PLUGIN_NAME);
        Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, $resultMsg . "\n");
    }

    /**
     * Build the filtered User update payload for default preferences and profile/entity/group settings.
     *
     * @param array $update JIT rule output array.
     * @return array Payload keyed by glpi_users column names including User::USERID.
     */
    private function buildUserDefaultsPayload(array $update): array
    {
        $userId = $update[User::USERSID];
        $userDefaults = [User::USERID => $userId];

        $fallbackSettings = [
            User::GROUPID => [
                'value'   => $update[User::GROUPID] ?? $update[User::GROUP_DEFAULT] ?? null,
                'found'   => __('JIT found default groupID: %s for userId: %s', PLUGIN_NAME),
                'skipped' => __('Jit didnt find a default GroupID to assign, skipping', PLUGIN_NAME),
            ],
            User::ENTITY_ID => [
                'value'   => $update[User::ENTITY_DEFAULT] ?? $update[User::ENTITY_ID] ?? null,
                'found'   => __('JIT found default entityID: %s for userId: %s', PLUGIN_NAME),
                'skipped' => __('Jit didnt find a default EntityId to assign, skipping', PLUGIN_NAME),
            ],
            User::PROFILESID => [
                'value'   => $update[User::PROFILE_DEFAULT] ?? $update[User::PROFILESID] ?? null,
                'found'   => __('JIT found default profileID: %s for userId: %s', PLUGIN_NAME),
                'skipped' => __('Jit didnt find a default ProfileId to assign, skipping', PLUGIN_NAME),
            ],
        ];
        foreach ($fallbackSettings as $field => $meta) {
            if ($meta['value'] !== null) {
                $userDefaults[$field] = (int) $meta['value'];
                Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, sprintf($meta['found'], (string) $meta['value'], (string) $userId) . "\n");
            } else {
                Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, $meta['skipped'] . "\n");
            }
        }

        $optionalFields = [
            User::TIMEZONE          => __('JIT found timezone: %s for userId: %s', PLUGIN_NAME),
            User::ACTIVE            => __('JIT found is_active: %s for userId: %s', PLUGIN_NAME),
            User::LOCATIONS_ID      => __('JIT found locations_id: %s for userId: %s', PLUGIN_NAME),
            User::USERCATEGORIES_ID => __('JIT found usercategories_id: %s for userId: %s', PLUGIN_NAME),
            User::USERTITLES_ID     => __('JIT found usertitles_id: %s for userId: %s', PLUGIN_NAME),
            User::LANGUAGE          => __('JIT found language: %s for userId: %s', PLUGIN_NAME),
        ];
        foreach ($optionalFields as $field => $foundTemplate) {
            if (isset($update[$field])) {
                $userDefaults[$field] = $update[$field];
                Toolbox::logInFile(PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS, sprintf($foundTemplate, (string) $update[$field], (string) $userId) . "\n");
            }
        }

        return $userDefaults;
    }

    /**
     * Check whether all default settings in the payload already match persisted GLPI user fields.
     *
     * @param glpiUser $user Loaded GLPI user object.
     * @param array $userDefaults Candidate default fields payload.
     * @return bool True when no fields require updating.
     */
    private function userDefaultsMatchCurrent(glpiUser $user, array $userDefaults): bool
    {
        foreach ($userDefaults as $key => $val) {
            if ($key === User::USERID) {
                continue;
            }
            if (!isset($user->fields[$key]) || (string) $user->fields[$key] !== (string) $val) {
                return false;
            }
        }

        return true;
    }
}
