CREATE TABLE IF NOT EXISTS module_fittings_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description TEXT NULL,
  visibility_scope VARCHAR(16) NOT NULL DEFAULT 'all',
  visibility_org_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_fittings_categories_scope (visibility_scope, visibility_org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_category_groups (
  category_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (category_id, group_id),
  KEY idx_fittings_cat_groups_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_doctrines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_fits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  doctrine_id BIGINT UNSIGNED NULL,
  name VARCHAR(128) NOT NULL,
  ship_name VARCHAR(128) NOT NULL,
  eft_text MEDIUMTEXT NOT NULL,
  parsed_json MEDIUMTEXT NOT NULL,
  tags_json TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  has_renamed_items TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_fittings_fits_category (category_id),
  KEY idx_fittings_fits_doctrine (doctrine_id),
  KEY idx_fittings_fits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_fit_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fit_id BIGINT UNSIGNED NOT NULL,
  revision INT NOT NULL,
  eft_text MEDIUMTEXT NOT NULL,
  parsed_json MEDIUMTEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  change_summary VARCHAR(255) NOT NULL DEFAULT '',
  KEY idx_fittings_fit_revisions_fit (fit_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  fit_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, fit_id),
  KEY idx_fittings_favorites_fit (fit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_saved_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fit_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fittings_saved_fit (fit_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_type_names (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type_id BIGINT UNSIGNED NULL,
  original_name VARCHAR(128) NOT NULL,
  current_name VARCHAR(128) NOT NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  renamed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_fittings_type_names_type (type_id),
  KEY idx_fittings_type_names_name (original_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fittings_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(255) NOT NULL,
  meta_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fittings_audit_created (created_at),
  KEY idx_fittings_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
