CREATE TABLE IF NOT EXISTS character_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  character_name VARCHAR(128) NOT NULL,
  status ENUM('linked','revoked') NOT NULL DEFAULT 'linked',
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  linked_by_user_id BIGINT UNSIGNED NULL,
  revoked_at TIMESTAMP NULL,
  revoked_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_character (character_id),
  UNIQUE KEY uniq_user_character (user_id, character_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_char_links_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS character_link_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_prefix CHAR(8) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  used_at TIMESTAMP NULL,
  used_character_id BIGINT UNSIGNED NULL,
  KEY idx_user (user_id),
  UNIQUE KEY uniq_token_hash (token_hash),
  CONSTRAINT fk_char_link_tokens_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
