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
 *  @version    1.2.1
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Config;


use Html;
use Search;
use Session;
use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\LoginState;
use Glpi\Application\View\TemplateRenderer;
use OneLogin\Saml2\Constants as Saml2Const;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class Handles the Configuration front/config.form.php Form
 */
class ConfigForm    //NOSONAR complexity by design.
{
    /**
     * Handles the form calls from the ConfigController and loads the
     * config idps listing (first screen before selecting a specific config)
     *
     * @param array     $postData $_POST data from form
     * @return string   String containing HTML form with values or redirect into added form.
     */
    public function invoke(Request $request){
        $this->displayUIHeader();
        Search::show(Config::class);
    }

    /**
     * Handles the form calls from the ConfigFormController and loads the
     * configuration item requested from the listing.
     *
     * @param array     $postData $_POST data from form
     * @return Response|RedirectResponse   String containing HTML form with values or redirect into added form.
     */
    public function invokeForm(Request $request): Response|RedirectResponse
    {
        $inputBag = $request->getPayload();                                     // Assign the inputBag
        $id = !empty($request->get('id')) ? (int) $request->get('id') : -1;     // Assign the ID if any
        $options['template'] = ( $request->get('template') &&                   // iF set URI?template={template}
                                 ctype_alpha($request->get('update')) ) ?       // iF template only contains alpha txt
                                 $request->get('update') :                      // THEN set it to requested template
                                 'default';                                     // Else fallback to default.
                                 
        // Add using template
        if( !$inputBag->has('update')     &&
            !$inputBag->has('delete')     ){                                    // IF the update is empy load a given template for initial form.

            $this->displayUIHeader();
            return $this->showForm($id, $options);                              // Return the form
    
        // Add new item
        }elseif($inputBag->has('update')  &&                                    // IF we received an update
                $id == -1                 ){                                    // AND ID param is empty
            $this->displayUIHeader();
            return $this->addSamlConfig($inputBag->getIterator());              // Call Create handler

        // Update an item
        }elseif($inputBag->has('update')  &&                                    // IF update is set
                $id > 0                   ){                                    // AND $id is higher than 0
            return $this->updateSamlConfig($inputBag->getIterator());

        // Delete an item
        }elseif($inputBag->has('delete')  &&                                    // IF get delete
                $id > 0                   ){                                    // AND $id is higer then 0
           return $this->deleteSamlConfig($inputBag->getIterator());
        }else{
            $this->displayUIHeader();
            return new Response('No valid instructions received');
        }
    }


    /**
     * Populates the GLPI headers (UI)
     * Might cause headers to be send prematurely as Html::header contains echo operations.
     *
     * @param void
     * @return void
     */
    private function displayUIHeader()
    {
        Html::header(Config::getTypeName().' entities',                         // Title for browser tab
                     SamlSsoController::CONFIG_ROUTE,
                     SamlSsoController::CONFIG_PNAME,
                     Config::class);
    }


    /**
     * Add new phpSaml configuration
     *
     * @param array     $postData $_POST data from form
     * @return          Response|RedirectResponse          String containing HTML form with values or redirect into added form.
     */
    public function addSamlConfig(\ArrayIterator $postData): Response|RedirectResponse
    {
        // Populate configEntity using post;
        $configEntity = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postData]);
        // Validate configEntity
        if($configEntity->isValid()){
            // Get the normalized database fields
            $fields = $configEntity->getDBFields([ConfigEntity::ID, ConfigEntity::CREATE_DATE, ConfigEntity::MOD_DATE]);
            // Get instance of SamlConfig for db update.
            $config = new Config();
            // Perform database insert using db fields.
            if($id = $config->add($fields)) {
                // Leave succes message for user and redirect
                Session::addMessageAfterRedirect(__('Successfully added new samlSSO configuration.', PLUGIN_NAME));
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$id);
            } else {
                // Leave error message for user and regenerate form with values
                Session::addMessageAfterRedirect(__('Unable to add new samlSSO configuration, please review error logging', PLUGIN_NAME));
                return new Response( $this->generateForm($configEntity) );
            }
        }else{
            // Leave error message for user and regenerate form with values
            Session::addMessageAfterRedirect(__('Configuration invalid, please correct all ⭕ errors first', PLUGIN_NAME));
            return new Response( $this->generateForm($configEntity) );
        }
    }

    /**
     * Update phpSaml configuration
     *
     * @param int   $id of configuration to update
     * @param array $postData $_POST data from form
     * @return      Response|RedirectResponse -
     */
    public function updateSamlConfig(\ArrayIterator $postData): Response|RedirectResponse
    {
        // Populate configEntity using post;
        $configEntity = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postData]);
        // Validate configEntity
        if($configEntity->isValid()){
            // Get the normalized database fields
            $fields = $configEntity->getDBFields([ConfigEntity::CREATE_DATE, ConfigEntity::IS_DELETED]);
            // Add the cross site request forgery token to the fields
            $fields['_glpi_csrf_token'] = $postData['_glpi_csrf_token'];
            // Get instance of SamlConfig for db update.
            $config = new Config();
            // Perform database update using fields.
            if($config->canUpdate()       &&
               $config->update($fields) ){
                // Leave a success message for the user and redirect using ID.
                Session::addMessageAfterRedirect(__('Configuration updated successfully', PLUGIN_NAME));
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$postData['id']);
            } else {
                // Leave a failed message
                Session::addMessageAfterRedirect(__('Configuration update failed, check your update rights or error logging', PLUGIN_NAME));
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$postData['id']);
            }
        }else{
            // Leave an error message and reload the form with provided values and errors
            Session::addMessageAfterRedirect(__('Configuration invalid please correct all ⭕ errors first', PLUGIN_NAME));
            $this->displayUIHeader();
            return new Response($this->generateForm($configEntity));
        }
    }

    /**
     * Add new phpSaml configuration
     *
     * @param array $postData $_POST data from form
     * @return      Response|RedirectResponse
     */
    public function deleteSamlConfig(\ArrayIterator $postData): RedirectResponse
    {
        // Get SamlConfig object for deletion
        $config = new Config();
        // Validate user has the rights to delete then delete
        if($config->canPurge()  &&
           $config->delete((array) $postData)){
            // Leave success message and redirect
            Session::addMessageAfterRedirect(__('Configuration deleted successfully', PLUGIN_NAME));
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIG_ROUTE);
        } else {
            // Leave fail message and redirect back to config.
            Session::addMessageAfterRedirect(__('Not allowed or error deleting SAML configuration!', PLUGIN_NAME));
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIG_ROUTE.'?id='.$postData['id']);
        }
    }

    /**
     * Figures out what form to show
     *
     * @param  integer  $id       ID the configuration item to show
     * @param  array    $options  Options
     * @return Response
     */
    public function showForm(int $id, array $options = []): Response|RedirectResponse
    {
        if($id === -1 || $id > 0){
            // Generate form using a template
            return new Response($this->generateForm(new ConfigEntity($id, $options)));
        }else{
            // Invalid id used redirect back to origin
            return new RedirectResponse(__('Invalid request, redirecting back', PLUGIN_NAME));
        }
    }

     /**
     * Figure out if there are errors in one of the tabs and displays a
     * warning sign if an error is found
     *
     * @param array $fields     from ConfigEntity->getFields()
     */
    private function getTabWarnings(array $fields): array
    {
        // What fields are in what tab
        $tabFields = ['general_warning'     => [configEntity::NAME,
                                                configEntity::CONF_DOMAIN,
                                                configEntity::CONF_ICON,
                                                configEntity::COMMENT,
                                                configEntity::IS_ACTIVE,
                                                configEntity::DEBUG],
                      'transit_warning'     => [configEntity::COMPRESS_REQ,
                                                configEntity::COMPRESS_RES,
                                                configEntity::PROXIED,
                                                configEntity::XML_VALIDATION,
                                                configEntity::DEST_VALIDATION,
                                                configEntity::LOWERCASE_URL],
                      'provider_warning'    => [configEntity::SP_CERTIFICATE,
                                                configEntity::SP_KEY,
                                                configEntity::SP_NAME_FORMAT],
                      'idp_warning'         => [configEntity::IDP_ENTITY_ID,
                                                configEntity::IDP_SSO_URL,
                                                configEntity::IDP_SLO_URL,
                                                configEntity::IDP_CERTIFICATE,
                                                configEntity::AUTHN_CONTEXT,
                                                configEntity::AUTHN_COMPARE],
                      'security_warning'    => [configEntity::ENFORCE_SSO,
                                                configEntity::STRICT,
                                                configEntity::USER_JIT,
                                                configEntity::ENCRYPT_NAMEID,
                                                configEntity::SIGN_AUTHN,
                                                configEntity::SIGN_SLO_REQ,
                                                configEntity::SIGN_SLO_RES]];
        // Parse config fields
        // https://github.com/DonutsNL/samlsso/issues/27
        // Make sure all tabs are present for twig.
        $warnings = [];
        foreach($tabFields as $tab => $entityFields){
            foreach($entityFields as $field) {
                if(!empty($fields[$field]['errors'])){
                    $warnings[$tab] = '⚠️';
                }else{
                    $warnings[$tab] = '';
                }
                // Add cert validation warnings
                if(!empty($fields[$field]['validate']['validations']['validTo'])   ||
                   !empty($fields[$field]['validate']['validations']['validFrom']) ){
                    $warnings[$tab] = '⚠️';
                }else{
                    $warnings[$tab] = '';
                }
            }
        }
        // Return warnings if any.
        return $warnings;
    }

    /**
     * Generates the HTML for the config form using the GLPI
     * template renderer.
     *
     * @param ConfigEntity $configEntity    Field values to populate in form
     * @return string ConfigForm            HTML
     * @since                               1.0.0
     * @see https://codeberg.org/QuinQuies/glpisaml/issues/17
     */
    private function generateForm(ConfigEntity $configEntity)
    {
        global $CFG_GLPI;
        $fields = $configEntity->getFields();
        // Get warnings tabs
        $tplVars  = [];
        $tplVars = array_merge($tplVars, $this->getTabWarnings($fields));
       
        // Get AuthN context as array
        $fields[ConfigEntity::AUTHN_CONTEXT][ConfigItem::VALUE] = $configEntity->getRequestedAuthnContextArray();

        // get the logging entries, but only if the object already exists
        // https://codeberg.org/QuinQuies/glpisaml/issues/15#issuecomment-1785284
        if(is_numeric($fields[ConfigEntity::ID]['value'])){
            $logging = LoginState::getLoggingEntries((int) $fields[ConfigEntity::ID]['value']);
        }else{
            $logging = [];
        }
        
        // Define static field translations
        $tplVars = array_merge($tplVars, [
            'plugin'                    =>  PLUGIN_NAME,
            'close_form'                =>  Html::closeForm(false),
            'glpi_rootdoc'              =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'glpi_tpl_macro'            =>  '/components/form/fields_macros.html.twig',
            'inputfields'               =>  $fields,
            'buttonsHiddenWarn'         =>  ($configEntity->getConfigDomain()) ? true : false,
            'loggingfields'             =>  $logging,
            'entityID'                  =>  $CFG_GLPI['url_base'].'/',
            'acsUrl'                    =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::ACS_ROUTE.'/'.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'metaUrl'                   =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::META_ROUTE.'/'.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'inputOptionsBool'          =>  [ 1                                 => __('Yes', PLUGIN_NAME),
                                              0                                 => __('No', PLUGIN_NAME)],
            'inputOptionsNameFormat'    =>  [Saml2Const::NAMEID_UNSPECIFIED     => __('Unspecified', PLUGIN_NAME),
                                             Saml2Const::NAMEID_EMAIL_ADDRESS   => __('Email Address', PLUGIN_NAME),
                                             Saml2Const::NAMEID_TRANSIENT       => __('Transient', PLUGIN_NAME),
                                             Saml2Const::NAMEID_PERSISTENT      => __('Persistent', PLUGIN_NAME)],
            'inputOptionsAuthnContext'  =>  ['PasswordProtectedTransport'   => __('PasswordProtectedTransport', PLUGIN_NAME),
                                             'Password'                     => __('Password', PLUGIN_NAME),
                                             'X509'                         => __('X509', PLUGIN_NAME),
                                             'none'                         => __('none', PLUGIN_NAME)],
            'inputOptionsAuthnCompare'  =>  ['exact'                        => __('Exact', PLUGIN_NAME),
                                             'minimum'                      => __('Minimum', PLUGIN_NAME),
                                             'maximum'                      => __('Maximum', PLUGIN_NAME),
                                             'better'                       => __('Better', PLUGIN_NAME)],
        ]);

        // https://codeberg.org/QuinQuies/glpisaml/issues/12
        return TemplateRenderer::getInstance()->render('@samlsso/configForm.html.twig',  $tplVars);
    }
}
