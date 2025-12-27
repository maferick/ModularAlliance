-- Add menu metadata and defaults for menu registry
ALTER TABLE menu_registry
  ADD module_slug VARCHAR(128) NOT NULL DEFAULT 'system' AFTER slug,
  ADD kind ENUM('module_root','subnav','action') NOT NULL DEFAULT 'action' AFTER module_slug,
  ADD allowed_areas TEXT NULL AFTER kind;

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
