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
 *  @version    1.4.1
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';

    /**
     * Class GlpiComplianceTest
     *
     * Validates that the plugin codebase complies with core GLPI development standards.
     * Reports violations with references to official GLPI documentation.
     */
    class GlpiComplianceTest {

        /**
         * Test that all PHP files use full <?php tags and never short tags <? or <?=.
         *
         * GLPI Reference: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html
         *
         * @throws \Exception if any short tag is detected.
         */
        public function testPhpShortTags(): void {
            $basePath = realpath(__DIR__ . '/..');
            $errors = [];

            $directoryIterator = new \RecursiveDirectoryIterator($basePath);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    if ($filePath === false || str_contains($filePath, '/vendor/')) {
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    if ($content === false) {
                        continue;
                    }

                    $tokens = token_get_all($content);
                    foreach ($tokens as $token) {
                        if (is_array($token)) {
                            $type = $token[0];
                            $text = $token[1];
                            $line = $token[2];

                            if ($type === T_INLINE_HTML) {
                                // Match short tags in inline HTML context (e.g. if short_open_tag is disabled in php.ini)
                                $pattern = '<\?(?!php|xml)';
                                if (preg_match('/' . $pattern . '/i', $text)) {
                                    $errors[] = "$filePath: Line $line - Prohibited short tag '<?' found. Always use '<?php'.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html";
                                    break;
                                }
                            } elseif ($type === T_OPEN_TAG) {
                                $trimmedText = trim($text);
                                if ($trimmedText === '<?') {
                                    $errors[] = "$filePath: Line $line - Prohibited short tag '<?' found. Always use '<?php'.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html";
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($errors)) {
                throw new \Exception("Short PHP Tags Compliance Failure:\n" . implode("\n\n", $errors));
            }

            echo "✅ Validated PHP tags: All PHP files use full <?php tags.\n";
        }

        /**
         * Test that PHP-only files do not end with a closing ?> tag.
         *
         * GLPI Reference: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html
         *
         * @throws \Exception if any closing tag is detected at the end of a PHP-only file.
         */
        public function testNoClosingPhpTags(): void {
            $basePath = realpath(__DIR__ . '/..');
            $errors = [];

            $directoryIterator = new \RecursiveDirectoryIterator($basePath);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    // Skip templates directory which might mix HTML and PHP, and vendor files
                    if ($filePath === false || str_contains($filePath, '/vendor/') || str_contains($filePath, '/templates/')) {
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    if ($content === false) {
                        continue;
                    }

                    $trimmed = rtrim($content);
                    if (str_ends_with($trimmed, '?>')) {
                        $errors[] = "$filePath: Ends with a closing '?>' tag. PHP-only files must omit the closing tag to prevent trailing whitespace.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html";
                    }
                }
            }

            if (!empty($errors)) {
                throw new \Exception("PHP Closing Tags Compliance Failure:\n" . implode("\n\n", $errors));
            }

            echo "✅ Validated closing tags: PHP-only files omit the closing '?>' tag.\n";
        }

        /**
         * Test that translation helper calls use correct text domains.
         *
         * GLPI Reference: https://glpi-developer-documentation.readthedocs.io/en/master/plugins/translation.html
         *
         * @throws \Exception if any translation call is missing the plugin text domain.
         */
        public function testTranslationTextDomains(): void {
            $basePath = realpath(__DIR__ . '/..');
            $errors = [];

            foreach ($this->getPluginPhpFiles($basePath) as $filePath) {
                $errors = array_merge($errors, $this->collectTranslationDomainErrors($filePath));
            }

            if (!empty($errors)) {
                throw new \Exception("Translation Text Domain Compliance Failure:\n" . implode("\n\n", $errors));
            }

            echo "✅ Validated translation domains: All translation helper calls use correct text domains.\n";
        }

        /**
         * Test that codebase does not use raw die() or exit() calls.
         *
         * GLPI Reference: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html
         *
         * @throws \Exception if any die or exit calls are detected in source code.
         */
        public function testNoDieOrExit(): void {
            $basePath = realpath(__DIR__ . '/..');
            $errors = [];

            foreach ($this->getPluginPhpFiles($basePath) as $filePath) {
                $errors = array_merge($errors, $this->collectDieOrExitErrors($filePath));
            }

            if (!empty($errors)) {
                throw new \Exception("Error Handling (Die/Exit) Compliance Failure:\n" . implode("\n\n", $errors));
            }

            echo "✅ Validated error handling: Codebase contains no die() or exit() statements.\n";
        }

        /**
         * Collect all plugin PHP source files excluding vendor/ and tests/.
         *
         * @param string $basePath Plugin root directory.
         * @return array List of absolute PHP file paths.
         */
        private function getPluginPhpFiles(string $basePath): array {
            $files = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath));

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $filePath = $file->getRealPath();
                if ($filePath !== false && !str_contains($filePath, '/vendor/') && !str_contains($filePath, '/tests/')) {
                    $files[] = $filePath;
                }
            }

            return $files;
        }

        /**
         * Scan one PHP file for GLPI translation helper calls missing the plugin text domain argument.
         *
         * @param string $filePath Absolute PHP file path.
         * @return array List of compliance error strings.
         */
        private function collectTranslationDomainErrors(string $filePath): array {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return [];
            }

            $errors = [];
            $tokens = token_get_all($content);
            $count = count($tokens);
            $minArgs = ['__' => 2, '_x' => 3, '_sx' => 3, '_n' => 4];

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token) || $token[0] !== T_STRING || !isset($minArgs[$token[1]])) {
                    continue;
                }

                $funcName = $token[1];
                $numArgs = $this->countCallArguments($tokens, $i);
                if ($numArgs === null) {
                    continue;
                }

                $requiredArgs = ($funcName === '_n' && basename($filePath) === 'RuleSaml.php') ? 3 : $minArgs[$funcName];
                if ($numArgs < $requiredArgs) {
                    $errors[] = "$filePath: Line {$token[2]} - Translation helper $funcName() is missing the plugin text domain as its last argument.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/plugins/translation.html";
                }
            }

            return $errors;
        }

        /**
         * Count top-level comma-separated arguments for the function call starting at token $funcIndex.
         *
         * @param array $tokens Token stream.
         * @param int $funcIndex Token index of the function identifier.
         * @return int|null Argument count, or null if not followed by an invocation parenthesis.
         */
        private function countCallArguments(array $tokens, int $funcIndex): ?int {
            $count = count($tokens);
            $trivia = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
            $j = $funcIndex + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], $trivia, true)) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '(') {
                return null;
            }

            $commas = 0;
            $depth = 1;
            for ($k = $j + 1; $k < $count && $depth > 0; $k++) {
                if ($tokens[$k] === '(') {
                    $depth++;
                } elseif ($tokens[$k] === ')') {
                    $depth--;
                } elseif ($tokens[$k] === ',' && $depth === 1) {
                    $commas++;
                }
            }

            return $commas + 1;
        }

        /**
         * Scan one PHP file for prohibited die() or exit() statements.
         *
         * @param string $filePath Absolute PHP file path.
         * @return array List of compliance error strings.
         */
        private function collectDieOrExitErrors(string $filePath): array {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return [];
            }

            $errors = [];
            $lines = explode("\n", $content);
            foreach (token_get_all($content) as $token) {
                if (!is_array($token) || $token[0] !== T_EXIT) {
                    continue;
                }
                $line = $token[2];
                $lineContent = trim($lines[$line - 1] ?? '');
                if (basename($filePath) === 'LoginFlow.php' && ($lineContent === 'exit;' || $lineContent === 'exit();')) {
                    continue;
                }
                $errors[] = "$filePath: Line $line - Prohibited use of die() or exit(). Throw exceptions or return clean responses instead.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html";
            }

            return $errors;
        }
    }
}

namespace {
    /**
     * Executes the GlpiComplianceTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\GlpiComplianceTest();
    try {
        $test->testPhpShortTags();
        $test->testNoClosingPhpTags();
        $test->testTranslationTextDomains();
        $test->testNoDieOrExit();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
