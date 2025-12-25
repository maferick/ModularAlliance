CREATE TABLE IF NOT EXISTS module_memberaudit_skill_sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description TEXT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
  source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_memberaudit_skill_sets_source (source_type, source_id),
  KEY idx_memberaudit_skill_sets_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_memberaudit_skill_set_skills (
  skill_set_id BIGINT UNSIGNED NOT NULL,
  skill_id BIGINT UNSIGNED NOT NULL,
  required_level INT NOT NULL DEFAULT 0,
  PRIMARY KEY (skill_set_id, skill_id),
  KEY idx_memberaudit_skill_set_skill (skill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_memberaudit_skill_set_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  skill_set_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_memberaudit_skill_set_assign (skill_set_id, character_id),
  KEY idx_memberaudit_skill_set_assign_char (character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_memberaudit_share_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  token_prefix VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_memberaudit_share_tokens_user (user_id),
  KEY idx_memberaudit_share_tokens_expires (expires_at),
  UNIQUE KEY uniq_memberaudit_share_tokens_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_memberaudit_share_token_characters (
  token_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (token_id, character_id),
  KEY idx_memberaudit_share_char (character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_memberaudit_access_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  access_type VARCHAR(32) NOT NULL,
  viewer_user_id BIGINT UNSIGNED NULL,
  character_id BIGINT UNSIGNED NULL,
  token_id BIGINT UNSIGNED NULL,
  scope VARCHAR(64) NOT NULL DEFAULT '',
  ip_address VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_memberaudit_access_log_time (accessed_at),
  KEY idx_memberaudit_access_log_type (access_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
