-- Add public_id for custom menu items
SET @menu_custom_has_public = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_custom_items'
    AND COLUMN_NAME = 'public_id'
);
SET @menu_custom_public_sql = IF(
  @menu_custom_has_public = 0,
  'ALTER TABLE menu_custom_items ADD COLUMN public_id CHAR(16) NULL AFTER id',
  'SELECT 1'
);
PREPARE menu_custom_public_stmt FROM @menu_custom_public_sql;
EXECUTE menu_custom_public_stmt;
DEALLOCATE PREPARE menu_custom_public_stmt;

UPDATE menu_custom_items
SET public_id = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 16)
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE menu_custom_items
  MODIFY COLUMN public_id CHAR(16) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_menu_custom_public_id
  ON menu_custom_items(public_id);
