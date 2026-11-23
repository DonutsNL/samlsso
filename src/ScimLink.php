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
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso;

use CommonDBTM;
use DBConnection;
use Migration;
use Session;

/**
 * ScimLink persists per-IdP SCIM 2.0 external identifiers and ownership links
 * for GLPI User and Group records provisioned or adopted by a specific IdP.
 *
 * Unlike glpi_users (which carries authtype + auths_id), glpi_groups has no
 * authentication-source ownership column. ScimLink provides symmetric per-IdP
 * isolation and RFC 7643 externalId tracking for both User and Group resources.
 *
 * @since 1.4.0
 */
class ScimLink extends CommonDBTM
{
    /** @var string Database column: auto-increment primary key. */
    public const ID = 'id';

    /** @var string Database column: foreign key to glpi_plugin_samlsso_configs.id. */
    public const CONFIGS_ID = 'configs_id';

    /** @var string Database column: SCIM resource type ('User' or 'Group'). */
    public const ITEM_TYPE = 'item_type';

    /** @var string Database column: foreign key to glpi_users.id or glpi_groups.id. */
    public const GLPI_ITEM_ID = 'glpi_item_id';

    /** @var string Database column: optional RFC 7643 externalId value. */
    public const EXTERNAL_ID = 'external_id';

    /** @var string Database column: timestamp of last SCIM synchronization. */
    public const LAST_SYNC_DATE = 'last_sync_date';

    /** @var string SCIM User resource discriminator. */
    public const TYPE_USER = 'User';

    /** @var string SCIM Group resource discriminator. */
    public const TYPE_GROUP = 'Group';

    /**
     * Find a SCIM ownership link by (configs_id, item_type, external_id).
     *
     * @param int    $configsId  Target IdP configuration ID.
     * @param string $itemType   Resource discriminator ('User' or 'Group').
     * @param string $externalId Non-empty RFC 7643 externalId.
     * @return array<string, mixed>|null
     */
    public static function findByExternalId(int $configsId, string $itemType, string $externalId): ?array
    {
        global $DB;

        $externalId = trim($externalId);
        if ($configsId <= 0 || $externalId === '') {
            return null;
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                self::CONFIGS_ID  => $configsId,
                self::ITEM_TYPE   => $itemType,
                self::EXTERNAL_ID => $externalId,
            ],
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Find a SCIM ownership link by (configs_id, item_type, glpi_item_id).
     *
     * @param int    $configsId  Target IdP configuration ID.
     * @param string $itemType   Resource discriminator ('User' or 'Group').
     * @param int    $glpiItemId GLPI User or Group primary key.
     * @return array<string, mixed>|null
     */
    public static function findByGlpiItem(int $configsId, string $itemType, int $glpiItemId): ?array
    {
        global $DB;

        if ($configsId <= 0 || $glpiItemId <= 0) {
            return null;
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                self::CONFIGS_ID   => $configsId,
                self::ITEM_TYPE    => $itemType,
                self::GLPI_ITEM_ID => $glpiItemId,
            ],
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    /**
     * List all SCIM ownership links for a given IdP and resource type.
     *
     * @param int    $configsId Target IdP configuration ID.
     * @param string $itemType  Resource discriminator ('User' or 'Group').
     * @return array<int, array<string, mixed>> Keyed by glpi_item_id.
     */
    public static function findAllForConfigAndType(int $configsId, string $itemType): array
    {
        global $DB;

        if ($configsId <= 0) {
            return [];
        }

        $links = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                self::CONFIGS_ID => $configsId,
                self::ITEM_TYPE  => $itemType,
            ],
        ]);

        foreach ($iterator as $row) {
            $itemId = (int) ($row[self::GLPI_ITEM_ID] ?? 0);
            if ($itemId > 0) {
                $links[$itemId] = $row;
            }
        }

        return $links;
    }

    /**
     * Insert or update a SCIM ownership link for a GLPI User or Group.
     *
     * @param int         $configsId  Target IdP configuration ID.
     * @param string      $itemType   Resource discriminator ('User' or 'Group').
     * @param int         $glpiItemId GLPI User or Group primary key.
     * @param string|null $externalId Optional RFC 7643 externalId (empty string normalized to null).
     * @return bool
     */
    public static function upsertLink(int $configsId, string $itemType, int $glpiItemId, ?string $externalId): bool
    {
        global $DB;

        if ($configsId <= 0 || $glpiItemId <= 0) {
            return false;
        }

        $normalizedExternalId = null;
        if ($externalId !== null) {
            $trimmed = trim($externalId);
            if ($trimmed !== '') {
                $normalizedExternalId = mb_substr($trimmed, 0, 255);
            }
        }

        $now = date('Y-m-d H:i:s');
        $table = self::getTable();
        $existing = self::findByGlpiItem($configsId, $itemType, $glpiItemId);

        if ($existing !== null) {
            $updateFields = [
                self::LAST_SYNC_DATE => $now,
            ];
            if ($externalId !== null) {
                $updateFields[self::EXTERNAL_ID] = $normalizedExternalId;
            }
            return (bool) $DB->update(
                $table,
                $updateFields,
                [self::ID => (int) $existing[self::ID]]
            );
        }

        return (bool) $DB->insert($table, [
            self::CONFIGS_ID     => $configsId,
            self::ITEM_TYPE      => $itemType,
            self::GLPI_ITEM_ID   => $glpiItemId,
            self::EXTERNAL_ID    => $normalizedExternalId,
            self::LAST_SYNC_DATE => $now,
        ]);
    }

    /**
     * Delete a SCIM ownership link by (configs_id, item_type, glpi_item_id).
     *
     * @param int    $configsId  Target IdP configuration ID.
     * @param string $itemType   Resource discriminator ('User' or 'Group').
     * @param int    $glpiItemId GLPI User or Group primary key.
     * @return bool
     */
    public static function deleteLink(int $configsId, string $itemType, int $glpiItemId): bool
    {
        global $DB;

        if ($configsId <= 0 || $glpiItemId <= 0) {
            return false;
        }

        return (bool) $DB->delete(self::getTable(), [
            self::CONFIGS_ID   => $configsId,
            self::ITEM_TYPE    => $itemType,
            self::GLPI_ITEM_ID => $glpiItemId,
        ]);
    }

    /**
     * Delete all SCIM ownership links belonging to a purged IdP configuration.
     *
     * @param int $configsId Target IdP configuration ID.
     * @return bool
     */
    public static function deleteAllForConfig(int $configsId): bool
    {
        global $DB;

        if ($configsId <= 0) {
            return false;
        }

        return (bool) $DB->delete(self::getTable(), [
            self::CONFIGS_ID => $configsId,
        ]);
    }

    /**
     * Find whether a GLPI User or Group is already owned via SCIM by a different IdP.
     *
     * @param int    $excludeConfigsId Current IdP configuration ID.
     * @param string $itemType         Resource discriminator ('User' or 'Group').
     * @param int    $glpiItemId       GLPI User or Group primary key.
     * @return array<string, mixed>|null Foreign ScimLink row when owned by another IdP, null otherwise.
     */
    public static function findForeignOwner(int $excludeConfigsId, string $itemType, int $glpiItemId): ?array
    {
        global $DB;

        if ($glpiItemId <= 0 || $DB === null || !method_exists($DB, 'request')) {
            return null;
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                self::ITEM_TYPE    => $itemType,
                self::GLPI_ITEM_ID => $glpiItemId,
            ],
        ]);

        foreach ($iterator as $row) {
            if ((int) ($row[self::CONFIGS_ID] ?? 0) !== $excludeConfigsId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Count linked SCIM resources for an IdP and resource type.
     *
     * @param int    $configsId Target IdP configuration ID.
     * @param string $itemType  Resource discriminator ('User' or 'Group').
     * @return int
     */
    public static function countForConfigAndType(int $configsId, string $itemType): int
    {
        return count(self::findAllForConfigAndType($configsId, $itemType));
    }

    /**
     * Retrieve the latest last_sync_date timestamp across all SCIM links for an IdP.
     *
     * @param int $configsId Target IdP configuration ID.
     * @return string|null
     */
    public static function getLastSyncForConfig(int $configsId): ?string
    {
        global $DB;

        if ($configsId <= 0 || $DB === null || !method_exists($DB, 'request') || !$DB->tableExists(self::getTable())) {
            return null;
        }

        $latest = null;
        $iterator = $DB->request([
            'SELECT' => [self::LAST_SYNC_DATE],
            'FROM'   => self::getTable(),
            'WHERE'  => [self::CONFIGS_ID => $configsId],
        ]);

        foreach ($iterator as $row) {
            $val = (string) ($row[self::LAST_SYNC_DATE] ?? '');
            if ($val !== '' && ($latest === null || strcmp($val, $latest) > 0)) {
                $latest = $val;
            }
        }

        return $latest;
    }

    /**
     * Install or upgrade the glpi_plugin_samlsso_scimlinks database table.
     *
     * @param Migration $migration GLPI migration object.
     * @return void
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = getTableForItemType(static::class);

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = <<<SQL
            CREATE TABLE `$table` (
                `id`             INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `configs_id`     INT {$default_key_sign} NOT NULL,
                `item_type`      VARCHAR(16) NOT NULL,
                `glpi_item_id`   INT {$default_key_sign} NOT NULL,
                `external_id`    VARCHAR(255) DEFAULT NULL,
                `last_sync_date` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `configs_id` (`configs_id`),
                UNIQUE KEY `uniq_idp_item` (`configs_id`, `item_type`, `glpi_item_id`),
                UNIQUE KEY `uniq_idp_extid` (`configs_id`, `item_type`, `external_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=COMPRESSED;
            SQL;
            if (!$DB->doQuery($query)) {
                throw new \RuntimeException($DB->error());
            }
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        } else {
            $migration->changeField($table, self::CONFIGS_ID, self::CONFIGS_ID, "INT {$default_key_sign} NOT NULL");
            $migration->changeField($table, self::GLPI_ITEM_ID, self::GLPI_ITEM_ID, "INT {$default_key_sign} NOT NULL");
            $migration->migrationOneTable($table);
        }
    }

    /**
     * Back up and drop the glpi_plugin_samlsso_scimlinks database table on uninstall.
     *
     * @param Migration $migration GLPI migration object.
     * @return void
     */
    public static function uninstall(Migration $migration): void
    {
        $table = getTableForItemType(static::class);
        $migration->backupTables([$table]);
        Session::addMessageAfterRedirect("🆗 backup: $table.");
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }
}
