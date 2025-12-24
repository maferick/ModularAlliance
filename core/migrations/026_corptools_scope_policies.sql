CREATE TABLE IF NOT EXISTS corp_scope_policies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  applies_to ENUM('corp_members', 'alliance_members', 'all_users') NOT NULL DEFAULT 'all_users',
  required_scopes_json MEDIUMTEXT NULL,
  optional_scopes_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_scope_policy_active (is_active),
  KEY idx_scope_policy_applies (applies_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS corp_scope_policy_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  policy_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('role', 'group', 'user') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  required_scopes_json MEDIUMTEXT NULL,
  optional_scopes_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_scope_override_policy (policy_id),
  KEY idx_scope_override_target (target_type, target_id),
  CONSTRAINT fk_scope_override_policy FOREIGN KEY (policy_id) REFERENCES corp_scope_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_character_scope_status (
  character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  policy_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'unknown',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  required_scopes_json MEDIUMTEXT NULL,
  optional_scopes_json MEDIUMTEXT NULL,
  granted_scopes_json MEDIUMTEXT NULL,
  missing_scopes_json MEDIUMTEXT NULL,
  token_expires_at TIMESTAMP NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_scope_status_user (user_id),
  KEY idx_scope_status_status (status),
  CONSTRAINT fk_scope_status_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scope_status_policy FOREIGN KEY (policy_id) REFERENCES corp_scope_policies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  character_id BIGINT UNSIGNED NULL,
  payload_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_event (event),
  KEY idx_audit_event_user (user_id),
  KEY idx_audit_event_character (character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
