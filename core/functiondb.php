<?php
declare(strict_types=1);

use App\Core\Db;

function identity_mapping_stats(Db $db): array
{
    $counts = $db->one(
        "SELECT
            (SELECT COUNT(*) FROM core_character_identities) AS identities,
            (SELECT COUNT(*) FROM core_character_orgs) AS orgs,
            (SELECT COUNT(*) FROM core_character_identities ci LEFT JOIN core_character_orgs co ON co.character_id=ci.character_id WHERE co.character_id IS NULL) AS missing_orgs,
            (SELECT COUNT(*) FROM core_character_orgs WHERE verified_at IS NULL) AS unverified_orgs"
    ) ?? [];

    $staleRow = $db->one(
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
    return $db->all(
        "SELECT ci.user_id, ci.character_id, ci.is_main,
                co.corp_id AS mapped_corp_id, co.alliance_id AS mapped_alliance_id, co.verified_at AS mapped_verified_at,
                cs.corp_id AS summary_corp_id, cs.alliance_id AS summary_alliance_id, cs.updated_at AS summary_updated_at
         FROM core_character_identities ci
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
    $db->run(
        "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
         SELECT character_id, user_id, 0, linked_at
         FROM character_links
         WHERE status='linked'
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_verified_at=VALUES(last_verified_at)"
    );

    $db->run(
        "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
         SELECT character_id, user_id, 0, updated_at
         FROM module_charlink_links
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_verified_at=VALUES(last_verified_at)"
    );

    $db->run(
        "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
         SELECT character_id, id AS user_id, 1, UTC_TIMESTAMP()
         FROM eve_users
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), is_main=1, last_verified_at=VALUES(last_verified_at)"
    );

    $db->run(
        "UPDATE core_character_identities ci
         JOIN eve_users u ON u.id=ci.user_id
         SET ci.is_main = IF(ci.character_id = u.character_id, 1, 0)"
    );

    $db->run(
        "INSERT INTO core_character_orgs (character_id, corp_id, alliance_id, verified_at)
         SELECT ci.character_id, cs.corp_id, cs.alliance_id, cs.updated_at
         FROM core_character_identities ci
         JOIN module_corptools_character_summary cs ON cs.character_id=ci.character_id
         ON DUPLICATE KEY UPDATE
           corp_id=VALUES(corp_id),
           alliance_id=VALUES(alliance_id),
           verified_at=VALUES(verified_at)"
    );

    $db->run(
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
