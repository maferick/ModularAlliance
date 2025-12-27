<?php
declare(strict_types=1);

use App\Core\Db;
use PDO;
use Throwable;

function db_normalize_params(array $params): array
{
    $out = [];
    foreach ($params as $key => $value) {
        if ($value instanceof DateTimeInterface) {
            $out[$key] = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $out[$key] = $value ? 1 : 0;
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

function db_exec(Db $db, string $sql, array $params = []): int
{
    $stmt = $db->pdo()->prepare($sql);
    $stmt->execute(db_normalize_params($params));
    return $stmt->rowCount();
}

function db_one(Db $db, string $sql, array $params = []): ?array
{
    $stmt = $db->pdo()->prepare($sql);
    $stmt->execute(db_normalize_params($params));
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function db_all(Db $db, string $sql, array $params = []): array
{
    $stmt = $db->pdo()->prepare($sql);
    $stmt->execute(db_normalize_params($params));
    return $stmt->fetchAll();
}

function db_value(Db $db, string $sql, array $params = [], mixed $default = null): mixed
{
    $stmt = $db->pdo()->prepare($sql);
    $stmt->execute(db_normalize_params($params));
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function db_tx(Db $db, callable $fn): mixed
{
    $db->begin();
    try {
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function db_upsert(Db $db, string $table, array $data, array $updateColumns = []): int
{
    if ($data === []) {
        return 0;
    }

    $columns = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $quotedColumns = implode(', ', array_map(fn(string $col): string => "`{$col}`", $columns));

    $updateColumns = $updateColumns ?: $columns;
    $updates = implode(', ', array_map(fn(string $col): string => "`{$col}`=VALUES(`{$col}`)", $updateColumns));

    $sql = "INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}";

    return db_exec($db, $sql, array_values($data));
}

function db_quote(Db $db, string $value): string
{
    return (string)$db->pdo()->quote($value);
}

function db_driver(Db $db): string
{
    return (string)$db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function db_set_attribute(Db $db, int $attribute, mixed $value): void
{
    $db->pdo()->setAttribute($attribute, $value);
}

function sde_ensure_tables(Db $db): void
{
    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_inv_categories (
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            icon_id INT UNSIGNED NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_inv_groups (
            group_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            icon_id INT UNSIGNED NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_inv_types (
            type_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            mass DOUBLE NULL,
            volume DOUBLE NULL,
            capacity DOUBLE NULL,
            portion_size INT UNSIGNED NULL,
            race_id INT UNSIGNED NULL,
            base_price DECIMAL(19,4) NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            market_group_id INT UNSIGNED NULL,
            icon_id INT UNSIGNED NULL,
            sound_id INT UNSIGNED NULL,
            graphic_id INT UNSIGNED NULL,
            PRIMARY KEY (type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_map_regions (
            region_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (region_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_map_constellations (
            constellation_id INT UNSIGNED NOT NULL,
            region_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (constellation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_map_solar_systems (
            solar_system_id INT UNSIGNED NOT NULL,
            constellation_id INT UNSIGNED NOT NULL,
            region_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            security DECIMAL(4,2) NULL,
            security_class VARCHAR(5) NULL,
            PRIMARY KEY (solar_system_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_sta_stations (
            station_id BIGINT UNSIGNED NOT NULL,
            station_type_id INT UNSIGNED NULL,
            corporation_id INT UNSIGNED NULL,
            solar_system_id INT UNSIGNED NOT NULL,
            constellation_id INT UNSIGNED NULL,
            region_id INT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            security DECIMAL(4,2) NULL,
            PRIMARY KEY (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS sde_meta (
            meta_key VARCHAR(64) NOT NULL,
            meta_value TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (meta_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sde_ensure_index($db, 'sde_inv_groups', 'idx_category_id', 'category_id');
    sde_ensure_index($db, 'sde_inv_types', 'idx_group_id', 'group_id');
    sde_ensure_index($db, 'sde_map_constellations', 'idx_region_id', 'region_id');
    sde_ensure_index($db, 'sde_map_solar_systems', 'idx_region_id', 'region_id');
    sde_ensure_index($db, 'sde_map_solar_systems', 'idx_constellation_id', 'constellation_id');
    sde_ensure_index($db, 'sde_sta_stations', 'idx_solar_system_id', 'solar_system_id');
    sde_ensure_index($db, 'sde_sta_stations', 'idx_region_id', 'region_id');
    sde_ensure_index($db, 'sde_sta_stations', 'idx_constellation_id', 'constellation_id');
}

function sde_ensure_index(Db $db, string $table, string $indexName, string $columns): void
{
    $exists = db_one(
        $db,
        "SHOW INDEX FROM `{$table}` WHERE Key_name=?",
        [$indexName]
    );
    if ($exists) {
        return;
    }

    db_exec($db, "ALTER TABLE `{$table}` ADD INDEX {$indexName} ({$columns})");
}

function identity_mapping_stats(Db $db): array
{
    $counts = db_one(
        $db,
        "SELECT
            (SELECT COUNT(*) FROM core_character_identities) AS identities,
            (SELECT COUNT(*) FROM core_character_orgs) AS orgs,
            (SELECT COUNT(*) FROM core_character_identities ci LEFT JOIN core_character_orgs co ON co.character_id=ci.character_id WHERE co.character_id IS NULL) AS missing_orgs,
            (SELECT COUNT(*) FROM core_character_orgs WHERE verified_at IS NULL) AS unverified_orgs"
    ) ?? [];

    $staleRow = db_one(
        $db,
        "SELECT COUNT(*) AS stale
         FROM core_character_orgs
         WHERE verified_at IS NOT NULL AND verified_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
    ) ?? [];

    return [
        'identities' => (int)($counts['identities'] ?? 0),
        'orgs' => (int)($counts['orgs'] ?? 0),
        'missing_orgs' => (int)($counts['missing_orgs'] ?? 0),
        'unverified_orgs' => (int)($counts['unverified_orgs'] ?? 0),
        'stale_orgs' => (int)($staleRow['stale'] ?? 0),
    ];
}

function identity_mapping_mismatches(Db $db, int $limit = 50): array
{
    return db_all(
        $db,
        "SELECT ci.user_id,
                ci.character_id,
                ci.is_main,
                u.public_id AS user_public_id,
                COALESCE(cl.character_name, u.character_name, 'Unknown') AS character_name,
                co.corp_id AS mapped_corp_id,
                co.alliance_id AS mapped_alliance_id,
                co.verified_at AS mapped_verified_at,
                cs.corp_id AS summary_corp_id,
                cs.alliance_id AS summary_alliance_id,
                cs.updated_at AS summary_updated_at
         FROM core_character_identities ci
         LEFT JOIN eve_users u ON u.id=ci.user_id
         LEFT JOIN character_links cl ON cl.character_id=ci.character_id AND cl.status='linked'
         LEFT JOIN core_character_orgs co ON co.character_id=ci.character_id
         LEFT JOIN module_corptools_character_summary cs ON cs.character_id=ci.character_id
         WHERE (co.character_id IS NULL)
            OR (cs.character_id IS NOT NULL AND (co.corp_id <> cs.corp_id OR co.alliance_id <> cs.alliance_id))
         ORDER BY ci.is_main DESC, ci.user_id ASC
         LIMIT ?",
        [$limit]
    );
}

function identity_mapping_rebuild(Db $db): array
{
    db_exec(
        $db,
        "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
         SELECT character_id, user_id, 0, linked_at
         FROM character_links
         WHERE status='linked'
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_verified_at=VALUES(last_verified_at)"
    );

    db_exec(
        $db,
        "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
         SELECT character_id, user_id, 0, updated_at
         FROM module_charlink_links
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_verified_at=VALUES(last_verified_at)"
    );

    db_exec(
        $db,
        "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
         SELECT character_id, id AS user_id, 1, UTC_TIMESTAMP()
         FROM eve_users
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), is_main=1, last_verified_at=VALUES(last_verified_at)"
    );

    db_exec(
        $db,
        "UPDATE core_character_identities ci
         JOIN eve_users u ON u.id=ci.user_id
         SET ci.is_main = IF(ci.character_id = u.character_id, 1, 0)"
    );

    db_exec(
        $db,
        "INSERT INTO core_character_orgs (character_id, corp_id, alliance_id, verified_at)
         SELECT ci.character_id, cs.corp_id, cs.alliance_id, cs.updated_at
         FROM core_character_identities ci
         JOIN module_corptools_character_summary cs ON cs.character_id=ci.character_id
         ON DUPLICATE KEY UPDATE
           corp_id=VALUES(corp_id),
           alliance_id=VALUES(alliance_id),
           verified_at=VALUES(verified_at)"
    );

    db_exec(
        $db,
        "INSERT INTO core_character_orgs (character_id, corp_id, alliance_id, verified_at)
         SELECT ci.character_id, ms.corp_id, ms.alliance_id, ms.updated_at
         FROM core_character_identities ci
         JOIN module_corptools_member_summary ms ON ms.user_id=ci.user_id AND ci.is_main=1
         ON DUPLICATE KEY UPDATE
           corp_id=IF(VALUES(corp_id) > 0, VALUES(corp_id), core_character_orgs.corp_id),
           alliance_id=IF(VALUES(alliance_id) > 0, VALUES(alliance_id), core_character_orgs.alliance_id),
           verified_at=IF(core_character_orgs.verified_at IS NULL OR VALUES(verified_at) > core_character_orgs.verified_at, VALUES(verified_at), core_character_orgs.verified_at)"
    );

    return identity_mapping_stats($db);
}
