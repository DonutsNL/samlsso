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

use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\Config\ConfigForm;

// Capture post before GLPI processes it.
$post  = $_POST;

// Include GLPI;
include_once '../../../inc/includes.php';                       //NOSONAR intentional include_once;
// Check the rights
Session::checkRight("config", UPDATE);

// Show header with saml config breadcrumbs.
Html::header(__('Identity providers'), $_SERVER['PHP_SELF'], "config", Config::class);

// Validate plugin is active and registered properly
if(!(new Plugin())->isInstalled(PLUGIN_NAME) ||
   !(new Plugin())->isActivated(PLUGIN_NAME) ||
   !class_exists(ConfigForm::class)          ){

    Html::displayNotFoundError();
// Load the config form
}else{
    $id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? $_GET['id'] : -1;
    $configForm = new ConfigForm();

    
    if(empty($post)){
        // Show config form.
        $options['template'] = (isset($_GET['template']) && ctype_alpha($_GET['template'])) ? $_GET['template'] : 'default';
        print $configForm->showForm($id, $options);

    }elseif(isset($post['update'])  &&
            isset($post['id'])      &&
            empty($post['id'])      ){

        // Add new item
        print $configForm->addSamlConfig($post);

    }elseif(isset($post['update'])  &&
            isset($post['id'])      &&
            $post['id'] > 0         ){

        // update existing item{
        print $configForm->updateSamlConfig($post);

    }elseif(isset($post['delete'])  &&
            $post['id'] > 0         ){
        // delete existing item
        print $configForm->deleteSamlConfig($post);
    }
}

// Show GLPI footer
Html::footer();

