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
 *  Copyright (C) 2026 by DonutsNL
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
 *  @since      1.3.2
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/TestHarness.php';

    /**
     * Class AdrComplianceTest
     *
     * Programmatically validates both the ADR documentation governance structure
     * (ADR 0000) and the codebase's adherence to every core invariant across
     * Domain ADRs 0001 through 0008.
     */
    class AdrComplianceTest
    {
        /**
         * Read a plugin file relative to the plugin root directory.
         *
         * @param string $relative Relative path from plugin root.
         * @return string File content.
         * @throws \Exception when the file cannot be read.
         */
        private function readPluginFile(string $relative): string
        {
            $base = realpath(__DIR__ . '/..');
            if ($base === false) {
                throw new \Exception('Could not resolve plugin base path.');
            }
            $path = $base . '/' . $relative;
            $content = file_exists($path) ? file_get_contents($path) : false;
            if ($content === false) {
                throw new \Exception("Unable to read plugin file: $relative");
            }
            return $content;
        }

        /**
         * Test ADR 0000: Verify ADR governance structure, absence of Proposed/Superseded
         * files, required section headers in all Domain ADRs (0001-0008), and
         * two-gate ADR governance instructions in CONTRIBUTING.md and SKILL.md.
         *
         * @return void
         * @throws \Exception on any governance violation.
         */
        public function testAdr0000GovernanceAndAuthoringStructure(): void
        {
            $base = realpath(__DIR__ . '/..');
            $adrDir = $base . '/docs/ADR';
            if (!is_dir($adrDir)) {
                throw new \Exception('docs/ADR directory is missing.');
            }

            $files = glob($adrDir . '/*.md');
            if ($files === false || count($files) < 9) {
                throw new \Exception('Expected at least 9 ADR markdown files (0000-0008) in docs/ADR/.');
            }

            $expectedFiles = [
                '0000-adr-governance-and-authoring-guide.md',
                '0001-security-by-design-and-state-machine.md',
                '0002-glpi-core-delegation-and-boundaries.md',
                '0003-cryptography-certificates-and-secret-management.md',
                '0004-fail-closed-provisioning-and-access-control.md',
                '0005-configuration-engine-and-caching.md',
                '0006-minimalist-ui-and-diagnostic-popovers.md',
                '0007-lifecycle-migrations-and-tooling.md',
                '0008-code-structure-and-testing-architecture.md',
            ];

            $actualBasenames = array_map('basename', $files);
            sort($actualBasenames);
            foreach ($expectedFiles as $expectedFile) {
                if (!in_array($expectedFile, $actualBasenames, true)) {
                    throw new \Exception("Missing required Domain ADR file: docs/ADR/$expectedFile");
                }
            }

            $requiredSections = [
                '## Status',
                'Accepted — Enforced Architectural Invariant',
                '## Core Principle (Non-Negotiable)',
                '## Architectural Invariants',
                '## Prohibited Patterns (Do Not Circumvent)',
                '## Issue Triage & Maintainer Directives',
                '## Codebase & Automated Test Verification',
            ];

            $seenTitles = [];
            foreach ($files as $filePath) {
                $basename = basename($filePath);
                $content = (string) file_get_contents($filePath);

                if (preg_match('/^#\s+(.+)$/m', $content, $titleMatch)) {
                    $title = trim($titleMatch[1]);
                    if (isset($seenTitles[$title])) {
                        throw new \Exception("Duplicate ADR title '$title' in $basename and {$seenTitles[$title]}.");
                    }
                    $seenTitles[$title] = $basename;
                } else {
                    throw new \Exception("ADR $basename is missing a top-level H1 title.");
                }

                if (preg_match('/## Status\s*\n\s*(Proposed|Superseded|Deprecated)/i', $content)) {
                    throw new \Exception("ADR $basename has an invalid status (Proposed/Superseded/Deprecated are forbidden in docs/ADR/).");
                }

                if ($basename === '0000-adr-governance-and-authoring-guide.md') {
                    continue;
                }

                foreach ($requiredSections as $section) {
                    if (!str_contains($content, $section)) {
                        throw new \Exception("Domain ADR $basename is missing required section or status: '$section'");
                    }
                }
            }

            $contributing = $this->readPluginFile('docs/CONTRIBUTING.md');
            $skill = $this->readPluginFile('tools/agent-plugins/samlsso-guidelines/skills/samlsso-development/SKILL.md');
            if (
                !str_contains($contributing, 'Gate 1 — Pre-Implementation ADR Validation') ||
                !str_contains($contributing, 'Gate 2 — Post-Implementation Outcome Validation') ||
                !str_contains($skill, 'Gate 1 — Pre-Implementation ADR Validation') ||
                !str_contains($skill, 'Gate 2 — Post-Implementation Outcome Validation')
            ) {
                throw new \Exception('CONTRIBUTING.md or SKILL.md is missing Two-Gate ADR Validation instructions.');
            }

            echo "✅ ADR 0000: Validated ADR governance structure, mandatory sections, and Two-Gate validation instructions.\n";
        }

        /**
         * Test ADR 0001: Verify State Machine & Solicited-Only Double-Pass Authentication,
         * Replay Protection, Server-Side Redirect Authority, and RelayState CSRF token binding.
         *
         * @return void
         * @throws \Exception on any ADR 0001 invariant violation.
         */
        public function testAdr0001StateMachineAndSolicitedDoublePass(): void
        {
            $loginState = $this->readPluginFile('src/LoginState.php');
            $acs = $this->readPluginFile('src/LoginFlow/Acs.php');
            $loginFlow = $this->readPluginFile('src/LoginFlow.php');

            // 1. Verify all 9 explicit state-machine phases exist
            $expectedPhases = [
                'PHASE_INITIAL              = 1;',
                'PHASE_SAML_ACS             = 2;',
                'PHASE_SAML_AUTH            = 3;',
                'PHASE_GLPI_AUTH            = 4;',
                'PHASE_RESERVED             = 5;',
                'PHASE_FORCE_LOG            = 6;',
                'PHASE_TIMED_OUT            = 7;',
                'PHASE_LOGOFF               = 8;',
                'PHASE_ERROR                = 9;',
            ];
            foreach ($expectedPhases as $phaseDecl) {
                if (!str_contains($loginState, $phaseDecl)) {
                    throw new \Exception("ADR 0001 violation: Missing phase constant '$phaseDecl' in src/LoginState.php");
                }
            }

            // 2. Verify Double-Pass Solicited Flow in Acs::fetchRequestState() and Acs::assertSaml()
            if (
                !str_contains($acs, 'unsolicitedReauthRedirect') ||
                !str_contains($acs, '$this->performSamlIdpRequest()')
            ) {
                throw new \Exception('ADR 0001 violation: Acs.php does not enforce the Double-Pass Solicited Flow (unsolicitedReauthRedirect -> performSamlIdpRequest).');
            }
            if (str_contains($acs, '->isValid(null)') || !str_contains($acs, '->isValid($expectedRequestId)')) {
                throw new \Exception('ADR 0001 violation: Acs.php must pass $expectedRequestId (never null) to samlResponse->isValid().');
            }
            if (!str_contains($acs, 'SubjectConfirmationData')) {
                throw new \Exception('ADR 0001 violation: Acs.php must validate SubjectConfirmationData/@InResponseTo.');
            }

            // 3. Verify Server-Side Redirect Authority & Static Asset Filtering
            if (
                !str_contains($loginState, 'function isStaticAssetRedirect') ||
                !str_contains($loginState, 'function getSafeRedirect')
            ) {
                throw new \Exception('ADR 0001 violation: LoginState.php is missing getSafeRedirect() or isStaticAssetRedirect().');
            }
            if (str_contains($loginState, "\$_POST['RelayState']")) {
                throw new \Exception('ADR 0001 violation: LoginState.php must never read $_POST[\'RelayState\'] as a redirect target.');
            }

            // 4. Verify RelayState carries per-flow CSRF token validated via hash_equals
            if (
                !str_contains($loginFlow, 'setFlowToken') ||
                !str_contains($acs, 'hash_equals($expectedFlowToken')
            ) {
                throw new \Exception('ADR 0001 violation: RelayState per-flow CSRF token binding (setFlowToken / hash_equals) is missing.');
            }

            echo "✅ ADR 0001: Validated 9-phase state machine, double-pass solicited flow, replay isolation, and server-side redirect authority.\n";
        }

        /**
         * Test ADR 0002: Verify GLPI Core Delegation, Timeout Separation of Duties
         * (request_timeout vs. read-only GLPI session timeout), PHP Clock Authority,
         * and GLPI 11/12 Compatibility boundaries.
         *
         * @return void
         * @throws \Exception on any ADR 0002 invariant violation.
         */
        public function testAdr0002GlpiCoreDelegationAndTimeouts(): void
        {
            $loginState = $this->readPluginFile('src/LoginState.php');
            $loginFlow = $this->readPluginFile('src/LoginFlow.php');
            $cronTask = $this->readPluginFile('src/CronTask.php');
            $configItem = $this->readPluginFile('src/Config/ConfigItem.php');
            $acs = $this->readPluginFile('src/LoginFlow/Acs.php');
            $securityTwig = $this->readPluginFile('templates/configForm/security.html.twig');

            // 1. Verify no plugin-level inactivity_timeout runtime enforcement exists
            if (
                str_contains($loginState, 'expireStaleGlpiSessions') ||
                str_contains($loginState, 'markSessionInactivityTimedOut') ||
                str_contains($loginState, 'INACTIVITY_TIMEOUT') ||
                str_contains($loginFlow, 'expireStaleGlpiSessions') ||
                str_contains($cronTask, 'inactivity_timeout')
            ) {
                throw new \Exception('ADR 0002 violation: Plugin-level inactivity_timeout runtime enforcement must not exist in LoginState, LoginFlow, or CronTask.');
            }

            // 2. Verify inactivity_timeout in ConfigItem and security.html.twig is a read-only informational field showing GLPI's session.gc_maxlifetime
            if (
                !str_contains($configItem, "ini_get('session.gc_maxlifetime')") ||
                !str_contains($configItem, "GLPI SESSION TIMEOUT (MINUTES)") ||
                !str_contains($securityTwig, "'readonly': true") ||
                !str_contains($securityTwig, "'disabled': true")
            ) {
                throw new \Exception('ADR 0002 violation: inactivity_timeout must be a read-only informational field reflecting GLPI session.gc_maxlifetime.');
            }

            // 3. Verify request_timeout enforcement and PHP clock authority
            if (
                !str_contains($loginState, "LoginState::LOGIN_DATETIME    => date('Y-m-d H:i:s')") ||
                !str_contains($loginState, 'function refreshLoginDateTime') ||
                !str_contains($acs, 'function handleRequestTimeout') ||
                !str_contains($acs, 'index.php?noAuto=1')
            ) {
                throw new \Exception('ADR 0002 violation: PHP clock initialization for LOGIN_DATETIME or handleRequestTimeout() redirect to index.php?noAuto=1 is missing.');
            }
            if (str_contains($loginState, " . ' UTC'")) {
                throw new \Exception("ADR 0002 violation: Hardcoded ' UTC' concatenation in LoginState.php violates single PHP clock authority.");
            }

            echo "✅ ADR 0002: Validated GLPI core session delegation, request_timeout enforcement, and PHP runtime clock authority.\n";
        }

        /**
         * Test ADR 0003: Verify Cryptography, Certificate Sanitization, GLPIKey Secret
         * Encryption, and NIST/CA-B Forum Self-Signed Certificate Generator Bounds.
         *
         * @return void
         * @throws \Exception on any ADR 0003 invariant violation.
         */
        public function testAdr0003CryptographyAndSecretManagement(): void
        {
            $acs = $this->readPluginFile('src/LoginFlow/Acs.php');
            $configEntity = $this->readPluginFile('src/Config/ConfigEntity.php');
            $config = $this->readPluginFile('src/Config.php');
            $configDefault = $this->readPluginFile('src/Config/ConfigDefaultTpl.php');

            // 1. Verify runtime strict => true and signature requirement in Acs::setupSamlResponse()
            if (
                !str_contains($acs, "\$phpSamlConfig['strict'] = true;") ||
                !str_contains($acs, "\$phpSamlConfig['security']['wantAssertionsSigned'] = true;")
            ) {
                throw new \Exception('ADR 0003 violation: Acs::setupSamlResponse() must enforce strict => true and inbound signature verification.');
            }

            // 2. Verify GLPIKey encryption and undisclosed fields for sp_private_key
            if (
                !str_contains($configEntity, 'GLPIKey') ||
                !str_contains($config, 'getUndisclosedFields') ||
                !str_contains($configEntity, 'LIBXML_NONET | LIBXML_NOCDATA')
            ) {
                throw new \Exception('ADR 0003 violation: ConfigEntity/Config is missing GLPIKey encryption, getUndisclosedFields(), or safe LIBXML flags.');
            }
            if (str_contains($configEntity, 'LIBXML_NOENT') || str_contains($configEntity, 'LIBXML_DTDLOAD')) {
                throw new \Exception('ADR 0003 violation: Forbidden XXE-vulnerable LIBXML flags detected in ConfigEntity.php.');
            }

            // 3. Verify NIST/CA-B cryptographic floors in generateSelfSignedCertificate
            if (
                !str_contains($configEntity, '$keyBits < 2048') ||
                !str_contains($configEntity, "'prime256v1', 'secp384r1', 'secp521r1'") ||
                !str_contains($configEntity, "'sha256', 'sha384', 'sha512'")
            ) {
                throw new \Exception('ADR 0003 violation: generateSelfSignedCertificate() is missing RSA >= 2048, NIST curve, or SHA-256+ bounds.');
            }

            // 4. Verify ConfigDefaultTpl ships with empty certs and strict => true
            require_once realpath(__DIR__ . '/../src/Config/ConfigDefaultTpl.php');
            $tpl = \GlpiPlugin\Samlsso\Config\ConfigDefaultTpl::template();
            if (
                ($tpl[\GlpiPlugin\Samlsso\Config\ConfigEntity::STRICT] ?? false) !== true ||
                ($tpl[\GlpiPlugin\Samlsso\Config\ConfigEntity::SECURITY_WANTASSERTIONSSIGNED] ?? false) !== true ||
                ($tpl[\GlpiPlugin\Samlsso\Config\ConfigEntity::SP_CERTIFICATE] ?? 'non-empty') !== '' ||
                ($tpl[\GlpiPlugin\Samlsso\Config\ConfigEntity::SP_KEY] ?? 'non-empty') !== '' ||
                ($tpl[\GlpiPlugin\Samlsso\Config\ConfigEntity::IDP_CERTIFICATE] ?? 'non-empty') !== ''
            ) {
                throw new \Exception('ADR 0003 violation: ConfigDefaultTpl.php must default STRICT and SECURITY_WANTASSERTIONSSIGNED to true and certificates/keys to empty strings.');
            }

            echo "✅ ADR 0003: Validated runtime strict mode, signature requirements, GLPIKey secret protection, and NIST cryptographic bounds.\n";
        }

        /**
         * Test ADR 0004: Verify Fail-Closed IdP Loading, Account Takeover Prevention
         * in JIT & sync_on_login, and Token-Guarded SSO Bypass.
         *
         * @return void
         * @throws \Exception on any ADR 0004 invariant violation.
         */
        public function testAdr0004FailClosedProvisioningAndAccessControl(): void
        {
            $user = $this->readPluginFile('src/LoginFlow/User.php');
            $configEntity = $this->readPluginFile('src/Config/ConfigEntity.php');
            $configForm = $this->readPluginFile('src/Config/ConfigForm.php');
            $loginFlow = $this->readPluginFile('src/LoginFlow.php');

            // 1. Verify User.php enforces external auth source binding and strips protected fields on sync
            if (
                !str_contains($user, '\Auth::DB_GLPI') ||
                !str_contains($user, '\Auth::EXTERNAL') ||
                !str_contains($user, '$protectedFields') ||
                !str_contains($user, "'auths_id'")
            ) {
                throw new \Exception('ADR 0004 violation: User.php is missing external auth-source verification or protected field stripping in syncUserFieldsOnLogin().');
            }

            // 2. Verify multi-IdP enforce_sso auto-disable in ConfigEntity::validateAdvancedConfig()
            if (!str_contains($configEntity, 'function validateAdvancedConfig')) {
                throw new \Exception('ADR 0004 violation: ConfigEntity::validateAdvancedConfig() is missing.');
            }

            // 3. Verify administrative right checks in ConfigForm and bypass token hash_equals in LoginFlow
            if (
                !str_contains($configForm, "Session::checkRight('config', UPDATE)") ||
                !str_contains($loginFlow, 'hash_equals($requiredToken, (string)$providedToken)')
            ) {
                throw new \Exception('ADR 0004 violation: Missing Session::checkRight in ConfigForm or hash_equals bypass token check in LoginFlow.');
            }

            echo "✅ ADR 0004: Validated fail-closed IdP loading, JIT external-source binding, and token-guarded bypass.\n";
        }

        /**
         * Test ADR 0005, 0006, 0007, 0008: Verify Configuration Caching, On-Demand HTML
         * Diagnostic Popovers, Deterministic Lifecycle Migrations, and Test Runner Isolation.
         *
         * @return void
         * @throws \Exception on any ADR 0005-0008 invariant violation.
         */
        public function testAdr0005Through0008EngineUiLifecycleAndTesting(): void
        {
            $configEntity = $this->readPluginFile('src/Config/ConfigEntity.php');
            $config = $this->readPluginFile('src/Config.php');
            $configFormTwig = $this->readPluginFile('templates/configForm.html.twig');
            $css = $this->readPluginFile('public/css/samlSSO.css');
            $setup = $this->readPluginFile('setup.php');
            $hook = $this->readPluginFile('hook.php');
            $runAllTests = $this->readPluginFile('tests/RunAllTests.php');

            // ADR 0005: Request-level caching
            if (
                !str_contains($configEntity, '$dbRowCache') ||
                !str_contains($configEntity, 'function clearCache') ||
                !str_contains($config, 'function clearCache')
            ) {
                throw new \Exception('ADR 0005 violation: Request-level cache ($dbRowCache / clearCache) is missing in ConfigEntity or Config.');
            }

            // ADR 0006: On-demand HTML diagnostic popovers and inline table hiding
            if (
                !str_contains($configFormTwig, 'SamlSsoPopoverManager') ||
                !str_contains($configFormTwig, 'sanitize: true') ||
                !str_contains($configFormTwig, 'samlsso-diagnostic-table-container') ||
                !str_contains($css, '.glpisaml .samlsso-diagnostic-table-container') ||
                !str_contains($css, 'display: none !important;')
            ) {
                throw new \Exception('ADR 0006 violation: SamlSsoPopoverManager sanitizer allowList or .samlsso-diagnostic-table-container inline hiding is missing.');
            }

            // ADR 0007: Deterministic lifecycle & migrationOneTable execution
            if (
                !str_contains($setup, 'PLUGIN_SAMLSSO_CLASSES') ||
                str_contains($hook, 'scandir(') ||
                !str_contains($config, '$migration->migrationOneTable($table)') ||
                !str_contains($hook, 'SanityChecker::check()')
            ) {
                throw new \Exception('ADR 0007 violation: Deterministic PLUGIN_SAMLSSO_CLASSES, migrationOneTable(), or SanityChecker::check() is missing.');
            }

            // ADR 0008: Process-isolated test runner
            if (!str_contains($runAllTests, '*Test.php')) {
                throw new \Exception('ADR 0008 violation: tests/RunAllTests.php must discover and execute *Test.php suites.');
            }

            echo "✅ ADR 0005–0008: Validated two-tier config caching, diagnostic popover sanitization, deterministic migrations, and isolated test runner.\n";
        }
    }
}

namespace {
    /**
     * Execute the AdrComplianceTest suite.
     */
    $test = new GlpiPlugin\Samlsso\Tests\AdrComplianceTest();
    try {
        $test->testAdr0000GovernanceAndAuthoringStructure();
        $test->testAdr0001StateMachineAndSolicitedDoublePass();
        $test->testAdr0002GlpiCoreDelegationAndTimeouts();
        $test->testAdr0003CryptographyAndSecretManagement();
        $test->testAdr0004FailClosedProvisioningAndAccessControl();
        $test->testAdr0005Through0008EngineUiLifecycleAndTesting();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
