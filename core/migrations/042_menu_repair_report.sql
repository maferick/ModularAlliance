-- Track menu repair and slug normalization changes
CREATE TABLE IF NOT EXISTS menu_repair_report (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  change_type VARCHAR(64) NOT NULL,
  message VARCHAR(255) NOT NULL,
  details_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_menu_repair_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
