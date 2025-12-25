CREATE TABLE IF NOT EXISTS module_securegroups_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE,
  description TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  visibility VARCHAR(32) NOT NULL DEFAULT 'admin_only',
  unknown_data_handling VARCHAR(16) NOT NULL DEFAULT 'fail',
  enforcement_mode VARCHAR(16) NOT NULL DEFAULT 'dry-run',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_securegroups_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(128) NOT NULL,
  rule_key VARCHAR(128) NOT NULL,
  operator VARCHAR(32) NOT NULL,
  value TEXT NULL,
  logic_group INT NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_securegroups_rules_group (group_id),
  CONSTRAINT fk_securegroups_rules_group FOREIGN KEY (group_id) REFERENCES module_securegroups_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_securegroups_membership (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'out',
  source VARCHAR(16) NOT NULL DEFAULT 'auto',
  last_evaluated_at DATETIME NULL,
  last_changed_at DATETIME NULL,
  reason_summary TEXT NULL,
  evidence_json MEDIUMTEXT NULL,
  eval_status VARCHAR(16) NOT NULL DEFAULT 'pending',
  eval_reason_summary TEXT NULL,
  eval_evidence_json MEDIUMTEXT NULL,
  manual_note TEXT NULL,
  manual_sticky TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_securegroups_member (group_id, user_id),
  KEY idx_securegroups_member_user (user_id),
  KEY idx_securegroups_member_status (status),
  KEY idx_securegroups_member_eval_status (eval_status),
  CONSTRAINT fk_securegroups_member_group FOREIGN KEY (group_id) REFERENCES module_securegroups_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_securegroups_member_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_securegroups_job_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_key VARCHAR(128) NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  processed_count INT NOT NULL DEFAULT 0,
  changed_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  log_excerpt TEXT NULL,
  details_json MEDIUMTEXT NULL,
  KEY idx_securegroups_job_runs_key (job_key, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
