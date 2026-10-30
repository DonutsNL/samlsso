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
 *  Copyright (C) 2026 by DonutsNL
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
 *  @version    1.3.2
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.3.2
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';

    /**
     * Class Glpi12CompatTest
     *
     * Validates the GLPI 12 compatibility wiring introduced for issue #167:
     * the version-typed compatibility layer (src/compat.php), the concrete
     * plugin classes extending their version-typed intermediate, the absolute
     * plugin web-dir helper that no longer relies on the removed
     * Plugin::getWebDir(), the declared maximum supported GLPI version, and the
     * absence of any obfuscation or core patching in the compatibility layer.
     */
    class Glpi12CompatTest
    {
        /**
         * Read a plugin file as text relative to the plugin root.
         *
         * @param string $relative Path relative to the plugin root directory.
         * @return string File contents.
         * @throws \Exception when the file cannot be resolved or read.
         */
        private function readPluginFile(string $relative): string
        {
            $base = realpath(__DIR__ . '/..');
            if ($base === false) {
                throw new \Exception('Could not resolve plugin base path.');
            }
            $path = $base . '/' . $relative;
            $content = file_exists($path) ? file_get_contents($path) : false;
            if ($content === false) {
                throw new \Exception("Unable to read plugin file: $relative");
            }
            return $content;
        }

        /**
         * Test that src/compat.php defines the version gate and both the typed
         * (GLPI 12) and untyped (GLPI 11) intermediate class shapes.
         *
         * @throws \Exception if any expected declaration is missing.
         */
        public function testCompatLayerProvidesTypedAndUntypedIntermediates(): void
        {
            $compat = $this->readPluginFile('src/compat.php');
            $errors = [];

            $expected = [
                'version gate helper' => 'function samlsso_glpi12(): bool',
                'GLPI 12 major threshold' => "'12.0.0'",
                'ConfigCompat' => 'abstract class ConfigCompat extends CommonDBTM',
                'LoginFlowCompat' => 'abstract class LoginFlowCompat extends CommonDBTM',
                'ExcludeCompat' => 'abstract class ExcludeCompat extends CommonDropdown',
                'RuleSamlCompat' => 'abstract class RuleSamlCompat extends Rule',
                'RuleSamlCollectionCompat' => 'abstract class RuleSamlCollectionCompat extends RuleCollection',
                'typed rightname (GLPI 12)' => 'public static string $rightname',
                'untyped rightname (GLPI 11)' => 'public static $rightname',
                'typed dohistory (GLPI 12)' => 'public bool $dohistory',
                'untyped dohistory (GLPI 11)' => 'public $dohistory',
                'typed menu_option (GLPI 12)' => 'public string $menu_option',
                'untyped menu_option (GLPI 11)' => 'public $menu_option',
            ];

            foreach ($expected as $label => $needle) {
                if (!str_contains($compat, $needle)) {
                    $errors[] = "src/compat.php is missing the $label ($needle).";
                }
            }

            if (!empty($errors)) {
                throw new \Exception("GLPI 12 compatibility layer validation failed:\n" . implode("\n", $errors));
            }

            echo "✅ Validated compatibility layer: src/compat.php defines the GLPI version gate and typed + untyped intermediates.\n";
        }

        /**
         * Test that every concrete plugin class requires src/compat.php and
         * extends its version-typed compatibility intermediate.
         *
         * @throws \Exception if any class is not wired to its compatibility base.
         */
        public function testPluginClassesExtendVersionTypedCompat(): void
        {
            $bindings = [
                'src/Config.php' => ['Config', 'ConfigCompat'],
                'src/Exclude.php' => ['Exclude', 'ExcludeCompat'],
                'src/LoginFlow.php' => ['LoginFlow', 'LoginFlowCompat'],
                'src/RuleSaml.php' => ['RuleSaml', 'RuleSamlCompat'],
                'src/RuleSamlCollection.php' => ['RuleSamlCollection', 'RuleSamlCollectionCompat'],
            ];

            $errors = [];
            foreach ($bindings as $file => $binding) {
                [$class, $compat] = $binding;
                $content = $this->readPluginFile($file);
                if (!str_contains($content, "require_once __DIR__ . '/compat.php'")) {
                    $errors[] = "$file does not require_once src/compat.php before declaring $class.";
                }
                if (!str_contains($content, "use GlpiPlugin\\Samlsso\\Compat\\$compat;")) {
                    $errors[] = "$file does not import Compat\\$compat.";
                }
                if (!str_contains($content, "class $class extends $compat")) {
                    $errors[] = "$file does not declare 'class $class extends $compat'.";
                }
            }

            if (!empty($errors)) {
                throw new \Exception("GLPI 12 version-typed class wiring failed:\n" . implode("\n", $errors));
            }

            echo "✅ Validated class wiring: Config, Exclude, LoginFlow, RuleSaml and RuleSamlCollection extend their version-typed base.\n";
        }

        /**
         * Test the absolute web-dir helper and the declared maximum supported
         * GLPI version: Plugin::getWebDir() must be used only behind a
         * method_exists guard, the call-time webdir helper must exist, and the
         * plugin must advertise GLPI 12 support.
         *
         * @throws \Exception if the web-dir fallback or the max version is wrong.
         */
        public function testAbsoluteWebDirHelperAndMaxGlpiVersion(): void
        {
            $setup = $this->readPluginFile('setup.php');
            $errors = [];

            if (!str_contains($setup, "method_exists('Plugin', 'getWebDir')")) {
                $errors[] = 'setup.php does not guard Plugin::getWebDir() behind method_exists().';
            }
            if (!str_contains($setup, 'function samlsso_plugin_web_dir')) {
                $errors[] = 'setup.php is missing the samlsso_plugin_web_dir() helper.';
            }
            if (!str_contains($setup, 'function samlsso_webdir')) {
                $errors[] = 'setup.php is missing the absolute samlsso_webdir() helper.';
            }
            if (
                !preg_match("/define\('PLUGIN_SAMLSSO_MAX_GLPI',\s*'([^']+)'\)/", $setup, $matches)
                || version_compare($matches[1], '12.0.0', '<')
            ) {
                $max = $matches[1] ?? 'undefined';
                $errors[] = "PLUGIN_SAMLSSO_MAX_GLPI must allow GLPI 12, found '$max'.";
            }

            if (!empty($errors)) {
                throw new \Exception("GLPI 12 web-dir / version support validation failed:\n" . implode("\n", $errors));
            }

            echo "✅ Validated web-dir helper and max GLPI version: absolute web dir resolved at call time, GLPI 12 supported.\n";
        }

        /**
         * Test that the compatibility layer contains no obfuscation and no
         * dynamic code execution (CONTRIBUTING "Obfuscations" rule).
         *
         * @throws \Exception if any obfuscated or dynamic-execution construct is found.
         */
        public function testCompatLayerContainsNoObfuscation(): void
        {
            $compat = $this->readPluginFile('src/compat.php');
            $forbidden = ['eval(', 'base64_decode(', 'base64_encode(', 'str_rot13', 'gzinflate(', 'gzuncompress(', 'create_function('];

            $errors = [];
            foreach ($forbidden as $construct) {
                if (stripos($compat, $construct) !== false) {
                    $errors[] = "src/compat.php contains forbidden construct '$construct'.";
                }
            }

            if (!empty($errors)) {
                throw new \Exception("Compatibility layer obfuscation check failed:\n" . implode("\n", $errors));
            }

            echo "✅ Validated compatibility layer: no obfuscation or dynamic code execution in src/compat.php.\n";
        }

        /**
         * Test that the Single Logout (SLO) endpoint is properly registered as
         * stateless in plugin_samlsso_boot() to prevent GLPI CSRF 403 on
         * asynchronous LogoutResponse from IdPs (Issue #164).
         *
         * @throws \Exception if the stateless path registration is missing.
         */
        public function testStatelessPathRegistration(): void
        {
            $setup = $this->readPluginFile('setup.php');
            $errors = [];

            if (
                !str_contains($setup, "registerPluginStatelessPath(PLUGIN_NAME, '#^/front/logout#')") &&
                !str_contains($setup, "registerPluginStatelessPath(PLUGIN_NAME, '#^/' . SamlSsoController::SLO_ROUTE . '#')")
            ) {
                $errors[] = "setup.php does not register SLO_ROUTE ('#^/front/logout#') as a stateless path.";
            }
            if (
                !str_contains($setup, "registerPluginStatelessPath(PLUGIN_NAME, '#^/front/acs/#')") &&
                !str_contains($setup, "RegisterPluginStatelessPath(PLUGIN_NAME, '#^/front/acs/#')") &&
                !str_contains($setup, "registerPluginStatelessPath(PLUGIN_NAME, '#^/' . SamlSsoController::ACS_ROUTE . '/#')") &&
                !str_contains($setup, "RegisterPluginStatelessPath(PLUGIN_NAME, '#^/' . SamlSsoController::ACS_ROUTE . '/#')")
            ) {
                $errors[] = "setup.php does not register ACS_ROUTE ('#^/front/acs/#') as a stateless path.";
            }

            if (!empty($errors)) {
                throw new \Exception("Stateless path registration failed:\n" . implode("\n", $errors));
            }

            echo "✅ Validated stateless path registration: ACS and SLO (/front/logout) registered to prevent CSRF 403.\n";
        }

        /**
         * Test that RuleSamlCompat and RuleSamlCollectionCompat use 'rule_ldap'
         * for authorization rights instead of 'rule_import'.
         *
         * @throws \Exception if the rightname is not 'rule_ldap'.
         */
        public function testRuleSamlRightnameIsRuleLdap(): void
        {
            $compat = $this->readPluginFile('src/compat.php');
            if (str_contains($compat, "public static string \$rightname = 'rule_import'") ||
                str_contains($compat, "public static \$rightname = 'rule_import'")) {
                throw new \Exception("src/compat.php still references 'rule_import' instead of 'rule_ldap'.");
            }

            require_once realpath(__DIR__ . '/../src/compat.php');
            if (\GlpiPlugin\Samlsso\Compat\RuleSamlCompat::$rightname !== 'rule_ldap') {
                throw new \Exception("RuleSamlCompat::\$rightname is not 'rule_ldap'. Got: " . \GlpiPlugin\Samlsso\Compat\RuleSamlCompat::$rightname);
            }

            echo "✅ Validated RuleSaml rightname: governed by 'rule_ldap' for user identity rights.\n";
        }
    }
}

namespace {
    /**
     * Executes the Glpi12CompatTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\Glpi12CompatTest();
    try {
        $test->testCompatLayerProvidesTypedAndUntypedIntermediates();
        $test->testPluginClassesExtendVersionTypedCompat();
        $test->testAbsoluteWebDirHelperAndMaxGlpiVersion();
        $test->testCompatLayerContainsNoObfuscation();
        $test->testStatelessPathRegistration();
        $test->testRuleSamlRightnameIsRuleLdap();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
