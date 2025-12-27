-- Add menu metadata and defaults for menu registry (idempotent)
SET @menu_module_slug_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_registry'
    AND COLUMN_NAME = 'module_slug'
);
SET @menu_module_slug_sql = IF(
  @menu_module_slug_exists = 0,
  'ALTER TABLE menu_registry ADD module_slug VARCHAR(128) NOT NULL DEFAULT ''system'' AFTER slug',
  'SELECT 1'
);
PREPARE menu_module_slug_stmt FROM @menu_module_slug_sql;
EXECUTE menu_module_slug_stmt;
DEALLOCATE PREPARE menu_module_slug_stmt;

SET @menu_kind_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_registry'
    AND COLUMN_NAME = 'kind'
);
SET @menu_kind_sql = IF(
  @menu_kind_exists = 0,
  'ALTER TABLE menu_registry ADD kind ENUM(''module_root'',''subnav'',''action'') NOT NULL DEFAULT ''action'' AFTER module_slug',
  'SELECT 1'
);
PREPARE menu_kind_stmt FROM @menu_kind_sql;
EXECUTE menu_kind_stmt;
DEALLOCATE PREPARE menu_kind_stmt;

SET @menu_allowed_areas_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_registry'
    AND COLUMN_NAME = 'allowed_areas'
);
SET @menu_allowed_areas_sql = IF(
  @menu_allowed_areas_exists = 0,
  'ALTER TABLE menu_registry ADD allowed_areas TEXT NULL AFTER kind',
  'SELECT 1'
);
PREPARE menu_allowed_areas_stmt FROM @menu_allowed_areas_sql;
EXECUTE menu_allowed_areas_stmt;
DEALLOCATE PREPARE menu_allowed_areas_stmt;

UPDATE menu_registry
SET kind = CASE
  WHEN parent_slug IS NOT NULL THEN 'subnav'
  WHEN area IN ('module_top','top_left') THEN 'module_root'
  ELSE 'action'
END;

UPDATE menu_registry
SET allowed_areas = CASE
  WHEN kind = 'module_root' THEN '["left","admin_top","user_top","top_left"]'
  ELSE CONCAT('["',
    CASE
      WHEN area = 'module_top' THEN 'top_left'
      WHEN area = 'site_admin_top' THEN 'admin_top'
      WHEN area = 'left_member' THEN 'left'
      WHEN area = 'left_admin' THEN 'left'
      ELSE area
    END,
  '"]')
END;

ALTER TABLE menu_registry
  MODIFY allowed_areas TEXT NOT NULL;
