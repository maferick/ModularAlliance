-- ==================================================================
-- Authorization decision audit (optional but high leverage)
-- ==================================================================

CREATE TABLE IF NOT EXISTS authz_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  route VARCHAR(190) NULL,
  right_slug VARCHAR(120) NULL,
  decision ENUM('allow','deny') NOT NULL,
  context_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_authz_user (user_id),
  KEY idx_authz_right (right_slug),
  KEY idx_authz_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
