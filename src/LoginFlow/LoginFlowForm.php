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
 *  @version    1.2.3
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\LoginFlow;

use Html;
use Search;
use Session;
use GlpiPlugin\Samlsso\LoginFlow;
use GlpiPlugin\Samlsso\LoginState;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;
use OneLogin\Saml2\Constants as Saml2Const;


/**
 * Class Handles the Configuration front/config.form.php Form
 */
class LoginFlowForm    //NOSONAR complexity by design.
{
    /**
     * Called by the controller to load the
     * configFlow list (top lvl).
     */
    public function init(): void
    {
        Html::header(__('Identity providers'),
                     SamlSsoController::FLOWFORM_ROUTE,
                     SamlSsoController::FLOWFORM_PNAME,
                     LoginFlow::class);
        Search::show(LoginFlow::class);
    }


    /**
     * Update phpSaml configuration
     *
     * @param int   $id of configuration to update
     * @param array $postData $_POST data from form
     * @return void -
     */
    public function updateSamlConfig(array $postData): string
    {
        // Populate configEntity using post;
        $loginFlowEntity = new LoginFlowEntity(1, ['template' => 'post', 'postData' => $postData]);
        // Validate configEntity
        if($loginFlowEntity->isValid()){
            // Get the normalized database fields
            $fields = $loginFlowEntity->getDBFields([loginFlowEntity::CREATE_DATE, loginFlowEntity::IS_DELETED]);
            // Add the cross site request forgery token to the fields
            $fields['_glpi_csrf_token'] = $postData['_glpi_csrf_token'];
            // Get instance of SamlConfig for db update.
            $config = new LoginFlow();
            // Perform database update using fields.
            if( $config->canUpdate()     &&
                $config->update($fields) ){
                // Leave a success message for the user and redirect using ID.
                Session::addMessageAfterRedirect(__('Configuration updated successfully', PLUGIN_NAME));
                Html::redirect(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::FLOWFORM_ROUTE.'/'.$postData['id']);
            } else {
                // Leave a failed message
                Session::addMessageAfterRedirect(__('Configuration update failed, check your update rights or error logging', PLUGIN_NAME));
                Html::redirect(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::FLOWFORM_ROUTE.'/'.$postData['id']);
            }
        }else{
            // Leave an error message and reload the form with provided values and errors
            Session::addMessageAfterRedirect(__('Configuration invalid please correct all ⭕ errors first', PLUGIN_NAME));
            return $this->generateForm($loginFlowEntity);
        }
    }


    /**
     * Figures out what form to show
     *
     * @param integer $id       ID the configuration item to show
     * @param array   $options  Options
     */
    public function showForm(int $id, array $options = []): string
    {
        if($id === -1 || $id > 0){
            // Generate form using a template
            return $this->generateForm(new LoginFlowEntity($id, $options));
        }else{
            // Invalid id used redirect back to origin
            Session::addMessageAfterRedirect(__('Invalid request, redirecting back', PLUGIN_NAME));
            Html::back();
            // Unreachable bogus return for linter.
            return '';
        }
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
    private function generateForm(LoginFlowEntity $loginFlowEntity)
    {
        global $CFG_GLPI;
        $fields = $loginFlowEntity->getFields();
        // Get warnings tabs
        $tplVars  = [];
       
        // Get AuthN context as array
        //$fields[ConfigEntity::AUTHN_CONTEXT][ConfigItem::VALUE] = $configEntity->getRequestedAuthnContextArray();

        // get the logging entries, but only if the object already exists
        // https://codeberg.org/QuinQuies/glpisaml/issues/15#issuecomment-1785284
        if(is_numeric($fields[LoginFlowEntity::ID]['value'])){
            $logging = LoginState::getLoggingEntries($fields[LoginFlowEntity::ID]['value']);
        }else{
            $logging = [];
        }
       
        // Define static field translations
        $tplVars = array_merge($tplVars, [
            'plugin'                    =>  PLUGIN_NAME,
            'close_form'                =>  Html::closeForm(false),
            'glpi_rootdoc'              =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::FLOWFORM_ROUTE.'?id='.$fields[LoginFlowEntity::ID][LoginFlowItem::VALUE],
            'glpi_tpl_macro'            =>  '/components/form/fields_macros.html.twig',
            'inputfields'               =>  $fields,
            'loggingfields'             =>  $logging,
            'entityID'                  =>  $CFG_GLPI['url_base'].'/',
            'acsUrl'                    =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::ACS_ROUTE.'?id='.$fields[LoginFlowEntity::ID][LoginFlowItem::VALUE],
            'metaUrl'                   =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::META_ROUTE.'?id='.$fields[LoginFlowEntity::ID][LoginFlowItem::VALUE],
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
        return TemplateRenderer::getInstance()->render('@samlsso/loginFlowForm.html.twig',  $tplVars);
    }
}
