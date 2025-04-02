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
 *  @version    1.2.0
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
use Group_User;
use Profile_User;
use User as glpiUser;
use Glpi\Toolbox\Sanitizer;
use OneLogin\Saml2\Response;
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
     * @param   array       Containing user attributes found in Saml claim
     * @return  glpiUser    GlpiUser object with populated fields.
     * @since               1.0.0
     */
    public function getOrCreateUser(array $userFields): glpiUser        //Not all paths have a return value error is by design.
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
            return $this->performJIT($userFields);

        }else{
        // User is found, check if we are allowed to use it.

            // Verify the found user is not deleted (in trashcan)
            if($user->fields[User::DELETED]){
                LoginFlow::showLoginError(__("User with GlpiUserid: ".$user->fields[User::USERID]." is marked deleted but still exists in the GLPI database. Because of
                                           this we cannot log you in as this would violate GLPI its security policies. Please contact the GLPI administrator
                                           to restore the user with provided ID or purge the user to allow the Just in Time (JIT) user creation to create a
                                           new user with the idp provided claims.", PLUGIN_NAME));
            }

            // Verify the found user is not disabled by the admin;
            if($user->fields[User::ACTIVE] == 0){
                LoginFlow::showLoginError(__("User with GlpiUserid: ".$user->fields[User::USERID]." is disabled. Please contact your GLPI administrator and request him to
                                            reactivate your account.", PLUGIN_NAME));
            }

            // User can be used to login, so return the user to the LoginFlow object
            // for session initialization!.
            return $user;
        }
    }

    private function performJIT(array $userFields): glpiUser {
        $user = new glpiUser();

        // Get current loginState and
        // Fetch the correct configEntity using the idp found in our loginState.
        if(!$configEntity = new ConfigEntity((new Loginstate())->getIdpId())){
            LoginFlow::showLoginError(__("Your SSO login was successful but we where not able to fetch
                                            the loginState from the database and could not continue to log
                                            you into GLPI.", PLUGIN_NAME));
        }

        // Are we allowed to perform JIT user creation?
        if($configEntity->getField(ConfigEntity::USER_JIT)){
            // Build the input array using the provided attributes (claims)
            // from the samlResponse. maybe use this method in the future
            // to also validate provided claims in one go.
            if(!$id = $user->add(Sanitizer::sanitize($userFields))){
                LoginFlow::showLoginError(__("Your SSO login was successful but there is no matching GLPI user account and
                                                we failed to create one dynamically using Just In Time user creation. Please
                                                request a GLPI administrator to review the logs and correct the problem or
                                                request the administrator to create a GLPI user manually.", PLUGIN_NAME));
            }else{
                $ruleCollection = new RuleSamlCollection();
                $matchInput = [User::EMAIL          => $userFields[User::EMAIL],
                                User::SAMLGROUPS     => $userFields[User::SAMLGROUPS],
                                User::SAMLJOBTITLE   => $userFields[User::SAMLJOBTITLE],
                                User::SAMLCOUNTRY    => $userFields[User::SAMLCOUNTRY],
                                User::SAMLCITY       => $userFields[User::SAMLCITY],
                                User::SAMLSTREET     => $userFields[User::SAMLSTREET]];
                // Uses a hook to call $this->updateUser() if a rule was found.
                $ruleCollection->processAllRules($matchInput, [User::USERSID => $id], []);
            }

            // Return the freshly created user!
            $user = new glpiUser();
            if($user->getFromDB($id)){
                Session::addMessageAfterRedirect('Dynamically created GLPI user for:'.$userFields[User::EMAIL]['0']);
                return $user;
            }else{
                LoginFlow::showLoginError(__("Critical error: samlSSO was unable to fetch newly created user from the database!", PLUGIN_NAME));
                exit; // Unreachable but prevents linting errors.
            }
        }else{
            // Show a nice login Error
            $idpName = $configEntity->getField(ConfigEntity::NAME);
            $email   = $userFields[User::EMAIL]['0'];
            LoginFlow::showLoginError(__("Your SSO login was successful but there is no matching GLPI user account. In addition the Just-in-time user creation
                                          is disabled for: $idpName. Please contact your GLPI administrator and request an account to be created matching the
                                          provided email claim: $email or login using a local user account.", PLUGIN_NAME));
            exit; // Unreachable but prevents linting errors.
        }
    }

    public function updateUserRights(array $params): void       //NOSONAR - Complexity by design
    {
        // Log that we are applying JIT.
        Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT was called with params:'. var_export($params, true)."\n\n" . "\n", true));

        // We are working on the output only.
        $update = $params[User::RULEOUTPUT];
        // Do we need to add a group?
        if(isset($update[User::GROUPID])  &&
           isset($update[User::USERSID])  ){
            // Get the Group_User object to update the user group relation.
            $groupuser = new Group_User();
            if(!$groupuser->add([User::USERSID   => $update[User::USERSID],
                                 User::GROUPID   => $update[User::GROUPID]])){
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT failed to assign groupID:'. $update[User::GROUPID] .' to userID'.$update[User::USERSID]."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT assigned groupID:'. $update[User::GROUPID] .' to userID'.$update[User::USERSID]."\n"));
            }
        }else{
            Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT found no groupId to add.'."\n"));
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
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT found entity for profile assignment:'.$update[User::ENTITY_ID]."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT didnt find a profile for entity assignment. Profile asignment might not work.'."\n"));
            }

            // Do we need to make the profile behave recursive?
            if(isset($update[User::PROFILE_RECURSIVE])){
                $rights[User::PROFILE_RECURSIVE] = (isset($update[User::PROFILE_RECURSIVE])) ? '1' : '0';
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT found to be assigned profile(s) to be recursive.'."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT didnt find to be assigned profile(s) to be recursive.'."\n"));
            }

            // Delete all default profile assignments
            Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT remove all existing and default profiles from newly created user:'."\n"));
            $profileUser = new Profile_User();
            if($pid = $profileUser->getForUser($update[User::USERSID])){
                foreach($pid as $key => $data){
                    $profileUser->delete(['id' => $key]);
                }
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('Done'."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('failed'."\n"));
            }
            

            // Assign collected Rights
            $profileUser = new Profile_User();
            if(!$profileUser->add($rights)){
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT was not able to assign profile with config:'.var_export($rights, true)."\n\n" . "\n", true));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT assigned profile with config:'.var_export($rights, true)."\n\n" . "\n", true));
            }
        }

        // Do we need to update the user profile defaults?
        if(isset($update[User::GROUP_DEFAULT])   ||
           isset($update[User::ENTITY_DEFAULT]) ||
           isset($update[User::PROFILE_DEFAULT]) ){
            // Set the user Id.
            $userDefaults['id'] = $update['users_id'];
            // Do we need to set a default group?
            if(isset($update[User::GROUP_DEFAULT])){
                $userDefaults[User::GROUPID]  = $update[User::GROUP_DEFAULT];
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT found default groupID:'.$update[User::GROUP_DEFAULT].'for userId:'.$update['users_id']."\n"));        #NOSONAR not creating a constant for text repetition
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('Jit didnt find a default GroupID to assign, skipping'."\n"));
            }
            // Do we need to set a specific default entity?
            if(isset($update[User::ENTITY_DEFAULT])){
                $userDefaults[User::ENTITY_ID] = $update[User::ENTITY_DEFAULT];
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT found default entityID:'.$update[User::GROUP_DEFAULT].'for userId:'.$update['users_id']."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('Jit didnt find a default EntityId to assign, skipping'."\n"));
            }
            // Do we need to set a specific profile?
            if(isset($update[User::PROFILE_DEFAULT])){
                $userDefaults[User::PROFILESID] = $update[User::PROFILE_DEFAULT];
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('JIT found default profileID:'.$update[User::GROUP_DEFAULT].'for userId:'.$update['users_id']."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('Jit didnt find a default ProfileId to assign, skipping'."\n"));
            }
            // Update the user
            $user = new glpiUser();
            if(!$user->update($userDefaults)){
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('Jit updated user defaults'."\n"));
            }else{
                Toolbox::logInFile(PLUGIN_NAME.LOGEVENTS, __('Jit didnt update user defaults'."\n"));
            }
        }
    }

    /**
     * This function figures out what the samlResponse provided claims are and
     * evaluates the values and assigns them to the UserArray that will be
     * passed to the Auth object in the loginFlow object. If a critical error
     * is found, processing is stopped and an error shown.
     *
     * @param    Response  Response object with the samlResponse attributes.
     * @return   array     user->add input fields array with properties.
     * @since    1.0.0
     */
    public static function getUserInputFieldsFromSamlClaim(Response $response): array     //NOSONAR - Complexity by design.
    {
        
        // https://codeberg.org/QuinQuies/glpisaml/issues/28
        // https://codeberg.org/QuinQuies/glpisaml/issues/39
        // https://codeberg.org/QuinQuies/glpisaml/issues/40

        // In essence we should just capture the props shared
        // and assign them to prefixed fields of the userObj.
        // Issue 39 shows tons of properties, but we want to
        // limit ourself to essential properties in the glpi
        // userObj.

        // properties to search for
        // glpi_users->name         => nameId
        // glpi_users->groups       => via_rules
        // glpi_users_groups        => schema_groups (⚠️ be aware Entra only emits object identifiers, not friendly names)
        // glpi_users->profiles     => via_rules
        // glpi_users->email        => schema_emailaddress
        // glpi_users->firstname    => schema_firstname
        // glpi_users->lastname     => schema_surname
        // glpi_users->mobile       => schema_mobilephone
        // glpi_users_titles        => schema_jobtitle


        // Validate nameId claim from provided samlResponse.
        // NameId should be formatted as set by NAMEID FORMAT.
        // This should be tested by the OneLogin plugin.
        if(!$user[User::NAME] = $response->getNameId()) {
            LoginFlow::printError(__('NameId attribute is missing in samlResponse', PLUGIN_NAME),
                                'getUserInputFieldsFromSamlClaim',
                                var_export($response, true));
        }
            
        // If the string #EXT# is found, a guest account is used thats not
        // owned by the Entra IdP handling this request. This is not supported.
        // https://github.com/derricksmith/phpsaml/issues/135
        if(strstr($user[User::NAME], '#EXT#@')){
            LoginFlow::printError(__('Detected a default guest user in samlResponse, this is not supported<br>
                                      by glpiSAML. Please create a dedicated account for this user owned by your
                                      tenant/identity provider.<br>
                                      Also see: https://learn.microsoft.com/en-us/azure/active-directory/develop/saml-claims-customization', PLUGIN_NAME),
                                     'getUserInputFieldsFromSamlClaim',
                                      var_export($response, true));
        }

        // Fetch additional claims from the samlResponse.
        if($claims = $response->getAttributes()){
            // Assign the available claims.
            // EmailAddress, if it is provided it should be a valid emailaddress.
            // This is a optional field.
            // https://codeberg.org/QuinQuies/glpisaml/issues/115
            // Add not empty test to validation.
            if(isset($claims[User::SCHEMA_EMAILADDRESS][0]) &&
               !empty($claims[User::SCHEMA_EMAILADDRESS][0])){
                if(filter_var($claims[User::SCHEMA_EMAILADDRESS][0], FILTER_VALIDATE_EMAIL) ){
                    $user[User::EMAIL]  = [$claims[User::SCHEMA_EMAILADDRESS][0]];
                }else{
                    // Try userName as fallback
                    // https://codeberg.org/QuinQuies/glpisaml/issues/115
                    if(filter_var( $user[User::NAME], FILTER_VALIDATE_EMAIL)){
                        $user[User::EMAIL] = $user[User::NAME];
                    }else{
                        LoginFlow::printError(__('SamlResponse should have at least 1 valid email address for GLPI  to find
                                          the corresponding GLPI user or create it (with JIT enabled). For this purpose make
                                          sure either the IDP provided NameId property is populated with the email address format,
                                          or configure the IDP to add the users email address in the samlResponse claims using
                                          the designated schema property key:'.User::SCHEMA_EMAILADDRESS, PLUGIN_NAME),
                                         'getUserInputFieldsFromSamlClaim',
                                          var_export($response, true));
                    }
                }
            }else{
                $user[User::EMAIL] = [];
            }

            // Groups to be passed to the rules engine
            $user[User::SAMLGROUPS] = isset($claims[User::SCHEMA_GROUPS]) ? $claims[User::SCHEMA_GROUPS] : [];

            // Firstname
            if(isset($claims[User::SCHEMA_FIRSTNAME][0]) ||
               isset($claims[User::SCHEMA_GIVENNAME][0]) ){
                // check and assign the value
                $firstname = (isset($claims[User::SCHEMA_FIRSTNAME][0])) ? $claims[User::SCHEMA_FIRSTNAME][0] : $claims[User::SCHEMA_GIVENNAME][0];
                if(strlen($firstname) <= 255){
                    $user[User::FIRSTNAME] = $firstname ;
                }else{
                    LoginFlow::printError(__('Provided firstname or givenname exceeded 255 characters. This claim should not be longer than 255 characters',
                                             'getUserInputFieldsFromSamlClaim',
                                              var_export($response, true)));
                }
            }

            // Surname
            if(isset($claims[User::SCHEMA_SURNAME][0])){
                if(strlen($claims[User::SCHEMA_SURNAME][0]) <= 255){
                    $user[User::REALNAME] = $claims[User::SCHEMA_SURNAME][0];
                }else{
                    LoginFlow::printError(__('Provided surname claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                             'getUserInputFieldsFromSamlClaim',
                                              var_export($response, true)));
                }
            }else{
                $user[User::REALNAME] = false;
            }

            // jobTitle
            if(isset($claims[User::SCHEMA_JOBTITLE][0])){
                if(strlen($claims[User::SCHEMA_JOBTITLE][0]) <= 255){
                    $user[User::SAMLJOBTITLE] = $claims[User::SCHEMA_JOBTITLE][0];
                }else{
                    LoginFlow::printError(__('Provided job title claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                             'getUserInputFieldsFromSamlClaim',
                                              var_export($response, true)));
                }
            }else{
                $user[User::SAMLJOBTITLE] = false;
            }

            // Mobile Phone
            if(isset($claims[User::SCHEMA_MOBILE][0])){
                if(strlen($claims[User::SCHEMA_MOBILE][0]) <= 255){
                    $user[User::MOBILE] = $claims[User::SCHEMA_MOBILE][0];
                }else{
                    LoginFlow::printError(__('Provided mobile phone number claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                             'getUserInputFieldsFromSamlClaim',
                                              var_export($response, true)));
                }
            }else{
                $user[User::MOBILE] = false;
            }

            // Telephone number
            if(isset($claims[User::SCHEMA_PHONE][0])){
                if(strlen($claims[User::SCHEMA_PHONE][0]) <= 255){
                    $user[User::PHONE] = $claims[User::SCHEMA_PHONE][0];
                }else{
                    LoginFlow::printError(__('Provided telephone phone number claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                             'getUserInputFieldsFromSamlClaim',
                                              var_export($response, true)));
                }
            }else{
                $user[User::PHONE] = false;
            }

            // Country
            if(isset($claims[User::SCHEMA_COUNTRY][0])){
                if(strlen($claims[User::SCHEMA_COUNTRY][0]) <= 255){
                    $user[User::SAMLCOUNTRY] = $claims[User::SCHEMA_COUNTRY][0];
                }else{
                    LoginFlow::printError(__('Provided country claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                                'getUserInputFieldsFromSamlClaim',
                                                var_export($response, true)));
                }
            }else{
                $user[User::SAMLCOUNTRY] = false;
            }

            // CITY
            if(isset($claims[User::SCHEMA_CITY][0])){
                if(strlen($claims[User::SCHEMA_CITY][0]) <= 255){
                    $user[User::SAMLCITY] = $claims[User::SCHEMA_CITY][0];
                }else{
                    LoginFlow::printError(__('Provided city claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                                'getUserInputFieldsFromSamlClaim',
                                                var_export($response, true)));
                }
            }else{
                $user[User::SAMLCITY] = false;
            }

            // STREET
            if(isset($claims[User::SCHEMA_STREET][0])){
                if(strlen($claims[User::SCHEMA_STREET][0]) <= 255){
                    $user[User::SAMLSTREET] = $claims[User::SCHEMA_STREET][0];
                }else{
                    LoginFlow::printError(__('Provided street address claim exceeded 255 characters. This claim should not be longer than 255 characters',
                                                'getUserInputFieldsFromSamlClaim',
                                                var_export($response, true)));
                }
            }else{
                $user[User::SAMLSTREET] = false;
            }
        }

        // Set additional user fields for user creation (if needed)
        // These fields are used for user->add($input);
        $user[User::COMMENT]    = __('Created by phpSaml Just-In-Time user creation on:'.date('Y-m-d H:i:s'));
        $password = bin2hex(random_bytes(20));
        $user[User::PASSWORD]   = $password;
        $user[User::PASSWORDN]  = $password;
        $user[User::AUTHTYPE]   = 4;
        $user[User::SYNCDATE]   = date('Y-m-d H:i:s');

        // Return the userArray.
        return $user;
   }
}
