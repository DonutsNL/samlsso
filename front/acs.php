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

use GlpiPlugin\Samlsso\LoginFlow\Acs;

// Capture the post and get before GLPI does.
$post = $_POST;     // Contains the samlResponse;

// https://codeberg.org/QuinQuies/glpisaml/issues/45
// Added idpId because we need to be able to unpack the samlResponse,
// get het RequestId inside and match that with our database and work
// from there as a way to get arround the cookie requirements.
$get = $_GET;       // Contains at least the idpId field;

// Use a countable datatype to empty the global
// https://github.com/derricksmith/phpsaml/issues/153
$_POST = [];
$_GET = [];

// Load GLPI includes
//include_once '../../../inc/includes.php';                       //NOSONAR - Cant be included with USE.

// Load ACS
$acs = new Acs();
$acs->init($get, $post);