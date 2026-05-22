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
 * RunAllTests.php
 * 
 * Central test runner that executes all test suites in isolated processes.
 * Ensures each test file runs in its own process space to prevent shim/class definition conflicts.
 */

/**
 * Get the current test directory.
 */
$testDir = __DIR__;

/**
 * Find all PHP files ending with Test.php in the test directory.
 */
$files = glob($testDir . '/*Test.php');

/**
 * Store the execution results of each test suite.
 */
$results = [];

/**
 * Keep track of whether all tests passed.
 */
$allPassed = true;

echo "================================================\n";
echo " GLPI SAMLSSO Plugin - Automated Test Runner\n";
echo "================================================\n\n";

/**
 * Execute each test file in a separate PHP CLI process.
 */
foreach ($files as $file) {
    $filename = basename($file);
    
    $output = [];
    $returnCode = 0;
    
    /**
     * Run the test file using the current PHP binary and capture output and return code.
     */
    exec("php " . escapeshellarg($file) . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        $results[$filename] = '✅ PASSED';
        
        /**
         * Print individual success symbols/lines from the test output to show granular progress.
         */
        foreach ($output as $line) {
            $trimmedLine = trim($line);
            if (str_starts_with($trimmedLine, '✅')) {
                echo $trimmedLine . "\n";
            }
        }
    } else {
        $results[$filename] = '❌ FAILED';
        $allPassed = false;
        
        /**
         * Output full details of the failure for debugging purposes.
         */
        echo "--- FAIL: $filename ---\n";
        echo implode("\n", $output) . "\n\n";
    }
}

echo "\n================================================\n";
echo " FINAL SUMMARY\n";
echo "================================================\n";

/**
 * Print the summary table containing the status of each test suite.
 */
foreach ($results as $test => $status) {
    echo str_pad($test, 30) . " $status\n";
}
echo "================================================\n";

/**
 * Exit with an error code if any test suite failed, to support CI/CD integration.
 */
if ($allPassed) {
    echo "Overall Status: ALL TESTS PASSED! 🚀\n";
} else {
    echo "Overall Status: SOME TESTS FAILED! 🛑\n";
    exit(1);
}

