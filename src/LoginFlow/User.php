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
 *  @version    1.3.0
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
use Glpi\Toolbox\Sanitizer;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Constants as Saml2Const;
use GlpiPlugin\Samlsso\LoginFlow;
use GlpiPlugin\Samlsso\LoginState;
use GlpiPlugin\Samlsso\RuleSamlCollection;
use GlpiPlugin\Samlsso\Config\ConfigEntity;

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
    public const AUTHTYPE           = 'authtype';
    public const SYNCDATE           = 'date_sync';  //Y-m-d H:i:s
    public const SAMLGROUPS         = 'samlClaimedGroups';
    public const SAMLJOBTITLE       = 'samlClaimedJobTitle';
    public const SAMLCOUNTRY        = 'country';
    public const SAMLCITY           = 'city';
    public const SAMLSTREET         = 'street';


    /**
     * samlResponse attributes or claims provided by IdP.
     * @see https://docs.oasis-open.org/security/saml/v2.0/saml-bindings-2.0-os.pdf
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/reference-saml-tokens
     */
    public const USERDATA                    = 'userData';
    public const SCHEMA_NAMEID               = 'NameId';                                                                // Used to match users in GLPI.
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
    public const SCHEMA_NAME                 = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name';            // Entra claim not used
    public const SCHEMA_TENANTID             = 'http://schemas.microsoft.com/identity/claims/tenantid';                 // Entra claim not used
    public const SCHEMA_OBJECTID             = 'http://schemas.microsoft.com/identity/claims/objectidentifier';         // Entra claim not used
    public const SCHEMA_DISPLAYNAME          = 'http://schemas.microsoft.com/identity/claims/displayname';              // Entra claim not used
    public const SCHEMA_IDP                  = 'http://schemas.microsoft.com/identity/claims/identityprovider';         // Entra claim not used
    public const SCHEMA_AUTHMETHODSREF       = 'http://schemas.microsoft.com/claims/authnmethodsreferences';            // Entra claim not used
    public const SCHEMA_WIDS                 = 'http://schemas.microsoft.com/ws/2008/06/identity/claims/wids';          // Entra claim not used

    /**
     * Gets or creates (if JIT is enabled for IDP) the GLPI user.
     *
     * This method is called after succesfull login by an IDP. Beware that
     * the return value glpiUser of this method will perform the actual login
     * into GLPI. So make sure not to return anything from this method unless
     * you actually intend to allow login.
     *
     * @param   array       $userFields Containing user attributes found in Saml claim
     * @return  glpiUser    GlpiUser object with populated fields.
     * @since               1.0.0
     */
    public function getOrCreateUser(array $userFields, ConfigEntity $configEntity): glpiUser        //Not all paths have a return value error is by design.
    {
        // At this point the userFields should be present and validated (textually) by loginFlow.
        // https://codeberg.org/QuinQuies/glpisaml/issues/71
        $name  = (array_key_exists(User::NAME, $userFields) && isset($userFields[User::NAME])) ? $userFields[User::NAME] : '';
        $email = (array_key_exists(User::EMAIL, $userFields) && isset($userFields[User::EMAIL][0])) ? $userFields[User::EMAIL][0] : '';

        
        // Verify if user exists in database.
        // https://codeberg.org/QuinQuies/glpisaml/issues/48
        $user = new glpiUser();
        if(!$user->getFromDBbyName($name)       &&      // Try to locate by name->NameId, continue on ! fail.
           !$user->getFromDBbyEmail($email)     &&      // Try to locate by email->emailaddress, continue on ! fail.
           !$user->getFromDBbyEmail($name)      ){      // Try to locate by email->emailaddress, continue on ! fail.
        // User IS NOT found.

            // Try to perform Just In Time (JIT) user creation;
            return $this->performJIT($userFields, $configEntity);

        }else{
        // User is found, check if we are allowed to use it.

            // Verify the found user is not deleted (in trashcan)
            if($user->fields[User::DELETED]){
                LoginFlow::PrintFatalLoginError(__("User with GlpiUserid: ".$user->fields[User::USERID]." is marked deleted but still exists in the GLPI database. Because of
                                           this we cannot log you in as this would violate GLPI its security policies. Please contact the GLPI administrator
                                           to restore the user with provided ID or purge the user to allow the Just in Time (JIT) user creation to create a
                                           new user with the idp provided claims.", PLUGIN_NAME));
            }

            // Verify the found user is not disabled by the admin;
            if($user->fields[User::ACTIVE] == 0){
                LoginFlow::PrintFatalLoginError(__("User with GlpiUserid: ".$user->fields[User::USERID]." is disabled. Please contact your GLPI administrator and request him to
                                            reactivate your account.", PLUGIN_NAME));
            }

            // Check if the user has any profiles assigned
            if (count(Profile_User::getForUser($user->fields[User::USERID])) === 0) {
                LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but no GLPI profile was assigned to your account. Please contact your GLPI administrator to assign a profile to your account.", PLUGIN_NAME));
            }

            // User can be used to login, so return the user to the LoginFlow object
            // for session initialization!.
            return $user;
        }
    }

    /**
     * Performs Just-In-Time (JIT) user creation dynamically.
     *
     * @param array $userFields User attributes from SAML claims.
     * @return glpiUser Freshly created or fetched GLPI user.
     * @since 1.0.0
     */
    private function performJIT(array $userFields, ConfigEntity $configEntity): glpiUser {
        $user = new glpiUser();

        // Strictly validate configuration and database origin
        $dbId = $configEntity->getField(ConfigEntity::ID);
        if (!$configEntity->isValid() || !$configEntity->isActive() || empty($dbId) || (int)$dbId <= 0) {
            LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but the identity provider configuration is invalid or disabled.", PLUGIN_NAME));
        }

        // Are we allowed to perform JIT user creation?
        if($configEntity->getField(ConfigEntity::USER_JIT)){
            // Build the input array using the provided attributes (claims)
            // from the samlResponse. maybe use this method in the future
            // to also validate provided claims in one go.
            if(!$id = $user->add(Sanitizer::sanitize($userFields))){
                LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but there is no matching GLPI user account and
                                                we failed to create one dynamically using Just In Time user creation. Please
                                                request a GLPI administrator to review the logs and correct the problem or
                                                request the administrator to create a GLPI user manually.", PLUGIN_NAME));
            }else{
                $ruleCollection = new RuleSamlCollection();
                $matchInput = [
                    User::EMAIL          => $userFields[User::EMAIL],
                    User::SAMLGROUPS     => $userFields[User::SAMLGROUPS],
                    User::SAMLJOBTITLE   => $userFields[User::SAMLJOBTITLE] ?? false,
                    User::SAMLCOUNTRY    => $userFields[User::SAMLCOUNTRY] ?? false,
                    User::SAMLCITY       => $userFields[User::SAMLCITY] ?? false,
                    User::SAMLSTREET     => $userFields[User::SAMLSTREET] ?? false,
                    'groups'             => $userFields[User::SAMLGROUPS],
                ];

                if (isset($userFields['_saml_rule_fields']) && is_array($userFields['_saml_rule_fields'])) {
                    foreach ($userFields['_saml_rule_fields'] as $field => $val) {
                        $matchInput[$field] = $val;
                        if ($field === 'jobtitle') {
                            $matchInput[User::SAMLJOBTITLE] = $val;
                        } elseif ($field === 'country') {
                            $matchInput[User::SAMLCOUNTRY] = $val;
                        } elseif ($field === 'city') {
                            $matchInput[User::SAMLCITY] = $val;
                        } elseif ($field === 'street') {
                            $matchInput[User::SAMLSTREET] = $val;
                        }
                    }
                }

                // Uses a hook to call $this->updateUser() if a rule was found.
                $ruleCollection->processAllRules($matchInput, [User::USERSID => $id], []);
            }

            // Return the freshly created user!
            $user = new glpiUser();
            if($user->getFromDB($id)){
                // Check if the user has any profiles assigned
                if (count(Profile_User::getForUser($id)) === 0) {
                    LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but no GLPI profile was assigned to your account and
                                                    we failed to assign one dynamically using Just In Time user creation. Please
                                                    request a GLPI administrator to review the logs and correct the problem or
                                                    request the administrator to assign a GLPI profile manually.", PLUGIN_NAME));
                }
                Session::addMessageAfterRedirect('Dynamically created GLPI user for:'.$userFields[User::EMAIL]['0']);
                return $user;
            }else{
                LoginFlow::PrintFatalLoginError(__("Critical error: samlSSO was unable to fetch newly created user from the database!", PLUGIN_NAME));
            }
        }else{
            // Show a nice login Error
            $idpName = $configEntity->getField(ConfigEntity::NAME);
            $email   = $userFields[User::EMAIL]['0'];
            LoginFlow::PrintFatalLoginError(__("Your SSO login was successful but there is no matching GLPI user account. In addition the Just-in-time user creation
                                          is disabled for: $idpName. Please contact your GLPI administrator and request an account to be created matching the
                                          provided email claim: $email or login using a local user account.", PLUGIN_NAME));
        }
    }

    /**
     * Updates user rights, groups, and profiles based on rule matching output.
     *
     * @param array $params Contains the rule output mapping details.
     * @return void
     * @since 1.0.0
     */
    public function updateUserRights(array $params): void       //NOSONAR - Complexity by design
    {
        // Log that we are applying JIT.
        Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT was called with params:'. var_export($params, true)."\n\n" . "\n", true));

        // We are working on the output only.
        $update = $params[User::RULEOUTPUT];
        // Do we need to add a group?
        if(isset($update[User::GROUPID])  &&
           isset($update[User::USERSID])  ){
            // Get the Group_User object to update the user group relation.
            $groupuser = new Group_User();
            if(!$groupuser->add([User::USERSID   => $update[User::USERSID],
                                 User::GROUPID   => $update[User::GROUPID]])){
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT failed to assign groupID:'. $update[User::GROUPID] .' to userID'.$update[User::USERSID]."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT assigned groupID:'. $update[User::GROUPID] .' to userID'.$update[User::USERSID]."\n"));
            }
        }else{
            Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found no groupId to add.'."\n"));
        }

        // Do we need to add profiles
        // If no profiles_id and user_id is present we skip.
        if(isset($update[User::PROFILESID]) &&
           isset($update[User::USERSID])    ){
            // Set the user to update
            $rights[User::USERSID] = $update[User::USERSID];
            // Set the profile to rights assignment
            $rights[User::PROFILESID] = $update[User::PROFILESID];
            // Do we need to set a profile for a specific entity?
            if(isset($update[User::ENTITY_ID])){
                $rights[User::ENTITY_ID] = $update[User::ENTITY_ID];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found entity for profile assignment:'.$update[User::ENTITY_ID]."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT didnt find a profile for entity assignment. Profile asignment might not work.'."\n"));
            }

            // Do we need to make the profile behave recursive?
            if(isset($update[User::PROFILE_RECURSIVE])){
                $rights[User::PROFILE_RECURSIVE] = (isset($update[User::PROFILE_RECURSIVE])) ? '1' : '0';
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found to be assigned profile(s) to be recursive.'."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT didnt find to be assigned profile(s) to be recursive.'."\n"));
            }
            

            // Assign collected Rights
            $profileUser = new Profile_User();
            if(!$profileUser->add($rights)){
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT was not able to assign profile with config:'.var_export($rights, true)."\n\n" . "\n", true));
            }else{
                // Delete all default profile assignments
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT remove all default profiles from newly created user:'."\n"));
                $profileUser = new Profile_User();
                if($pid = $profileUser->getForUser($update[User::USERSID])){
                    foreach($pid as $key => $data){
                        if ($data['profiles_id'] != $rights[User::PROFILESID]) {
                            $profileUser->delete(['id' => $key]);
                        }
                    }
                    Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('Done'."\n"));
                } else {
                    Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('failed'."\n"));
                }
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT assigned profile with config:'.var_export($rights, true)."\n\n" . "\n", true));
            }
        }

        // Do we need to update the user profile defaults?
        if(isset($update[User::GROUP_DEFAULT])   ||
           isset($update[User::ENTITY_DEFAULT]) ||
           isset($update[User::PROFILE_DEFAULT]) ||
           isset($update['is_active'])          ||
           isset($update['timezone'])           ||
           isset($update['locations_id'])       ||
           isset($update['usercategories_id'])  ||
           isset($update['usertitles_id'])      ||
           isset($update['language'])           ){
            // Set the user Id.
            $userDefaults['id'] = $update['users_id'];
            // Do we need to set a default group?
            if(isset($update[User::GROUP_DEFAULT])){
                $userDefaults[User::GROUPID]  = $update[User::GROUP_DEFAULT];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found default groupID:'.$update[User::GROUP_DEFAULT].'for userId:'.$update['users_id']."\n"));        #NOSONAR not creating a constant for text repetition
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('Jit didnt find a default GroupID to assign, skipping'."\n"));
            }
            // Do we need to set a specific default entity?
            if(isset($update[User::ENTITY_DEFAULT])){
                $userDefaults[User::ENTITY_ID] = $update[User::ENTITY_DEFAULT];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found default entityID:'.$update[User::ENTITY_DEFAULT].'for userId:'.$update['users_id']."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('Jit didnt find a default EntityId to assign, skipping'."\n"));
            }
            // Do we need to set a specific profile?
            if(isset($update[User::PROFILE_DEFAULT])){
                $userDefaults[User::PROFILESID] = $update[User::PROFILE_DEFAULT];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found default profileID:'.$update[User::PROFILE_DEFAULT].'for userId:'.$update['users_id']."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('Jit didnt find a default ProfileId to assign, skipping'."\n"));
            }
            // Do we need to set timezone?
            if(isset($update['timezone'])){
                $userDefaults['timezone'] = $update['timezone'];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found timezone:'.$update['timezone'].'for userId:'.$update['users_id']."\n"));
            }
            // Do we need to set active state?
            if(isset($update['is_active'])){
                $userDefaults['is_active'] = $update['is_active'];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found is_active:'.$update['is_active'].'for userId:'.$update['users_id']."\n"));
            }
            // Do we need to set location?
            if(isset($update['locations_id'])){
                $userDefaults['locations_id'] = $update['locations_id'];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found locations_id:'.$update['locations_id'].'for userId:'.$update['users_id']."\n"));
            }
            // Do we need to set department?
            if(isset($update['usercategories_id'])){
                $userDefaults['usercategories_id'] = $update['usercategories_id'];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found usercategories_id:'.$update['usercategories_id'].'for userId:'.$update['users_id']."\n"));
            }
            // Do we need to set user title?
            if(isset($update['usertitles_id'])){
                $userDefaults['usertitles_id'] = $update['usertitles_id'];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found usertitles_id:'.$update['usertitles_id'].'for userId:'.$update['users_id']."\n"));
            }
            // Do we need to set language?
            if(isset($update['language'])){
                $userDefaults['language'] = $update['language'];
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('JIT found language:'.$update['language'].'for userId:'.$update['users_id']."\n"));
            }
            // Update the user
            $user = new glpiUser();
            if(!$user->update($userDefaults)){
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('Jit updated user defaults'."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.PLUGIN_SAMLSSO_LOGEVENTS, __('Jit didnt update user defaults'."\n"));
            }
        }
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
                \GlpiPlugin\Samlsso\ObservedClaim::trackClaim($idpId, (string)$claimKey);
            }
            \GlpiPlugin\Samlsso\ObservedClaim::trackClaim($idpId, 'NameId');
        }

        $claimMapEntity = ($idpId > 0) ? new \GlpiPlugin\Samlsso\Config\ClaimMapEntity($idpId) : null;

        $user = [];

        // 1. Resolve username
        $usernameClaimKey = ($claimMapEntity !== null) ? $claimMapEntity->getMapping('username', 'user_field') : null;
        $usernameVal = null;
        if ($usernameClaimKey !== null && isset($claims[$usernameClaimKey][0]) && !empty($claims[$usernameClaimKey][0])) {
            $usernameVal = $claims[$usernameClaimKey][0];
        } else {
            $usernameVal = $response->getNameId();
        }

        // Apply fallback if username is missing/empty
        if (empty($usernameVal)) {
            $usernameVal = ($claimMapEntity !== null) ? $claimMapEntity->getDefault('username', 'user_field') : '';
        }

        // If still empty and required, abort
        $isUsernameRequired = ($claimMapEntity !== null) ? $claimMapEntity->isRequired('username', 'user_field') : true;
        if (empty($usernameVal) && $isUsernameRequired) {
            LoginFlow::printError(__('NameId attribute is missing in samlResponse', PLUGIN_NAME),
                                'getUserInputFieldsFromSamlClaim',
                                var_export($response, true));
        }

        if (!empty($usernameVal) && strstr($usernameVal, '#EXT#@')) {
            LoginFlow::printError(__('Detected a default guest user in samlResponse, this is not supported by glpiSAML.', PLUGIN_NAME),
                                 'getUserInputFieldsFromSamlClaim',
                                  var_export($response, true));
        }

        $user[User::NAME] = $usernameVal;

        // 2. Resolve email
        $emailClaimKey = ($claimMapEntity !== null) ? $claimMapEntity->getMapping('email', 'user_field') : null;
        if ($emailClaimKey === null) {
            $emailClaimKey = User::SCHEMA_EMAILADDRESS;
        }

        $emailVal = null;
        if (isset($claims[$emailClaimKey][0]) && !empty($claims[$emailClaimKey][0])) {
            $emailVal = $claims[$emailClaimKey][0];
        }

        // Validate email value
        if ($emailVal !== null && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
            $emailVal = null;
        }

        // Check NameID format
        $nameIdFormat = null;
        try {
            $nameIdFormat = $response->getNameIdFormat();
        } catch (\Throwable $t) {
            // ignore
        }

        $isEmailFormat = ($nameIdFormat === Saml2Const::NAMEID_EMAIL_ADDRESS);
        $usernameIsEmail = filter_var($usernameVal, FILTER_VALIDATE_EMAIL);

        if ($isEmailFormat) {
            if (!$usernameIsEmail) {
                // If type requested is email but value observed doesn't have email type, show warning
                \Session::addMessageAfterRedirect(
                    __('Warning: SAML NameId format was requested as email but observed value is not a valid email. Falling back to configured email field.', PLUGIN_NAME),
                    false,
                    WARNING
                );
            } else {
                if ($emailVal === null) {
                    $emailVal = $usernameVal;
                }
            }
        } else {
            // Fallback email should only be used if NameId format requested is not emailaddress
            if ($emailVal === null && $usernameIsEmail) {
                $emailVal = $usernameVal;
            }
        }

        // Apply fallback if still missing
        if ($emailVal === null && $claimMapEntity !== null) {
            $defaultEmail = $claimMapEntity->getDefault('email', 'user_field');
            if (filter_var($defaultEmail, FILTER_VALIDATE_EMAIL)) {
                $emailVal = $defaultEmail;
            }
        }

        if (empty($emailVal)) {
            LoginFlow::printError(__('invalid values where used to identify the user during auth', PLUGIN_NAME),
                                 'getUserInputFieldsFromSamlClaim',
                                  var_export($response, true));
        }

        $user[User::EMAIL] = [$emailVal];

        // 3. Resolve other dynamic user_field mappings
        $otherUserFields = ['realname', 'firstname', 'phone', 'mobile', 'jobtitle', 'country', 'city', 'street'];
        foreach ($otherUserFields as $field) {
            $claimKey = ($claimMapEntity !== null) ? $claimMapEntity->getMapping($field, 'user_field') : null;
            if ($claimKey === null) {
                $defaultSchemas = [
                    'realname'  => [User::SCHEMA_SURNAME],
                    'firstname' => [User::SCHEMA_FIRSTNAME, User::SCHEMA_GIVENNAME],
                    'mobile'    => [User::SCHEMA_MOBILE],
                    'phone'     => [User::SCHEMA_PHONE],
                    'jobtitle'  => [User::SCHEMA_JOBTITLE],
                    'country'   => [User::SCHEMA_COUNTRY],
                    'city'      => [User::SCHEMA_CITY],
                    'street'    => [User::SCHEMA_STREET]
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

            // If missing or exceeds length constraint (255 characters), use default value
            if ($val === null || strlen((string)$val) > 255) {
                $val = ($claimMapEntity !== null) ? $claimMapEntity->getDefault($field, 'user_field') : '';
            }

            // Check if required
            $isRequired = ($claimMapEntity !== null) ? $claimMapEntity->isRequired($field, 'user_field') : false;
            if (empty($val) && $isRequired) {
                LoginFlow::printError(sprintf(__('Required user field "%s" is missing or invalid in SAML response', PLUGIN_NAME), $field),
                                     'getUserInputFieldsFromSamlClaim',
                                      var_export($response, true));
            }

            $keyMap = [
                'firstname' => User::FIRSTNAME,
                'realname'  => User::REALNAME,
                'mobile'    => User::MOBILE,
                'phone'     => User::PHONE,
                'jobtitle'  => User::SAMLJOBTITLE,
                'country'   => User::SAMLCOUNTRY,
                'city'      => User::SAMLCITY,
                'street'    => User::SAMLSTREET
            ];
            $destKey = $keyMap[$field] ?? $field;
            $user[$destKey] = $val !== '' ? $val : false;
        }

        // 4. Resolve dynamic rule_fields to pass to JIT Rules
        $groupsClaimKey = ($claimMapEntity !== null) ? $claimMapEntity->getMapping('groups', 'rule_field') : null;
        if ($groupsClaimKey === null) {
            $groupsClaimKey = User::SCHEMA_GROUPS;
        }
        $groupsVal = isset($claims[$groupsClaimKey]) ? $claims[$groupsClaimKey] : null;
        if (empty($groupsVal) && $claimMapEntity !== null) {
            $defaultGroup = $claimMapEntity->getDefault('groups', 'rule_field');
            $groupsVal = ($defaultGroup !== '') ? [$defaultGroup] : [];
        }
        $isGroupsRequired = ($claimMapEntity !== null) ? $claimMapEntity->isRequired('groups', 'rule_field') : false;
        if (empty($groupsVal) && $isGroupsRequired) {
            LoginFlow::printError(__('Required rule field "groups" is missing in SAML response', PLUGIN_NAME),
                                 'getUserInputFieldsFromSamlClaim',
                                  var_export($response, true));
        }
        $user[User::SAMLGROUPS] = is_array($groupsVal) ? $groupsVal : (array)$groupsVal;

        $ruleFields = ['jobtitle', 'country', 'city', 'street'];
        $user['_saml_rule_fields'] = [];
        foreach ($ruleFields as $field) {
            $claimKey = ($claimMapEntity !== null) ? $claimMapEntity->getMapping($field, 'rule_field') : null;
            if ($claimKey === null) {
                $defaultSchemas = [
                    'jobtitle'  => [User::SCHEMA_JOBTITLE],
                    'country'   => [User::SCHEMA_COUNTRY],
                    'city'      => [User::SCHEMA_CITY],
                    'street'    => [User::SCHEMA_STREET]
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
                $val = ($claimMapEntity !== null) ? $claimMapEntity->getDefault($field, 'rule_field') : '';
            }

            $isRequired = ($claimMapEntity !== null) ? $claimMapEntity->isRequired($field, 'rule_field') : false;
            if (empty($val) && $isRequired) {
                LoginFlow::printError(sprintf(__('Required rule field "%s" is missing or invalid in SAML response', PLUGIN_NAME), $field),
                                     'getUserInputFieldsFromSamlClaim',
                                      var_export($response, true));
            }

            $user['_saml_rule_fields'][$field] = $val;
        }

        $user[User::COMMENT]    = __('Created by phpSaml Just-In-Time user creation on:').date('Y-m-d H:i:s');
        $password = bin2hex(random_bytes(20));
        $user[User::PASSWORD]   = $password;
        $user[User::PASSWORDN]  = $password;
        $user[User::AUTHTYPE]   = 4;
        $user[User::SYNCDATE]   = date('Y-m-d H:i:s');

        return $user;
   }
}
