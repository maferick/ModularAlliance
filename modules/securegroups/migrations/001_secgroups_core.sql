CREATE TABLE IF NOT EXISTS module_secgroups_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_slug VARCHAR(128) NOT NULL,
  display_name VARCHAR(128) NOT NULL,
  description TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  auto_group TINYINT(1) NOT NULL DEFAULT 1,
  include_in_updates TINYINT(1) NOT NULL DEFAULT 1,
  can_grace TINYINT(1) NOT NULL DEFAULT 0,
  grace_default_days INT NOT NULL DEFAULT 0,
  allow_applications TINYINT(1) NOT NULL DEFAULT 0,
  notify_on_add TINYINT(1) NOT NULL DEFAULT 0,
  notify_on_remove TINYINT(1) NOT NULL DEFAULT 0,
  notify_on_grace TINYINT(1) NOT NULL DEFAULT 0,
  rights_group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_update_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_secgroups_key (key_slug),
  KEY idx_secgroups_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_filters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  config_json MEDIUMTEXT NOT NULL,
  grace_period_days INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_group_filters (
  group_id BIGINT UNSIGNED NOT NULL,
  filter_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (group_id, filter_id),
  KEY idx_secgroups_group_filters (group_id, sort_order),
  CONSTRAINT fk_secgroups_group_filters_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_secgroups_group_filters_filter FOREIGN KEY (filter_id) REFERENCES module_secgroups_filters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  source VARCHAR(16) NOT NULL DEFAULT 'AUTO',
  reason TEXT NULL,
  evidence_json MEDIUMTEXT NULL,
  last_evaluated_at DATETIME NULL,
  grace_expires_at DATETIME NULL,
  grace_filter_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_secgroups_membership (group_id, user_id),
  KEY idx_secgroups_memberships_status (status),
  CONSTRAINT fk_secgroups_memberships_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_secgroups_memberships_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  note TEXT NULL,
  admin_note TEXT NULL,
  KEY idx_secgroups_requests_status (status),
  CONSTRAINT fk_secgroups_requests_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_secgroups_requests_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  forced_state VARCHAR(16) NOT NULL,
  expires_at DATETIME NULL,
  reason TEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_secgroups_overrides_expires (expires_at),
  CONSTRAINT fk_secgroups_overrides_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_secgroups_overrides_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL,
  source VARCHAR(32) NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  meta_json MEDIUMTEXT NULL,
  KEY idx_secgroups_logs_created (created_at),
  CONSTRAINT fk_secgroups_logs_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_secgroups_logs_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_secgroups_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  KEY idx_secgroups_notifications_user (user_id, created_at),
  CONSTRAINT fk_secgroups_notifications_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_secgroups_notifications_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
