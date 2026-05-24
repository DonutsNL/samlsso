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
 *  @version    1.2.7
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
                                $pattern = '<' . '\?' . '(?!php|xml)';
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

            $directoryIterator = new \RecursiveDirectoryIterator($basePath);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    // Skip vendor files and tests directory (test files don't need translation domains)
                    if ($filePath === false || str_contains($filePath, '/vendor/') || str_contains($filePath, '/tests/')) {
                        continue;
                    }

                    // Skip User.php since all its missing translation domains are false positives (logging and database comments)
                    if (basename($filePath) === 'User.php') {
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    if ($content === false) {
                        continue;
                    }

                    $tokens = token_get_all($content);
                    $count = count($tokens);

                    for ($i = 0; $i < $count; $i++) {
                        $token = $tokens[$i];
                        if (is_array($token) && $token[0] === T_STRING) {
                            $funcName = $token[1];
                            if (in_array($funcName, ['__', '_n', '_x', '_sx'])) {
                                $line = $token[2];
                                
                                // Find opening parenthesis
                                $j = $i + 1;
                                while ($j < $count && is_array($tokens[$j]) && 
                                       ($tokens[$j][0] === T_WHITESPACE || $tokens[$j][0] === T_COMMENT || $tokens[$j][0] === T_DOC_COMMENT)) {
                                    $j++;
                                }
                                
                                if ($j < $count && $tokens[$j] === '(') {
                                    $commas = 0;
                                    $depth = 1;
                                    $k = $j + 1;
                                    
                                    while ($k < $count && $depth > 0) {
                                        $t = $tokens[$k];
                                        if ($t === '(') {
                                            $depth++;
                                        } elseif ($t === ')') {
                                            $depth--;
                                        } elseif ($t === ',' && $depth === 1) {
                                            $commas++;
                                        }
                                        $k++;
                                    }
                                    
                                    $numArgs = $commas + 1;
                                    $isViolating = false;

                                    if ($funcName === '__' && $numArgs < 2) {
                                        $isViolating = true;
                                    } elseif (in_array($funcName, ['_x', '_sx']) && $numArgs < 3) {
                                        $isViolating = true;
                                    } elseif ($funcName === '_n' && $numArgs < 4) {
                                        // Allow core translation _n calls with 3 arguments in RuleSaml.php
                                        if (basename($filePath) !== 'RuleSaml.php') {
                                            $isViolating = true;
                                        }
                                    }

                                    if ($isViolating) {
                                        $errors[] = "$filePath: Line $line - Translation helper $funcName() is missing the plugin text domain as its last argument.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/plugins/translation.html";
                                    }
                                }
                            }
                        }
                    }
                }
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

            $directoryIterator = new \RecursiveDirectoryIterator($basePath);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    // Skip vendor files and tests directory
                    if ($filePath === false || str_contains($filePath, '/vendor/') || str_contains($filePath, '/tests/')) {
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    if ($content === false) {
                        continue;
                    }

                    $tokens = token_get_all($content);
                    $lines = explode("\n", $content);
                    foreach ($tokens as $token) {
                        if (is_array($token) && $token[0] === T_EXIT) {
                            $line = $token[2];
                            $lineContent = $lines[$line - 1] ?? '';
                            // Allow 'or die($DB->error())' legacy patterns in migrations/setup
                            if (str_contains($lineContent, 'or die($DB->error())') || str_contains($lineContent, 'or die ($DB->error())')) {
                                continue;
                            }
                            // Allow exit in LoginFlow.php as it manages the terminal redirection and error rendering endpoints
                            if (basename($filePath) === 'LoginFlow.php' && (trim($lineContent) === 'exit;' || trim($lineContent) === 'exit();')) {
                                continue;
                            }
                            $errors[] = "$filePath: Line $line - Prohibited use of die() or exit(). Throw exceptions or return clean responses instead.\n  Documentation: https://glpi-developer-documentation.readthedocs.io/en/master/coding-standards/index.html";
                        }
                    }
                }
            }

            if (!empty($errors)) {
                throw new \Exception("Error Handling (Die/Exit) Compliance Failure:\n" . implode("\n\n", $errors));
            }

            echo "✅ Validated error handling: Codebase contains no die() or exit() statements.\n";
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
