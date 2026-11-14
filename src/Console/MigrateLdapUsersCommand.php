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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlsso/wiki/Migrating-Users-from-LDAP-to-SAML
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Samlsso\Utility\LdapUserMigrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Native GLPI Console command (php bin/console plugins:samlsso:migrate_ldap_users)
 * shipped inside the release archive to migrate GLPI LDAP users in-place to a
 * samlSSO Identity Provider with 0600 GLPIKey HMAC-signed revert registers.
 */
class MigrateLdapUsersCommand extends AbstractCommand
{
    private ?string $registerDir;

    /**
     * Initializes the GLPI console command with an optional register directory override for testing.
     *
     * @param string|null $name Optional Symfony command name override.
     * @param string|null $registerDir Optional signed register directory override.
     */
    public function __construct(?string $name = null, ?string $registerDir = null)
    {
        $this->registerDir = $registerDir;
        parent::__construct($name);
    }

    /**
     * Registers command metadata and CLI options under GLPI's plugins:samlsso namespace.
     *
     * @return void
     */
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:samlsso:migrate_ldap_users');
        $this->setDescription(
            __('Migrate GLPI LDAP users in-place to a samlSSO Identity Provider with signed revert registers', 'samlsso')
        );
        $this->setHelp('Manual: ' . LdapUserMigrator::WIKI_URL);

        $this->addOption(
            'ldap-id',
            null,
            InputOption::VALUE_REQUIRED,
            __('Source GLPI LDAP directory ID (glpi_authldaps.id) or "all"', 'samlsso')
        );
        $this->addOption(
            'saml-idp-id',
            null,
            InputOption::VALUE_REQUIRED,
            __('Target active samlSSO Identity Provider ID (glpi_plugin_samlsso_configs.id)', 'samlsso')
        );
        $this->addOption(
            'login-format',
            null,
            InputOption::VALUE_REQUIRED,
            __('Keep existing glpi_users.name ("keep") or rewrite to primary email ("email")', 'samlsso'),
            'keep'
        );
        $this->addOption(
            'deactivate-ldap-server',
            null,
            InputOption::VALUE_NONE,
            __('Deactivate active source glpi_authldaps directory before applying', 'samlsso')
        );
        $this->addOption(
            'skip-deleted-ldap',
            null,
            InputOption::VALUE_NONE,
            __('Skip accounts flagged with is_deleted_ldap = 1', 'samlsso')
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            __('Preview planned changes without modifying the database (default)', 'samlsso')
        );
        $this->addOption(
            'apply',
            null,
            InputOption::VALUE_NONE,
            __('Execute database updates and write or consume the signed migration register', 'samlsso')
        );
        $this->addOption(
            'list-registers',
            null,
            InputOption::VALUE_NONE,
            __('List signed migration register files and verify their HMAC integrity', 'samlsso')
        );
        $this->addOption(
            'revert',
            null,
            InputOption::VALUE_REQUIRED,
            __('Restore exact pre-migration state from a signed register filename', 'samlsso')
        );
    }

    /**
     * Delegates execution to LdapUserMigrator and streams output to Symfony Console.
     *
     * @param InputInterface  $input  Console input options.
     * @param OutputInterface $output Console output stream.
     * @return int Command::SUCCESS (0) or Command::FAILURE (1).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argv = ['plugins:samlsso:migrate_ldap_users'];

        $ldapId = $input->getOption('ldap-id');
        if ($ldapId !== null && $ldapId !== '') {
            $argv[] = '--ldap-id=' . (string)$ldapId;
        }

        $samlIdpId = $input->getOption('saml-idp-id');
        if ($samlIdpId !== null && $samlIdpId !== '') {
            $argv[] = '--saml-idp-id=' . (string)$samlIdpId;
        }

        $loginFormat = $input->getOption('login-format');
        if ($loginFormat !== null && $loginFormat !== '') {
            $argv[] = '--login-format=' . (string)$loginFormat;
        }

        if ($input->getOption('deactivate-ldap-server')) {
            $argv[] = '--deactivate-ldap-server';
        }
        if ($input->getOption('skip-deleted-ldap')) {
            $argv[] = '--skip-deleted-ldap';
        }
        if ($input->getOption('dry-run')) {
            $argv[] = '--dry-run';
        }
        if ($input->getOption('apply')) {
            $argv[] = '--apply';
        }
        if ($input->getOption('list-registers')) {
            $argv[] = '--list-registers';
        }

        $revert = $input->getOption('revert');
        if ($revert !== null && $revert !== '') {
            $argv[] = '--revert=' . (string)$revert;
        }

        $outStream = fopen('php://temp', 'w+');
        $errStream = fopen('php://temp', 'w+');

        $migrator = new LdapUserMigrator($this->registerDir);
        $code = $migrator->run($argv, $outStream, $errStream);

        rewind($outStream);
        rewind($errStream);
        $stdoutText = (string)stream_get_contents($outStream);
        $stderrText = (string)stream_get_contents($errStream);
        fclose($outStream);
        fclose($errStream);

        if ($stdoutText !== '') {
            $output->write($stdoutText);
        }
        if ($stderrText !== '') {
            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->write($stderrText);
            } else {
                $output->write($stderrText);
            }
        }

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
