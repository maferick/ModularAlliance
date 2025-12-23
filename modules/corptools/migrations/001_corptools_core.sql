CREATE TABLE IF NOT EXISTS module_corptools_character_audit_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(64) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_corptools_char_audit_snap_user (user_id),
  KEY idx_corptools_char_audit_snap_char (character_id, fetched_at),
  KEY idx_corptools_char_audit_snap_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_corp_audit_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  corp_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(64) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_corptools_corp_audit_snap_corp (corp_id, fetched_at),
  KEY idx_corptools_corp_audit_snap_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_jobs (
  job_key VARCHAR(128) NOT NULL PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  schedule_seconds INT NOT NULL DEFAULT 60,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  next_run_at DATETIME NULL,
  last_status VARCHAR(32) NOT NULL DEFAULT 'never',
  last_duration_ms INT NOT NULL DEFAULT 0,
  last_message VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_job_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_key VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  duration_ms INT NOT NULL DEFAULT 0,
  message VARCHAR(255) NOT NULL DEFAULT '',
  error_trace MEDIUMTEXT NULL,
  meta_json MEDIUMTEXT NULL,
  KEY idx_corptools_job_runs_key (job_key, started_at),
  KEY idx_corptools_job_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_job_locks (
  job_key VARCHAR(128) NOT NULL PRIMARY KEY,
  owner VARCHAR(64) NOT NULL,
  locked_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_corptools_job_locks_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
