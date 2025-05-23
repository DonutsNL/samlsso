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
 *  @version    1.2.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso;

use Session;
use Throwable;
use Migration;
use Exception;
use CommonDBTM;
use DBConnection;
use GlpiPlugin\Samlsso\Exclude;


/*
 * The goal of this object is to keep track of the login state in the database.
 * this will allow us to 'influence' the login state of a specific session if
 * we want to, for instance to forcefully log someone off or force re-authentication.
 * we can also extend this session logging for (future) SIEM purposes.
 */
class LoginState extends CommonDBTM
{
    // CLASS CONSTANTS
    public const SESSION_GLPI_NAME_ACCESSOR = 'glpiname';       // NULL -> Populated with user->name in Session::class:128 after GLPI login->init;
    public const SESSION_VALID_ID_ACCESSOR  = 'valid_id';       // NULL -> Populated with session_id() in Session::class:107 after GLPI login;
    public const STATE_ID                   = 'id';             // State identifier
    public const USER_ID                    = 'userId';         // Glpi user_id
    public const USER_NAME                  = 'userName';       // The username
    public const SESSION_ID                 = 'sessionId';      // php session_id;
    public const SESSION_NAME               = 'sessionName';    // Php session_name();
    public const GLPI_AUTHED                = 'glpiAuthed';     // Session authed by GLPI
    public const SAML_AUTHED                = 'samlAuthed';     // Session authed by SAML
    public const LOCATION                   = 'location';       // Location requested;
    public const IDP_ID                     = 'idpId';          // What IdP handled the Auth?
    public const LOGIN_DATETIME             = 'loginTime';      // When did we first see the session
    public const LAST_ACTIVITY              = 'lastClickTime';  // When did we last update the session
    public const ENFORCE_LOGOFF             = 'enforceLogoff';  // Do we want to enforce a logoff (one time)
    public const EXCLUDED_PATH              = 'excludedPath';   // If request was made using saml bypass.
    public const EXCLUDED_ACTION            = 'excludedAction'; // Action to perform on Exclude.
    public const SAML_RESPONSE              = 'serverParams';   // Stores the Saml Response
    public const SAML_REQUEST               = 'requestParams';  // Stores the SSO request
    public const SAML_REQUEST_ID            = 'requestId';      // Saml request ID generated by phpSaml
    public const SAML_UNSOLICITED           = 'requesUnsol';    // Are we dealing with an unsollicited request?
    public const SAML_RESPONSE_ID           = 'responseId';     // SamlResponseId
    public const LOGIN_FLOW_TRACE           = 'loginFlowTrace'; // Registers the steps and outcomes of the LoginFlow
    public const PHASE                      = 'phase';          // Describes the current state GLPI, ACS, TIMEOUT, LOGGED IN, LOGGED OUT.
    public const DATABASE                   = 'database';       // State fetched from database
    public const PHASE_INITIAL              = 1;                // Initial visit
    public const PHASE_SAML_ACS             = 2;                // Performed SAML IDP call expected back at ACS
    public const PHASE_SAML_AUTH            = 3;                // Successfully performed IDP auth
    public const PHASE_GLPI_AUTH            = 4;                // Successfully performed GLPI auth
    public const PHASE_FILE_EXCL            = 5;                // Excluded file called
    public const PHASE_FORCE_LOG            = 6;                // Session forced logged off
    public const PHASE_TIMED_OUT            = 7;                // Session Timed out
    public const PHASE_LOGOFF               = 8;                // Session was logged off

    private $state = [];                                        // Stores the stateValues;

    /**
     * Decide how to get and update our state database
     * object should always return a valid stateObject
     * if it could not fetch, it will provide an empty
     * one, but will not update the database!
     *
     * Be aware, this object is called various times
     * as its part of the plugin and hooked on INIT
     * This makes debugging this object very complex
     * and error prone!
     *
     * @param   $samlInResponseTo   - (optional) Load from database using requestId or get default (empty) state on fail.
     * @return  LoginState          - Returns LoginState Object with (pre) populated State.
     * @since   1.0.0
     */
    public function __construct(string $samlInResponseTo = '')
    {
        // Figure out we are processing excluded path
        $this->state[LoginState::EXCLUDED_PATH] = false;
        if($this->state[LoginState::EXCLUDED_PATH] = Exclude::isExcluded()){
            $this->state[LoginState::EXCLUDED_ACTION] = Exclude::GetExcludeAction($this->state[LoginState::EXCLUDED_PATH]);
        }

        // We either populate the state initially (first call to GLPI) meaning there
        // is no sessionId or requestId present in the database that we can use
        // or we populate using the samlRequestId presented in the samlResponse. If absent
        // in the state database we are dealing with an unsollicited request, or we use
        // the phpSessionId, but this can only be true after we logged in succesfully because
        // the sessionId is reset by GLPI after the IDP redirect (SAML request/response)
        if( empty($samlInResponseTo) ){
            // This is the default path 99% of the time, while GLPI is being used.
            // this path should not be called if we are handling requests originating
            // from the assertion consumer service (acs.php)
            $this->getState();
        }else{
            // getUsingInResponseTo
            $this->getStateInResponseTo($samlInResponseTo);
        }

       // Only write here if we are not in an excluded path
        if(!$this->state[LoginState::EXCLUDED_PATH]){
            // Write state to database.
            if(!$this->WriteStateToDb()){ //NOSONAR - not merging if statements by design
                throw new Exception(__('LoginState could not write initial state to the state database. see: https://codeberg.org/QuinQuies/glpisaml/wiki/LoginState.php'));          //NOSONAR - We use generic Exceptions
            }
        }// We are done, do nothing the object is returned to the caller.
    }

    /**
     * Update the state from the database using the requestId
     * this method is called by the acs.php and its main purpose is to
     * update the sessionId with the new Reset one and make sure
     * the correct state is found after the acs auth redirect.
     *
     * @return  bool
     * @since   1.2.0
     */
    private function getStateInResponseTo(string $samlInResponseTo)
    {
        if(!empty($samlInResponseTo)){
            // Populate the state from the database (if any)
            $this->getState($samlInResponseTo);

            // Update the sessionId with the new (resetted) value
            $this->state = array_merge($this->state,[
                LoginState::SESSION_ID => session_id()]);

            try {
                $this->WriteStateToDb();
            } catch(Throwable $e) {
                throw new Exception('Could not write initial state to the state database with error:'. $e);          //NOSONAR - We use generic Exceptions
            }
        }
    }

    /**
     * Get state from database or if it doesnt exist
     * create a new initial one. This method is called initially and
     * after each successive click after the auth step.
     *
     * @param   $samlInResponseTo   - Fetch state using inResponseTo instead of phpSessionId;
     * @return  bool
     * @since   1.2.0
     */
    private function getState(string $samlInResponseTo = '') : void
    {
        // Get the globals we need
        global $DB;

        // Use either the sessionId or the requestId (after redirect)
        // to find the correct session;
        if(empty($samlInResponseTo)){
            // register session id for initial phase
            // the sessionId will be reset after idp redirect
            // and should be updated by the ACS using the SAML request and responseIds.
            // If this match fails we assume an unsolicited assertion (not requested by GLPI)
            // that is either allowed or not with a new configuration item and logged.
            // See if we are a new or existing session. This should always end up in a saved
            // session once the sessionId was updated by the acs.php.
            if(!$sessionIterator = $DB->request(['FROM' => LoginState::getTable(), 'WHERE' => [LoginState::SESSION_ID => session_id()]])){
                throw new Exception('Could not fetch Login State from database');               //NOSONAR - We use generic Exceptions
            }
        }else{
            // Try and fetch the state from the database using the provided samlInResponseTo field
            // This field should match the samlRequestId registered in LoginFlow::performSamlSSO();
            if(!$sessionIterator = $DB->request(['FROM' => LoginState::getTable(), 'WHERE' => [LoginState::SAML_REQUEST_ID => $samlInResponseTo]])){
                throw new Exception('Could not fetch Login State from database');               //NOSONAR - We use generic Exceptions
            }
        }

        // We should never get more then one row, if we do
        // just overwrite the values with the later entries.
        // Maybe we want to do more with this in the future
        // to prevent session hijacking scenarios.
        if($sessionIterator->numrows() > 0){
            // Populate the username field based on actual values.
            // Get all the relevant fields from the database
            foreach($sessionIterator as $sessionState)
            {
                $this->state = array_merge($this->state,[
                    LoginState::STATE_ID          => $sessionState[LoginState::STATE_ID],
                    LoginState::USER_ID           => $sessionState[LoginState::USER_ID],
                    LoginState::SESSION_ID        => $sessionState[LoginState::SESSION_ID],
                    LoginState::SESSION_NAME      => $sessionState[LoginState::SESSION_NAME],
                    LoginState::LOCATION          => $sessionState[LoginState::LOCATION],
                    LoginState::GLPI_AUTHED       => (bool) $sessionState[LoginState::GLPI_AUTHED],
                    LoginState::SAML_AUTHED       => (bool) $sessionState[LoginState::SAML_AUTHED],
                    LoginState::LOGIN_DATETIME    => $sessionState[LoginState::LOGIN_DATETIME],
                    LoginState::ENFORCE_LOGOFF    => $sessionState[LoginState::ENFORCE_LOGOFF],
                    LoginState::IDP_ID            => $sessionState[LoginState::IDP_ID],
                    LoginState::SAML_RESPONSE_ID  => $sessionState[LoginState::SAML_RESPONSE_ID],
                    LoginState::SAML_REQUEST_ID   => $sessionState[LoginState::SAML_REQUEST_ID],
                    LoginState::SAML_UNSOLICITED  => $sessionState[LoginState::SAML_UNSOLICITED],
                    LoginState::LOGIN_FLOW_TRACE  => $sessionState[LoginState::LOGIN_FLOW_TRACE],
                    LoginState::PHASE             => $sessionState[LoginState::PHASE],
                    LoginState::DATABASE          => true,
                ]);
            }

            // SAML_UNSOLICITED is not set the initial fetch by the acs
            // so if the SAML_UNSOLICITED is null and $samlInResponseTo
            // is not we need to update the field accordingly.
            //var_dump($this->state);
            if(($this->state[LoginState::SAML_UNSOLICITED] === null) &&
                $samlInResponseTo                                    ){
                $this->state = array_merge($this->state, [LoginState::SAML_UNSOLICITED  => '1']);
            }else{
                $this->state = array_merge($this->state, [LoginState::SAML_UNSOLICITED  => '0']);
            }

            // Get the last activity
            $this->getLastActivity();

            // Populate the username field
            $this->setGlpiUserName();
            
        } else {
            $this->createInitialState();
            
            // If InResponseTo was set, but had no database results,
            // then we are dealing with an unsolicited samlResponse
            // and we should log this accordingly in our database
            // Also update the phase correctly
            if(!empty($samlInResponseTo)){
                $this->state = array_merge($this->state, [LoginState::SAML_UNSOLICITED  => true,
                                                         LoginState::PHASE  => LoginState::PHASE_SAML_ACS]);
            }
        }
    }

    /**
     * Create initial state in database This method is called only the very first time
     * someone opens GLPI and is about to authenticate or if there is no valid state
     * found in the database. This condition is also handled as if the visitor still
     * needs to login.
     *
     * @return  bool
     * @since   1.2.0
     */
    private function createInitialState() : bool
    {
        // Get the last activity
        $this->getLastActivity();

        // Populate the GLPI state first.
        $this->getGlpiState();

        // Populate the username field
        $this->setGlpiUserName();

        // Populate session using actual
        $this->state = $this->state = array_merge($this->state,[
            LoginState::USER_ID           => 0,
            LoginState::SESSION_ID        => session_id(),
            LoginState::SESSION_NAME      => session_name(),
            LoginState::LOCATION          => '',
            LoginState::SAML_AUTHED       => 0,
            LoginState::ENFORCE_LOGOFF    => 0,
            LoginState::EXCLUDED_PATH     => $this->state[LoginState::EXCLUDED_PATH],
            LoginState::IDP_ID            => 0,
            LoginState::SAML_REQUEST_ID   => null,
            LoginState::DATABASE          => false,
            LoginState::PHASE             => LoginState::PHASE_INITIAL,
            LoginState::LOGIN_FLOW_TRACE  => serialize([]),
        ]);

        return true;
    }

    /**
     * Write the state into the database
     * for external (SIEM) evaluation and interaction
     *
     * @return  bool
     * @since   1.0.0
     */
    private function writeStateToDb(): bool   //NOSONAR - WIP
    {
        // Register state in database;
        if(!$this->state[LoginState::DATABASE]){
            if(!$id = $this->add($this->state)){
                return false;
            }else{
                // Update the ID for future updates when methods
                // are called on the same object.
                $this->state[LoginState::STATE_ID] = $id;
            }
        }else{
            // Perform an UPDATE
            if(!$this->update($this->state)){
                return false;
            }
        }
        return true;
    }

    /**
     * Get and set last activity in state array
     * @since   1.0.0
     */
    private function getLastActivity(): void
    {
        $this->state[LoginState::LOCATION] = (isset($_SERVER['REQUEST_URI'])) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : 'CLI';
        $this->state[LoginState::LAST_ACTIVITY] = date('Y-m-d H:i:s');
    }

    /**
     * Gets glpi state from the SESSION super global and
     * updates the state array accordingly for initial state.
     *
     * @since   1.0.0
     */
    private function getGlpiState(): void
    {
        // Verify if user is already authenticated by GLPI.
        // Name_Accessor: Populated with user->name in Session::class:128 after GLPI login->init;
        // Id_Accessor: Populated with session_id() in Session::class:107 after GLPI login;
        if (isset($_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR]) &&
            isset($_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR])  ){
            $this->state[LoginState::GLPI_AUTHED] = true;
            $this->state[LoginState::PHASE] = LoginState::PHASE_GLPI_AUTH;
        } else {
            $this->state[LoginState::GLPI_AUTHED] = false;
            $this->state[LoginState::PHASE] = LoginState::PHASE_INITIAL;
        }
    }

    /**
     * Update the loginPhase in the state database.
     * @param int   $phase ID
     * @since       1.0.0
     * @see         LoginState::PHASE_## constants for valid values
     */
    public function setPhase(int $phase): bool
    {
        // figure out if we tried to use SAML for authentication
        if($phase >= LoginState::PHASE_SAML_ACS){
            // Update the SAML_Authed flag as well
            $this->state[LoginState::SAML_AUTHED] = true;
        }
        // Process the session state
        // Consideration Is there a valid scenario where we would
        // update the session phase with a lower number than is initially present
        // degrading the session essentially?
        // would checking if the phase is always higher provide an additional layer of security?
        if($phase > 0 && $phase <= 8){
            $this->state[LoginState::PHASE] = $phase;
            return ($this->update($this->state)) ? true : false;
        }
        return false;
    }

    /**
     * Update the loginPhase in the state database.
     * @param int   $phase ID
     * @since       1.0.0
     * @see         LoginState::PHASE_## constants for valid values
     */
    public function addLoginFlowTrace(array $condition): bool
    {
        if(!empty($this->state[LoginState::LOGIN_FLOW_TRACE])){
            $trace = unserialize($this->state[LoginState::LOGIN_FLOW_TRACE]);
            if(is_array($trace)){
                $trace = array_merge($trace, $condition);
            }else{
                // We might lose data here, but given its a trace
                // we dont care to much. It should never happen though!
                $trace = $condition;
            }
        }else{
            $trace = $condition;
        }
        // Serialize the new array for db storage;
        $this->state[LoginState::LOGIN_FLOW_TRACE] = serialize($trace);
        return ($this->update($this->state)) ? true : false;
    }

    /**
     * Gets the current login phase
     * @return int  phase id
     * @see         LoginState::PHASE_## constants for valid values
     * @since       1.0.0
     */
    public function getPhase(): int
    {
        return (!empty($this->state[LoginState::PHASE])) ? (int) $this->state[LoginState::PHASE] : 0;
    }

    /**
     * Sets the IdpId used in current session.
     * @param int   ConfigItem::ID pointing to IdP provider.
     * @since       1.0.0
     */
    public function setIdpId(int $idpId): bool
    {
        if($idpId > 0 && $idpId < 999){
            $this->state[LoginState::IDP_ID] = $idpId;
            return ($this->update($this->state)) ? true : false;
        }else{
            return false;
        }
    }

    /**
     * Sets the IdpId used in current session.
     * @param int   ConfigItem::ID pointing to IdP provider.
     * @since       1.0.0
     */
    public function setSessionId(): bool
    {
        $this->state[LoginState::SESSION_ID] = session_id();
        return ($this->update($this->state)) ? true : false;
    }

    /**
     * Gets current IdpId used in current session.
     * @return int  ConfigItem::ID pointing to IdP provider.
     * @since       1.0.0
     */
    public function getIdpId(): int
    {
        return (!empty($this->state[LoginState::IDP_ID])) ? $this->state[LoginState::IDP_ID] : 0;
    }

    /**
     * Returns the EXCLUDED_PATH if set, else it returns empty.
     * @return int  ConfigItem::ID pointing to IdP provider.
     * @since       1.0.0
     */
    public function isExcluded(): string
    {
        return (!empty($this->state[LoginState::EXCLUDED_PATH])) ?  $this->state[LoginState::EXCLUDED_PATH] : '';
    }

    public function getExcludeAction(): bool
    {
        return (isset($this->state[LoginState::EXCLUDED_ACTION]) && !empty($this->state[LoginState::EXCLUDED_ACTION])) ? $this->state[LoginState::EXCLUDED_ACTION] : false;
    }

    /**
     * Adds SamlResponse to the state table
     * @param  string   json_encoded samlResponse
     * @return bool     true on success.
     * @since           1.0.0
     */
    public function setSamlResponseParams($samlResponse): bool
    {
        if($samlResponse > 0){
            $this->state[LoginState::SAML_RESPONSE] = $samlResponse;
            return ($this->update($this->state)) ? true : false;
        }
        return false;
    }

    /**
     * Adds SamlRequest to the state table
     * @param  string   json_encoded samlRequest
     * @return bool     true on success.
     * @since           1.0.0
     */
    public function setRequestParams(string $samlRequest): bool
    {
        if($samlRequest > 0){
            $this->state[LoginState::SAML_REQUEST] = $samlRequest;
            return ($this->update($this->state)) ? true : false;
        }
        return false;
    }

    /**
     * Adds SamlRequestId to the state table
     * @param  string   samlRequestId "ONELOGIN_[0-9a-z]{40}"
     * @return bool     true on success.
     * @since           1.0.0
     */
    public function setRequestId(string $requestId): bool
    {
        $this->state[LoginState::SAML_REQUEST_ID] = $requestId;
        return ($this->update($this->state)) ? true : false;
    }

    /**
     * Registeres the samlResponseId in the state database
     * @return bool
     * @since       1.2.0
     */
    public function setSamlResponseId(string $samlResponseId): bool
    {
        if(!empty($samlResponseId)){
            $this->state[LoginState::SAML_RESPONSE_ID] = $samlResponseId;
            return ($this->update($this->state)) ? true : false;
        }else{
            return false;
        }
    }

    /**
     * Gets the samlResponseId from the state (if any).
     * @return int  LoginState::SAML_RESPONSE_ID.
     * @since       1.2.0
     */
    public function getSamlResponseId(): int
    {
        return (!empty($this->state[LoginState::SAML_RESPONSE_ID])) ? $this->state[LoginState::SAML_RESPONSE_ID] : 0;
    }

    /**
     * Verifies the requestId is not present in the state
     * database.
     * @return  bool
     * @since           1.2.0
     */
    public function checkResponseIdUnique(string $responseId): bool
    {
        global $DB;
        // Do we need validate the input for SQL injections?
        // Verify if $DB->request is already escaping the string.

        // This field should match the samlRequestId registered in LoginFlow::performSamlSSO();
        if(!$sessionIterator = $DB->request(['FROM' => LoginState::getTable(), 'WHERE' => [LoginState::SAML_RESPONSE_ID => $responseId]])){
            throw new Exception('Could not fetch Login State from database');   //NOSONAR we are happy with this one!
        }

        return ($sessionIterator->numrows() > 0) ? false : true;
    }

    /**
     * Get the glpi Username and set it in the state.
     * If no user was identified, use remote IP as user.
     *
     * @param   int      $idpId - identity of the IDP for which we are fetching the logging
     * @return  array    Array with logging entries (if any) else empty array;
     * @since   1.1.0
     */
    private function setGlpiUserName(): void
    {
        // Use remote ip as username if session is anonymous.
        // https://codeberg.org/QuinQuies/glpisaml/issues/18
        $altUser = (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : 'CLI';
        $remote = (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $altUser;
        $this->state[LoginState::USER_NAME] = (!empty($_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR])) ? $_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR] : $remote;
    }

    /**
     * Gets the logging entries from the loginState database for given identity provider
     * for presentation in the logging tab.
     *
     * @param   int      $idpId - identity of the IDP for which we are fetching the logging
     * @return  array    Array with logging entries (if any) else empty array;
     * @since   1.2.0
     */
    public static function getLoggingEntries(int $idpId): array
    {
        global $DB;
        // Create an empty logging array
        $logging = [];
        // Should be a positive number.
        if(is_numeric($idpId)){
            // Fetch logging only for the given identity provider
            foreach($DB->request(['FROM' => LoginState::getTable(),
                                  'WHERE' => [LoginState::IDP_ID => $idpId],
                                  'ORDER' => [LoginState::LOGIN_DATETIME.' DESC']]) as $id => $row ){
                
                // Unserialize the loginTrace, format it and export it;
                if(!empty($row[LoginState::LOGIN_FLOW_TRACE])){
                    $i = 1;
                    $trace = '';
                    foreach(unserialize($row[LoginState::LOGIN_FLOW_TRACE]) as $key => $value){
                        $trace .= "$i : $key => $value\n\n";
                        $i++;
                    }
                }else{
                    $trace = '';
                }
                
                $row[LoginState::LOGIN_FLOW_TRACE] = $trace;
                $logging[$id] = $row;
            }
        }
        return $logging;
    }


    /**
     * Determin if the state was loaded from the LoginState database or if you are dealing with
     * an initial version.
     *
     * @return  bool
     * @since   1.2.0
     */
    public function isLoadedFromDb(): bool
    {
        return ($this->state[LoginState::DATABASE]) ? true : false;
    }

    /**
     * Install the LoginState DB table
     * @param   Migration $obj
     * @return  void
     * @since   1.0.0
     */
    public static function install(Migration $migration) : void
    {
        global $DB;
        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = LoginState::getTable();

        // Create the base table if it does not yet exist;
        // Do not update this table for later versions, use the migration class;
        if (!$DB->tableExists($table)) {
            // Create table
            $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`                        int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `userId`                    int {$default_key_sign} NOT NULL,
                `userName`                  varchar(255) NULL,
                `sessionId`                 varchar(255) NOT NULL,
                `sessionName`               varchar(255) NOT NULL,
                `glpiAuthed`                tinyint {$default_key_sign} NULL,
                `samlAuthed`                tinyint {$default_key_sign} NULL,
                `loginTime`                 timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `lastClickTime`             timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `location`                  longtext NOT NULL,
                `enforceLogoff`             tinyint {$default_key_sign} NULL,
                `excludedPath`              text NULL,
                `idpId`                     int NULL,
                `serverParams`              text NULL,
                `requestParams`             text NULL,
                `loggedOff`                 tinyint {$default_key_sign} NULL,
                `phase`                     text NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        }else{
            Session::addMessageAfterRedirect("🆗 $table allready installed");
        }

        // Add new requestId field for version 1.2.
        // https://codeberg.org/QuinQuies/glpisaml/issues/45
        if ( $DB->tableExists($table)                                                                                                                  &&   // Table should exist
            !$DB->fieldExists($table, LoginState::SAML_REQUEST_ID, false)                                                                              &&   // Field should not exist
            $migration->addField($table, LoginState::SAML_REQUEST_ID, 'str', ['null' => true, 'after' => LoginState::SAML_REQUEST, 'update' => true])  ){   // @see Migration::fieldFormat()
                Session::addMessageAfterRedirect("🆗 Added field LoginState::SAML_REQUEST_ID for v1.2.0");
        } // We silently ignore errors. Most common cause for an error is if the field already exists.

        if ( $DB->tableExists($table)                                                                                                                      &&   // Table should exist
            !$DB->fieldExists($table, LoginState::SAML_UNSOLICITED, false)                                                                                 &&   // Field should not exist
            $migration->addField($table, LoginState::SAML_UNSOLICITED, 'str', ['null' => true, 'after' => LoginState::SAML_REQUEST_ID, 'update' => true])  ){   // @see Migration::fieldFormat()
                Session::addMessageAfterRedirect("🆗 Added field LoginState::SAML_UNSOLLICITED for v1.2.0");
        } // We silently ignore errors. Most common cause for an error is if the field already exists.

        if ( $DB->tableExists($table)                                                                                                                       &&   // Table should exist
            !$DB->fieldExists($table, LoginState::SAML_RESPONSE_ID, false)                                                                                  &&   // Field should not exist
            $migration->addField($table, LoginState::SAML_RESPONSE_ID, 'str', ['null' => true, 'after' => LoginState::SAML_UNSOLICITED, 'update' => true]) ){   // @see Migration::fieldFormat()
                Session::addMessageAfterRedirect("🆗 Added field LoginState::SAML_RESPONSE_ID for v1.2.0");
        } // We silently ignore errors. Most common cause for an error is if the field already exists.

        if ( $DB->tableExists($table)                                                                                                                       &&   // Table should exist
            !$DB->fieldExists($table, LoginState::LOGIN_FLOW_TRACE, false)                                                                                  &&   // Field should not exist
            $migration->addField($table, LoginState::LOGIN_FLOW_TRACE, 'str', ['null' => true, 'after' => LoginState::SAML_RESPONSE_ID, 'update' => true]) ){   // @see Migration::fieldFormat()
                Session::addMessageAfterRedirect("🆗 Added field LoginState::LOGIN_FLOW_TRACE for v1.2.0");
        } // We silently ignore errors. Most common cause for an error is if the field already exists.

        // Clean old cookies
        if(isset($_COOKIE['enforce_sso'])){
            // Unset by setting expire in the past.
            setcookie(
                'enforce_sso',
                '-1',
                ['expires' => time() - 3600,
                'secure'   => true,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'None',]);
        }

        // Clean table
        if ( $DB->tableExists($table)) {
                $query = <<<SQL
                    UPDATE $table SET location = '' where id > 0;
                SQL;
                $DB->doQuery($query) or die($DB->error());
                Session::addMessageAfterRedirect("🆗 Cleaned: $table.");
        } // We silently ignore errors. Most common cause for an error is if the field already exists.
    }

    /**
     * Uninstall the LoginState DB table
     * @param   Migration $obj
     * @return  void
     * @since   1.0.0
     */
    public static function uninstall(Migration $migration) : void
    {
        $table = LoginState::getTable();
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }
}
