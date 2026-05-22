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
     * Class VersionAndCopyrightTest
     *
     * Validates that all codebase PHP files contain the standard copyright header,
     * that their @version tags align with the version declared in setup.php, and
     * that samlsso.xml is syntactically valid and has the correct version download listed.
     */
    class VersionAndCopyrightTest {

        /**
         * Test that all PHP files contain the correct copyright header and matching version.
         *
         * @throws \Exception if any file is missing the copyright header or has an outdated version.
         */
        public function testPhpFilesCopyrightAndVersion(): void {
            $basePath = realpath(__DIR__ . '/..');
            if ($basePath === false) {
                throw new \Exception("Could not resolve base path.");
            }

            $setupPath = $basePath . '/setup.php';
            if (!file_exists($setupPath)) {
                throw new \Exception("setup.php not found at $setupPath");
            }

            $setupContent = file_get_contents($setupPath);
            if ($setupContent === false) {
                throw new \Exception("Failed to read setup.php");
            }

            if (!preg_match("/define\('PLUGIN_SAMLSSO_VERSION',\s*'([^']+)'\)/", $setupContent, $matches)) {
                throw new \Exception("PLUGIN_SAMLSSO_VERSION definition not found in setup.php");
            }

            $targetVersion = $matches[1];
            $errors = [];

            $directoryIterator = new \RecursiveDirectoryIterator($basePath);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    if ($filePath === false) {
                        continue;
                    }

                    if (str_contains($filePath, '/vendor/')) {
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    if ($content === false) {
                        $errors[] = "$filePath: Failed to read file.";
                        continue;
                    }

                    if (!preg_match('/Copyright\s*\(C\)\s*2024\s*(by)?\s*Chris Gralike/i', $content)) {
                        $errors[] = "$filePath: Missing copyright header.";
                        continue;
                    }

                    if (!preg_match('/@version\s+([0-9\.]+)/', $content, $vMatches)) {
                        $errors[] = "$filePath: Missing @version tag in header.";
                        continue;
                    }

                    $fileVersion = $vMatches[1];
                    if ($fileVersion !== $targetVersion) {
                        $errors[] = "$filePath: Version mismatch. Expected '$targetVersion', found '$fileVersion'.";
                    }
                }
            }

            if (!empty($errors)) {
                throw new \Exception("Version/Copyright validation failed:\n" . implode("\n", $errors));
            }

            echo "✅ Validated all PHP files: Copyright headers present and versions aligned.\n";
        }

        /**
         * Test that samlsso.xml is valid XML and has the required version download listed.
         *
         * @throws \Exception if samlsso.xml is invalid or missing the required version block.
         */
        public function testSamlSsoXmlValidityAndDownload(): void {
            $basePath = realpath(__DIR__ . '/..');
            if ($basePath === false) {
                throw new \Exception("Could not resolve base path.");
            }

            $setupPath = $basePath . '/setup.php';
            if (!file_exists($setupPath)) {
                throw new \Exception("setup.php not found at $setupPath");
            }

            $setupContent = file_get_contents($setupPath);
            if ($setupContent === false) {
                throw new \Exception("Failed to read setup.php");
            }

            if (!preg_match("/define\('PLUGIN_SAMLSSO_VERSION',\s*'([^']+)'\)/", $setupContent, $matches)) {
                throw new \Exception("PLUGIN_SAMLSSO_VERSION definition not found in setup.php");
            }

            $targetVersion = $matches[1];

            $xmlPath = $basePath . '/samlsso.xml';
            if (!file_exists($xmlPath)) {
                throw new \Exception("samlsso.xml not found at $xmlPath");
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($xmlPath);
            if ($xml === false) {
                $xmlErrors = libxml_get_errors();
                $errMsgs = [];
                foreach ($xmlErrors as $err) {
                    $errMsgs[] = trim($err->message) . " on line " . $err->line;
                }
                libxml_clear_errors();
                throw new \Exception("samlsso.xml is not valid XML:\n" . implode("\n", $errMsgs));
            }

            $versionFound = false;
            if (isset($xml->versions->version)) {
                foreach ($xml->versions->version as $v) {
                    if ((string)$v->num === $targetVersion) {
                        $downloadUrl = (string)$v->download_url;
                        if (!str_contains($downloadUrl, "v{$targetVersion}/samlsso.zip")) {
                            throw new \Exception("samlsso.xml version $targetVersion listed, but download_url is invalid: $downloadUrl");
                        }
                        $versionFound = true;
                        break;
                    }
                }
            }

            if (!$versionFound) {
                throw new \Exception("samlsso.xml is missing a <version> block for version $targetVersion under <versions>.");
            }

            echo "✅ Validated samlsso.xml: Valid XML and version $targetVersion listed with download URL.\n";
        }
    }
}

namespace {
    /**
     * Executes the VersionAndCopyrightTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\VersionAndCopyrightTest();
    try {
        $test->testPhpFilesCopyrightAndVersion();
        $test->testSamlSsoXmlValidityAndDownload();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
