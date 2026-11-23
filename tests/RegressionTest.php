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
                $this->inspectPhpFileVendorUsage($file);
            }

            echo "✅ Vendor Libraries Compatibility: All referenced classes, methods, and constants exist and are active (not deprecated)\n";
        }

        /**
         * Inspect a single PHP source file for vendor class, method, and constant compatibility.
         *
         * @param string $file Path to PHP source file.
         * @return void
         * @throws \Exception on missing or deprecated vendor symbols.
         */
        private function inspectPhpFileVendorUsage(string $file): void {
            $content = file_get_contents($file);
            $tokens = token_get_all($content);
            $imports = $this->extractFileImports($tokens);
            $this->scanTokensForVendorCompatibility($tokens, $imports, $file);
        }

        /**
         * Extract imported class alias map from PHP use statements.
         *
         * @param array $tokens Token list from token_get_all().
         * @return array Map of short alias name => FQCN.
         */
        private function extractFileImports(array $tokens): array {
            $imports = [];
            $count = count($tokens);
            $nameTokenTypes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];

            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                    continue;
                }
                $fullClass = '';
                $alias = '';
                $i++;
                while ($i < $count && $tokens[$i] !== ';') {
                    $token = $tokens[$i];
                    if (is_array($token) && in_array($token[0], $nameTokenTypes, true)) {
                        if ($alias !== '') {
                            $alias .= $token[1];
                        } else {
                            $fullClass .= $token[1];
                        }
                    } elseif (is_array($token) && $token[0] === T_AS) {
                        $alias = '';
                    }
                    $i++;
                }
                $fullClass = trim($fullClass);
                if ($alias === '') {
                    $parts = explode('\\', $fullClass);
                    $alias = (string) end($parts);
                } else {
                    $alias = trim($alias);
                }
                $imports[$alias] = $fullClass;
            }

            return $imports;
        }

        /**
         * Resolve a potentially imported or qualified class name against file use-imports.
         *
         * @param string $name Raw class name token text.
         * @param array $imports File use-alias map.
         * @return string Resolved FQCN.
         */
        private function resolveImportedClassName(string $name, array $imports): string {
            $name = ltrim($name, '\\');
            if (isset($imports[$name])) {
                return $imports[$name];
            }
            $parts = explode('\\', $name);
            if (isset($imports[$parts[0]])) {
                $parts[0] = $imports[$parts[0]];
                return implode('\\', $parts);
            }
            return $name;
        }

        /**
         * Check whether a resolved class belongs to tracked external SAML/XML security vendor namespaces.
         *
         * @param string $resolvedClass Resolved FQCN.
         * @return bool
         */
        private function isTrackedVendorClass(string $resolvedClass): bool {
            return str_starts_with($resolvedClass, 'OneLogin\\Saml2\\')
                || str_starts_with($resolvedClass, 'RobRichards\\XMLSecLibs\\');
        }

        /**
         * Advance token index past whitespace and comment trivia tokens.
         *
         * @param array $tokens Token stream.
         * @param int $index Starting token index.
         * @return int First non-trivia index or count($tokens).
         */
        private function skipTriviaForward(array $tokens, int $index): int {
            $count = count($tokens);
            $trivia = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
            while ($index < $count && is_array($tokens[$index]) && in_array($tokens[$index][0], $trivia, true)) {
                $index++;
            }
            return $index;
        }

        /**
         * Read a chain of variable/object property tokens backwards from an operator index.
         *
         * @param array $tokens Token stream.
         * @param int $index Token index immediately before operator.
         * @return array Tuple of [string $expr, int $stopIndex].
         */
        private function readBackwardVariableChain(array $tokens, int $index): array {
            $trivia = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
            $chain = [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR];
            $collected = [];

            while ($index >= 0) {
                $token = $tokens[$index];
                if (is_array($token) && in_array($token[0], $trivia, true)) {
                    $index--;
                    continue;
                }
                if (is_array($token) && in_array($token[0], $chain, true)) {
                    $collected[] = $token[1];
                    $index--;
                    continue;
                }
                break;
            }

            return [trim(implode('', array_reverse($collected))), $index];
        }

        /**
         * Scan tokenized PHP code to track variable types and validate vendor member accesses.
         *
         * @param array $tokens Token stream.
         * @param array $imports File use-alias map.
         * @param string $file Source path for error reporting.
         * @return void
         * @throws \Exception on missing or deprecated vendor symbols.
         */
        private function scanTokensForVendorCompatibility(array $tokens, array $imports, string $file): void {
            $varTypes = [];
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i])) {
                    continue;
                }
                $tokenType = $tokens[$i][0];
                if ($tokenType === T_FUNCTION) {
                    $this->recordFunctionParamTypes($tokens, $i, $imports, $varTypes);
                } elseif ($tokenType === T_NEW) {
                    $this->recordNewInstantiationType($tokens, $i, $imports, $varTypes, $file);
                } elseif (in_array($tokenType, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->validateStaticVendorMember($tokens, $i, $imports, $file);
                } elseif ($tokenType === T_OBJECT_OPERATOR) {
                    $this->validateObjectVendorMethod($tokens, $i, $varTypes, $file);
                }
            }
        }

        /**
         * Parse typed function parameters into $varTypes map.
         *
         * @param array $tokens Token stream.
         * @param int $fnIndex T_FUNCTION token index.
         * @param array $imports File use-alias map.
         * @param array $varTypes Variable type registry passed by reference.
         * @return void
         */
        private function recordFunctionParamTypes(array $tokens, int $fnIndex, array $imports, array &$varTypes): void {
            $count = count($tokens);
            $j = $fnIndex + 1;
            while ($j < $count && $tokens[$j] !== '(') {
                $j++;
            }
            if ($j >= $count) {
                return;
            }

            $j++;
            $trivia = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
            $nameTypes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];

            while ($j < $count && $tokens[$j] !== ')') {
                $paramTokens = [];
                while ($j < $count && $tokens[$j] !== ',' && $tokens[$j] !== ')') {
                    if (!(is_array($tokens[$j]) && in_array($tokens[$j][0], $trivia, true))) {
                        $paramTokens[] = $tokens[$j];
                    }
                    $j++;
                }
                $this->extractSingleParamType($paramTokens, $nameTypes, $imports, $varTypes);
                if ($j < $count && $tokens[$j] === ',') {
                    $j++;
                }
            }
        }

        /**
         * Record type hint for a single parsed parameter token slice.
         *
         * @param array $paramTokens Non-trivia tokens inside one parameter declaration.
         * @param array $nameTypes Token types composing class names.
         * @param array $imports File use-alias map.
         * @param array $varTypes Variable type registry passed by reference.
         * @return void
         */
        private function extractSingleParamType(array $paramTokens, array $nameTypes, array $imports, array &$varTypes): void {
            $paramCount = count($paramTokens);
            if ($paramCount < 2) {
                return;
            }
            $lastToken = $paramTokens[$paramCount - 1];
            if (!is_array($lastToken) || $lastToken[0] !== T_VARIABLE) {
                return;
            }

            $typeParts = [];
            for ($p = 0; $p < $paramCount - 1; $p++) {
                if (is_array($paramTokens[$p]) && in_array($paramTokens[$p][0], $nameTypes, true)) {
                    $typeParts[] = $paramTokens[$p][1];
                }
            }
            if (!empty($typeParts)) {
                $varTypes[$lastToken[1]] = $this->resolveImportedClassName(implode('', $typeParts), $imports);
            }
        }

        /**
         * Validate `new VendorClass()` instantiation and record the assigned variable type.
         *
         * @param array $tokens Token stream.
         * @param int $newIndex T_NEW token index.
         * @param array $imports File use-alias map.
         * @param array $varTypes Variable type registry passed by reference.
         * @param string $file Source path for error reporting.
         * @return void
         * @throws \Exception when instantiated vendor class does not exist.
         */
        private function recordNewInstantiationType(array $tokens, int $newIndex, array $imports, array &$varTypes, string $file): void {
            $count = count($tokens);
            $nameTypes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];
            $trivia = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
            $classTokens = [];

            for ($j = $newIndex + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], $nameTypes, true)) {
                    $classTokens[] = $t[1];
                } elseif (!(is_array($t) && in_array($t[0], $trivia, true))) {
                    break;
                }
            }
            if (empty($classTokens)) {
                return;
            }

            $resolvedClass = $this->resolveImportedClassName(implode('', $classTokens), $imports);
            if (!$this->isTrackedVendorClass($resolvedClass)) {
                return;
            }
            if (!class_exists($resolvedClass) && !interface_exists($resolvedClass)) {
                throw new \Exception("Vendor class '$resolvedClass' does not exist (instantiated in $file).");
            }

            $k = $newIndex - 1;
            while ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], $trivia, true)) {
                $k--;
            }
            if ($k >= 0 && $tokens[$k] === '=') {
                [$varStr] = $this->readBackwardVariableChain($tokens, $k - 1);
                if ($varStr !== '') {
                    $varTypes[$varStr] = $resolvedClass;
                }
            }
        }

        /**
         * Validate static vendor method calls and class constant references (`Class::member`).
         *
         * @param array $tokens Token stream.
         * @param int $classIndex Token index of the class identifier.
         * @param array $imports File use-alias map.
         * @param string $file Source path for error reporting.
         * @return void
         * @throws \Exception when class, method, or constant is missing or deprecated.
         */
        private function validateStaticVendorMember(array $tokens, int $classIndex, array $imports, string $file): void {
            $count = count($tokens);
            if ($classIndex + 1 >= $count || !is_array($tokens[$classIndex + 1]) || $tokens[$classIndex + 1][0] !== T_DOUBLE_COLON) {
                return;
            }

            $resolvedClass = $this->resolveImportedClassName($tokens[$classIndex][1], $imports);
            if (!$this->isTrackedVendorClass($resolvedClass)) {
                return;
            }

            $j = $this->skipTriviaForward($tokens, $classIndex + 2);
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                return;
            }
            $memberName = $tokens[$j][1];
            $k = $this->skipTriviaForward($tokens, $j + 1);
            $isCall = $k < $count && $tokens[$k] === '(';

            $this->assertVendorMemberExists($resolvedClass, $memberName, $isCall, $isCall ? 'static' : 'constant', $file);
        }

        /**
         * Validate method calls on tracked vendor object variables (`$obj->method()`).
         *
         * @param array $tokens Token stream.
         * @param int $opIndex T_OBJECT_OPERATOR token index.
         * @param array $varTypes Variable type registry.
         * @param string $file Source path for error reporting.
         * @return void
         * @throws \Exception when class or method is missing or deprecated.
         */
        private function validateObjectVendorMethod(array $tokens, int $opIndex, array $varTypes, string $file): void {
            [$varStr] = $this->readBackwardVariableChain($tokens, $opIndex - 1);
            if ($varStr === '' || !isset($varTypes[$varStr])) {
                return;
            }

            $resolvedClass = $varTypes[$varStr];
            if (!$this->isTrackedVendorClass($resolvedClass)) {
                return;
            }

            $count = count($tokens);
            $j = $this->skipTriviaForward($tokens, $opIndex + 1);
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                return;
            }
            $methodName = $tokens[$j][1];
            $k = $this->skipTriviaForward($tokens, $j + 1);
            if ($k < $count && $tokens[$k] === '(') {
                $this->assertVendorMemberExists($resolvedClass, $methodName, true, 'method', $file);
            }
        }

        /**
         * Assert via Reflection/class_exists that a vendor class method or constant exists and is not deprecated.
         *
         * @param string $resolvedClass Vendor FQCN.
         * @param string $memberName Method or constant name.
         * @param bool $isCall True for method call, false for class constant.
         * @param string $kind Diagnostic label ('static', 'method', or 'constant').
         * @param string $file Source path for error reporting.
         * @return void
         * @throws \Exception on missing or deprecated vendor member.
         */
        private function assertVendorMemberExists(string $resolvedClass, string $memberName, bool $isCall, string $kind, string $file): void {
            $missingClassLabel = $kind === 'static' ? 'static call' : ($kind === 'constant' ? 'constant reference' : 'method call');
            if (!class_exists($resolvedClass) && !interface_exists($resolvedClass)) {
                throw new \Exception("Vendor class '$resolvedClass' does not exist ($missingClassLabel in $file).");
            }

            if (!$isCall) {
                $refClass = new \ReflectionClass($resolvedClass);
                if (!$refClass->hasConstant($memberName)) {
                    throw new \Exception("Vendor constant '$resolvedClass::$memberName' does not exist (referenced in $file).");
                }
                return;
            }

            if (!method_exists($resolvedClass, $memberName)) {
                $prefix = $kind === 'static' ? 'Vendor static method' : 'Vendor method';
                throw new \Exception("$prefix '$resolvedClass::$memberName' does not exist (called in $file).");
            }
            $refMethod = new \ReflectionMethod($resolvedClass, $memberName);
            if ($refMethod->isDeprecated()) {
                $prefix = $kind === 'static' ? 'Vendor static method' : 'Vendor method';
                throw new \Exception("$prefix '$resolvedClass::$memberName' is deprecated (called in $file).");
            }
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
