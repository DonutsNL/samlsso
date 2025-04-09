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

namespace GlpiPlugin\Samlsso\LoginFlow;

use Exception;
use OneLogin\Saml2\Settings;                                                        // Required to generate XML metadata
use GlpiPlugin\Samlsso\Config\ConfigEntity;                                         // Required to fetch requested SAML configuration
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 *  Responsible to handle any requests for Metadata.
 *  invoked by the MetaController. 
 */
class Meta
{
    public const STAG  = '<xml><error>';                                            // Prevent repetition
    public const ETAG  = '</error></xml>';                                          // Prevent repetition

    public final function getSPMeta(Request $request): Response
    {

        $id = (filter_var($request->get('idpId'), FILTER_VALIDATE_INT)) ?           // Did we get a valid ID?
              (int) $request->get('idpId') :                                        // Then assign it
              -1;                                                                   // Else fall back to -1

        try{                                                                        // If we have an ID then try to build the metadata.
            $configEntity = new ConfigEntity($id);                                  // ConfigEntity expects/validates datatype INT.
            if($configEntity->getField(ConfigEntity::DEBUG)){                       // Are we allowed to expose metadata.
                $samlSettings = new Settings($configEntity->getPhpSamlConfig());    // Get the samlConfig using the provided ID.
                if ( !$metadata = $samlSettings->getSPMetadata() ) {                // Get the Serviceprovider metadata.
                    $metadata = self::STAG.                                         // Set error if something is wrong
                                __("Error fetching spMetedata.",PLUGIN_NAME).
                                self::ETAG;
                }
            }else{
                $metadata = self::STAG.                                             // Set error if something is wrong
                            __("Invalid id or metadata not exposed.",PLUGIN_NAME).
                            self::ETAG;
            }
        } catch (Exception $e) {
            $metadata = self::STAG.                                                 // Set error if something is wrong
                        __("Error fetching config.",PLUGIN_NAME).
                        self::ETAG;
        }

        return new Response($metadata, 200, ['Content-Type' => 'text/xml']);        // Return the result to the calling object.
    }
}