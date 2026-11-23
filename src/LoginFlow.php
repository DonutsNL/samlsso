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
 *  @version    1.4.1
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
use Session;
use Toolbox;
use Throwable;
use OneLogin\Saml2\Auth as samlAuth;
use OneLogin\Saml2\Response;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\LoginState;
use GlpiPlugin\Samlsso\Config\ConfigEntity;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;
use GlpiPlugin\Samlsso\LoginFlow\User;
use GlpiPlugin\Samlsso\LoginFlow\Auth as GlpiAuth;

/**
 * This object brings it all together. It is responsible to handle the
 * main logic concerned with the Saml login and logout flows.
 * it will call upon various supporting objects to perform its tasks.
 */
class LoginFlow
{
    // https://codeberg.org/QuinQuies/glpisaml/issues/37
    public const POSTFIELD           = 'samlIdpId';
    public const GETFIELD            = 'samlIdpId';
    public const SAMLBYPASS          = 'bypass';
    public const BYPASS_HASH_KEY     = 'bypass_hash';
    public const BYPASS_PROOF_PREFIX = 'samlsso_bypass|';
    public const SLOLOGOUT           = 'sloLogout';
    public const LOCALLOGOUT         = 'localLogout';

    /**
     * Holds the state object
     * @var ?LoginState
     */
    protected ?LoginState $state;

    /**
     * Holds the currently active state object for static error handlers to log details.
     * @var ?LoginState
     */
    public static ?LoginState $activeState = null;

    /**
     * Support exception throwing in tests instead of exiting.
     * @var bool
     */
    public static bool $throwOnError = false;

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
        $requestPath = $this->extractRequestPath();
        if ($this->shouldSkipAuthEvaluation($requestPath)) {
            return;
        }

        try {
            $this->state = new LoginState();
            self::$activeState = $this->state;
        } catch (Throwable $e) {
            $this->printError(sprintf(__("Loading login state failed with: %s", PLUGIN_NAME), (string) $e));
        }

        $this->handleTerminalPhases();

        if (\Toolbox::isAjax() || Exclude::isExcluded()) {
            return;
        }

        if ($this->state->getStateId() == -1) {
            $this->state->writeState();
        }

        $isLogout = $this->isLogoutRequest($requestPath);
        if ($isLogout || Session::getLoginUserID() === false) {
            LoginState::expireStaleAcsRequests();
        }

        if (!$isLogout && Session::getLoginUserID() !== false) {
            $this->state->updateLastActivity();
            return;
        }

        if ($this->handleLogoutDispatch($requestPath)) {
            return;
        }

        $this->state->setRedirect();

        if ($this->isImpersonationAttempt()) {
            $this->state->addLoginFlowTrace(['Impersonated someone' => true]);
            return;
        }

        $this->interceptBypass();
        $this->applySelectedOrEnforcedIdp();
    }

    /**
     * Extract the normalized request URI path for route matching.
     *
     * @return string Request path or empty string.
     */
    private function extractRequestPath(): string
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        return is_string($requestPath) ? $requestPath : '';
    }

    /**
     * Determine whether CLI execution, sessionless stateless endpoints, or plugin SAML/SCIM routes
     * must bypass LoginState lookup and enforced SSO evaluation.
     *
     * @param string $requestPath Normalized request URI path.
     * @return bool True when doAuth() must return immediately without touching LoginState.
     */
    private function shouldSkipAuthEvaluation(string $requestPath): bool
    {
        if (function_exists('isCommandLine') && isCommandLine()) {
            return true;
        }

        if (session_id() === '') {
            return true;
        }

        return str_contains($requestPath, SamlSsoController::ACS_ROUTE)
            || str_contains($requestPath, SamlSsoController::SCIM_ROUTE)
            || str_contains($requestPath, SamlSsoController::META_ROUTE);
    }

    /**
     * Determine whether the current request targets local or SAML Single Logout.
     *
     * @param string $requestPath Normalized request path.
     * @return bool True when a logout route or query flag is active.
     */
    private function isLogoutRequest(string $requestPath): bool
    {
        return str_contains($requestPath, SamlSsoController::SLO_ROUTE)
            || isset($_GET[self::SLOLOGOUT])
            || isset($_GET[self::LOCALLOGOUT]);
    }

    /**
     * Enforce admin force-logoff and expired AuthnRequest timeout terminal phases.
     *
     * @return void
     */
    private function handleTerminalPhases(): void
    {
        global $CFG_GLPI;

        $phase = $this->state->getPhase();
        if ($phase === LoginState::PHASE_FORCE_LOG) {
            $this->terminateWithLoginRedirect(
                $CFG_GLPI['url_base'],
                __('You have been forcefully logged out by an administrator.', PLUGIN_NAME)
            );
        }

        if ($phase !== LoginState::PHASE_TIMED_OUT) {
            return;
        }

        $this->state->setPhase(LoginState::PHASE_LOGOFF);
        if (isset($_POST[LoginFlow::POSTFIELD]) || isset($_GET[LoginFlow::GETFIELD])) {
            return;
        }

        Session::cleanOnLogout();
        if (\Toolbox::isAjax()) {
            http_response_code(401);
            exit;
        }
        Session::addMessageAfterRedirect(__('Your SAML authentication request timed out. Please try logging in again.', PLUGIN_NAME), false, ERROR);
        if (!isset($_GET['noAuto']) && !isset($_GET['noAUTO'])) {
            header('Location: ' . $CFG_GLPI['url_base'] . '/index.php?noAuto=1');
            exit;
        }
    }

    /**
     * Transition state to PHASE_LOGOFF, clean GLPI session, and redirect/exit to login page.
     *
     * @param string $urlBase GLPI base URL.
     * @param string $message Error banner message for interactive requests.
     * @return never
     */
    private function terminateWithLoginRedirect(string $urlBase, string $message): void
    {
        $this->state->setPhase(LoginState::PHASE_LOGOFF);
        Session::cleanOnLogout();
        if (\Toolbox::isAjax()) {
            http_response_code(401);
            exit;
        }
        Session::addMessageAfterRedirect($message, false, ERROR);
        header('Location: ' . $urlBase . '/index.php?noAuto=1');
        exit;
    }

    /**
     * Dispatch explicit local logout or IdP Single Logout requests.
     *
     * @param string $requestPath Normalized request path.
     * @return bool True when doAuth() should return without continuing SSO initiation.
     */
    private function handleLogoutDispatch(string $requestPath): bool
    {
        if (isset($_GET[self::LOCALLOGOUT])) {
            $this->state->setPhase(LoginState::PHASE_LOGOFF);
            return true;
        }

        if (isset($_GET[self::SLOLOGOUT])) {
            $this->performSloRedirectLogout();
        }

        if (str_contains($requestPath, SamlSsoController::SLO_ROUTE) && !isset($_GET[self::LOCALLOGOUT])) {
            $this->performLogOff();
            return true;
        }

        return false;
    }

    /**
     * Initiate IdP Single Logout redirect when active config supports SLO, otherwise redirect home.
     *
     * @return never
     */
    private function performSloRedirectLogout(): void
    {
        global $CFG_GLPI;

        $samlConfig = $this->resolveActiveSamlConfig((int) $this->state->getIdpId());
        $nameId = !empty($_SESSION['glpiname']) ? (string) $_SESSION['glpiname'] : null;

        $this->state->setPhase(LoginState::PHASE_LOGOFF);
        Session::cleanOnLogout();

        $sloUrl = $samlConfig !== null
            ? $this->buildIdpSloUrl($samlConfig, $nameId, (string) ($CFG_GLPI['url_base'] ?? '/'))
            : null;

        $targetUrl = !empty($sloUrl) ? $sloUrl : ($CFG_GLPI['url_base'] . '/');
        header('Location: ' . $targetUrl);
        exit;
    }

    /**
     * Resolve php-saml settings array for a valid and active IdP configuration.
     *
     * @param int $idpId IdP configuration ID.
     * @return array|null php-saml configuration array or null when inactive/invalid.
     */
    private function resolveActiveSamlConfig(int $idpId): ?array
    {
        if ($idpId <= 0) {
            return null;
        }
        $configEntity = new ConfigEntity($idpId);
        if (!$configEntity->isValid() || !$configEntity->isActive()) {
            return null;
        }
        $samlConfig = $configEntity->getPhpSamlConfig();
        return !empty($samlConfig) ? $samlConfig : null;
    }

    /**
     * Build a signed SAML LogoutRequest redirect URL from php-saml settings.
     *
     * @param array $samlConfig Active php-saml settings array.
     * @param string|null $nameId Current GLPI username/NameID.
     * @param string $samlBaseReturnTo Post-logout return URL.
     * @return string|null Redirect URL, or null on failure.
     */
    private function buildIdpSloUrl(array $samlConfig, ?string $nameId, string $samlBaseReturnTo): ?string
    {
        $nameIdFormat = isset($samlConfig['sp']['NameIDFormat']) && is_string($samlConfig['sp']['NameIDFormat'])
            ? $samlConfig['sp']['NameIDFormat']
            : null;

        try {
            $samlAuth = new samlAuth($samlConfig);
            return $samlAuth->logout($samlBaseReturnTo, [], $nameId, null, true, $nameIdFormat) ?: null;
        } catch (Throwable $e) {
            Toolbox::logInFile(PLUGIN_NAME . '-errors', 'SLO logout failed: ' . $e->getMessage() . "\n", true);
            return null;
        }
    }

    /**
     * Check whether the current POST request initiates GLPI native user impersonation.
     *
     * @return bool
     */
    private function isImpersonationAttempt(): bool
    {
        return isset($_POST['impersonate']) && $_POST['impersonate'] == '1' && !empty($_POST['id']);
    }

    /**
     * Resolve explicit, query-parameter, or enforced single-IdP selection and launch SAML AuthnRequest.
     *
     * @return void
     */
    private function applySelectedOrEnforcedIdp(): void
    {
        $id = $this->resolveIdpFromLoginForm();
        if ($id) {
            $_POST[LoginFlow::POSTFIELD] = $id;
        }

        if (isset($_GET[LoginFlow::GETFIELD]) && is_numeric($_GET[LoginFlow::GETFIELD])) {
            $this->state->addLoginFlowTrace(['loginViaGetter' => 'getValue:' . $_GET[LoginFlow::GETFIELD]]);
            $_POST[LoginFlow::POSTFIELD] = $_GET[LoginFlow::GETFIELD];
        }

        if ($this->shouldEnforceSingleIdp()) {
            $this->state->addLoginFlowTrace(['OnlyOneIdpEnforced' => 'idpId:' . Config::getIsOnlyOneConfig()]);
            $_POST[LoginFlow::POSTFIELD] = Config::getIsOnlyOneConfig();
        }

        if (isset($_POST[LoginFlow::POSTFIELD]) && is_numeric($_POST[LoginFlow::POSTFIELD])) {
            $selectedIdpId = (int) $_POST[LoginFlow::POSTFIELD];
            if ($selectedIdpId > 0 && $selectedIdpId <= 999) {
                $this->state->addLoginFlowTrace(['finalIdp' => 'idpId:' . $selectedIdpId]);
                $this->state->setIdpId($selectedIdpId);
                $this->performSamlIdpRequest();
            }
        }
    }

    /**
     * Determine whether single-IdP automatic enforcement should select the sole active IdP.
     *
     * @return bool
     */
    private function shouldEnforceSingleIdp(): bool
    {
        $phase = $this->state->getPhase();
        $validPhase = $phase == LoginState::PHASE_INITIAL || $phase == LoginState::PHASE_LOGOFF;
        return $validPhase
            && Config::getIsOnlyOneConfig()
            && Config::getIsEnforced()
            && !isset($_GET['noAuto'])
            && !isset($_GET['noAUTO'])
            && !self::isSessionBypassActive();
    }

    /**
     * Resolve the server-side emergency bypass secret from constant or environment.
     *
     * @return string Configured secret token, or empty string when unset.
     */
    public static function resolveBypassToken(): string
    {
        return defined('PLUGIN_SAMLSSO_BYPASS_TOKEN')
            ? (string) PLUGIN_SAMLSSO_BYPASS_TOKEN
            : (string) getenv('SAMLSSO_BYPASS_TOKEN');
    }

    /**
     * Compute a session-bound HMAC-SHA256 bypass proof so the raw secret is never stored in $_SESSION.
     *
     * @param string $requiredToken Server-side configured bypass secret.
     * @return string Hex-encoded HMAC-SHA256 digest bound to the active PHP session ID.
     */
    public static function computeBypassProof(string $requiredToken): string
    {
        return hash_hmac('sha256', self::BYPASS_PROOF_PREFIX . session_id(), $requiredToken);
    }

    /**
     * Verify whether the current PHP session holds a valid, server-verified emergency bypass proof.
     *
     * @return bool True when bypass is active and matches the current server bypass secret.
     */
    public static function hasValidBypassSession(): bool
    {
        if (empty($_SESSION['glpi_plugins']['samlsso'][self::SAMLBYPASS])) {
            return false;
        }
        $requiredToken = self::resolveBypassToken();
        $sessionHash = (string) ($_SESSION['glpi_plugins']['samlsso'][self::BYPASS_HASH_KEY] ?? '');
        return $requiredToken !== ''
            && $sessionHash !== ''
            && hash_equals(self::computeBypassProof($requiredToken), $sessionHash);
    }

    /**
     * Checks whether an administrator emergency bypass token has been cryptographically
     * verified and recorded in the active PHP session.
     *
     * @return bool
     */
    private static function isSessionBypassActive(): bool
    {
        return self::hasValidBypassSession();
    }

    /**
     * Intercept and handle bypass SAML authentication requests.
     *
     * @return bool True if bypass was triggered
     */
    private function interceptBypass(): bool
    {
        global $CFG_GLPI;

        $bypassRequested = (isset($_GET[LoginFlow::SAMLBYPASS]) && $_GET[LoginFlow::SAMLBYPASS] == 1);
        $noAutoRequested = isset($_GET['noAuto']);
        $noAutoUpperSet = isset($_GET['noAUTO']);

        if ($bypassRequested || $noAutoRequested) {
            $requiredToken = self::resolveBypassToken();
            $providedToken = $_GET['token'] ?? $_GET['bypass_token'] ?? null;

            if ($requiredToken === '' || $providedToken === null || !hash_equals($requiredToken, (string)$providedToken)) {
                return false;
            }

            $_SESSION['glpi_plugins']['samlsso'][self::SAMLBYPASS] = true;
            $_SESSION['glpi_plugins']['samlsso'][self::BYPASS_HASH_KEY] = self::computeBypassProof($requiredToken);

            if (!$bypassRequested || !$noAutoUpperSet) {
                $this->state->addLoginFlowTrace(['bypassUsed' => true]);
                $url = $CFG_GLPI['url_base'] . '/?' . LoginFlow::SAMLBYPASS . '=1&noAUTO=1';
                header('Location:' . $url);
                exit();
            }
            return true;
        }
        return false;
    }

    /**
     * Parse the login form parameters to see if IDP matching the domain is configured.
     *
     * @return int|null Resolved IDP configuration ID, or null
     */
    private function resolveIdpFromLoginForm(): ?int
    {
        foreach ($_POST as $key => $value) {
            if (str_contains((string)$key, 'login_name') && !empty($_POST[$key])) {
                $id = Config::getConfigIdByEmailDomain($_POST[$key]);
                if ($id) {
                    $this->state->addLoginFlowTrace(['loginViaUserfield' => 'user:' . $_POST[$key] . ',idpId:' . $id]);
                    return (int)$id;
                }
            }
        }
        return null;
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

        $samlConfig = [];
        $auth = null;
        $ssoBuiltUrl = '';

        // Fetch the correct configEntity GLPI
        $configEntity = new ConfigEntity($this->state->getIdpId());
        $samlConfig = $configEntity->getPhpSamlConfig();

        // Validate if the IDP configuration is enabled
        // https://codeberg.org/QuinQuies/glpisaml/issues/4
        if ($configEntity->isActive()) {                            // Validate the IdP config is activated

            // Initialize the OneLogin phpSaml auth object
            // using the requested phpSaml configuration from
            // the samlsso config database. Catch all throwable
            // errors and exceptions.
            try {
                $auth = new samlAuth($samlConfig);
            } catch (Throwable $e) {
                $safeConfig = $samlConfig;
                if (isset($safeConfig['sp']['privateKey'])) {
                    $safeConfig['sp']['privateKey'] = '***REDACTED***';
                }
                $this->printError($e->getMessage(), 'Saml::Auth->init', var_export($safeConfig, true));
            }

            // Added version 1.2.0
            // Capture and register requestId in database
            // before performing the redirect so we don't need Cookies
            $forceAuthn = false;
            if ($configEntity !== null) {
                $forceAuthn = (bool) $configEntity->getField(ConfigEntity::FORCE_AUTHN);
            }

            try {
                $ssoBuiltUrl = $auth->login(
                    $CFG_GLPI['url_base'] ?? '/',
                    array(),
                    $forceAuthn,
                    false,
                    true,
                    true,
                    null
                );
            } catch (Throwable $e) {
                $this->printError($e->getMessage(), 'Saml::Auth->init', var_export($auth->getErrors(), true));
            }

            if ($auth !== null) {
                $this->state->setRequestId($auth->getLastRequestID());
            }

            $this->state->refreshLoginDateTime();
            if (!$this->state->setPhase(LoginState::PHASE_SAML_ACS)) {
                $this->printError(sprintf(__('Could not update the loginState and therefor stopped the loginFlow for: %s', PLUGIN_NAME), (string)($_POST[LoginFlow::POSTFIELD] ?? $this->state->getIdpId())));
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
        global $CFG_GLPI;
        $auth = null;

        // Push the state into this objects property just in case.
        $this->state = $state;

        // Validate samlResponse and extract provided attributes (saml claims).
        // This validation will print and exit(!) on errors because user information is mandatory
        // after this step.
        $userFields = User::getUserInputFieldsFromSamlClaim($response, $state->getIdpId());

        // Resolve the ConfigEntity to propagate down the authentication chain
        $configEntity = ($this instanceof \GlpiPlugin\Samlsso\LoginFlow\Acs) ? $this->configEntity : new ConfigEntity($state->getIdpId());

        // Try to populate GLPI Auth using the fetched samlResponse attributes;
        try {
            $auth = (new GlpiAuth())->loadUser($userFields, $configEntity, $state);
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

        if ($auth !== null) {
            if (!empty($auth->getErrors())) {
                LoginFlow::PrintFatalLoginError(implode("<br />", $auth->getErrors()));
            }

            Session::init($auth);
        }

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

        // Make sure we are looking at a logged in session
        // before showing this.
        if (!array_key_exists('glpiID', $_SESSION) || empty($_SESSION["glpiID"])) {
            // Ignore the call.
            return;
        }

        // Update flowtrace field.
        $this->state->addLoginFlowTrace(['logoutPressed' => true]);

        // Get IdpConfiguration if any and figure out if we
        // need to handle some sort of logout at the IDP or
        // just ignore the logout request and let GLPI handle it.
        $configEntity = new ConfigEntity($this->state->getIdpId());         // Get the correct IDP configuration using the state.

        // If the session is samlAuthed and the SLO url was configured
        // capture the logout and present an option to logout with the
        // IDP in addition to logging out of GLPI.
        if (
            $configEntity->getField(ConfigEntity::IDP_SLO_URL)  &&
            $this->state->isSamlAuthed()
        ) {

            $isEnforced = (bool) $configEntity->getField(ConfigEntity::ENFORCE_SSO);
            $idpName = htmlentities((string) $configEntity->getField(ConfigEntity::NAME));

            $returnLabel = $isEnforced ? __('Go back to GLPI', PLUGIN_NAME) : __('Continue with local logout', PLUGIN_NAME);
            $sloLabel    = __('Log out everywhere', PLUGIN_NAME);

            if ($isEnforced) {
                $infoText = sprintf(
                    __('You have been authenticated using the SAML2 identity provider: <b>%1$s</b>.<br><br>Because SAML authentication is enforced, logging out of GLPI locally will automatically log you back in immediately.<br><br>To sign out completely, please select <b>\'%2$s\'</b>. Note that this will also terminate your active sessions in all other applications connected to this Identity Provider.<br><br>If you want to leave GLPI, just close this browser tab.', PLUGIN_NAME),
                    $idpName,
                    $sloLabel
                );
            } else {
                $infoText = sprintf(
                    __('You have been authenticated using the SAML2 identity provider: <b>%1$s</b>.<br><br>You can log out permanently by also logging out with the IDP by pressing <b>\'%2$s\'</b> (which terminates active sessions in all other applications depending on it), or perform a local logout from GLPI only by pressing <b>\'%3$s\'</b>.', PLUGIN_NAME),
                    $idpName,
                    $sloLabel,
                    $returnLabel
                );
            }

            // Define static translatable elements
            $tplVars = [
                'header'        => __('🤔 How would you like to proceed?', PLUGIN_NAME),
                'idpName'       => $configEntity->getField(ConfigEntity::NAME),
                'infoText'      => $infoText,
                'returnLabel'   => $returnLabel,
                'returnPath'    => $isEnforced ? $CFG_GLPI["url_base"] . '/' : $CFG_GLPI["url_base"] . '/front/logout.php?' . self::LOCALLOGOUT . '=1&noAUTO=1',
                'closeLabel'    => __('Close tab', PLUGIN_NAME),
                'sloLabel'      => $sloLabel,
                'sloPath'       =>  $CFG_GLPI["url_base"] . '/front/logout.php?' . self::SLOLOGOUT . '=1',
                'isEnforced'    => $isEnforced,
            ];

            // print GLPI headers
            Html::nullHeader("SamlSSO Logout catcher", '/');
            // Render twig template
            // https://codeberg.org/QuinQuies/glpisaml/issues/12
            echo TemplateRenderer::getInstance()->render('@samlsso/logout.html.twig',  $tplVars);
            // print nullFooter
            Html::nullFooter();

            // Note: GLPI session is NOT cleared here; it will be cleared once the user selects localLogout or sloLogout.
            exit;
        } else {
            Session::cleanOnLogout();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }
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
        $bypassActive = self::isSessionBypassActive();

        // Hide local GLPI password/auth fields only when bypass is NOT active in session
        if (!$bypassActive && Config::getHideLoginFields()) {
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

        $isEnforced = !$bypassActive && Config::getIsEnforced();
        $tplVars = Config::getLoginButtons(12);
        if (!empty($tplVars)) {
            $tplVars['action']      = rtrim(samlsso_webdir(true), '/');
            $tplVars['header']      = !empty($tplVars['header']) ? $tplVars['header'] : __('Login with external provider', PLUGIN_NAME);
            $tplVars['showbuttons'] = true;
            $tplVars['postfield']   = LoginFlow::POSTFIELD;
            $tplVars['enforced']    = $isEnforced;
            // https://codeberg.org/QuinQuies/glpisaml/issues/12
            TemplateRenderer::getInstance()->display('@samlsso/loginScreen.html.twig',  $tplVars);
        } elseif ($isEnforced) {
            TemplateRenderer::getInstance()->display('@samlsso/loginScreen.html.twig', [
                'showbuttons' => false,
                'enforced'    => true,
            ]);
        }
    }

    // ERROR HANDLING
    /**
     * Records an authentication error in the active LoginState (if not already
     * authenticated in GLPI) and resolves whether debug output is enabled for the IdP.
     *
     * @param string $traceKey     Trace key label ('Fatal Error' or 'Error').
     * @param string $traceMessage Detailed message to append to the login flow trace.
     * @return bool                True when debug mode is enabled on the active IdP.
     */
    private static function recordErrorInState(string $traceKey, string $traceMessage): bool
    {
        $debug = false;
        try {
            $state = self::$activeState;
            if ($state === null || $state->getStateId() <= 0) {
                $state = new LoginState();
            }
            if ($state->getStateId() > 0) {
                if ($state->getPhase() !== LoginState::PHASE_GLPI_AUTH) {
                    $state->addLoginFlowTrace([$traceKey => $traceMessage]);
                    $state->setPhase(LoginState::PHASE_ERROR);
                }
                if ($state->getIdpId() > 0) {
                    $configEntity = new ConfigEntity($state->getIdpId());
                    $debug = (bool)$configEntity->getField(ConfigEntity::DEBUG);
                }
            }
        } catch (\Throwable $t) {
            // Ignore state persistence failures during error rendering
        }

        return $debug;
    }

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

        /* Log in file */
        Toolbox::logInFile(PLUGIN_NAME . "-errors", 'FATAL SAML LOGIN ERROR:' . $errorMsg . "\n", true);

        if (self::$throwOnError) {
            throw new \Exception((string)$errorMsg);
        }

        $debug = self::recordErrorInState('Fatal Error', (string)$errorMsg);

        $displayMsg = $debug ? (string)$errorMsg : __('Something went wrong during authentication assertion and prevents you from logging in. Please contact your GLPI administrator for more information and support.', PLUGIN_NAME);

        /* Define static translatable elements */
        $tplVars['header']      = __('⚠️ Sorry we are unable to log you in', PLUGIN_NAME);
        /* https://github.com/DonutsNL/samlsso/issues/21 */
        $tplVars['error']       = (string)$displayMsg;
        $tplVars['returnPath']  = $CFG_GLPI["url_base"] . '/';
        $tplVars['returnLabel'] = __('Return to GLPI', PLUGIN_NAME);

        // print header
        http_response_code(403); // AccessDeniedHttpException
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

        /* Log in file */
        Toolbox::logInFile(PLUGIN_NAME . "-errors", $errorMsg . "\n", true);
        if ($extended) {
            Toolbox::logInFile(PLUGIN_NAME . "-errors", $extended . "\n", true);
        }

        if (self::$throwOnError) {
            throw new \Exception($errorMsg);
        }

        $debug = self::recordErrorInState('Error', $errorMsg . ($extended ? ": " . $extended : ""));

        $displayMsg = $debug ? $errorMsg : __('Something went wrong during authentication assertion and prevents you from logging in. Please contact your GLPI administrator for more information and support.', PLUGIN_NAME);

        /* Define static translatable elements */
        $tplVars['header']      = __('⚠️ An error occurred', PLUGIN_NAME);
        $tplVars['leading']     = __("We are sorry, something went wrong while processing your request!", PLUGIN_NAME);
        $tplVars['error']       = $displayMsg;
        $tplVars['returnPath']  = $CFG_GLPI["url_base"] . '/';
        $tplVars['returnLabel'] = __('Return to GLPI', PLUGIN_NAME);
        // print header
        http_response_code(400); // BadRequestHttpException
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
        $state = null;
        try {
            $state = new LoginState();
        } catch (Throwable $e) {
            LoginFlow::printError(sprintf(__("Loading login state failed with: %s", PLUGIN_NAME), (string)$e));
        }

        $safeRedirect = $state !== null ? $state->getSafeRedirect() : '';
        if (!empty($safeRedirect)) {
            if (str_starts_with($safeRedirect, '/')) {
                $url = rtrim($CFG_GLPI['url_base'], '/') . $safeRedirect;
            } else {
                $url = rtrim($CFG_GLPI['url_base'], '/') . '/?redirect=' . rawurlencode($safeRedirect);
            }
        } else {
            $url = $CFG_GLPI['url_base'];
        }

        printf(
            '<!DOCTYPE html>
                <html>
                    <head>
                        <meta charset="UTF-8" />
                        <meta http-equiv="refresh" content="0;url=\'%1$s\'" />

                        <title>%2$s</title>
                    </head>
                    <body>&nbsp;</body>
                </html>',
            \htmlescape($url),
            'Auth succesfull'
        );
        exit;
    }
}
