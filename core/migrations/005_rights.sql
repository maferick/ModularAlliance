-- Seed admin group (admin-proof)
INSERT IGNORE INTO groups (slug, name, is_admin)
VALUES ('admin', 'Administrator', 1);

-- Seed admin-related rights
INSERT IGNORE INTO rights (slug, description) VALUES
 ('admin.access', 'Admin Access'),
 ('admin.cache',  'Manage ESI Cache'),
 ('admin.users',  'Manage Users & Groups'),
 ('admin.menu',   'Manage Menu');

-- Grant admin group all admin.* rights (and we also have hard override in code)
INSERT IGNORE INTO group_rights (group_id, right_id)
SELECT g.id, r.id
FROM groups g
JOIN rights r ON r.slug IN ('admin.access','admin.cache','admin.users','admin.menu')
WHERE g.slug='admin';
