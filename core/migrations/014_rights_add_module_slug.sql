-- ==================================================================
-- Rights: add module_slug for grouping/filtering (idempotent)
-- ==================================================================

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'rights'
    AND COLUMN_NAME = 'module_slug'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE rights ADD COLUMN module_slug VARCHAR(80) NOT NULL DEFAULT ''core''',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'rights'
    AND INDEX_NAME = 'idx_rights_module_slug'
);

SET @sql2 := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_rights_module_slug ON rights(module_slug)',
  'SELECT 1'
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
