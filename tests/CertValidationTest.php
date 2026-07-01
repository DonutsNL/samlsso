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
 *  @version    1.3.2
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
 * CertValidationTest.php
 * 
 * Unit tests validating x509 certificate parsing, malformed certificate rejection,
 * and certificate/private key modulus matching logic.
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/Config/ConfigItem.php';
    require_once __DIR__ . '/../src/Config/ConfigEntity.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ConfigItem;
    use GlpiPlugin\Samlsso\Config\ConfigEntity;

    /**
     * TestableConfigItem subclass.
     * Exposes protected helper methods of ConfigItem for testing.
     */
    class TestableConfigItem extends ConfigItem {
        /**
         * Exposes protected parseX509Certificate method.
         *
         * @param string $cert Pem certificate block.
         * @return array|bool Parsed certificate metadata, or false if invalid.
         */
        public function testParseX509Certificate(string $cert): array|bool {
            return $this->parseX509Certificate($cert);
        }

        /**
         * Exposes protected validateCertKeyPairModulus method.
         *
         * @param string $cert Pem certificate block.
         * @param string $key Pem private key block.
         * @return bool True if modulus matches.
         */
        public function testValidateCertKeyPairModulus(string $cert, string $key): bool {
            return $this->validateCertKeyPairModulus($cert, $key);
        }
    }

    /**
     * CertValidationTest class.
     * Validates cryptographic and formatting checks on SAML certificates and keys.
     */
    class CertValidationTest extends TestHarness {
        
        /**
         * Generates a valid temporary self-signed x509 certificate block for testing.
         *
         * @return string Pem encoded certificate.
         */
        private function generateValidCert(): string {
            $res = \openssl_pkey_new([
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ]);
            $dn = ["countryName" => "NL", "organizationName" => "Test", "commonName" => "localhost"];
            $csr = \openssl_csr_new($dn, $res);
            $certRes = \openssl_csr_sign($csr, null, $res, 365);
            \openssl_x509_export($certRes, $certStr);
            return $certStr;
        }

        /**
         * Returns an IdP certificate whose validTo value is represented by
         * OpenSSL as GeneralizedTime because the expiry year is 2050.
         *
         * @return string Pem encoded certificate.
         */
        private function getGeneralizedTimeCert(): string {
            return <<<'CERT'
-----BEGIN CERTIFICATE-----
MIIDVTCCAj2gAwIBAgIUHWkLB0XI93oWDdwAwHWLur8/L4IwDQYJKoZIhvcNAQEL
BQAwOTELMAkGA1UEBhMCWloxEDAOBgNVBAoMB0V4YW1wbGUxGDAWBgNVBAMMD2lk
cC5leGFtcGxlLmNvbTAgFw0yNjA2MjkxMzMwNDVaGA8yMDUwMDYyOTEzMzA0NVow
OTELMAkGA1UEBhMCWloxEDAOBgNVBAoMB0V4YW1wbGUxGDAWBgNVBAMMD2lkcC5l
eGFtcGxlLmNvbTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAMo2NVs2
1mp0bSWKIGoOXIAb9tyvPUFpYTkYhoBhYUvWwTebWJGx+ectc3p4dauKOlMCbuNK
vFT09IeMIURZD+Lk/iVye8b22X4vspcyADj6mG3XMyPMBZJEo/PuNdvWRYfxO2oV
O72Qtf0Xmr3G7iN1Ug0OAAKyDULUQZvEcoq1q3V59vUqQMeEDbm5+IXRwP/snaCD
KWLKQCZSuD+16QfISMDWcMQpq9DIwFoQZVZ1O31Oprl6OzzJMTnBFdlUSEIYAkOR
85KVuCLaF5cuZpN8BfacVR1ld/edkNwFJ5SWFhPCWifqGO63WFsfvdeKkMs0uMG4
LXOg8ju7lPh+oyECAwEAAaNTMFEwHQYDVR0OBBYEFE20FudzZkRUk3wc6pPIr9p6
1AxMMB8GA1UdIwQYMBaAFE20FudzZkRUk3wc6pPIr9p61AxMMA8GA1UdEwEB/wQF
MAMBAf8wDQYJKoZIhvcNAQELBQADggEBAJN0IHjFSME+utsgymY+ODXJzZvwzpRc
tfaYZKY2J2TmpaKccrMYWmGi38UyJYq8Lxqe8wHvEQAegJGejqA+NdhFidLAVshR
Ur1GpR4NtKpE3/2ghQ78jrBmPM1reLv82A2Hqi2NaLjt9AsOdNofaf5LAUG5G5Is
rwFkfQ7J6sQKhbbvGxAWn9H+aA0uueoNMd4KSW7vSqXsRx4p21ExDnLoGCqksqjm
1/wIWWFnWT9uvqR6ufN3LlxpyQkRKyAG6r63GO7zLouHxgk+PzoP17apUHF7kvuK
kZwRN56BOwZzX3NO6AxOZWfm223o5LtDT58by1Ib7V+CLYYqIVfyDfU=
-----END CERTIFICATE-----
CERT;
        }

        /**
         * Test that a valid x509 certificate string is successfully parsed.
         *
         * @throws \Exception if the valid certificate is rejected.
         */
        public function testValidCertificate(): void {
            $cert = $this->generateValidCert();
            $configItem = new TestableConfigItem();
            $result = $configItem->testParseX509Certificate($cert);
            
            if ($result === false) {
                throw new \Exception("Valid certificate rejected.\nInput: '$cert'\nResult: FALSE");
            }
            if (!isset($result['validations']) || !is_array($result['validations'])) {
                throw new \Exception("Valid certificate missing 'validations' array.");
            }
            echo "✅ Valid X509 certificate parsing\n";
        }

        /**
         * Test that a certificate expiring in 2050 parses without validTo errors.
         *
         * @throws \Exception if GeneralizedTime validity dates are rejected.
         */
        public function testGeneralizedTimeCertificate(): void {
            $configItem = new TestableConfigItem();
            $result = $configItem->testParseX509Certificate($this->getGeneralizedTimeCert());

            if ($result === false) {
                throw new \Exception("GeneralizedTime certificate rejected.");
            }
            if (($result['subject']['CN'] ?? '') !== 'idp.example.com') {
                throw new \Exception("GeneralizedTime certificate subject was not parsed.");
            }
            if (!empty($result['validations'])) {
                throw new \Exception("GeneralizedTime certificate produced validation errors: " . print_r($result['validations'], true));
            }
            echo "✅ GeneralizedTime X509 certificate parsing\n";
        }

        /**
         * Test that a malformed certificate string is rejected with an error message.
         *
         * @throws \Exception if malformed certificate is not rejected properly.
         */
        public function testMalformedCertificate(): void {
            $cert = "NOT A CERTIFICATE";
            $configItem = new TestableConfigItem();
            $result = $configItem->testParseX509Certificate($cert);
            
            if (!isset($result['validations']) || !is_string($result['validations'])) {
                throw new \Exception("Malformed certificate not identified.\nInput: '$cert'");
            }
            echo "✅ Malformed certificate rejection\n";
        }

        /**
         * Test that certificate and private key modulus matching verifies correctly.
         *
         * @throws \Exception if modulus checking fails or accepts mismatching keys.
         */
        public function testModulusMatching(): void {
            $res = \openssl_pkey_new(["private_key_bits" => 2048]);
            \openssl_pkey_export($res, $privKey);
            $dn = ["countryName" => "NL", "commonName" => "localhost"];
            $csr = \openssl_csr_new($dn, $res);
            $certRes = \openssl_csr_sign($csr, null, $res, 365);
            \openssl_x509_export($certRes, $certStr);

            $configItem = new TestableConfigItem();
            if (!$configItem->testValidateCertKeyPairModulus($certStr, $privKey)) {
                throw new \Exception("Matching modulus rejected.");
            }
            
            $res2 = \openssl_pkey_new(["private_key_bits" => 2048]);
            \openssl_pkey_export($res2, $privKey2);
            if ($configItem->testValidateCertKeyPairModulus($certStr, $privKey2)) {
                throw new \Exception("Mismatching modulus accepted.");
            }
            echo "✅ Certificate/Key modulus matching\n";
        }
    }
}

namespace {
    /**
     * Runs the CertValidationTest test suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\CertValidationTest();
    try {
        $test->testValidCertificate();
        $test->testGeneralizedTimeCertificate();
        $test->testMalformedCertificate();
        if (function_exists('openssl_pkey_new')) {
            $test->testModulusMatching();
        }
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
