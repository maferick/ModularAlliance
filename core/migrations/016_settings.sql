-- ==================================================================
-- Settings (compatible with existing schema in your DB)
-- Table: settings(`key`, `value`, updated_at)
-- ==================================================================

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Migrate legacy schema (k/v) to key/value if needed.
SET @settings_has_key := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings'
    AND COLUMN_NAME = 'key'
);
SET @settings_has_k := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings'
    AND COLUMN_NAME = 'k'
);

SET @settings_rename_sql := IF(
  @settings_has_key = 0 AND @settings_has_k = 1,
  'ALTER TABLE settings CHANGE COLUMN k `key` VARCHAR(64) NOT NULL, CHANGE COLUMN v `value` TEXT NULL',
  'SELECT 1'
);
PREPARE settings_rename_stmt FROM @settings_rename_sql;
EXECUTE settings_rename_stmt;
DEALLOCATE PREPARE settings_rename_stmt;

SET @settings_has_updated_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings'
    AND COLUMN_NAME = 'updated_at'
);
SET @settings_updated_sql := IF(
  @settings_has_updated_at = 0,
  'ALTER TABLE settings ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE settings_updated_stmt FROM @settings_updated_sql;
EXECUTE settings_updated_stmt;
DEALLOCATE PREPARE settings_updated_stmt;

-- Safe defaults (won't overwrite if already present)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('site.brand.name', 'killsineve.online'),
('site.identity.type', 'corporation'),
('site.identity.id', '0');
