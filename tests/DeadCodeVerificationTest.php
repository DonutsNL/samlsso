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
     * Class DeadCodeVerificationTest
     *
     * Validates that all classes, interfaces, traits, and methods defined in the
     * plugin codebase are referenced at least once. If any are unreferenced,
     * it casts a warning rather than failing the test suite.
     */
    class DeadCodeVerificationTest
    {
        /**
         * Standard hook and lifecycle methods defined by GLPI or standard PHP interfaces
         * which are called dynamically by the GLPI Core or PHP engine.
         */
        private const KNOWN_SYSTEM_METHODS = [
            'install',
            'uninstall',
            'getTabNameForItem',
            'defineTabs',
            'showForm',
            'rawSearchOptions',
            'rawSearchOptionsToAdd',
            'getIcon',
            'getTypeName',
            'getMenuContent',
            '__construct',
            '__destruct',
            '__toString',
            'template',
            'execute',
            'register',
            'checkInput',
            'configform',
            'itemform',
            'cronInfo',
            'cronUpdateGeoIP',
            'reApplyRulesOnAuth',
        ];

        /**
         * Scans the codebase for class, interface, trait, and method declarations,
         * checks for references, and prints warnings for any unreferenced elements.
         *
         * @return void
         */
        public function testCodebaseReferences(): void
        {
            $basePath = realpath(__DIR__ . '/..');
            if ($basePath === false) {
                return;
            }

            $srcPath = $basePath . '/src';
            if (!is_dir($srcPath)) {
                return;
            }

            /* Retrieve all source and template files in the codebase */
            $allFiles = $this->collectCodebaseFiles($basePath);

            /* Parse declarations from source files */
            $declarations = $this->parseDeclarations($srcPath);

            $unreferencedClasses = [];
            $unreferencedMethods = [];

            foreach ($declarations as $file => $data) {
                $fileContent = file_get_contents($file);
                if ($fileContent === false) {
                    continue;
                }

                /* Check classes/interfaces/traits references */
                foreach ($data['classes'] as $class) {
                    if (!$this->isElementReferenced($class, $file, $allFiles)) {
                        $unreferencedClasses[] = [
                            'name' => $class,
                            'file' => $file,
                        ];
                    }
                }

                /* Check methods references */
                foreach ($data['methods'] as $method) {
                    if (in_array($method, self::KNOWN_SYSTEM_METHODS, true)) {
                        continue;
                    }

                    if (!$this->isElementReferenced($method, $file, $allFiles)) {
                        $unreferencedMethods[] = [
                            'name' => $method,
                            'file' => $file,
                        ];
                    }
                }
            }

            /* Print warnings for unreferenced components */
            if (!empty($unreferencedClasses) || !empty($unreferencedMethods)) {
                echo "⚠️  WARNING: Unreferenced codebase objects detected!\n";
                foreach ($unreferencedClasses as $item) {
                    echo "   - Class/Interface/Trait '{$item['name']}' in " . basename($item['file']) . " appears to be unreferenced.\n";
                }
                foreach ($unreferencedMethods as $item) {
                    echo "   - Method '{$item['name']}' in " . basename($item['file']) . " appears to be unreferenced.\n";
                }
            }

            echo "✅ DeadCodeVerificationTest completed successfully.\n";
        }

        /**
         * Verifies the detection logic of the verification test itself.
         *
         * @throws \Exception if the mock check behaves incorrectly.
         * @return void
         */
        public function testWarningDetectionLogic(): void
        {
            $allFiles = [__FILE__];

            /* A method name that is definitely not in this file or any other */
            $unreferencedElem = 'definitelyUnreferencedDummyMethod_xyz';
            if ($this->isElementReferenced($unreferencedElem, __FILE__, $allFiles)) {
                throw new \Exception("Analyzer failed: flagged a non-existent method as referenced.");
            }

            /* A method name that is defined and called in this file */
            $referencedElem = 'testCodebaseReferences';
            if (!$this->isElementReferenced($referencedElem, __FILE__, $allFiles)) {
                throw new \Exception("Analyzer failed: flagged a known referenced method as unreferenced.");
            }

            echo "✅ Validated warning detection logic: successfully detected mock dead code.\n";
        }

        /**
         * Collects all PHP, twig, JS, and configuration files in the plugin codebase.
         *
         * @param string $path Base directory path.
         * @return array List of file paths.
         */
        private function collectCodebaseFiles(string $path): array
        {
            $files = [];
            $directoryIterator = new \RecursiveDirectoryIterator($path);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $filePath = $file->getRealPath();
                    if ($filePath === false || str_contains($filePath, '/vendor/')) {
                        continue;
                    }

                    $ext = $file->getExtension();
                    if (in_array($ext, ['php', 'twig', 'js', 'json', 'xml'], true)) {
                        $files[] = $filePath;
                    }
                }
            }

            return $files;
        }

        /**
         * Parses class and method declarations from PHP files.
         *
         * @param string $srcPath Path to the source directory.
         * @return array Declarations mapped by file path.
         */
        private function parseDeclarations(string $srcPath): array
        {
            $declarations = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcPath));

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $filePath = $file->getRealPath();
                $parsed = $filePath !== false ? $this->extractFileDeclarations($filePath) : null;
                if ($parsed !== null) {
                    $declarations[$filePath] = $parsed;
                }
            }

            return $declarations;
        }

        /**
         * Extract declared class/interface/trait and function names from a single PHP file.
         *
         * @param string $filePath Absolute path to PHP file.
         * @return array|null Associative array with 'classes' and 'methods' keys, or null if unreadable.
         */
        private function extractFileDeclarations(string $filePath): ?array
        {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return null;
            }

            $tokens = token_get_all($content);
            $classes = [];
            $methods = [];
            $classTypes = [T_CLASS, T_INTERFACE, T_TRAIT];
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i])) {
                    continue;
                }
                $tokenType = $tokens[$i][0];
                if (in_array($tokenType, $classTypes, true)) {
                    $name = $this->findNextIdentifier($tokens, $i);
                    if ($name !== null) {
                        $classes[] = $name;
                    }
                } elseif ($tokenType === T_FUNCTION) {
                    $name = $this->findNextIdentifier($tokens, $i);
                    if ($name !== null) {
                        $methods[] = $name;
                    }
                }
            }

            return [
                'classes' => array_unique($classes),
                'methods' => array_unique($methods),
            ];
        }

        /**
         * Return the identifier token string immediately following whitespace after a declaration keyword.
         *
         * @param array $tokens Token stream.
         * @param int $index Keyword token index.
         * @return string|null Identifier name or null if none found (e.g. anonymous class or closure).
         */
        private function findNextIdentifier(array $tokens, int $index): ?string
        {
            $count = count($tokens);
            $j = $index + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            return ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING)
                ? $tokens[$j][1]
                : null;
        }

        /**
         * Checks if a class or method name is referenced anywhere in the codebase.
         *
         * @param string $name Name of the element to check.
         * @param string $definitionFile File path where the element is defined.
         * @param array $allFiles List of all codebase files to search.
         * @return bool True if a reference is found.
         */
        private function isElementReferenced(string $name, string $definitionFile, array $allFiles): bool
        {
            foreach ($allFiles as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                if ($file === $definitionFile) {
                    /* If searching the defining file, require at least 2 occurrences */
                    $pattern = '/\b' . preg_quote($name, '/') . '\b/';
                    if (preg_match_all($pattern, $content) > 1) {
                        return true;
                    }
                } else {
                    /* If searching other files, 1 occurrence is sufficient */
                    $pattern = '/\b' . preg_quote($name, '/') . '\b/';
                    if (preg_match($pattern, $content) === 1) {
                        return true;
                    }
                }
            }

            return false;
        }
    }
}

namespace {
    /**
     * Executes the DeadCodeVerificationTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\DeadCodeVerificationTest();
    try {
        $test->testWarningDetectionLogic();
        $test->testCodebaseReferences();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
