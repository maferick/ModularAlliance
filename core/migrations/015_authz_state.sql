-- ==================================================================
-- AuthZ global state: version-based cache invalidation (idempotent)
-- ==================================================================

CREATE TABLE IF NOT EXISTS authz_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO authz_state (id, version) VALUES (1, 1);
