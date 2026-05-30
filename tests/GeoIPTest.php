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

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';
    require_once __DIR__ . '/../src/Utility/GeoIPResolver.php';

    use GlpiPlugin\Samlsso\Utility\GeoIPResolver;

    /**
     * Class GeoIPTest
     * Verifies offline IP country and flag resolution.
     */
    class GeoIPTest extends TestHarness
    {
        /**
         * Test basic country lookup of known IP ranges in default test database.
         *
         * @throws \Exception
         */
        public function testGeoIPResolution(): void
        {
            // Test NL range
            $countryNL = GeoIPResolver::resolveCountry('81.204.2.126');
            if ($countryNL !== 'NL') {
                throw new \Exception("Failed to resolve 81.204.2.126 as NL, got: " . var_export($countryNL, true));
            }

            // Test US range
            $countryUS = GeoIPResolver::resolveCountry('8.8.8.8');
            if ($countryUS !== 'US') {
                throw new \Exception("Failed to resolve 8.8.8.8 as US, got: " . var_export($countryUS, true));
            }

            // Test AU range
            $countryAU = GeoIPResolver::resolveCountry('1.1.1.1');
            if ($countryAU !== 'AU') {
                throw new \Exception("Failed to resolve 1.1.1.1 as AU, got: " . var_export($countryAU, true));
            }

            // Test local/private IP returns fallback '??'
            $countryLocal = GeoIPResolver::resolveCountry('127.0.0.1');
            if ($countryLocal !== '??') {
                throw new \Exception("Expected '??' for local IP, got: " . var_export($countryLocal, true));
            }

            echo "✅ GeoIP: country code lookup from IP address verified\n";
        }

        /**
         * Test conversion of country codes to flags and names.
         *
         * @throws \Exception
         */
        public function testGeoIPFlagAndNameMapping(): void
        {
            // Test country name resolution
            $nameNL = GeoIPResolver::countryCodeToName('NL');
            if ($nameNL !== 'Netherlands') {
                throw new \Exception("Expected 'Netherlands', got: " . var_export($nameNL, true));
            }

            // Test flag mapping
            $flagNL = GeoIPResolver::countryCodeToFlag('NL');
            // 'NL' flag emoji consists of regional indicators N (U+1F1F3) and L (U+1F1F1)
            $expectedFlagNL = "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB1"; 
            if ($flagNL !== $expectedFlagNL) {
                throw new \Exception("Unexpected flag emoji for NL: " . bin2hex($flagNL));
            }

            // Test fallback/empty input
            $flagEmpty = GeoIPResolver::countryCodeToFlag('??');
            if ($flagEmpty !== '') {
                throw new \Exception("Expected empty flag for '??', got: " . var_export($flagEmpty, true));
            }

            echo "✅ GeoIP: flag emoji and country name translation verified\n";
        }
    }
}

namespace {
    // Run tests directly if invoked from command line
    $test = new GlpiPlugin\Samlsso\Tests\GeoIPTest();
    try {
        $test->testGeoIPResolution();
        $test->testGeoIPFlagAndNameMapping();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
