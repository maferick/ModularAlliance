-- Adds missing column expected by modules/auth and corptools UI
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'module_corptools_character_summary'
    AND column_name = 'last_login_at'
);
SET @add_col_sql := IF(
  @col_exists = 0,
  'ALTER TABLE module_corptools_character_summary ADD COLUMN last_login_at TIMESTAMP NULL AFTER total_sp',
  'SELECT 1'
);
PREPARE stmt_add_col FROM @add_col_sql;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

-- Optional but recommended for list screens/sorting
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'module_corptools_character_summary'
    AND index_name = 'idx_corptools_char_last_login'
);
SET @add_idx_sql := IF(
  @idx_exists = 0,
  'ALTER TABLE module_corptools_character_summary ADD KEY idx_corptools_char_last_login (last_login_at)',
  'SELECT 1'
);
PREPARE stmt_add_idx FROM @add_idx_sql;
EXECUTE stmt_add_idx;
DEALLOCATE PREPARE stmt_add_idx;
