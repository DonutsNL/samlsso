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
 *  @since      1.2.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Controller;

use Glpi\Http\Firewall;                                                 // Required to allow anonymous access to ACS route
use Glpi\Controller\AbstractController;                                 // The controller 
use Glpi\Security\Attribute\SecurityStrategy;                           // Required to decorate the invoke
use Glpi\Security\Attribute\DisableCsrfChecks;                          // Required to disable the Csrf checks
use Symfony\Component\HttpFoundation\Request;                           // Required for __invoke
use Symfony\Component\HttpFoundation\Response;                          // Required for __invoke
use Symfony\Component\Routing\Attribute\Route;                          // Required to register controller route
use GlpiPlugin\Samlsso\LoginFlow\Acs;                                   // Required to call the ACS object
use GlpiPlugin\Samlsso\LoginFlow\Meta;                                  // Required to call Exclude object
use GlpiPlugin\Samlsso\Config\ConfigForm;                               // Required to call Config object
use GlpiPlugin\Samlsso\Exclude;                                         // Required to call Exclude object
use GlpiPlugin\Samlsso\RuleSaml;                                        // Required to call Rules object
use GlpiPlugin\Samlsso\LoginState;                                      //
use GlpiPlugin\Samlsso\LoginFlow\LoginFlowForm;                         //




final class SamlSsoController extends AbstractController
{
    ####################################################################
    // ACS route
    public const ACS_ROUTE      = 'front/acs';                          // Route being registered by __class__
    public const ACS_PARAM      = '/{'.LoginState::IDP_ID.'}';
    public const ACS_NAME       = 'samlsso_ACS';                        // Route name

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::ACS_ROUTE.self::ACS_PARAM, name: self::ACS_NAME)]     // Decorator to register route to controller
    public function acs(Request $request): Response                     // What to do if route is invoked.
    {
        return new Response((new Acs)->init($request));                 // Call the ACS handler.
    }


    ####################################################################
    // SLO route
    public const SLO_ROUTE      = 'front/slo';                          // Route being registered by __class__
    public const SLO_NAME       = 'samlsso_SLO';                        // Route name
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    public function slo(Request $request): Response                     // What to do if route is invoked.
    {
        global $CFG_GLPI;
        return new Response('', 307, ['location' => $CFG_GLPI['url_base'].'/']);    // Redirect back to application root.
    }


    ####################################################################
    // Meta route
    public const META_ROUTE     = 'front/meta';                         // Route being registered by __class__
    public const META_PARAM     = '/{idpId}';
    public const META_NAME      = 'samlsso_META';                       // Route name

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::META_ROUTE.self::META_PARAM, name: self::META_NAME)]  // Decorator to register route to controller
    public function meta(Request $request): Response                    // What to do if route is invoked.
    {
        return (new Meta)->getSPMeta($request);                         // Call the SPMeta handler.
    }


    ####################################################################
    // Config routes
    public const CONFIG_FILE     = 'front/config.php';                  // Register old route as well
    public const CONFIG_ROUTE    = 'front/config';                      // Route being registered by __class__
    public const CONFIG_NAME     = 'configMain';                        // Route name
    public const CONFIG_PNAME    = 'config';                            // Parent object name

    //#[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::CONFIG_ROUTE, name: self::CONFIG_NAME)]               // Decorator to register route to controller
    #[Route(self::CONFIG_FILE, name: self::CONFIG_NAME.'_file')]        // Decorator to register old route to handle GLPI generated menu's
    public function config(Request $request): Response                  // What to do if route is invoked.
    {
        return new Response((new ConfigForm)->invoke($request));        // Call the form handler.
    }

    // ConfigForm routes
    public const CONFIGFORM_FILE = 'front/config.form.php';             // Register old route as well
    public const CONFIGFORM_ROUTE= 'front/configform';                 // Route being registered by __class__
    public const CONFIGFORM_NAME = 'configForm';                        // Route name
    public const CONFIGFORM_PNAME= 'config';                            // Parent object name

    //#[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                  // Decorator to disable authentication check
    #[Route(self::CONFIGFORM_ROUTE, name: self::CONFIGFORM_NAME)]       // Decorator to register route to controller
    #[Route(self::CONFIGFORM_FILE, name: self::CONFIGFORM_NAME.'_file')]// Decorator to register old route to handle GLPI generated menu's
    public function configform(Request $request): Response
    {
        return new Response((new ConfigForm)->invokeForm($request));    // Call the form handler.
    }


    ####################################################################
    // LoginFlowConfig
    public const FLOWFORM_FILE = 'front/loginflow.form.php';
    public const FLOWFORM_ROUTE= 'front/flowconfig';                    // Route being registered by __class__
    public const FLOWFORM_NAME = 'flowMain';                            // Route name
    public const FLOWFORM_PNAME= 'config';                              // Parent object name
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::FLOWFORM_ROUTE, name: self::FLOWFORM_NAME)]           // Decorator to register route to controller
    #[Route(self::FLOWFORM_FILE, name: self::FLOWFORM_NAME.'_file')]    // Decorator to register old route to handle GLPI generated menu's
    public function loginflow(Request $request): Response               // What to do if route is invoked.
    {
        return new Response((new LoginFlowForm)->init($request));       // Call the ACS handler.
    }


    ####################################################################
    // Exclude routes
    public const EXCLUDE_ROUTE = 'front/exclude';                       // Route being registered by __class__
    public const EXCLUDE_NAME  = 'excludeMain';                         // Route name
    public const EXCLUDE_PNAME = 'config';                              // Parent object name
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::EXCLUDE_ROUTE, name: self::EXCLUDE_NAME)]             // Decorator to register route to controller
    public function exclude(): Response                                 // What to do if route is invoked.
    {
        return new Response((new Exclude)->invoke());                   // Call the ACS handler.
    }


    ####################################################################
    // Rules routes
    public const RULES_FILE     = 'front/rulesaml.php';                 // Register all route as well because these might be autogenerated
    public const RULES_ROUTE    = 'front/rule';                         // Route being registered by __class__
    public const RULES_NAME     = 'ruleMain';                           // Route name
    public const RULES_PNAME    = 'config';                             // Parent object name
    public const RULESFORM_FILE = 'front/rulesaml.form.php';            // Register old route as well because paths are auto generated
    public const RULESFORM_ROUTE= 'front/ruleForm';                     // Route being registered by __class__
    public const RULESFORM_NAME = 'ruleForm';                           // Route name
    public const RULESFORM_PNAME= 'config';                             // Parent object name


    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::RULES_ROUTE, name: self::RULES_NAME)]                 // Decorator to register route to controller
    #[Route(self::RULES_FILE, name: self::RULES_NAME.'_file')]          // Decorator to register route to controller
    public function __invoke(Request $request): Response                // What to do if route is invoked.
    {
        return new Response((new RuleSaml)->invoke());                  // Call the ACS handler.
    }

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]                    // Decorator to disable authentication check
    #[Route(self::RULESFORM_ROUTE, name: self::RULESFORM_NAME)]         // Decorator to register route to controller
    #[Route(self::RULESFORM_FILE, name: self::RULESFORM_NAME.'_file')]  // Decorator to register route to controller
    public function itemform(Request $request): Response                // What to do if route is invoked.
    {
        return new Response((new RuleSaml)->invokeForm());              // Call the ACS handler.
    }

}
