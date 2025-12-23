-- ==================================================================
-- Access log storage
-- ==================================================================

CREATE TABLE IF NOT EXISTS access_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  character_id BIGINT UNSIGNED NULL,
  ip VARCHAR(64) NULL,
  method VARCHAR(10) NULL,
  path VARCHAR(255) NULL,
  status SMALLINT UNSIGNED NULL,
  decision VARCHAR(16) NULL,
  reason VARCHAR(64) NULL,
  context_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_access_created (created_at),
  KEY idx_access_user (user_id),
  KEY idx_access_path (path),
  KEY idx_access_decision (decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
