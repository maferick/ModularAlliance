-- Add node_type support for menu entries
SET @menu_node_type_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_registry'
    AND COLUMN_NAME = 'node_type'
);
SET @menu_node_type_sql = IF(
  @menu_node_type_exists = 0,
  'ALTER TABLE menu_registry ADD COLUMN node_type ENUM(''container'',''link'',''both'') NOT NULL DEFAULT ''link'' AFTER kind',
  'SELECT 1'
);
PREPARE menu_node_type_stmt FROM @menu_node_type_sql;
EXECUTE menu_node_type_stmt;
DEALLOCATE PREPARE menu_node_type_stmt;

SET @menu_override_node_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_overrides'
    AND COLUMN_NAME = 'node_type'
);
SET @menu_override_node_sql = IF(
  @menu_override_node_exists = 0,
  'ALTER TABLE menu_overrides ADD COLUMN node_type ENUM(''container'',''link'',''both'') NULL AFTER url',
  'SELECT 1'
);
PREPARE menu_override_node_stmt FROM @menu_override_node_sql;
EXECUTE menu_override_node_stmt;
DEALLOCATE PREPARE menu_override_node_stmt;

SET @menu_custom_node_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_custom_items'
    AND COLUMN_NAME = 'node_type'
);
SET @menu_custom_node_sql = IF(
  @menu_custom_node_exists = 0,
  'ALTER TABLE menu_custom_items ADD COLUMN node_type ENUM(''container'',''link'',''both'') NOT NULL DEFAULT ''link'' AFTER url',
  'SELECT 1'
);
PREPARE menu_custom_node_stmt FROM @menu_custom_node_sql;
EXECUTE menu_custom_node_stmt;
DEALLOCATE PREPARE menu_custom_node_stmt;
