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

/**
 * RegressionTest.php
 * 
 * Unit/static analysis tests verifying code constraints to prevent future regressions.
 * Specifically checks that:
 * 1. Prohibited direct database query calls ($DB->query/queryOrDie) are not used.
 * 2. Vendor libraries (OneLogin, XMLSecLibs) referenced in the codebase are valid and not deprecated.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';

    /**
     * RegressionTest class.
     * Analyzes source files for coding standard regressions and vendor API compatibility.
     */
    class RegressionTest extends TestHarness {

        /**
         * Recursively retrieves all PHP files under a given directory.
         *
         * @param string $dir Path to the target directory.
         * @return array List of absolute PHP file paths.
         */
        private function getPhpFiles(string $dir): array {
            $files = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
            return $files;
        }

        /**
         * Verifies that the codebase does not contain prohibited direct database queries.
         *
         * @throws \Exception if a direct $DB->query() or $DB->queryOrDie() call is detected.
         */
        public function testDirectDatabaseQueries(): void {
            $pluginDir = dirname(__DIR__);
            $files = $this->getPhpFiles($pluginDir . '/src');
            $files[] = $pluginDir . '/setup.php';
            $files[] = $pluginDir . '/hook.php';

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (preg_match('/\$DB\s*->\s*(query|queryOrDie)\s*\(/', $content, $matches)) {
                    throw new \Exception("Prohibited direct database query method '{$matches[1]}' used in $file");
                }
            }

            echo "✅ Database Queries: No prohibited direct query calls (\$DB->query/queryOrDie) found\n";
        }

        /**
         * Validates that all classes, methods, and constants referenced from vendor libraries exist
         * and are not deprecated in the currently installed vendor packages.
         *
         * @throws \Exception if any class, method, or constant doesn't exist, or is deprecated.
         */
        public function testVendorLibrariesCompatibility(): void {
            $pluginDir = dirname(__DIR__);
            $files = $this->getPhpFiles($pluginDir . '/src');
            $files[] = $pluginDir . '/setup.php';
            $files[] = $pluginDir . '/hook.php';

            foreach ($files as $file) {
                $content = file_get_contents($file);
                $tokens = token_get_all($content);
                $count = count($tokens);

                $imports = [];
                
                /**
                 * First pass: extract imports (use statements) from the file tokens.
                 */
                for ($i = 0; $i < $count; $i++) {
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                        $fullClass = '';
                        $alias = '';
                        $i++;
                        while ($i < $count && $tokens[$i] !== ';') {
                            $token = $tokens[$i];
                            if (is_array($token)) {
                                if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                                    if ($alias !== '') {
                                        $alias .= $token[1];
                                    } else {
                                        $fullClass .= $token[1];
                                    }
                                } elseif ($token[0] === T_AS) {
                                    $alias = ''; 
                                }
                            }
                            $i++;
                        }
                        $fullClass = trim($fullClass);
                        if ($alias === '') {
                            $parts = explode('\\', $fullClass);
                            $alias = end($parts);
                        } else {
                            $alias = trim($alias);
                        }
                        $imports[$alias] = $fullClass;
                    }
                }

                /**
                 * Helper closure to resolve standard class names against imports.
                 *
                 * @param string $name Class name to resolve.
                 * @return string Fully qualified resolved class name.
                 */
                $resolveClass = function(string $name) use ($imports): string {
                    $name = ltrim($name, '\\');
                    if (isset($imports[$name])) {
                        return $imports[$name];
                    }
                    $parts = explode('\\', $name);
                    $first = $parts[0];
                    if (isset($imports[$first])) {
                        $parts[0] = $imports[$first];
                        return implode('\\', $parts);
                    }
                    return $name;
                };

                /**
                 * Second pass: track variable types and validate member calls.
                 */
                $varTypes = [];
                for ($i = 0; $i < $count; $i++) {
                    /**
                     * Extract function parameter types to build variable type registry.
                     */
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                        $j = $i + 1;
                        while ($j < $count && $tokens[$j] !== '(') {
                            $j++;
                        }
                        if ($j < $count) {
                            $j++; /** skip '(' symbol */
                            while ($j < $count && $tokens[$j] !== ')') {
                                $paramTokens = [];
                                while ($j < $count && $tokens[$j] !== ',' && $tokens[$j] !== ')') {
                                    $t = $tokens[$j];
                                    if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                        /** skip comment and whitespace tokens */
                                    } else {
                                        $paramTokens[] = $t;
                                    }
                                    $j++;
                                }
                                $paramCount = count($paramTokens);
                                if ($paramCount >= 2) {
                                    $lastToken = $paramTokens[$paramCount - 1];
                                    if (is_array($lastToken) && $lastToken[0] === T_VARIABLE) {
                                        $varName = $lastToken[1];
                                        $typeParts = [];
                                        for ($p = 0; $p < $paramCount - 1; $p++) {
                                            $pt = $paramTokens[$p];
                                            if (is_array($pt) && in_array($pt[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                                                $typeParts[] = $pt[1];
                                            }
                                        }
                                        if (!empty($typeParts)) {
                                            $typeStr = implode('', $typeParts);
                                            $varTypes[$varName] = $resolveClass($typeStr);
                                        }
                                    }
                                }
                                if ($j < $count && $tokens[$j] === ',') {
                                    $j++; 
                                }
                            }
                        }
                    }

                    /**
                     * Detect instantiations (new ClassName) and assign types to variables.
                     */
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_NEW) {
                        $classTokens = [];
                        $j = $i + 1;
                        while ($j < $count) {
                            $t = $tokens[$j];
                            if (is_array($t)) {
                                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                    /** skip */
                                } elseif (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                                    $classTokens[] = $t[1];
                                } else {
                                    break;
                                }
                            } else {
                                break;
                            }
                            $j++;
                        }
                        if (!empty($classTokens)) {
                            $className = implode('', $classTokens);
                            $resolvedClass = $resolveClass($className);
                            
                            if (str_starts_with($resolvedClass, 'OneLogin\\Saml2\\') || str_starts_with($resolvedClass, 'RobRichards\\XMLSecLibs\\')) {
                                if (!class_exists($resolvedClass) && !interface_exists($resolvedClass)) {
                                    throw new \Exception("Vendor class '$resolvedClass' does not exist (instantiated in $file).");
                                }
                                
                                /**
                                 * Look behind for the assignment variable.
                                 */
                                $k = $i - 1;
                                while ($k >= 0) {
                                    $t = $tokens[$k];
                                    if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                        $k--;
                                        continue;
                                    }
                                    if ($t === '=') {
                                        $k--;
                                        $assignTokens = [];
                                        while ($k >= 0) {
                                            $pt = $tokens[$k];
                                            if (is_array($pt) && in_array($pt[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                                $k--;
                                                continue;
                                            }
                                            if (is_array($pt) && in_array($pt[0], [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR])) {
                                                $assignTokens[] = $pt;
                                                $k--;
                                            } else {
                                                break;
                                            }
                                        }
                                        if (!empty($assignTokens)) {
                                            $assignTokens = array_reverse($assignTokens);
                                            $varStr = '';
                                            foreach ($assignTokens as $at) {
                                                $varStr .= $at[1];
                                            }
                                            $varTypes[trim($varStr)] = $resolvedClass;
                                        }
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    /**
                     * Check static calls (ClassName::method) and class constants.
                     */
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        if ($i + 1 < $count && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON) {
                            $className = $tokens[$i][1];
                            $resolvedClass = $resolveClass($className);
                            if (str_starts_with($resolvedClass, 'OneLogin\\Saml2\\') || str_starts_with($resolvedClass, 'RobRichards\\XMLSecLibs\\')) {
                                $j = $i + 2;
                                while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                    $j++;
                                }
                                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                    $memberName = $tokens[$j][1];
                                    $k = $j + 1;
                                    while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                        $k++;
                                    }
                                    if ($k < $count && $tokens[$k] === '(') {
                                        /**
                                         * Static Method Call validation.
                                         */
                                        if (!class_exists($resolvedClass) && !interface_exists($resolvedClass)) {
                                            throw new \Exception("Vendor class '$resolvedClass' does not exist (static call in $file).");
                                        }
                                        if (!method_exists($resolvedClass, $memberName)) {
                                            throw new \Exception("Vendor static method '$resolvedClass::$memberName' does not exist (called in $file).");
                                        }
                                        $refMethod = new \ReflectionMethod($resolvedClass, $memberName);
                                        if ($refMethod->isDeprecated()) {
                                            throw new \Exception("Vendor static method '$resolvedClass::$memberName' is deprecated (called in $file).");
                                        }
                                    } else {
                                        /**
                                         * Constant Reference validation.
                                         */
                                        if (!class_exists($resolvedClass) && !interface_exists($resolvedClass)) {
                                            throw new \Exception("Vendor class '$resolvedClass' does not exist (constant reference in $file).");
                                        }
                                        $refClass = new \ReflectionClass($resolvedClass);
                                        if (!$refClass->hasConstant($memberName)) {
                                            throw new \Exception("Vendor constant '$resolvedClass::$memberName' does not exist (referenced in $file).");
                                        }
                                    }
                                }
                            }
                        }
                    }

                    /**
                     * Check object method calls ($object->method).
                     */
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_OBJECT_OPERATOR) {
                        $k = $i - 1;
                        $varTokens = [];
                        while ($k >= 0) {
                            $t = $tokens[$k];
                            if (is_array($t)) {
                                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                    $k--;
                                    continue;
                                }
                                if (in_array($t[0], [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR])) {
                                    $varTokens[] = $t;
                                    $k--;
                                } else {
                                    break;
                                }
                            } else {
                                break;
                            }
                        }
                        if (!empty($varTokens)) {
                            $varTokens = array_reverse($varTokens);
                            $varStr = '';
                            foreach ($varTokens as $vt) {
                                $varStr .= $vt[1];
                            }
                            $varStr = trim($varStr);
                            if (isset($varTypes[$varStr])) {
                                $resolvedClass = $varTypes[$varStr];
                                if (!(str_starts_with($resolvedClass, 'OneLogin\\Saml2\\') || str_starts_with($resolvedClass, 'RobRichards\\XMLSecLibs\\'))) {
                                    continue;
                                }
                                $j = $i + 1;
                                while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                    $j++;
                                }
                                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                    $methodName = $tokens[$j][1];
                                    $k = $j + 1;
                                    while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                        $k++;
                                    }
                                    if ($k < $count && $tokens[$k] === '(') {
                                        if (!class_exists($resolvedClass) && !interface_exists($resolvedClass)) {
                                            throw new \Exception("Vendor class '$resolvedClass' does not exist (method call in $file).");
                                        }
                                        if (!method_exists($resolvedClass, $methodName)) {
                                            throw new \Exception("Vendor method '$resolvedClass::$methodName' does not exist (called in $file).");
                                        }
                                        $refMethod = new \ReflectionMethod($resolvedClass, $methodName);
                                        if ($refMethod->isDeprecated()) {
                                            throw new \Exception("Vendor method '$resolvedClass::$methodName' is deprecated (called in $file).");
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            echo "✅ Vendor Libraries Compatibility: All referenced classes, methods, and constants exist and are active (not deprecated)\n";
        }
    }
}

namespace {
    /**
     * Executes the RegressionTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\RegressionTest();
    try {
        $test->testDirectDatabaseQueries();
        $test->testVendorLibrariesCompatibility();
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
