-- Add top_left menu area support
ALTER TABLE menu_registry
  MODIFY area ENUM('left','admin_top','user_top','top_left','left_member','left_admin','module_top','site_admin_top') NOT NULL DEFAULT 'left';

ALTER TABLE menu_overrides
  MODIFY area ENUM('left','admin_top','user_top','top_left','left_member','left_admin','module_top','site_admin_top') DEFAULT NULL;
