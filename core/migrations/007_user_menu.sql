-- Add User dropdown items (area=user_top)
INSERT IGNORE INTO menu_registry (slug,title,url,parent_slug,sort_order,area,right_slug,enabled) VALUES
('user.login','Login','/auth/login',NULL,10,'user_top',NULL,1),
('user.profile','Profile','/me',NULL,20,'user_top',NULL,1),
('user.alts','Linked Characters','/user/alts',NULL,30,'user_top',NULL,1),
('user.logout','Logout','/auth/logout',NULL,40,'user_top',NULL,1);
