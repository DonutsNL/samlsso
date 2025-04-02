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

use OneLogin\Saml2\Settings;
use GlpiPlugin\Samlsso\Config\ConfigEntity;

// Load GLPI
include_once '../../../inc/includes.php';                       //NOSONAR - Cant be included with USE.

// Capture the post before GLPI does.
$get = $_GET;
// Use a countable datatype to empty the global
// https://github.com/derricksmith/phpsaml/issues/153
$_GET = [];

// Get the ID from the URL and validate its input.
if(isset($get['id']) && is_numeric($get['id'])){
    $id = filter_var($get['id'], FILTER_VALIDATE_INT);     // input string, returns datatype INT.
}else{
    $id = false;
}

// If we have an ID then start building the metadata.
if($id){
    try{
        // ConfigEntity expects/validates datatype INT.
        $configEntity = new ConfigEntity($id);
        // Temporary blockage prevent exposure of IDP configurations.
        if($configEntity->getField(ConfigEntity::DEBUG)){
            $samlSettings = new Settings($configEntity->getPhpSamlConfig());
            $metadata = $samlSettings->getSPMetadata();
            if (empty($errors)) {
                header('Content-Type: text/xml');
                echo $metadata;
            } else {
                throw new OneLogin\Saml2\Error(
                    'Invalid SP metadata: '.implode(', ', $errors),
                    OneLogin\Saml2\Error::METADATA_SP_INVALID
                );
            }
        }else{
            // Do not expose anything meaningful here, administrator needs to toggle debug first.
            header('Content-Type: text/xml');
            echo "<xml><Error>".__("If this is a valid ID. Please request your administrator to enable the 'debug' flag to expose the requested IdP config using this meta file",PLUGIN_NAME)."</Error></xml>";
        }
    } catch (Exception $e) {
        // Do not expose anything meaningful here, an invalid id was used
        header('Content-Type: text/xml');
        echo "<xml><Error>".__("If this is a valid ID. Please request your administrator to enable the 'debug' flag to expose the requested IdP config using this meta file",PLUGIN_NAME)."</Error></xml>";
    }
}else{
    // Do not expose anything meaningful here. An invalid url was used.
    header('Content-Type: text/xml');
    echo "<xml><Error>".__("If this is a valid ID. Please request your administrator to enable the 'debug' flag to expose the requested IdP config using this meta file",PLUGIN_NAME)."</Error></xml>";
}