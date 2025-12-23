CREATE TABLE IF NOT EXISTS module_charlink_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_prefix CHAR(8) NOT NULL,
  purpose VARCHAR(32) NOT NULL DEFAULT 'link',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  used_at TIMESTAMP NULL,
  used_character_id BIGINT UNSIGNED NULL,
  KEY idx_user (user_id),
  UNIQUE KEY uniq_charlink_token_hash (token_hash),
  CONSTRAINT fk_charlink_state_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
