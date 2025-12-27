-- Add User dropdown items (area=user_top)
INSERT IGNORE INTO menu_registry (slug,module_slug,kind,allowed_areas,title,url,parent_slug,sort_order,area,right_slug,enabled) VALUES
('user.login','system','action','[\"user_top\"]','Login','/auth/login',NULL,10,'user_top',NULL,1),
('user.profile','system','action','[\"user_top\"]','Profile','/me',NULL,20,'user_top',NULL,1),
('user.alts','system','action','[\"user_top\"]','Linked Characters','/user/alts',NULL,30,'user_top',NULL,1),
('user.logout','system','action','[\"user_top\"]','Logout','/auth/logout',NULL,40,'user_top',NULL,1);
