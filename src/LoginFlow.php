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

use Html;
use Plugin;
use Session;
use Toolbox;
use Throwable;
use CommonDBTM;
use DBConnection;
use Migration;
use OneLogin\Saml2\Auth as samlAuth;
use OneLogin\Saml2\Response;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\LoginState;
use GlpiPlugin\Samlsso\Config\ConfigEntity;
use GlpiPlugin\Samlsso\LoginFlow\User;
use GlpiPlugin\Samlsso\LoginFlow\Auth as glpiAuth;

/**
 * This object brings it all together. It is responsible to handle the
 * main logic concerned with the Saml login and logout flows.
 * it will call upon various supporting objects to perform its tasks.
 */
class LoginFlow extends CommonDBTM
{
    // Database fields
    public const ID                 =   'id';
    public const DEBUG              =   'debug';
    public const ENFORCED           =   'enforced';
    public const ENFORCED_IDP       =   'forcedIdp';
    public const EN_GETTER_LOGIN    =   'enableGetterLogin';
    public const EN_GLPI_LOGIN      =   'enableGlpiLogin';
    public const EN_SAML_BUTTONS    =   'enableSamlButtons';
    public const EN_USERNAME_LOGIN  =   'enableUsername';
    public const CUSTOM_LOGIN_TPL   =   'useCustomLoginTemplate';
    public const BYPASS_VAR         =   'byPassVar';
    public const BYPASS_STR         =   'byPassString';
    public const EN_IDP_LOGOUT      =   'enableIdpLogout';
    public const ENF_AUTH_AFTER     =   'enforceReAuthAfterIdle';       // Time in minutes that session is allowed to idle before forcing reAuth
    public const BLK_AFTER_LOGOUT   =   'blockAfterEnfocedLogout';      // Time to block user after he/she was forcefully logged out.

    // https://codeberg.org/QuinQuies/glpisaml/issues/37
    public const POSTFIELD   = 'samlIdpId';
    public const GETFIELD    = 'samlIdpId';
    public const SAMLBYPASS  =  'bypass';

    // LOGIN FLOW AFTER PRESSING A IDP BUTTON.

     /**
     * Tell DBTM to keep history
     * @var    bool     - $dohistory
     */
    public $dohistory = true;

    /**
     * Tell CommonGLPI to use config (Setup->Setup in UI) rights.
     * @var    string   - $rightname
     */
    public static $rightname = 'config';

    /**
     * Overloads missing canCreate Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canCreate(): bool
    {
        return static::canUpdate();
    }

    /**
     * Overloads missing canDelete Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canDelete(): bool
    {
        return static::canUpdate();
    }

    /**
     * Overloads missing canPurge Setup right and returns canUpdate instead
     *
     * @return bool     - Returns true if profile assigned Setup->Setup->Update right
     * @see             - https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canPurge(): bool
    {
        return static::canUpdate();
    }

    /**
     * Evaluates the session and determines if login/logout is required
     * Called by post_init hook via function in hooks.php. It watches POST
     * information passed from the loginForm.
     *
     * @return  null
     * @since                   1.0.0
     */
    public function doAuth()                         //NOSONAR - complexity by design
    {
        global $CFG_GLPI;

        // Get current state
        if(!$state = new Loginstate()){
            $this->printError(__('Could not load loginState', PLUGIN_NAME));
        }

        // FILE EXCLUDED
        // Do we need to skip because of exclusion?
        if($state->isExcluded()){
            //return $state->getExcludeAction();
            // Return false seems to break GLPI in all kind of ways.
            $state->addLoginFlowTrace(['isExcluded' => true]);
            return;
        }

        // LOGOUT PRESSED?
        // https://codeberg.org/QuinQuies/glpisaml/issues/18
        if ( isset($_SERVER['REQUEST_URI']) && ( strpos($_SERVER['REQUEST_URI'], 'front/logout.php') !== false) ){
            // Stop GLPI from processing cookie based auto login.
            $_SESSION['noAUTO'] = 1;
            $state->addLoginFlowTrace(['logoutPressed' => true]);
            $this->performLogOff();
        }

        // BYPASS SAML ENFORCE OPTION
        // https://codeberg.org/QuinQuies/glpisaml/issues/35
        if(isset($_GET[LoginFlow::SAMLBYPASS])                  &&  // Is ?bypass=1 set in our uri
           strpos($_SERVER['REQUEST_URI'], '/front/') !== false &&  // We are not on the login page
           $_GET[LoginFlow::SAMLBYPASS] == 1                    ){  // bypass is set to 1 (can be replaced with secret key)
            $state->addLoginFlowTrace(['bypassUsed' => true]);      // Register the bypass was used
            $this->performLogOff();                                 // Perform logoff
            $this->doMetaRefresh($CFG_GLPI['url_base'].'/');        // Redirect user to the login page
        }

        // CAPTURE LOGIN FIELD
        // https://codeberg.org/QuinQuies/glpisaml/issues/3
        // Capture the post of regular login and verify if the provided domain is SSO enabled.
        // by evaluating the domain portion against the configured user domains.
        // we need to iterate through the keys because of the added csrf token i.e.
        // [fielda[csrf_token]] = value.
        foreach($_POST as $key => $value){
            if(strstr($key, 'fielda')                               &&                                      // Test keys if fielda[token] is present in the POST.
               !empty($_POST[$key])                                 &&                                      // Test if fielda actually has a value we can process
               $id = Config::getConfigIdByEmailDomain($_POST[$key]) ){                                      // If all is true try to find an matching idp id.
                $state->addLoginFlowTrace(['loginViaUserfield' => true]);     // Register the userfield was used with user
                $_POST[LoginFlow::POSTFIELD] = $id;                                                         // If we found an ID Set the POST phpsaml to our found ID this will trigger
            }
        }

        // MANUAL IDP ID VIA GETTER
        // Check if the user manually provided the correct idp to use
        // this to provision Idp Initiated SAML flows.
        if(isset($_GET[LoginFlow::GETFIELD])        &&                                                      // If correct SAML config ID was provided manually, use that
           is_numeric($_GET[LoginFlow::GETFIELD])   ){                                                      // Make sure its a numeric value and not a string
            $state->addLoginFlowTrace(['loginViaGetter' => true]);
            $_POST[LoginFlow::POSTFIELD] = $_GET[LoginFlow::GETFIELD];
        }

        // Check if we only have 1 configuration and its enforced
        // https://codeberg.org/QuinQuies/glpisaml/issues/61
        if(($state->getPhase() == LoginState::PHASE_INITIAL ||      // Make sure we only do this if state is initial
            $state->getPhase() == LoginState::PHASE_LOGOFF) &&      // Make sure we only do this if state is logoff
            Config::getIsOnlyOneConfig()                    &&      // Only perform this login type with only one samlConfig entry
            Config::getIsEnforced()                         ){      // Only perform this login type if samlLogin is enforced.
             $state->addLoginFlowTrace(['OnlyOneIdpEnforced' => true]);
             $_POST[LoginFlow::POSTFIELD] = Config::getIsOnlyOneConfig();
        }


        // Check if a SAML button was pressed and handle the corresponding logon request!
        if (isset($_POST[LoginFlow::POSTFIELD])         &&      // Must be set
            is_numeric($_POST[LoginFlow::POSTFIELD])    &&      // Value must be numeric
            strlen($_POST[LoginFlow::POSTFIELD]) < 3    ){      // Should not exceed 999
            $state->addLoginFlowTrace(['redirectedIdp' => 'idpId:'.$_POST[LoginFlow::POSTFIELD]]);
            // If we know the idp we register it in the login State
            $state->setIdpId((int) filter_var($_POST[LoginFlow::POSTFIELD], FILTER_SANITIZE_NUMBER_INT));

            // Actually perform SSO
            $this->performSamlSSO($state);
        }
        
        // Be aware that returning an value like false breaks glpi in all kinds of nasty ways.
        // Do nothing and return nothing let GLPI load normally.
    }

    /**
     * Method uses phpSaml to perform a sign-in request with the
     * selected Idp that is stored in the state. The Idp will
     * perform the sign-in and if successful perform a user redirect
     * to /marketplace/glpisaml/front/acs.php
     *
     * @param   Loginstate $state       The current LoginState
     * @return  void
     * @since                           1.0.0
     */
    protected function performSamlSSO(Loginstate $state): void
    {
        global $CFG_GLPI;
        
        // Fetch the correct configEntity GLPI
        if($configEntity = new ConfigEntity($state->getIdpId())){ // Get the configEntity object using our stored ID
            $samlConfig = $configEntity->getPhpSamlConfig();      // Get the correctly formatted SamlConfig array
        }

        // Validate if the IDP configuration is enabled
        // https://codeberg.org/QuinQuies/glpisaml/issues/4
        if($configEntity->isActive()){                            // Validate the IdP config is activated

            // Initialize the OneLogin phpSaml auth object
            // using the requested phpSaml configuration from
            // the glpisaml config database. Catch all throwable
            // errors and exceptions.
            try { $auth = new samlAuth($samlConfig); } catch (Throwable $e) {
                $this->printError($e->getMessage(), 'Saml::Auth->init', var_export($auth->getErrors(), true));
            }

            // Added version 1.2.0
            // Capture and register requestId in database
            // before performing the redirect so we don't need Cookies
            // https://codeberg.org/QuinQuies/glpisaml/issues/45
            $ssoBuiltUrl = $auth->login($CFG_GLPI["url_base"], array(), false, false, true);
            
            // Register the requestId in the database and $_SESSION var;
            $state->setRequestId($auth->getLastRequestID());

            // Update the current phase in database. The state is verified by the Acs
            // while handling the received SamlResponse. Any other state will force Acs
            // into an error state. This is to prevent unexpected (possibly replayed)
            // samlResponses from being processed. to prevent playback attacks.
            if(!$state->setPhase(LoginState::PHASE_SAML_ACS) ){
                $this->printError(__('Could not update the loginState and therefor stopped the loginFlow for:'.$_POST[LoginFlow::POSTFIELD] , PLUGIN_NAME));
            }

            // Perform redirect using HTTP-GET
            header('Pragma: no-cache');
            header('Cache-Control: no-cache, must-revalidate');
            header('Location: ' . $ssoBuiltUrl);
            exit();

        } // Do nothing, ignore the samlSSORequest.
    }

    /**
     * Called by the src/LoginFlow/Acs class if the received response was valid
     * to handle the samlLogin or invalidate the login if there are deeper issues
     * with the response, for instance important claims are missing.
     *
     * @param   Response    SamlResponse from Acs.
     * @return  void
     * @since               1.0.0
     */
    protected function performSamlLogin(Response $response): void
    {
        global $CFG_GLPI;

        // Validate samlResponse and returns provided Saml attributes (claims).
        // validation will print and exit on errors because user information is required.
        $userFields = User::getUserInputFieldsFromSamlClaim($response);
       
        // Try to populate GLPI Auth using provided attributes;
        try {
            $auth = (new GlpiAuth())->loadUser($userFields);
        } catch (Throwable $e) {
            $this->printError($e->getMessage(), 'doSamlLogin');
        }

        // Update the current state
        if(!$state = new Loginstate()){ $this->printError(__('Could not load loginState from database!', PLUGIN_NAME)); }
        $state->setPhase(LoginState::PHASE_GLPI_AUTH);

        // Populate Glpi session with Auth.
        Session::init($auth);

        // Update the sessionID (thats reset by Session::init)
        // So we can find it after the redirect! Else we will
        // end up in a login loop. Very anoying!
        $state->setSessionId();

        // Redirect back to main page
        // We should fix added .'/' to prevent (string|int) type issue.
        // Html::redirect($CFG_GLPI['url_base']);
        // https://codeberg.org/QuinQuies/glpisaml/issues/42
        $this->doMetaRefresh($CFG_GLPI['url_base'].'/');
    }

    /**
     * This is a 'nasty' hack to deal with the session cookie not being accessible on
     * redirect with the php.ini:session.cookie_samesite='Strict'. Performing a meta
     * refresh makes sure the cookie survives.
     *
     * @param    Response  Response object with the samlResponse attributes.
     * @return   array     user->add input fields array with properties.
     * @since    1.1.3
     */
    private function doMetaRefresh(string $location): void
    {

        $location = (filter_var($location, FILTER_VALIDATE_URL)) ? $location : '/';
        echo <<<HTML
        <html>
        <head>
            <meta http-equiv="refresh" content="0;URL='$location'"/>
        </head>
            <body></body>
        </html>
        HTML;
        exit;
    }

    /**
     * Responsible to generate the login buttons to show in conjunction
     * with the glpi login field (not enforced). Only shows if there are
     * buttons to show. Else it will skip.
     *
     * @see https://github.com/DonutsNL/glpisaml/issues/7
     * @return  string  html form for the login screen
     * @since           1.0.0
     */
    public function showLoginScreen(): void
    {
        
        $tplVars = Config::getLoginButtons(12);         // Fetch the global DB object;
        if(!empty($tplVars)){                           // Only show the interface if we have buttons to show.
            // Define static translatable elements
            $tplVars['action']     = PLUGIN_SAMLSSO_WEBDIR;
            $tplVars['header']     = __('Login with external provider', PLUGIN_NAME);
            $tplVars['showbuttons']= true;
            $tplVars['postfield']  = LoginFlow::POSTFIELD;
            $tplVars['enforced']   = Config::getIsEnforced();
            // https://codeberg.org/QuinQuies/glpisaml/issues/12
            TemplateRenderer::getInstance()->display('@samlsso/loginScreen.html.twig',  $tplVars);
        }else{
            // We might still need to hide password, remember and database login fields
            if($tplVars['enforced'] = Config::getIsEnforced() &&    // Validate there is 'an' enforced saml Config
               !isset($_GET['bypass'])                        ){    // Validate we don't want to bypass our enforcement
                
                // Call the renderer to render our CSS injection.
                TemplateRenderer::getInstance()->display('@samlsso/loginScreen.html.twig',  $tplVars);
            }
        }
    }

    // LOGOUT
    /**
     * Makes sure user is logged out of GLPI, if required logged out from SAML.
     * @return void
     */
    protected function performLogOff(): void
    {
        // Update the loginState
        if(!$state = new Loginstate()){ $this->printError(__('Could not load loginState from database!', PLUGIN_NAME)); }
        $state->setPhase(LoginState::PHASE_LOGOFF);

        // Invalidate GLPI session (needs review)
        $validId   = @$_SESSION['valid_id'];
        $cookieKey = array_search($validId, $_COOKIE);
        Session::destroy();
        
        //Remove cookie?
        $cookiePath = ini_get('session.cookie_path');
        if (isset($_COOKIE[$cookieKey])) {
           setcookie($cookieKey, '', time() - 3600, $cookiePath);
           unset($_COOKIE[$cookieKey]);
        }

        // If required perform IDP logout as well
        // Future feature.
        // https://codeberg.org/QuinQuies/glpisaml/issues/1
    }

    // ERROR HANDLING

    /**
     * Shows a login error with human readable message
     *
     * @see https://github.com/DonutsNL/glpisaml/issues/7
     * @param   string   error message to show
     * @since 1.0.0
     */
    public static function showLoginError($errorMsg): void
    {
        global $CFG_GLPI;
        // Define static translatable elements
        $tplVars['header']      = __('⚠️ we are unable to log you in', PLUGIN_NAME);
        $tplVars['error']       = htmlentities($errorMsg);
        $tplVars['returnPath']  = $CFG_GLPI["root_doc"] .'/';
        $tplVars['returnLabel'] = __('Return to GLPI', PLUGIN_NAME);
        // print header
        Html::nullHeader("Login",  $CFG_GLPI["root_doc"] . '/');
        // Render twig template
        // https://codeberg.org/QuinQuies/glpisaml/issues/12
        echo TemplateRenderer::getInstance()->render('@glpisaml/loginError.html.twig',  $tplVars);
        // print footer
        Html::nullFooter();
        // This function always needs to exit to prevent accidental
        // login with disabled or deleted users!
        exit;
    }

   
    /**
     * Prints a nice error message with 'back' button and
     * logs the error passed in the GlpiSaml log file.
     *
     * @see https://github.com/DonutsNL/glpisaml/issues/7
     * @param string errorMsg   string with raw error message to be printed
     * @param string action     optionally add 'action' that was performed to error message
     * @param string extended   optionally add 'extended' information about the error in the log file.
     * @return void             no return, PHP execution is terminated by this method.
     * @since 1.0.0
     */
    public static function printError(string $errorMsg, string $action = '', string $extended = ''): void
    {
        // Pull GLPI config into scope.
        global $CFG_GLPI;

        // Log in file
        Toolbox::logInFile(PLUGIN_NAME."-errors", $errorMsg . "\n", true);
        if($extended){
            Toolbox::logInFile(PLUGIN_NAME."-errors", $extended . "\n", true);
        }

        // Define static translatable elements
        $tplVars['header']      = __('⚠️ An error occurred', PLUGIN_NAME);
        $tplVars['leading']     = __("We are sorry, something went terribly wrong while processing your $action request!", PLUGIN_NAME);
        $tplVars['error']       = $errorMsg;
        $tplVars['returnPath']  = $CFG_GLPI["root_doc"] .'/';
        $tplVars['returnLabel'] = __('Return to GLPI', PLUGIN_NAME);
        // print header
        Html::nullHeader("Login",  $CFG_GLPI["root_doc"] . '/');
        // Render twig template
        echo TemplateRenderer::getInstance()->render('@samlsso/errorScreen.html.twig',  $tplVars);
        // print footer
        Html::nullFooter();
        
        // make sure we stop execution.
        exit;
    }

    
    /**
     * Install the LoginFlow DB table
     * @param   Migration $obj
     * @return  void
     * @since   1.0.0
     */
    public static function install(Migration $migration) : void
    {
        global $DB;
        if(!is_object($migration)){ return; }
        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = LoginFlow::getTable();
    
        // Do not update this table for later versions, use the migration class;
        if (!$DB->tableExists($table)) {
            // Create table
            $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`                        int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `debug`                     tinyint NOT NULL DEFAULT 0,
                `enforced`                  tinyint NOT NULL DEFAULT 0,
                `forcedIdp`                 int DEFAULT -1,
                `enableDomainLogin`         tinyint NOT NULL DEFAULT 0,
                `enableGetterLogin`         tinyint NOT NULL DEFAULT 0,
                `hideGlpiLogin`             tinyint NOT NULL DEFAULT 0,
                `hideSamlButtons`           tinyint NOT NULL DEFAULT 0,
                `hidePassword`              tinyint NOT NULL DEFAULT 0,
                `applyRulesOnAuth`          tinyint NOT NULL DEFAULT 0,
                `applyRulesOnJit`           tinyint NOT NULL DEFAULT 1,
                `allowUnsolicited`          tinyint NOT NULL DEFAULT 0,
                `processRedirects`          tinyint NOT NULL DEFAULT 0,
                `byPassString`              varchar(255) DEFAULT '1234',
                `byPassVar`                 varchar(255) DEFAULT 'bypass',
                `enableIdpLogout`           tinyint NOT NULL DEFAULT 0,
                `enforceReAuthAfterIdle`    int NOT NULL DEFAULT -1,
                `blockAfterEnfocedLogout`   int NOT NULL DEFAULT -1,
                `is_deleted`                tinyint NOT NULL DEFAULT '0',
                `date_creation`             timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod`                  timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());

            // Alter column width for conf_domain
            if($DB->tableExists($table)                                                         &&
               !$DB->insert( $table, [\GlpiPlugin\Samlsso\LoginFlow\LoginFlowEntity::ID => 1 ]) ){
                Session::addMessageAfterRedirect("⚠️ Error on creating initial loginflow config");
            }
        }else{
            Session::addMessageAfterRedirect("ℹ️ $table allready exists");
        }
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
