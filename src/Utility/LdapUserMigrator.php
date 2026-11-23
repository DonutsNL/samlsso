<?php

declare(strict_types=1);

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
 *  @see        https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Utility;

use GlpiPlugin\Samlsso\Config\ConfigEntity;
use GlpiPlugin\Samlsso\LoginFlow\User;

/**
 * Non-destructive, reversible in-place migration engine for converting GLPI LDAP users
 * (authtype = Auth::LDAP) to a configured samlSSO Identity Provider
 * (authtype = Auth::EXTERNAL, auths_id = User::toAuthsId($samlIdpId)).
 *
 * Invoked by both the native GLPI console command (plugins:samlsso:migrate_ldap_users)
 * and the standalone CLI wrapper (tools/migrate_ldap_users.php).
 *
 * Full documentation & operational runbook:
 * https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML
 */
class LdapUserMigrator
{
    public const WIKI_URL = 'https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML';
    public const REGISTER_SCHEMA_VERSION = 1;

    private string $registerDir;

    /**
     * Initializes the migrator with an optional register storage directory override.
     *
     * @param string|null $registerDir Absolute directory path outside webroot for signed registers.
     */
    public function __construct(?string $registerDir = null)
    {
        if ($registerDir !== null && $registerDir !== '') {
            $this->registerDir = rtrim($registerDir, '/\\');
        } elseif (defined('GLPI_PLUGIN_DOC_DIR')) {
            $this->registerDir = rtrim((string)GLPI_PLUGIN_DOC_DIR, '/\\') . '/samlsso/migrations';
        } elseif (defined('GLPI_VAR_DIR')) {
            $this->registerDir = rtrim((string)GLPI_VAR_DIR, '/\\') . '/_plugins/samlsso/migrations';
        } else {
            $this->registerDir = dirname(__DIR__, 4) . '/files/_plugins/samlsso/migrations';
        }
    }

    /**
     * Executes the CLI migration, register listing, or deterministic revert workflow.
     *
     * @param array<int, string> $argv Command-line arguments including command/script name at index 0.
     * @param resource|null $out Output stream (defaults to STDOUT).
     * @param resource|null $err Error stream (defaults to STDERR).
     * @return int Process status code (0 on success, non-zero on validation/runtime failure).
     */
    public function run(array $argv, $out = null, $err = null): int
    {
        $stdout = is_resource($out) ? $out : \STDOUT;
        $stderr = is_resource($err) ? $err : \STDERR;

        if (\PHP_SAPI !== 'cli') {
            fwrite($stderr, "[ERROR] LDAP user migration may only be executed from PHP CLI.\n");
            return 1;
        }

        $opts = $this->parseOptions($argv);
        if ($opts['help']) {
            $this->writeHelp($stdout);
            return 0;
        }

        try {
            if ($opts['list_registers']) {
                return $this->handleListRegisters($stdout);
            }

            if ($opts['revert'] !== null) {
                return $this->handleRevert($opts['revert'], $opts['apply'], $stdout);
            }

            return $this->handleMigrate($opts, $stdout);
        } catch (\Throwable $e) {
            fwrite($stderr, '[ERROR] ' . $e->getMessage() . "\n");
            fwrite($stderr, 'Manual: ' . self::WIKI_URL . "\n");
            return 1;
        }
    }

    /**
     * Parses supported command-line flags into a normalized options dictionary.
     *
     * @param array<int, string> $argv
     * @return array{
     *   help: bool,
     *   apply: bool,
     *   list_registers: bool,
     *   revert: string|null,
     *   ldap_id: int|null,
     *   saml_idp_id: int,
     *   login_format: string,
     *   deactivate_ldap_server: bool,
     *   include_deleted_ldap: bool
     * }
     */
    private function parseOptions(array $argv): array
    {
        $parsed = [
            'help'                   => false,
            'apply'                  => false,
            'list_registers'         => false,
            'revert'                 => null,
            'ldap_id'                => null,
            'saml_idp_id'            => 0,
            'login_format'           => 'keep',
            'deactivate_ldap_server' => false,
            'include_deleted_ldap'   => true,
        ];

        $count = count($argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = (string)$argv[$i];
            if ($arg === '--help' || $arg === '-h') {
                $parsed['help'] = true;
            } elseif ($arg === '--apply') {
                $parsed['apply'] = true;
            } elseif ($arg === '--dry-run') {
                $parsed['apply'] = false;
            } elseif ($arg === '--list-registers') {
                $parsed['list_registers'] = true;
            } elseif ($arg === '--deactivate-ldap-server') {
                $parsed['deactivate_ldap_server'] = true;
            } elseif ($arg === '--skip-deleted-ldap') {
                $parsed['include_deleted_ldap'] = false;
            } elseif (str_starts_with($arg, '--revert=')) {
                $parsed['revert'] = trim(substr($arg, strlen('--revert=')));
            } elseif (str_starts_with($arg, '--ldap-id=')) {
                $val = trim(substr($arg, strlen('--ldap-id=')));
                $parsed['ldap_id'] = strtolower($val) === 'all' ? -1 : (int)$val;
            } elseif (str_starts_with($arg, '--saml-idp-id=')) {
                $parsed['saml_idp_id'] = (int)trim(substr($arg, strlen('--saml-idp-id=')));
            } elseif (str_starts_with($arg, '--login-format=')) {
                $parsed['login_format'] = strtolower(trim(substr($arg, strlen('--login-format='))));
            }
        }

        return $parsed;
    }

    /**
     * Writes CLI usage instructions and the GitHub Wiki manual pointer.
     *
     * @param resource $stdout
     * @return void
     */
    private function writeHelp($stdout): void
    {
        $lines = [
            'samlSSO — Non-Destructive, Reversible LDAP-to-SAML User Migration Utility',
            'Manual: ' . self::WIKI_URL,
            '',
            'Usage (GLPI Console — shipped in release package):',
            '  Preview (Dry-Run, default):',
            '    php bin/console plugins:samlsso:migrate_ldap_users --ldap-id=<ID|all> --saml-idp-id=<ID> [--login-format=keep|email]',
            '',
            '  Execute In-Place Migration (writes 0600 signed user-ID register before applying):',
            '    php bin/console plugins:samlsso:migrate_ldap_users --ldap-id=<ID|all> --saml-idp-id=<ID> [--login-format=keep|email] [--deactivate-ldap-server] --apply',
            '',
            '  Inspect Existing Migration Registers:',
            '    php bin/console plugins:samlsso:migrate_ldap_users --list-registers',
            '',
            '  Revert a Previous Migration from its Signed Register:',
            '    php bin/console plugins:samlsso:migrate_ldap_users --revert=<register-filename.json> [--apply]',
            '',
            '  (Also callable via: php plugins/samlsso/tools/migrate_ldap_users.php ...)',
            '',
            'Options:',
            '  --ldap-id=<ID|all>         Source GLPI LDAP directory ID (glpi_authldaps.id) or "all".',
            '  --saml-idp-id=<ID>         Target active samlSSO Identity Provider ID (glpi_plugin_samlsso_configs.id).',
            '  --login-format=keep|email  Keep existing glpi_users.name ("keep", default) or rewrite to primary email ("email").',
            '  --deactivate-ldap-server   Deactivate active source glpi_authldaps directory to prevent LDAP cron re-import.',
            '  --skip-deleted-ldap        Skip accounts currently flagged with is_deleted_ldap = 1.',
            '  --dry-run                  Preview planned changes without modifying the database (default).',
            '  --apply                    Execute database updates and write/consume the signed migration register.',
            '  --list-registers           List signed migration register files stored in ' . $this->registerDir,
            '  --revert=<filename.json>   Restore exact pre-migration state for all migrated_user_ids in a signed register.',
            '  --help, -h                 Show this help message.',
            '',
        ];
        fwrite($stdout, implode("\n", $lines));
    }

    /**
     * Plans and optionally applies an in-place LDAP-to-SAML user migration.
     *
     * @param array<string, mixed> $opts
     * @param resource $stdout
     * @return int
     */
    private function handleMigrate(array $opts, $stdout): int
    {
        global $DB;

        if ($DB === null || !method_exists($DB, 'request')) {
            throw new \RuntimeException('GLPI database connection ($DB) is not initialized.');
        }

        $ldapId = $opts['ldap_id'];
        $samlIdpId = (int)$opts['saml_idp_id'];
        $loginFormat = (string)$opts['login_format'];
        $apply = (bool)$opts['apply'];

        if ($ldapId === null || ($ldapId !== -1 && $ldapId <= 0)) {
            throw new \RuntimeException('Missing or invalid --ldap-id=<ID|all>. Run with --help for usage.');
        }
        if ($samlIdpId <= 0) {
            throw new \RuntimeException('Missing or invalid --saml-idp-id=<ID>. Run with --help for usage.');
        }
        if (!in_array($loginFormat, ['keep', 'email'], true)) {
            throw new \RuntimeException("Invalid --login-format value '{$loginFormat}'. Allowed values: keep, email.");
        }

        $configEntity = new ConfigEntity($samlIdpId);
        if (!$configEntity->isValid() || !$configEntity->isActive() || $configEntity->isDeleted()) {
            throw new \RuntimeException("Target samlSSO Identity Provider #{$samlIdpId} is missing, invalid, inactive, or deleted.");
        }

        $targetAuthsId = User::toAuthsId($samlIdpId);
        $ldapAuthType = class_exists('\Auth') && defined('\Auth::LDAP') ? \Auth::LDAP : 3;
        $externalAuthType = class_exists('\Auth') && defined('\Auth::EXTERNAL') ? \Auth::EXTERNAL : 4;
        $localAuthType = class_exists('\Auth') && defined('\Auth::DB_GLPI') ? \Auth::DB_GLPI : 1;

        /**
         * Inspect active source glpi_authldaps directories being retired.
         * Unrelated LDAP servers (including one whose ID matches $samlIdpId) are unaffected
         * because target users bind to User::toAuthsId($samlIdpId) (1,000,000 + $samlIdpId).
         */
        $activeSourceLdapServers = [];
        $ldapRows = $DB->request(['FROM' => 'glpi_authldaps']);
        foreach ($ldapRows as $lRow) {
            $lid = (int)($lRow['id'] ?? 0);
            $isSourceLdap = ($ldapId === -1) || ($lid === $ldapId);
            if ($isSourceLdap && (int)($lRow['is_active'] ?? 0) === 1) {
                $activeSourceLdapServers[$lid] = [
                    'id'        => $lid,
                    'name'      => (string)($lRow['name'] ?? ''),
                    'is_active' => 1,
                ];
            }
        }

        if ($apply && !empty($activeSourceLdapServers) && !$opts['deactivate_ldap_server']) {
            $idsList = implode(', ', array_keys($activeSourceLdapServers));
            throw new \RuntimeException(
                "Active source GLPI LDAP directory ID(s) [{$idsList}] could re-import or conflict with migrated users. "
                . 'Deactivate the source LDAP directory in GLPI first or pass --deactivate-ldap-server.'
            );
        }

        /** Load all users to classify source LDAP candidates vs. non-migrating accounts across all auth sources. */
        $allUsers = [];
        $localUserIds = [];
        $externalUserIds = [];
        $existingExternalIdentities = [];
        $existingLocalIdentities = ['glpi' => true];
        $existingOtherUserNames = ['glpi' => 1];

        $userIter = $DB->request(['FROM' => 'glpi_users']);
        foreach ($userIter as $uRow) {
            if (!is_array($uRow) || !isset($uRow[User::USERID])) {
                continue;
            }
            $uid = (int)$uRow[User::USERID];
            $unameLower = strtolower((string)($uRow[User::NAME] ?? ''));
            $uAuthtype = (int)($uRow[User::AUTHTYPE] ?? 0);
            $uAuthsId = (int)($uRow[User::AUTHS_ID] ?? 0);
            $uUserDn = trim((string)($uRow[User::USER_DN] ?? ''));

            $allUsers[$uid] = $uRow;

            if ($uAuthtype === $localAuthType || $unameLower === 'glpi') {
                $existingLocalIdentities[$unameLower] = true;
                $localUserIds[$uid] = true;
            }

            $isTargetSamlRow = $uAuthtype === $externalAuthType
                && ($uAuthsId === $targetAuthsId || ($uAuthsId === $samlIdpId && $uUserDn === ''));
            if ($isTargetSamlRow) {
                $existingExternalIdentities[$unameLower] = $uid;
                $externalUserIds[$uid] = true;
            }

            $isEligibleCandidate = $uAuthtype === $ldapAuthType
                && ($ldapId === -1 || $uAuthsId === $ldapId)
                && (int)($uRow[User::DELETED] ?? 0) === 0
                && ($opts['include_deleted_ldap'] || (int)($uRow[User::IS_DELETED_LDAP] ?? 0) === 0);

            if (!$isEligibleCandidate && $unameLower !== '') {
                $existingOtherUserNames[$unameLower] = $uid;
            }
        }

        /** Load glpi_useremails to resolve primary emails and detect email-based local/SAML collisions. */
        $primaryEmails = [];
        $userEmailsMap = [];
        $emailIter = $DB->request(['FROM' => 'glpi_useremails']);
        foreach ($emailIter as $eRow) {
            $uid = (int)($eRow[User::USERSID] ?? 0);
            $email = trim((string)($eRow['email'] ?? ''));
            if ($uid <= 0 || $email === '') {
                continue;
            }
            $emailLower = strtolower($email);
            $userEmailsMap[$uid][] = $emailLower;
            if (isset($localUserIds[$uid])) {
                $existingLocalIdentities[$emailLower] = true;
            }
            if (isset($externalUserIds[$uid])) {
                $existingExternalIdentities[$emailLower] = $uid;
            }
            $isDefault = (int)($eRow['is_default'] ?? 0) === 1;
            if (!isset($primaryEmails[$uid]) || $isDefault) {
                $primaryEmails[$uid] = $email;
            }
        }

        $planned = [];
        $skipped = [];
        foreach ($allUsers as $uid => $uRow) {
            if ((int)($uRow[User::AUTHTYPE] ?? 0) !== $ldapAuthType) {
                continue;
            }
            if ($ldapId !== -1 && (int)($uRow[User::AUTHS_ID] ?? 0) !== $ldapId) {
                continue;
            }
            if ((int)($uRow[User::DELETED] ?? 0) === 1) {
                $skipped[] = "ID {$uid} ({$uRow[User::NAME]}): skipped (user is in GLPI trashbin is_deleted=1).";
                continue;
            }
            if (!$opts['include_deleted_ldap'] && (int)($uRow[User::IS_DELETED_LDAP] ?? 0) === 1) {
                $skipped[] = "ID {$uid} ({$uRow[User::NAME]}): skipped (is_deleted_ldap=1 and --skip-deleted-ldap set).";
                continue;
            }

            $currentName = (string)($uRow[User::NAME] ?? '');
            $currentLower = strtolower($currentName);
            $targetName = $currentName;
            if ($loginFormat === 'email') {
                if (empty($primaryEmails[$uid])) {
                    $skipped[] = "ID {$uid} ({$currentName}): skipped (no email in glpi_useremails for --login-format=email).";
                    if ($currentLower !== '') {
                        $existingOtherUserNames[$currentLower] = $uid;
                    }
                    continue;
                }
                $targetName = $primaryEmails[$uid];
            }

            if (class_exists('\Auth') && method_exists('\Auth', 'isValidLogin') && !\Auth::isValidLogin($targetName)) {
                $skipped[] = "ID {$uid} ({$currentName} -> {$targetName}): skipped (target login rejected by Auth::isValidLogin).";
                if ($currentLower !== '') {
                    $existingOtherUserNames[$currentLower] = $uid;
                }
                continue;
            }

            $targetLower = strtolower($targetName);
            $identifiersToCheck = array_values(array_unique(array_merge(
                [$currentLower, $targetLower],
                $userEmailsMap[$uid] ?? []
            )));

            $collidesLocal = false;
            $collidesExternalId = null;
            foreach ($identifiersToCheck as $ident) {
                if ($ident === '') {
                    continue;
                }
                if (isset($existingLocalIdentities[$ident])) {
                    $collidesLocal = true;
                    break;
                }
                if (isset($existingExternalIdentities[$ident])) {
                    $collidesExternalId = $existingExternalIdentities[$ident];
                    break;
                }
            }

            if ($collidesLocal) {
                $skipped[] = "ID {$uid} ({$currentName} -> {$targetName}): skipped (collides with local DB_GLPI / glpi account).";
                if ($currentLower !== '') {
                    $existingOtherUserNames[$currentLower] = $uid;
                }
                continue;
            }
            if ($collidesExternalId !== null) {
                $skipped[] = "ID {$uid} ({$currentName} -> {$targetName}): skipped (target SAML account already exists as ID {$collidesExternalId}).";
                if ($currentLower !== '') {
                    $existingOtherUserNames[$currentLower] = $uid;
                }
                continue;
            }
            if ($targetLower !== '' && isset($existingOtherUserNames[$targetLower]) && $existingOtherUserNames[$targetLower] !== $uid) {
                $otherUid = $existingOtherUserNames[$targetLower];
                $skipped[] = "ID {$uid} ({$currentName} -> {$targetName}): skipped (target login collides with existing user ID {$otherUid}).";
                if ($currentLower !== '') {
                    $existingOtherUserNames[$currentLower] = $uid;
                }
                continue;
            }

            foreach ($identifiersToCheck as $ident) {
                if ($ident !== '') {
                    $existingExternalIdentities[$ident] = $uid;
                }
            }
            if ($targetLower !== '') {
                $existingOtherUserNames[$targetLower] = $uid;
            }

            $planned[$uid] = [
                'before'      => [
                    User::NAME            => $currentName,
                    User::AUTHTYPE        => (int)($uRow[User::AUTHTYPE] ?? $ldapAuthType),
                    User::AUTHS_ID        => (int)($uRow[User::AUTHS_ID] ?? 0),
                    User::USER_DN         => $uRow[User::USER_DN] ?? null,
                    User::USER_DN_HASH    => $uRow[User::USER_DN_HASH] ?? null,
                    User::SYNC_FIELD      => $uRow[User::SYNC_FIELD] ?? null,
                    User::IS_DELETED_LDAP => (int)($uRow[User::IS_DELETED_LDAP] ?? 0),
                    User::ACTIVE          => (int)($uRow[User::ACTIVE] ?? 1),
                ],
                'target_name' => $targetName,
            ];
        }

        $modeLabel = $apply ? 'APPLY' : 'DRY-RUN (no database changes written)';
        fwrite($stdout, "=== samlSSO LDAP-to-SAML Migration [{$modeLabel}] ===\n");
        fwrite($stdout, 'Manual: ' . self::WIKI_URL . "\n");
        fwrite($stdout, sprintf(
            "Source LDAP ID: %s | Target SAML IdP: #%d (%s, auths_id=%d) | Login Format: %s\n",
            $ldapId === -1 ? 'all' : (string)$ldapId,
            $samlIdpId,
            (string)$configEntity->getField(ConfigEntity::NAME),
            $targetAuthsId,
            $loginFormat
        ));
        fwrite($stdout, sprintf("Planned migrations: %d | Skipped: %d\n\n", count($planned), count($skipped)));

        foreach ($planned as $uid => $item) {
            fwrite($stdout, sprintf(
                "  [MIGRATE] userId=%d : '%s' (LDAP #%d) -> '%s' (Auth::EXTERNAL, samlIdp=#%d / auths_id=%d)\n",
                $uid,
                $item['before'][User::NAME],
                $item['before'][User::AUTHS_ID],
                $item['target_name'],
                $samlIdpId,
                $targetAuthsId
            ));
        }
        foreach ($skipped as $skipMsg) {
            fwrite($stdout, "  [SKIP]    {$skipMsg}\n");
        }
        if (!empty($activeSourceLdapServers)) {
            fwrite($stdout, sprintf(
                "\n  [NOTICE]  Active source glpi_authldaps server(s) detected: [%s]%s\n",
                implode(', ', array_keys($activeSourceLdapServers)),
                $apply ? ' -> deactivating as requested.' : ' -> pass --deactivate-ldap-server with --apply.'
            ));
        }

        if (!$apply) {
            fwrite($stdout, "\nDry-run complete. Re-run with --apply to write the signed register and execute.\n");
            return 0;
        }

        if (empty($planned)) {
            fwrite($stdout, "\nNo eligible LDAP users matched; nothing to apply.\n");
            return 0;
        }

        /** Build and atomically persist the 0600 GLPIKey HMAC-signed register BEFORE mutating rows. */
        $migratedUserIds = array_values(array_map('intval', array_keys($planned)));
        $userSnapshots = [];
        foreach ($planned as $uid => $item) {
            $userSnapshots[(string)$uid] = $item['before'];
        }

        $registerPayload = [
            'schema_version'    => self::REGISTER_SCHEMA_VERSION,
            'created_at'        => date('c'),
            'manual_url'        => self::WIKI_URL,
            'source_ldap_id'    => $ldapId === -1 ? 'all' : $ldapId,
            'target_saml_idp'   => $samlIdpId,
            'target_auths_id'   => $targetAuthsId,
            'login_format'      => $loginFormat,
            'migrated_user_ids' => $migratedUserIds,
            'ldap_servers'      => $activeSourceLdapServers,
            'users'             => $userSnapshots,
        ];

        $registerPath = $this->writeSignedRegister($registerPayload, $samlIdpId);

        /** Deactivate active source LDAP directories first so cron sync cannot re-import migrated rows. */
        foreach ($activeSourceLdapServers as $lid => $srv) {
            $DB->update('glpi_authldaps', ['is_active' => 0], ['id' => (int)$lid]);
        }

        /** Update each user in-place (preserving glpi_users.id and all FK relations). */
        $now = date('Y-m-d H:i:s');
        $_SESSION['glpiname'] = $_SESSION['glpiname'] ?? 'cli';
        $_SESSION['glpi_currenttime'] = $_SESSION['glpi_currenttime'] ?? $now;

        foreach ($planned as $uid => $item) {
            $DB->update(
                'glpi_users',
                [
                    User::NAME            => $item['target_name'],
                    User::AUTHTYPE        => $externalAuthType,
                    User::AUTHS_ID        => $targetAuthsId,
                    User::PASSWORD        => '',
                    User::USER_DN         => null,
                    User::USER_DN_HASH    => null,
                    User::SYNC_FIELD      => null,
                    User::IS_DELETED_LDAP => 0,
                    User::DATE_MOD        => $now,
                ],
                [User::USERID => (int)$uid]
            );

            if (class_exists('\Log') && method_exists('\Log', 'history')) {
                $actionCode = defined('\Log::HISTORY_LOG_SIMPLE_MESSAGE') ? \Log::HISTORY_LOG_SIMPLE_MESSAGE : 12;
                \Log::history(
                    (int)$uid,
                    'User',
                    [0, '', sprintf('Migrated authentication from LDAP #%d to samlSSO IdP #%d', $item['before'][User::AUTHS_ID], $samlIdpId)],
                    '',
                    $actionCode
                );
            }
        }

        fwrite($stdout, sprintf(
            "\nMigrated %d user(s) in-place. Signed revert register written to:\n  %s\nTo revert later: php bin/console plugins:samlsso:migrate_ldap_users --revert=%s --apply\n",
            count($migratedUserIds),
            $registerPath,
            basename($registerPath)
        ));

        return 0;
    }

    /**
     * Lists all signed migration registers in the register directory and verifies their HMAC integrity.
     *
     * @param resource $stdout
     * @return int
     */
    private function handleListRegisters($stdout): int
    {
        fwrite($stdout, "=== samlSSO LDAP Migration Registers ===\n");
        fwrite($stdout, 'Manual: ' . self::WIKI_URL . "\n");
        fwrite($stdout, 'Directory: ' . $this->registerDir . "\n\n");

        if (!is_dir($this->registerDir)) {
            fwrite($stdout, "No migration registers directory found yet.\n");
            return 0;
        }

        $files = glob($this->registerDir . '/*.json') ?: [];
        sort($files);
        if (empty($files)) {
            fwrite($stdout, "No migration register files found.\n");
            return 0;
        }

        foreach ($files as $file) {
            $status = 'INVALID';
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && $this->verifyRegisterSignature($decoded)) {
                $status = 'VALID';
            }
            $count = is_array($decoded['migrated_user_ids'] ?? null) ? count($decoded['migrated_user_ids']) : 0;
            $created = (string)($decoded['created_at'] ?? 'unknown');
            $src = (string)($decoded['source_ldap_id'] ?? '?');
            $dst = (string)($decoded['target_saml_idp'] ?? '?');
            fwrite($stdout, sprintf(
                "  %-48s | created=%s | ldap=%s -> saml=#%s | users=%d | hmac=%s\n",
                basename($file),
                $created,
                $src,
                $dst,
                $count,
                $status
            ));
        }

        return 0;
    }

    /**
     * Verifies a signed migration register and deterministically reverts migrated_user_ids.
     *
     * @param string $registerArg Filename inside the register directory.
     * @param bool $apply Whether to apply DB updates or preview only.
     * @param resource $stdout
     * @return int
     */
    private function handleRevert(string $registerArg, bool $apply, $stdout): int
    {
        global $DB;

        if ($DB === null || !method_exists($DB, 'update')) {
            throw new \RuntimeException('GLPI database connection ($DB) is not initialized.');
        }

        $safePath = $this->resolveSafeRegisterPath($registerArg);
        $raw = @file_get_contents($safePath);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException("Unable to read migration register file: {$registerArg}");
        }

        $decoded = json_decode($raw, true, 16);
        if (!is_array($decoded) || !$this->verifyRegisterSignature($decoded)) {
            throw new \RuntimeException('Migration register HMAC-SHA256 verification failed! Refusing to revert potentially tampered register.');
        }

        $migratedIds = $decoded['migrated_user_ids'] ?? null;
        $usersMap = $decoded['users'] ?? null;
        if (!is_array($migratedIds) || !is_array($usersMap)) {
            throw new \RuntimeException('Migration register is malformed (missing migrated_user_ids or users map).');
        }

        $modeLabel = $apply ? 'REVERT APPLY' : 'REVERT DRY-RUN (pass --apply to execute)';
        fwrite($stdout, "=== samlSSO Migration Revert [{$modeLabel}] ===\n");
        fwrite($stdout, 'Manual: ' . self::WIKI_URL . "\n");
        fwrite($stdout, sprintf("Register: %s (%d user IDs)\n\n", basename($safePath), count($migratedIds)));

        $now = date('Y-m-d H:i:s');
        $_SESSION['glpiname'] = $_SESSION['glpiname'] ?? 'cli';
        $_SESSION['glpi_currenttime'] = $_SESSION['glpi_currenttime'] ?? $now;

        foreach ($migratedIds as $rawId) {
            $uid = (int)$rawId;
            $snap = $usersMap[(string)$uid] ?? null;
            if ($uid <= 0 || !is_array($snap)) {
                continue;
            }
            fwrite($stdout, sprintf(
                "  [REVERT] userId=%d -> name='%s', authtype=%d, auths_id=%d\n",
                $uid,
                (string)($snap[User::NAME] ?? ''),
                (int)($snap[User::AUTHTYPE] ?? 3),
                (int)($snap[User::AUTHS_ID] ?? 0)
            ));

            if ($apply) {
                $DB->update(
                    'glpi_users',
                    [
                        User::NAME            => (string)($snap[User::NAME] ?? ''),
                        User::AUTHTYPE        => (int)($snap[User::AUTHTYPE] ?? 3),
                        User::AUTHS_ID        => (int)($snap[User::AUTHS_ID] ?? 0),
                        User::USER_DN         => $snap[User::USER_DN] ?? null,
                        User::USER_DN_HASH    => $snap[User::USER_DN_HASH] ?? null,
                        User::SYNC_FIELD      => $snap[User::SYNC_FIELD] ?? null,
                        User::IS_DELETED_LDAP => (int)($snap[User::IS_DELETED_LDAP] ?? 0),
                        User::ACTIVE          => (int)($snap[User::ACTIVE] ?? 1),
                        User::DATE_MOD        => $now,
                    ],
                    [User::USERID => $uid]
                );

                if (class_exists('\Log') && method_exists('\Log', 'history')) {
                    $actionCode = defined('\Log::HISTORY_LOG_SIMPLE_MESSAGE') ? \Log::HISTORY_LOG_SIMPLE_MESSAGE : 12;
                    \Log::history(
                        $uid,
                        'User',
                        [0, '', sprintf('Reverted samlSSO migration back to LDAP #%d via register %s', (int)($snap[User::AUTHS_ID] ?? 0), basename($safePath))],
                        '',
                        $actionCode
                    );
                }
            }
        }

        $ldapServers = is_array($decoded['ldap_servers'] ?? null) ? $decoded['ldap_servers'] : [];
        foreach ($ldapServers as $lid => $srv) {
            if ((int)($srv['is_active'] ?? 0) === 1) {
                fwrite($stdout, sprintf("  [REVERT] glpi_authldaps id=%d -> is_active=1\n", (int)$lid));
                if ($apply) {
                    $DB->update('glpi_authldaps', ['is_active' => 1], ['id' => (int)$lid]);
                }
            }
        }

        return 0;
    }

    /**
     * Signs and atomically writes a 0600 JSON register file inside $this->registerDir.
     *
     * @param array<string, mixed> $payload Unsigned register payload.
     * @param int $samlIdpId Target SAML IdP ID.
     * @return string Full path to the created register file.
     */
    private function writeSignedRegister(array $payload, int $samlIdpId): string
    {
        if (!is_dir($this->registerDir) && !@mkdir($this->registerDir, 0700, true) && !is_dir($this->registerDir)) {
            throw new \RuntimeException("Unable to create migration register directory: {$this->registerDir}");
        }
        @chmod($this->registerDir, 0700);

        $key = $this->loadSigningKey();
        $canonical = $this->encodeCanonical($payload);
        $payload['hmac_sha256'] = hash_hmac('sha256', $canonical, $key);

        $filename = sprintf(
            'ldap_to_saml_idp%d_%s_%s.json',
            $samlIdpId,
            date('Ymd_His'),
            bin2hex(random_bytes(4))
        );
        $destPath = $this->registerDir . \DIRECTORY_SEPARATOR . $filename;
        $tmpPath = $destPath . '.tmp.' . bin2hex(random_bytes(4));

        $encoded = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded === false || @file_put_contents($tmpPath, $encoded . "\n", \LOCK_EX) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to write temporary migration register: {$tmpPath}");
        }
        @chmod($tmpPath, 0600);
        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to finalize migration register atomically: {$destPath}");
        }
        @chmod($destPath, 0600);

        return $destPath;
    }

    /**
     * Verifies the GLPIKey HMAC-SHA256 signature of a decoded register payload in constant time.
     *
     * @param array<string, mixed> $decoded
     * @return bool
     */
    private function verifyRegisterSignature(array $decoded): bool
    {
        $providedHmac = isset($decoded['hmac_sha256']) && is_string($decoded['hmac_sha256'])
            ? $decoded['hmac_sha256']
            : '';
        if (strlen($providedHmac) !== 64) {
            return false;
        }
        unset($decoded['hmac_sha256']);
        $key = $this->loadSigningKey();
        $expectedHmac = hash_hmac('sha256', $this->encodeCanonical($decoded), $key);
        return hash_equals($expectedHmac, $providedHmac);
    }

    /**
     * Resolves and strictly confines a register filename inside $this->registerDir.
     *
     * @param string $registerArg User-supplied filename.
     * @return string Canonical path verified to reside inside $this->registerDir.
     */
    private function resolveSafeRegisterPath(string $registerArg): string
    {
        $cleanName = basename($registerArg);
        if (
            $cleanName === '' ||
            $cleanName !== $registerArg ||
            !preg_match('/^[A-Za-z0-9._-]+\.json$/', $cleanName)
        ) {
            throw new \RuntimeException("Invalid register filename '{$registerArg}'. Pass the JSON filename located inside {$this->registerDir}.");
        }

        $resolvedDir = realpath($this->registerDir);
        if ($resolvedDir === false) {
            throw new \RuntimeException("Migration register directory does not exist: {$this->registerDir}");
        }
        $candidatePath = $resolvedDir . \DIRECTORY_SEPARATOR . $cleanName;
        $resolvedFile = realpath($candidatePath);
        if (
            $resolvedFile === false ||
            !is_file($resolvedFile) ||
            !str_starts_with($resolvedFile, rtrim($resolvedDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException("Register file not found inside {$resolvedDir}: {$cleanName}");
        }

        return $resolvedFile;
    }

    /**
     * Loads the raw GLPI cryptographic key from GLPIKey::get(), failing closed if unavailable.
     *
     * @return string
     */
    private function loadSigningKey(): string
    {
        if (!class_exists('\GLPIKey')) {
            throw new \RuntimeException('GLPIKey class is unavailable; cannot sign or verify migration register.');
        }
        $glpiKey = new \GLPIKey();
        $raw = method_exists($glpiKey, 'get') ? $glpiKey->get() : null;
        if (!is_string($raw) || strlen($raw) < 16) {
            throw new \RuntimeException('GLPI security key (glpicrypt.key) is missing or unreadable.');
        }
        return $raw;
    }

    /**
     * Encodes an associative array deterministically for HMAC computation.
     *
     * @param array<string, mixed> $payload
     * @return string
     */
    private function encodeCanonical(array $payload): string
    {
        $encoded = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode canonical register payload.');
        }
        return $encoded;
    }
}
