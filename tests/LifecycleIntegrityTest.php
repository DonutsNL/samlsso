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
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.3.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

/**
 * LifecycleIntegrityTest.php
 *
 * Validates installation and uninstallation method completeness across all plugin classes,
 * and ensures they are correctly registered in the PLUGIN_SAMLSSO_CLASSES setup array.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';

    /**
     * Class LifecycleIntegrityTest
     */
    class LifecycleIntegrityTest extends TestHarness {

        /**
         * Test that classes containing install/uninstall methods:
         * 1. Implement both methods (symmetry requirement).
         * 2. Are correctly listed in PLUGIN_SAMLSSO_CLASSES in setup.php.
         *
         * @throws \Exception if any validation fails.
         */
        public function testLifecycleClasses(): void {
            $basePath = realpath(__DIR__ . '/..');
            $srcDir = $basePath . '/src';
            $setupFile = $basePath . '/setup.php';

            if (!file_exists($setupFile)) {
                throw new \Exception("setup.php not found at $setupFile");
            }

            // Extract PLUGIN_SAMLSSO_CLASSES from setup.php
            $setupContent = file_get_contents($setupFile);
            if (!preg_match('/define\(\'PLUGIN_SAMLSSO_CLASSES\'\s*,\s*\[(.*?)\]\)/s', $setupContent, $matches)) {
                throw new \Exception("PLUGIN_SAMLSSO_CLASSES constant not found in setup.php");
            }

            // Support both formats:
            //   Old: \GlpiPlugin\Samlsso\Config::class
            //   New: 'GlpiPlugin\\Samlsso\\Config'  (string literal, autoloader-safe)
            $classesInSetup = [];

            // Match ::class constants
            preg_match_all('/\\\\?GlpiPlugin\\\\Samlsso\\\\\\w+(?:\\\\\\w+)*::class/', $matches[1], $classConstMatches);
            foreach ($classConstMatches[0] as $match) {
                $class = str_replace('::class', '', $match);
                $class = ltrim($class, '\\');
                $classesInSetup[] = $class;
            }

            // Match quoted string literals: 'GlpiPlugin\\Samlsso\\Foo' or "GlpiPlugin\\Samlsso\\Foo"
            preg_match_all('/[\'"]GlpiPlugin\\\\\\\\Samlsso\\\\\\\\[\\w\\\\\\\\]+[\'"]/', $matches[1], $classStringMatches);
            foreach ($classStringMatches[0] as $match) {
                $class = trim($match, '\'"');           // strip surrounding quotes
                $class = str_replace('\\\\', '\\', $class); // unescape double-backslashes
                $classesInSetup[] = $class;
            }

            $classesInSetup = array_unique($classesInSetup);

            // Find all PHP files in src/ recursively
            $directoryIterator = new \RecursiveDirectoryIterator($srcDir);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            $classesWithLifecycle = [];
            $mismatchedClasses = [];

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    if ($filePath === false) {
                        continue;
                    }

                    // Extract methods using token_get_all to ignore commented code
                    $content = file_get_contents($filePath);
                    $tokens = token_get_all($content);
                    $count = count($tokens);
                    $hasInstall = false;
                    $hasUninstall = false;

                    for ($i = 0; $i < $count; $i++) {
                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                            $j = $i + 1;
                            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                                $j++;
                            }
                            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                $funcName = $tokens[$j][1];
                                if ($funcName === 'install') {
                                    $hasInstall = true;
                                } elseif ($funcName === 'uninstall') {
                                    $hasUninstall = true;
                                }
                            }
                        }
                    }

                    // Verify symmetry: a class has both or neither
                    if ($hasInstall !== $hasUninstall) {
                        $relative = str_replace($srcDir . DIRECTORY_SEPARATOR, '', $filePath);
                        $mismatchedClasses[] = "Class in $relative has " . ($hasInstall ? 'install' : 'uninstall') . " but is missing the corresponding " . ($hasInstall ? 'uninstall' : 'install') . " method.";
                    }

                    if ($hasInstall && $hasUninstall) {
                        // Construct class FQCN
                        $relative = str_replace($srcDir . DIRECTORY_SEPARATOR, '', $filePath);
                        $relative = str_replace('.php', '', $relative);
                        $className = 'GlpiPlugin\\Samlsso\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                        $classesWithLifecycle[] = $className;
                    }
                }
            }

            if (!empty($mismatchedClasses)) {
                throw new \Exception("Lifecycle Method Mismatch Failure:\n" . implode("\n", $mismatchedClasses));
            }

            // Verify all classes containing install/uninstall are in PLUGIN_SAMLSSO_CLASSES
            $missingFromSetup = array_diff($classesWithLifecycle, $classesInSetup);
            if (!empty($missingFromSetup)) {
                throw new \Exception("The following classes have install/uninstall methods but are missing from PLUGIN_SAMLSSO_CLASSES in setup.php:\n" . implode("\n", $missingFromSetup));
            }

            // Verify that all classes listed in PLUGIN_SAMLSSO_CLASSES actually exist and have install/uninstall methods
            foreach ($classesInSetup as $class) {
                if (!in_array($class, $classesWithLifecycle)) {
                    throw new \Exception("Class '$class' is listed in PLUGIN_SAMLSSO_CLASSES in setup.php but does not implement both install and uninstall methods in the codebase.");
                }
            }

            echo "✅ Validated lifecycle classes list: All lifecycle-managed classes are correctly registered in PLUGIN_SAMLSSO_CLASSES.\n";
            echo "✅ Validated lifecycle completeness: Classes implementing install also implement uninstall.\n";
        }

        /**
         * Test that all tables created by our install methods use the correct primary/foreign key signing options
         * to prevent signed integer primary or foreign key warnings in GLPI 11.
         *
         * @throws \Exception if key signing is incorrect.
         */
        public function testPrimaryKeyAndForeignKeySigning(): void {
            $basePath = realpath(__DIR__ . '/..');
            $files = [
                'src/Config.php',
                'src/ClaimMap.php',
                'src/ObservedClaim.php',
                'src/Exclude.php',
                'src/LoginState.php',
            ];

            foreach ($files as $relPath) {
                $filePath = $basePath . '/' . $relPath;
                if (!file_exists($filePath)) {
                    throw new \Exception("File not found: $filePath");
                }

                $content = file_get_contents($filePath);

                // Find the CREATE TABLE query in the file
                if (!preg_match('/CREATE\s+TABLE\s+.*?\((.*?)\)\s*ENGINE/is', $content, $matches)) {
                    throw new \Exception("Could not find CREATE TABLE statement in $relPath");
                }

                $fieldsBlock = $matches[1];
                $lines = explode("\n", $fieldsBlock);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Check if it defines a column (starts with backtick)
                    if (str_starts_with($line, '`')) {
                        if (preg_match('/^`([^`]+)`\s+([A-Za-z0-9_]+)(.*?)$/i', $line, $fieldMatch)) {
                            $fieldName = $fieldMatch[1];
                            $fieldType = strtoupper($fieldMatch[2]);
                            $fieldModifiers = $fieldMatch[3];

                            // Primary key 'id'
                            if ($fieldName === 'id') {
                                if (!str_contains($fieldModifiers, '{$default_key_sign}') && !str_contains($fieldModifiers, '{$default_primary_key_sign}')) {
                                    throw new \Exception("Primary key 'id' in $relPath does not use the default_key_sign placeholder: $line");
                                }
                            }

                            // Foreign keys (configs_id, idpId, userId)
                            if ($fieldName === 'configs_id' || $fieldName === 'idpId' || $fieldName === 'userId') {
                                if (!str_contains($fieldModifiers, '{$default_key_sign}') && !str_contains($fieldModifiers, '{$default_primary_key_sign}')) {
                                    throw new \Exception("Foreign key '$fieldName' in $relPath does not use the default_key_sign placeholder: $line");
                                }
                            }
                        }
                    }
                }
            }
            echo "✅ Validated primary and foreign key unsigned placeholders in all table SQL definitions.\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\LifecycleIntegrityTest();
    try {
        $test->testLifecycleClasses();
        $test->testPrimaryKeyAndForeignKeySigning();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

