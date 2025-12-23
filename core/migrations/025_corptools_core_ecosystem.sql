CREATE TABLE IF NOT EXISTS module_corptools_audit_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  scopes_json MEDIUMTEXT NULL,
  message VARCHAR(255) NOT NULL DEFAULT '',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL,
  KEY idx_corptools_audit_user (user_id),
  KEY idx_corptools_audit_character (character_id),
  KEY idx_corptools_audit_status (status),
  CONSTRAINT fk_corptools_audit_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_character_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(64) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_corptools_character_category (character_id, category),
  KEY idx_corptools_char_user (user_id),
  KEY idx_corptools_char_category (category),
  CONSTRAINT fk_corptools_char_audit_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_character_summary (
  character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_name VARCHAR(128) NOT NULL,
  is_main TINYINT(1) NOT NULL DEFAULT 0,
  corp_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  alliance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  home_station_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  death_clone_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  jump_clone_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  location_system_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  location_region_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  current_ship_type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  current_ship_name VARCHAR(128) NOT NULL DEFAULT '',
  wallet_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_sp BIGINT UNSIGNED NOT NULL DEFAULT 0,
  assets_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  assets_value DECIMAL(18,2) NOT NULL DEFAULT 0,
  corp_roles_json MEDIUMTEXT NULL,
  corp_title VARCHAR(128) NOT NULL DEFAULT '',
  last_audit_at TIMESTAMP NULL,
  audit_loaded TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_corptools_char_user (user_id),
  KEY idx_corptools_char_corp (corp_id),
  KEY idx_corptools_char_location_system (location_system_id),
  KEY idx_corptools_char_location_region (location_region_id),
  KEY idx_corptools_char_ship_type (current_ship_type_id),
  KEY idx_corptools_char_assets_count (assets_count),
  KEY idx_corptools_char_total_sp (total_sp),
  KEY idx_corptools_char_audit_loaded (audit_loaded),
  CONSTRAINT fk_corptools_char_summary_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_member_summary (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  main_character_id BIGINT UNSIGNED NOT NULL,
  main_character_name VARCHAR(128) NOT NULL,
  corp_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  alliance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  highest_sp BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_login_at TIMESTAMP NULL,
  corp_joined_at DATETIME NULL,
  audit_loaded TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_corptools_member_corp (corp_id),
  KEY idx_corptools_member_sp (highest_sp),
  KEY idx_corptools_member_last_login (last_login_at),
  KEY idx_corptools_member_audit_loaded (audit_loaded),
  CONSTRAINT fk_corptools_member_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_character_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  type_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  location_type VARCHAR(32) NOT NULL DEFAULT '',
  quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_singleton TINYINT(1) NOT NULL DEFAULT 0,
  is_blueprint_copy TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_corptools_asset_item (character_id, item_id),
  KEY idx_corptools_asset_user (user_id),
  KEY idx_corptools_asset_type (type_id),
  KEY idx_corptools_asset_group (group_id),
  KEY idx_corptools_asset_category (category_id),
  KEY idx_corptools_asset_location (location_id),
  CONSTRAINT fk_corptools_asset_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_character_skills (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  skill_id BIGINT UNSIGNED NOT NULL,
  trained_level INT NOT NULL DEFAULT 0,
  active_level INT NOT NULL DEFAULT 0,
  skillpoints_in_skill INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_corptools_skill (character_id, skill_id),
  KEY idx_corptools_skill_user (user_id),
  KEY idx_corptools_skill_id (skill_id),
  CONSTRAINT fk_corptools_skill_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_pings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_hash CHAR(64) NOT NULL,
  source VARCHAR(64) NOT NULL DEFAULT 'webhook',
  payload_json MEDIUMTEXT NOT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  UNIQUE KEY uniq_corptools_ping_hash (event_hash),
  KEY idx_corptools_ping_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_industry_structures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  corp_id BIGINT UNSIGNED NOT NULL,
  structure_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL DEFAULT '',
  system_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  region_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rigs_json MEDIUMTEXT NULL,
  services_json MEDIUMTEXT NULL,
  fuel_expires_at DATETIME NULL,
  state VARCHAR(32) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_corptools_structure (corp_id, structure_id),
  KEY idx_corptools_structure_system (system_id),
  KEY idx_corptools_structure_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_corptools_corp_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  corp_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(64) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_corptools_corp_category (corp_id, category),
  KEY idx_corptools_corp (corp_id),
  KEY idx_corptools_corp_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
