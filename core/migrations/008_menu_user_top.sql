-- Extend menu area ENUM to support user_top + new layout areas
ALTER TABLE menu_registry
  MODIFY area ENUM('left','admin_top','user_top','left_member','left_admin','module_top','site_admin_top') NOT NULL DEFAULT 'left_member';

ALTER TABLE menu_overrides
  MODIFY area ENUM('left','admin_top','user_top','left_member','left_admin','module_top','site_admin_top') DEFAULT NULL;
