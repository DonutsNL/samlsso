<?php
declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/Config/ConfigItem.php';
    require_once __DIR__ . '/../src/Config/ConfigEntity.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\Config\ConfigItem;
    use GlpiPlugin\Samlsso\Config\ConfigEntity;

    class TestableConfigItem extends ConfigItem {
        public function testParseX509Certificate(string $cert): array|bool {
            return $this->parseX509Certificate($cert);
        }
        public function testValidateCertKeyPairModulus(string $cert, string $key): bool {
            return $this->validateCertKeyPairModulus($cert, $key);
        }
    }

    class CertValidationTest extends TestHarness {
        
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

        public function testMalformedCertificate(): void {
            $cert = "NOT A CERTIFICATE";
            $configItem = new TestableConfigItem();
            $result = $configItem->testParseX509Certificate($cert);
            
            if (!isset($result['validations']) || !is_string($result['validations'])) {
                throw new \Exception("Malformed certificate not identified.\nInput: '$cert'");
            }
            echo "✅ Malformed certificate rejection\n";
        }

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
    $test = new GlpiPlugin\Samlsso\Tests\CertValidationTest();
    try {
        $test->testValidCertificate();
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
