-- Extend menu area ENUM to support user_top
ALTER TABLE menu_registry
  MODIFY area ENUM('left','admin_top','user_top') NOT NULL DEFAULT 'left';

ALTER TABLE menu_overrides
  MODIFY area ENUM('left','admin_top','user_top') DEFAULT NULL;
