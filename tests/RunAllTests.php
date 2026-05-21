<?php
declare(strict_types=1);

/**
 * RunAllTests.php
 * 
 * Central test runner that executes all test suites in isolated processes.
 */

$testDir = __DIR__;
$files = glob($testDir . '/*Test.php');

$results = [];
$allPassed = true;

echo "================================================\n";
echo " GLPI SAMLSSO Plugin - Automated Test Runner\n";
echo "================================================\n\n";

foreach ($files as $file) {
    $filename = basename($file);
    
    $output = [];
    $returnCode = 0;
    exec("php " . escapeshellarg($file) . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        $results[$filename] = '✅ PASSED';
        // Print the individual success lines
        foreach ($output as $line) {
            $trimmedLine = trim($line);
            if (str_starts_with($trimmedLine, '✅')) {
                echo $trimmedLine . "\n";
            }
        }
    } else {
        $results[$filename] = '❌ FAILED';
        $allPassed = false;
        echo "--- FAIL: $filename ---\n";
        echo implode("\n", $output) . "\n\n";
    }
}

echo "\n================================================\n";
echo " FINAL SUMMARY\n";
echo "================================================\n";
foreach ($results as $test => $status) {
    echo str_pad($test, 30) . " $status\n";
}
echo "================================================\n";

if ($allPassed) {
    echo "Overall Status: ALL TESTS PASSED! 🚀\n";
} else {
    echo "Overall Status: SOME TESTS FAILED! 🛑\n";
    exit(1);
}
