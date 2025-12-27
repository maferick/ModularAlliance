-- Add custom menu items
CREATE TABLE IF NOT EXISTS menu_custom_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(128) NOT NULL,
  title VARCHAR(128) NOT NULL,
  url VARCHAR(255) NOT NULL DEFAULT '',
  parent_slug VARCHAR(128) NULL,
  sort_order INT NOT NULL DEFAULT 10,
  area ENUM('left','admin_top','user_top','top_left','left_member','left_admin','module_top','site_admin_top') NOT NULL DEFAULT 'left',
  right_slug VARCHAR(128) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  allowed_areas TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_menu_custom_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
