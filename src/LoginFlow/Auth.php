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

use Auth as glpiAuth;
use Profile_User;
use GlpiPlugin\Samlsso\LoginFlow\User;

/**
 * Extends the glpi Auth class for injection into Session::init();
 * by the LoginFlow class. Loads the $this->user after successful
 * authentication by phpSaml using the provided claim attributes
 * and sets all session variables to allow for login.
 */
class Auth extends glpiAuth
{
    public function loadUser(array $userFields)
    {
        global $DB;

        // Get or Jit create user or exit on error.
        $this->user = (new User())->getOrCreateUser($userFields);

        // Setting this property actually authorizes the login for the user.
        // Be aware (sic) Succeeded is spelled incorrectly in parent GLPI object
        // as per Auth.php:1043
        $this->auth_succeded = $this->user;

        // Return this object for injection into the session.
        return $this;
    }
}
