<?php
declare(strict_types=1);
/**
 *  ------------------------------------------------------------------------
 *  Samlsso
 *
 *  Samlsso was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Samlsso project.
 *
 * Samlsso plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Samlsso is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Samlsso. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    Samlsso
 *  @version    1.2.5
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlsso/readme.md
 *  @link       https://github.com/DonutsNL/samlsso
 * ------------------------------------------------------------------------
 *
 * The concern this class addresses is added because we want to add support
 * for multiple idp's. Deciding what idp to use might involve more complex
 * algorithms then we used (1:1) in the previous version of phpSaml. These
 * can then be implemented here.
 *
 **/

namespace GlpiPlugin\Samlsso;

use Html;
use Plugin;
use Session;
use Toolbox;
use Throwable;
use CommonDBTM;
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
    public const SAMLBYPASS  = 'bypass';
    public const SLOLOGOUT   = 'sloLogout';

    /**
     * Holds the state object
     * @var ?LoginState
     */
    private ?LoginState $state;

    /**
     * Holds the captured user piripheral name if any.
     * @var string subject
     */
    private ?string $subject;

     /**
     * Tell DBTM to keep history
     * @var    bool     $dohistory
     */
    public $dohistory = true;

    /**
     * Tell CommonGLPI to use config (Setup->Setup in UI) rights.
     * @var    string   $rightname
     */
    public static $rightname = 'config';

    /**
     * Overloads missing canCreate Setup right and returns canUpdate instead
     *
     * @return bool     Returns true if profile assigned Setup->Setup->Update right
     * @see             https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canCreate(): bool
    {
        return (bool) static::canUpdate();
    }

    /**
     * Overloads missing canDelete Setup right and returns canUpdate instead
     *
     * @return bool     Returns true if profile assigned Setup->Setup->Update right
     * @see             https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canDelete(): bool
    {
        return (bool) static::canUpdate();
    }

    /**
     * Overloads missing canPurge Setup right and returns canUpdate instead
     *
     * @return bool     Returns true if profile assigned Setup->Setup->Update right
     * @see             https://github.com/pluginsGLPI/example/issues/50
     */
    public static function canPurge(): bool
    {
        return (bool) static::canUpdate();
    }

    /**
     * returns class friendly TypeName.
     *
     * @param  int      $nb return plural or singular friendly name.
     * @return string   returns translated friendly name.
     */
    public static function getTypeName($nb = 0): string
    {
        return __('samlFlow', PLUGIN_NAME);
    }

    /**
     * Returns class icon to use in menus and tabs
     *
     * @return string   returns Font Awesome icon className.
     * @see             https://fontawesome.com/search
     */
    public static function getIcon(): string
    {
        return 'fa-fw fas fa-sign-in-alt';
    }

    // LOGIN FLOW AFTER PRESSING A IDP BUTTON.
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
        // The plugin should remain dormant with all CLI calls.
        // https://github.com/DonutsNL/samlsso/issues/38
        // TODO remove all other SAPI = cli validations in the code
        // as this renders them useless.
        if(PHP_SAPI == 'cli') {
            // Do nothing.
            return;
        }

        // Dont process anything if we are handling an ACS call.
        // Generating a state in this phase will taint it because we
        // have a new sessionId that wont align with existing entries
        // and need to use the samlRequestId to populate the stateobj.
        // https://github.com/DonutsNL/samlsso/issues/29
        if(strpos($_SERVER['REQUEST_URI'], 'front/acs') !== false ){
                return;
        }

        // If we hit an excluded file, we return and do nothing, not even log the
        // event. Possibly we want to enable the user to perform SIEM calls by
        // implementing this functionality prior to this validation.
        if(Exclude::isExcluded()){
            return;
        }

        // Make the GLPI configuration available.
        global $CFG_GLPI;

        // Get current state this can either be an initial state (new session) or
        // an existing one. The state properties tell which one we are dealing with.
        try {
            $this->state = new Loginstate();
            // We need to check if this is the initial state, if so we write it to database.
            // This logic cant be part of the objects logic because its reinitialized by the
            // CommonDBTM object causing the sessions to be duplicated. This should also be
            // the only place where we call the writeState() method.
            // https://github.com/DonutsNL/samlsso/issues/26
            if($this->state->getStateId() == -1){
                // Dont write anything if we are in the middle of an ACS request statelessly
                // This logic is always called by the GLPI hook this could or should also
                // be an exclusion but this is safer.
                $this->state->writeState();
            }// We loaded a valid state from the database, do nothing more.
        } catch(Throwable $e){
            $this->printError(__("Loading login state failed with: $e", PLUGIN_NAME));
        }

        // Store any redirects passed to GLPI in the state,
        // so we can process them after the auth redirects.
        $this->state->setRedirect();

        // Is the LOGOUT button PRESSED?
        // https://codeberg.org/QuinQuies/glpisaml/issues/18
        if(isset($_SERVER['REQUEST_URI'])                                   &&          // Request URI available (non CLI)
          (strpos($_SERVER['REQUEST_URI'], 'front/logout') !== false)       ){          // font/logout part of the request URI

                // Stop GLPI from processing cookie based auto login.
                $this->state->addLoginFlowTrace(['logoutPressed' => true]);
                $this->performLogOff();
                // If we reach this then GLPI should handle the logoff not us.
                return;
        }

        // Perform SLO logout with idp
        // Called by $this->performLogOff()
        if(isset($_GET[self::SLOLOGOUT])                                    &&          // SLO was triggered and we need to process it.
           $configEntity = new ConfigEntity($this->state->getIdpId())       ){          // Get the config so we can generate IDP logout request.
            // We can assume GLPI session was allready destroyed and we
            // can continue here. In worst if forced manuallu the IDP will
            // be logged out while GLPI still has a valid session that
            // will then be invalidated by the GLPI session manager
            // because of the redirects performed tainting the cookie
            // resulting in an invalid session error from GLPI.
            $samlAuth = new samlAuth($configEntity->getPhpSamlConfig());
            // Get the (signed) logout url.
            $sloUrl = $samlAuth->logout();
            header('location:'.$sloUrl);
            exit;
        }

        // Do nothing if glpi is trying to impersonate someone
        // Let GLPI handle auth in this scenario
        // https://codeberg.org/QuinQuies/glpisaml/issues/159
        if(isset($_POST['impersonate']) &&
           $_POST['impersonate'] == '1' &&
           !empty($_POST['id'])         ){

                $this->state->addLoginFlowTrace(['Impersonated someone' => true]);
                return;
        }

        // BYPASS SAML ENFORCE OPTION
        // https://codeberg.org/QuinQuies/glpisaml/issues/35
        if((isset($_GET[LoginFlow::SAMLBYPASS])                  &&                                             // Is ?bypass=1 set in our uri (replace with GLPIs noAuto?)
            $_GET[LoginFlow::SAMLBYPASS] == 1)                   ||                                             // bypass key is set (drop this?)
            isset($_GET['noAuto'])                               ){                                             // bypass is set to 1 (can be replaced with secret key)

                $this->state->addLoginFlowTrace(['bypassUsed' => true]);                                        // Register the bypass was used
                $url = $CFG_GLPI['url_base'].'/?'.LoginFlow::SAMLBYPASS.'=1&noAUTO=1';                          // Craft url with bypass make sure we land on page
                // Perform serverside redirect.
                header('Location:'.$url);
        }

        // CAPTURE LOGIN FIELD
        // https://codeberg.org/QuinQuies/glpisaml/issues/3
        // https://github.com/DonutsNL/samlsso/issues/16
        // Capture the post of regular login and verify if the provided domain is SSO enabled.
        // by evaluating the domain portion against the configured user domains.
        // we need to iterate through the keys because of the added csrf token i.e.
        // [fielda[csrf_token]] = value.
        foreach($_POST as $key => $value){
            if(strstr($key, 'login_name')                           &&                                          // Test keys if fielda[token] is present in the POST.
              !empty($_POST[$key])                                  &&                                          // Test if fielda actually has a value we can process
              $id = Config::getConfigIdByEmailDomain($_POST[$key])  ){                                          // If all is true try to find an matching idp id.

                $this->state->addLoginFlowTrace(['loginViaUserfield' => 'user:'.$_POST[$key].',idpId:'.$id]);   // Register the userfield was used with user
                $this->subject = $_POST[$key];                                                                  // Save the user piripheral name as samlSubject to pass to the IDP
                $_POST[LoginFlow::POSTFIELD] = $id;                                                             // If we found an ID Set the POST phpsaml to our found ID this will trigger
            }
        }

        // MANUAL IDP ID VIA GETTER
        // Check if the user manually provided the correct idp to use
        // this to provision Idp Initiated SAML flows.
        if(isset($_GET[LoginFlow::GETFIELD])        &&                                                          // If correct SAML config ID was provided manually, use that
           is_numeric($_GET[LoginFlow::GETFIELD])   ){                                                          // Make sure its a numeric value and not a string

            $this->state->addLoginFlowTrace(['loginViaGetter' => 'getValue:'.$_GET[LoginFlow::GETFIELD]]);
            $_POST[LoginFlow::POSTFIELD] = $_GET[LoginFlow::GETFIELD];
        }

        // Check if we only have 1 configuration and its enforced
        // https://codeberg.org/QuinQuies/glpisaml/issues/61
        if(($this->state->getPhase() == LoginState::PHASE_INITIAL   ||      // Make sure we only do this if state is initial
            $this->state->getPhase() == LoginState::PHASE_LOGOFF)   &&      // Make sure we only do this if state is logoff
            Config::getIsOnlyOneConfig()                            &&      // Only perform this login type with only one samlConfig entry
            Config::getIsEnforced()                                 &&      // Only Enforce if we have enforced option configured.
            !isset($_GET['noAuto'])                                 &&      // Only perform this if GLPI 'noAuto' is absent.
            !isset($_GET[LoginFlow::SAMLBYPASS])                    ){      // Only perform this if user is not trying to bypass samlAuth.

                $this->state->addLoginFlowTrace(['OnlyOneIdpEnforced' => 'idpId:'.Config::getIsOnlyOneConfig()]);
                $_POST[LoginFlow::POSTFIELD] = Config::getIsOnlyOneConfig();
        }

        // https://github.com/DonutsNL/samlsso/issues/12 add typecast.
        // Check if a SAML button was pressed and handle the corresponding logon request!
        if (isset($_POST[LoginFlow::POSTFIELD])                  &&      // Must be set
            is_numeric($_POST[LoginFlow::POSTFIELD])             &&      // Value must be numeric
            strlen((string) $_POST[LoginFlow::POSTFIELD]) < 3    ){      // Should not exceed 999

                $this->state->addLoginFlowTrace(['finalIdp' => 'idpId:'.$_POST[LoginFlow::POSTFIELD]]);
                // If we know the idp we register it in the login State
                // the input is validated as is_numeric. Floats will be truncated by
                // the cast to int (int).
                $this->state->setIdpId((int) filter_var($_POST[LoginFlow::POSTFIELD], FILTER_SANITIZE_NUMBER_INT));

                // Actually perform SSO
                $this->performSamlIdpRequest();
        }
        // Do nothing and return nothing.
        // Returning an value like false breaks glpi in all kinds of nasty ways.
    }

    /**
     * Method uses phpSaml to perform a sign-in request with the
     * selected Idp that is stored in the state. The Idp will
     * perform the sign-in and if successful perform a user redirect
     * to /plugins/samlsso/front/acs.php
     *
     * @return  void
     * @since                           1.0.0
     */
    protected function performSamlIdpRequest(): void
    {
        global $CFG_GLPI;
        
        // Fetch the correct configEntity GLPI
        if($configEntity = new ConfigEntity($this->state->getIdpId())){ // Get the configEntity object using our stored ID
            $samlConfig = $configEntity->getPhpSamlConfig();      // Get the correctly formatted SamlConfig array
        }

        // Validate if the IDP configuration is enabled
        // https://codeberg.org/QuinQuies/glpisaml/issues/4
        if($configEntity->isActive()){                            // Validate the IdP config is activated

            // Initialize the OneLogin phpSaml auth object
            // using the requested phpSaml configuration from
            // the samlsso config database. Catch all throwable
            // errors and exceptions.
            try { $auth = new samlAuth($samlConfig); } catch (Throwable $e) {
                $this->printError($e->getMessage(), 'Saml::Auth->init', var_export($auth->getErrors(), true));
            }

            // Added version 1.2.0
            // Capture and register requestId in database
            // before performing the redirect so we don't need Cookies
            // https://codeberg.org/QuinQuies/glpisaml/issues/45
            try{
                $ssoBuiltUrl = $auth->login(
                    $CFG_GLPI["url_base"],  // $returnTo (1st param)
                    array(),                // $parameters (2nd param, empty array)
                    false,                  // $forceAuthn (3rd param)
                    false,                  // $isPassive (4th param)
                    true,                   // $stay (5th param)
                    true,                   // $setNameIdPolicy (6th param)
                    null,                   // $nameIdValueReq pass Subject, not supported by Microsoft $this->subject
                );
            } catch (Throwable $e) {
                $this->printError($e->getMessage(), 'Saml::Auth->init', var_export($auth->getErrors(), true));
            }
            
            // Register the requestId in the database and $_SESSION var;
            $this->state->setRequestId($auth->getLastRequestID());

            // Update the current phase in database. The state is verified by the Acs
            // while handling the received SamlResponse. Any other state will force Acs
            // into an error state. This is to prevent unexpected (possibly replayed)
            // samlResponses from being processed. to prevent playback attacks.
            if(!$this->state->setPhase(LoginState::PHASE_SAML_ACS) ){
                $this->printError(__('Could not update the loginState and therefor stopped the loginFlow for:'.$_POST[LoginFlow::POSTFIELD] , PLUGIN_NAME));
            }

            // Perform redirect to Idp using HTTP-GET
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
     * @param   LoginState  The correct state loaded with the samlRequestId passed by ACS
     * @return  void
     * @since               1.0.0
     */
    protected function performGlpiLogin(Response $response, LoginState $state): void
    {
        // Push the state into this objects property just in case.
        $this->state = $state;
        
        // Validate samlResponse and extract provided attributes (saml claims).
        // This validation will print and exit(!) on errors because user information is mandatory
        // after this step.
        $userFields = User::getUserInputFieldsFromSamlClaim($response);
       
        // Try to populate GLPI Auth using the fetched samlResponse attributes;
        try {
            $auth = (new GlpiAuth())->loadUser($userFields);
        } catch (Throwable $e) {
            $this->printError($e->getMessage(), 'doSamlLogin');
        }

        // update the state that samlAuth was succesfull.
        $this->state->setSamlAuthTrue();

        // Before we continue we need to make sure to have a valid GLPI session. This is important
        // because the initiall call was stateless. At this point we want to start authenticating
        // the saml user with GLPI (merge auth) and we need a valid glpi session for that, that will
        // survive the next redirect back to GLPI. For this we need to make the session statefull and
        // perform a browser redirect (meta refresh) to remove any taints in the browsers requestchain.
        ini_set('session.use_cookies', 1);  // Renable Cookies Disabled by PostBootListner/SessionStart.php:106
        Session::destroy();                 // Clean existing session
        Session::start();                   // Create a new statefull one.

        // Re populate Glpi session with the populated GlpiAuth object
        // This tells GLPI a valid GLPI user was logged in.
        Session::init($auth);

        // Update the samlState table with the new sessionId.
        // so we can keep tracking it after the next redirect.
        // https://github.com/DonutsNL/samlsso/issues/26
        $state->setSessionId();

        // Dont depend on GLPI core to perform the correct type of redirect.
        $this->performBrowserRedirect();
    }

    /**
     * Makes sure user is logged out of GLPI, and if requested also logged out from SAML.
     * @return void
     */
    protected function performLogOff(): void
    {
        global $CFG_GLPI;

        // Update the state indicating that logout has been pressed.
        $this->state->setPhase(LoginState::PHASE_LOGOFF);

        // Get IdpConfiguration if any and figure out if we
        // need to handle some sort of logout at the IDP or
        // just ignore the logout request and let GLPI handle it.
        $configEntity = new ConfigEntity($this->state->getIdpId());         // Get the correct IDP configuration using the state.

        // If the session is samlAuthed and the SLO url was configured
        // capture the logout and present an option to logout with the
        // IDP in addition to logging out of GLPI.
        if( $configEntity->getField(ConfigEntity::IDP_SLO_URL)  &&
            $this->state->isSamlAuthed()                        ){
            
            // Define static translatable elements
            $tplVars = [
                    'header'        => __('⚠️ Are you sure?', PLUGIN_NAME),
                    'idpName'       => $configEntity->getField(ConfigEntity::NAME),
                    'returnLabel'   => __('Continue GLPI logout', PLUGIN_NAME),
                    'returnPath'    => $CFG_GLPI["url_base"] .'/',
                    'sloLabel'      => __('Log out everywhere', PLUGIN_NAME),
                    'sloPath'       =>  $CFG_GLPI["url_base"] .'/?'.self::SLOLOGOUT.'=1',
            ];
            
            // print GLPI headers
            Html::nullHeader("SamlSSO Logout catcher", '/');
            // Render twig template
            // https://codeberg.org/QuinQuies/glpisaml/issues/12
            echo TemplateRenderer::getInstance()->render('@samlsso/logout.html.twig',  $tplVars);
            // print nullFooter
            Html::nullFooter();

            // Make sure the GLPI session is cleaned
            // https://github.com/DonutsNL/samlsso/issues/35
            Session::cleanOnLogout();
            exit;
        } // else silently ignore and continue GLPI logout.
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
        // Validate if we need to hide the login fields?
        if(Config::getHideLoginFields()){
            echo '<style>
                   .card-body div.mb-4:has(#login_password) {
                        display: none;
                    }
                    .card-body div.mb-3:has([id^="dropdown_auth"]){
                        display:none;
                    }
                    .card-body div.mb-2:has(#login_remember){
                        display:none;
                    }
                  </style>';
        }
        
        $tplVars = Config::getLoginButtons(12);         // Fetch the global DB object;
        if(!empty($tplVars)){                           // Only show the interface if we have buttons to show.
            // Define static translatable elements
            $tplVars['action']     = Plugin::getWebDir(PLUGIN_NAME, true);
            $tplVars['header']     = __('Login with external provider', PLUGIN_NAME);
            $tplVars['showbuttons']    = true;
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

    // ERROR HANDLING
    /**
     * Prints and logs a Fatal login error with human readable message and doesnt offer
     * any 'back' options. used mainly in the ACS flow where the only option is
     * to correct configuration issues.
     *
     * @see https://github.com/DonutsNL/glpisaml/issues/7
     * @param string   error message to show
     * @since 1.0.0
     */
    public static function PrintFatalLoginError($errorMsg): never
    {
        global $CFG_GLPI;

        // Log in file
        Toolbox::logInFile(PLUGIN_NAME."-errors", 'FATAL SAML LOGIN ERROR:'. $errorMsg . "\n", true);

        // Define static translatable elements
        $tplVars['header']      = __('⚠️ Sorry we are unable to log you in', PLUGIN_NAME);
        // https://github.com/DonutsNL/samlsso/issues/21
        // Typecast might break if the passed object doesnt have a __toString() magic method.
        $tplVars['error']       = htmlentities((string) $errorMsg);
        $tplVars['returnPath']  = $CFG_GLPI["url_base"] .'/';
        $tplVars['returnLabel'] = __('Return to GLPI', PLUGIN_NAME);
        // print header
        Html::nullHeader("Login",  $CFG_GLPI["url_base"] . '/');
        // Render twig template
        // https://codeberg.org/QuinQuies/glpisaml/issues/12
        echo TemplateRenderer::getInstance()->render('@samlsso/loginError.html.twig',  $tplVars);
        // print footer
        Html::nullFooter();

        // Make sure php execution is stopped.
        exit;
    }

   
    /**
     * Prints and logs error message with 'back' button
     *
     * @see https://github.com/DonutsNL/glpisaml/issues/7
     * @param string errorMsg   string with raw error message to be printed
     * @param string action     optionally add 'action' that was performed to error message
     * @param string extended   optionally add 'extended' information about the error in the log file.
     * @return void             no return, PHP execution is terminated by this method.
     * @since 1.0.0
     */
    public static function printError(string $errorMsg, string $action = '', string $extended = ''): never
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
        $tplVars['returnPath']  = $CFG_GLPI["url_base"] .'/';
        $tplVars['returnLabel'] = __('Return to GLPI', PLUGIN_NAME);
        // print header
        Html::nullHeader("Login",  $CFG_GLPI["url_base"] . '/');
        // Render twig template
        echo TemplateRenderer::getInstance()->render('@samlsso/errorScreen.html.twig',  $tplVars);
        // print footer
        Html::nullFooter();
        
        // Make sure php execution is stopped.
        exit;
    }

     /**
     * Perform browser redirect to make sure we send a HTTP200 OK. The HTTP200 OK is
     * needed to ensure the browser resets the request chain originating from the IDP.
     * Not resetting the chain will invalidate the GLPI cookies.
     *
     * @see https://github.com/DonutsNL/glpisaml/issues/7
     * @return void             no return, PHP execution is terminated by this method.
     * @since 1.0.0
     */
    public static function performBrowserRedirect(): never
    {
        // reference global config;
        global $CFG_GLPI;
        // get actual state;
        try {
            $state = new Loginstate();
        } catch(Throwable $e){
            LoginFlow::printError(__("Loading login state failed with: $e", PLUGIN_NAME));
        }

        // Restore stored redirect requests.
        // https://github.com/DonutsNL/samlsso/issues/2
        if(!empty($state->getRedirect())){
            $url=$CFG_GLPI['url_base'].'?redirect='.$state->getRedirect();
        }else{
            $url=$CFG_GLPI['url_base'];
        }

        printf('<!DOCTYPE html>
                <html>
                    <head>
                        <meta charset="UTF-8" />
                        <meta http-equiv="refresh" content="0;url=\'%1$s\'" />

                        <title>%2$s</title>
                    </head>
                    <body>&nbsp;</body>
                </html>',
                \htmlescape($url),
                'Auth succesfull');
        exit;
    }
    
    /**
     * Install the LoginFlow DB table
     * @param   Migration $obj
     * @return  void
     * @since   1.0.0
     */

    /*  //NOSONAR - This is in preparation of version 1.2.0 but should not YET be processed by
        //          the hook.php install
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
                `debug`                     tinyint NOT NULL DEFAULT 0,
                `enforced`                  tinyint NOT NULL DEFAULT 0,
                `forcedIdp`                 int DEFAULT -1,
                `enableGetterLogin`         tinyint NOT NULL DEFAULT 0,
                `hideGlpiLogin`             tinyint NOT NULL DEFAULT 0,
                `hideSamlButtons`           tinyint NOT NULL DEFAULT 0,
                `hideUsername`              tinyint NOT NULL DEFAULT 0,
                `useCustomLoginTemplate`    varchar(255) NULL,
                `byPassString`              varchar(255) DEFAULT '1',
                `byPassVar`                 varchar(255) DEFAULT 'bypass',
                `enableIdpLogout`           tinyint NOT NULL DEFAULT 0,
                `enforceReAuthAfterIdle`    int NOT NULL DEFAULT -1,                        // Time in minutes that session is allowed to idle before forcing reAuth
                `blockAfterEnfocedLogout`   int NOT NULL DEFAULT -1,                        // Time to block user after he/she was forcefully logged out.
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            $DB->doQuery($query) or die($DB->error());
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        }
    }
    */

    /**
     * Uninstall the LoginState DB table
     * @param   Migration $obj
     * @return  void
     * @since   1.0.0
     */

    /*  //NOSONAR - This is in preparation of version 1.2.0 but should not YET be processed by
        //          the hook.php install
    public static function uninstall(Migration $migration) : void
    {
        $table = LoginState::getTable();
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }
    */
}
