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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
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

            $classesInSetup = $this->parseSetupLifecycleClasses($setupFile);
            [$classesWithLifecycle, $mismatchedClasses] = $this->scanLifecycleClassesInSource($srcDir);

            if (!empty($mismatchedClasses)) {
                throw new \Exception("Lifecycle Method Mismatch Failure:\n" . implode("\n", $mismatchedClasses));
            }

            $missingFromSetup = array_diff($classesWithLifecycle, $classesInSetup);
            if (!empty($missingFromSetup)) {
                throw new \Exception("The following classes have install/uninstall methods but are missing from PLUGIN_SAMLSSO_CLASSES in setup.php:\n" . implode("\n", $missingFromSetup));
            }

            foreach ($classesInSetup as $class) {
                if (!in_array($class, $classesWithLifecycle, true)) {
                    throw new \Exception("Class '$class' is listed in PLUGIN_SAMLSSO_CLASSES in setup.php but does not implement both install and uninstall methods in the codebase.");
                }
            }

            echo "✅ Validated lifecycle classes list: All lifecycle-managed classes are correctly registered in PLUGIN_SAMLSSO_CLASSES.\n";
            echo "✅ Validated lifecycle completeness: Classes implementing install also implement uninstall.\n";
        }

        /**
         * Extract FQCN strings declared in PLUGIN_SAMLSSO_CLASSES inside setup.php.
         *
         * @param string $setupFile Path to setup.php.
         * @return array Unique list of declared FQCNs.
         * @throws \Exception if setup.php or PLUGIN_SAMLSSO_CLASSES is missing.
         */
        private function parseSetupLifecycleClasses(string $setupFile): array {
            if (!file_exists($setupFile)) {
                throw new \Exception("setup.php not found at $setupFile");
            }

            $setupContent = file_get_contents($setupFile);
            if (!preg_match('/define\(\'PLUGIN_SAMLSSO_CLASSES\'\s*,\s*\[(.*?)\]\)/s', $setupContent, $matches)) {
                throw new \Exception("PLUGIN_SAMLSSO_CLASSES constant not found in setup.php");
            }

            $classesInSetup = [];
            preg_match_all('/\\\\?GlpiPlugin\\\\Samlsso\\\\\\w+(?:\\\\\\w+)*::class/', $matches[1], $classConstMatches);
            foreach ($classConstMatches[0] as $match) {
                $classesInSetup[] = ltrim(str_replace('::class', '', $match), '\\');
            }

            preg_match_all('/[\'"]GlpiPlugin\\\\\\\\Samlsso\\\\\\\\[\\w\\\\\\\\]+[\'"]/', $matches[1], $classStringMatches);
            foreach ($classStringMatches[0] as $match) {
                $classesInSetup[] = str_replace('\\\\', '\\', trim($match, '\'"'));
            }

            return array_unique($classesInSetup);
        }

        /**
         * Scan src/ PHP files for install() and uninstall() implementations.
         *
         * @param string $srcDir Path to plugin src/ directory.
         * @return array Tuple of [array $classesWithLifecycle, array $mismatchedClasses].
         */
        private function scanLifecycleClassesInSource(string $srcDir): array {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
            $classesWithLifecycle = [];
            $mismatchedClasses = [];

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $filePath = $file->getRealPath();
                if ($filePath === false) {
                    continue;
                }

                [$hasInstall, $hasUninstall] = $this->detectLifecycleMethods($filePath);
                $relative = str_replace($srcDir . DIRECTORY_SEPARATOR, '', $filePath);

                if ($hasInstall !== $hasUninstall) {
                    $present = $hasInstall ? 'install' : 'uninstall';
                    $missing = $hasInstall ? 'uninstall' : 'install';
                    $mismatchedClasses[] = "Class in $relative has $present but is missing the corresponding $missing method.";
                } elseif ($hasInstall && $hasUninstall) {
                    $fqcnSuffix = str_replace(DIRECTORY_SEPARATOR, '\\', str_replace('.php', '', $relative));
                    $classesWithLifecycle[] = 'GlpiPlugin\\Samlsso\\' . $fqcnSuffix;
                }
            }

            return [$classesWithLifecycle, $mismatchedClasses];
        }

        /**
         * Inspect PHP tokens in a file to determine if install and uninstall methods exist.
         *
         * @param string $filePath Absolute path to PHP file.
         * @return array Tuple of [bool $hasInstall, bool $hasUninstall].
         */
        private function detectLifecycleMethods(string $filePath): array {
            $tokens = token_get_all((string) file_get_contents($filePath));
            $count = count($tokens);
            $hasInstall = false;
            $hasUninstall = false;

            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $hasInstall = $hasInstall || $tokens[$j][1] === 'install';
                    $hasUninstall = $hasUninstall || $tokens[$j][1] === 'uninstall';
                }
            }

            return [$hasInstall, $hasUninstall];
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
                'src/ScimLink.php',
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

                            // Foreign keys (configs_id, idpId, userId, glpi_item_id)
                            if ($fieldName === 'configs_id' || $fieldName === 'idpId' || $fieldName === 'userId' || $fieldName === 'glpi_item_id') {
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

        /**
         * Test that plugin_samlsso_install proactively resets the GLPI cache.
         *
         * @throws \Exception if the cache is not reset during installation.
         */
        public function testInstallResetsCache(): void {
            $basePath = realpath(__DIR__ . '/..');
            require_once $basePath . '/hook.php';

            if (!function_exists('plugin_samlsso_install')) {
                throw new \Exception("plugin_samlsso_install function not defined in hook.php");
            }

            /**
             * Reset the static flag on the shimmed CacheManager to verify it gets toggled.
             */
            if (class_exists(\Glpi\Cache\CacheManager::class) && property_exists(\Glpi\Cache\CacheManager::class, 'wasResetCalled')) {
                \Glpi\Cache\CacheManager::$wasResetCalled = false;
            }

            /**
             * Call the install function.
             */
            $result = plugin_samlsso_install();

            if (!$result) {
                throw new \Exception("plugin_samlsso_install returned false");
            }

            /**
             * Verify that resetAllCaches was indeed called.
             */
            if (class_exists(\Glpi\Cache\CacheManager::class) && property_exists(\Glpi\Cache\CacheManager::class, 'wasResetCalled')) {
                if (!\Glpi\Cache\CacheManager::$wasResetCalled) {
                    throw new \Exception("CacheManager::resetAllCaches() was not called during plugin_samlsso_install()");
                }
            } else {
                throw new \Exception("Glpi\\Cache\\CacheManager shim not loaded correctly in test environment");
            }

            echo "✅ Validated cache reset: CacheManager::resetAllCaches() is called during installation.\n";
        }

        /**
         * Test that plugin_samlsso_uninstall removes RuleSaml entries from glpi_rules,
         * glpi_rulecriterias, and glpi_ruleactions.
         */
        public function testUninstallCleansJitRules(): void {
            global $DB;
            require_once realpath(__DIR__ . '/../hook.php');

            $db = new MockDB();
            $DB = $db;

            // Set up mock rules table with a RuleSaml rule
            $db->setResponse('glpi_rules', [
                ['id' => 101, 'sub_type' => \GlpiPlugin\Samlsso\RuleSaml::class]
            ]);

            $result = plugin_samlsso_uninstall();
            if (!$result) {
                throw new \Exception("plugin_samlsso_uninstall returned false");
            }

            // Verify that delete was called on glpi_rules, glpi_rulecriterias, and glpi_ruleactions
            $deletedTables = array_column($db->deletedRows, 'table');
            if (!in_array('glpi_rules', $deletedTables) ||
                !in_array('glpi_rulecriterias', $deletedTables) ||
                !in_array('glpi_ruleactions', $deletedTables)
            ) {
                throw new \Exception("plugin_samlsso_uninstall failed to delete rules from glpi_rules/glpi_rulecriterias/glpi_ruleactions");
            }

            echo "✅ Validated JIT rule cleanup on uninstall: glpi_rules, glpi_rulecriterias, glpi_ruleactions purged.\n";
        }

        /**
         * Test that plugin_samlsso_check_prerequisites() accepts 'Strict', 'Lax', and ''
         * without warning while warning on 'None' (matching GLPI Core SessionsSecurityConfiguration).
         *
         * @return void
         * @throws \Exception if SameSite prerequisite validation behaves unexpectedly.
         */
        public function testPrerequisiteCookieSameSiteValidation(): void {
            $setupPath = realpath(__DIR__ . '/../setup.php');
            $shimsPath = realpath(__DIR__ . '/Shims.php');
            if ($setupPath === false || $shimsPath === false) {
                throw new \Exception("Failed to resolve setup.php or Shims.php path");
            }

            $cases = [
                'Strict' => false,
                'Lax'    => false,
                ''       => false,
                'None'   => true,
            ];

            foreach ($cases as $sameSite => $expectWarning) {
                $runnerCode = sprintf(
                    '$_SERVER["HTTPS"] = "on"; require_once %s; require_once %s; ob_start(); $ok = plugin_samlsso_check_prerequisites(); $out = ob_get_clean(); echo json_encode(["ok" => $ok, "out" => $out]);',
                    var_export($setupPath, true),
                    var_export($shimsPath, true)
                );
                $cmd = sprintf(
                    '%s -d session.cookie_secure=1 -d session.cookie_httponly=1 -d %s -r %s',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg('session.cookie_samesite="' . $sameSite . '"'),
                    escapeshellarg($runnerCode)
                );

                $rawOutput = shell_exec($cmd);
                $decoded = is_string($rawOutput) ? json_decode($rawOutput, true) : null;
                if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
                    throw new \Exception("Prerequisite runner failed for SameSite='$sameSite': " . (string) $rawOutput);
                }

                $hasWarning = str_contains((string) ($decoded['out'] ?? ''), 'not aligned');
                if ($hasWarning !== $expectWarning) {
                    throw new \Exception(sprintf(
                        "Unexpected prerequisite warning state for session.cookie_samesite='%s': expected warning=%s, got output=%s",
                        $sameSite,
                        $expectWarning ? 'true' : 'false',
                        (string) ($decoded['out'] ?? '')
                    ));
                }
            }

            echo "✅ Validated cookie SameSite prerequisite check: Strict and Lax accepted; None flagged.\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\LifecycleIntegrityTest();
    try {
        $test->testLifecycleClasses();
        $test->testPrimaryKeyAndForeignKeySigning();
        $test->testInstallResetsCache();
        $test->testUninstallCleansJitRules();
        $test->testPrerequisiteCookieSameSiteValidation();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

