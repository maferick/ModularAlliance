-- ==================================================================
-- Admin modules overview right
-- ==================================================================

INSERT IGNORE INTO rights (slug, description)
VALUES ('admin.module', 'Manage Modules');

INSERT IGNORE INTO group_rights (group_id, right_id)
SELECT g.id, r.id
FROM groups g
JOIN rights r ON r.slug = 'admin.module'
WHERE g.slug='admin';
