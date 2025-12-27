CREATE TABLE IF NOT EXISTS core_character_identities (
  character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  is_main TINYINT(1) NOT NULL DEFAULT 0,
  last_verified_at TIMESTAMP NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_core_char_user (user_id),
  KEY idx_core_char_main (user_id, is_main),
  CONSTRAINT fk_core_char_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS core_character_orgs (
  character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  corp_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  alliance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at TIMESTAMP NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_core_org_corp (corp_id),
  KEY idx_core_org_alliance (alliance_id),
  CONSTRAINT fk_core_org_character FOREIGN KEY (character_id) REFERENCES core_character_identities(character_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
SELECT character_id, user_id, 0, linked_at
FROM character_links
WHERE status='linked'
ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_verified_at=VALUES(last_verified_at);

INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
SELECT character_id, user_id, 0, updated_at
FROM module_charlink_links
ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_verified_at=VALUES(last_verified_at);

INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
SELECT character_id, id AS user_id, 1, UTC_TIMESTAMP()
FROM eve_users
ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), is_main=1, last_verified_at=VALUES(last_verified_at);

UPDATE core_character_identities ci
JOIN eve_users u ON u.id=ci.user_id
SET ci.is_main = IF(ci.character_id = u.character_id, 1, 0);

INSERT INTO core_character_orgs (character_id, corp_id, alliance_id, verified_at)
SELECT ci.character_id, cs.corp_id, cs.alliance_id, cs.updated_at
FROM core_character_identities ci
JOIN module_corptools_character_summary cs ON cs.character_id=ci.character_id
ON DUPLICATE KEY UPDATE
  corp_id=VALUES(corp_id),
  alliance_id=VALUES(alliance_id),
  verified_at=VALUES(verified_at);

INSERT INTO core_character_orgs (character_id, corp_id, alliance_id, verified_at)
SELECT ci.character_id, ms.corp_id, ms.alliance_id, ms.updated_at
FROM core_character_identities ci
JOIN module_corptools_member_summary ms ON ms.user_id=ci.user_id AND ci.is_main=1
ON DUPLICATE KEY UPDATE
  corp_id=IF(VALUES(corp_id) > 0, VALUES(corp_id), core_character_orgs.corp_id),
  alliance_id=IF(VALUES(alliance_id) > 0, VALUES(alliance_id), core_character_orgs.alliance_id),
  verified_at=IF(core_character_orgs.verified_at IS NULL OR VALUES(verified_at) > core_character_orgs.verified_at, VALUES(verified_at), core_character_orgs.verified_at);
