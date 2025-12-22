-- ==================================================================
-- Align admin modules right slug (admin.modules)
-- ==================================================================

INSERT IGNORE INTO rights (slug, description)
VALUES ('admin.modules', 'Manage Modules');

-- Copy existing grants from admin.module to admin.modules (if present)
INSERT IGNORE INTO group_rights (group_id, right_id)
SELECT gr.group_id, r_new.id
FROM group_rights gr
JOIN rights r_old ON r_old.id = gr.right_id
JOIN rights r_new ON r_new.slug = 'admin.modules'
WHERE r_old.slug = 'admin.module';

-- Ensure admin group has the new right
INSERT IGNORE INTO group_rights (group_id, right_id)
SELECT g.id, r.id
FROM groups g
JOIN rights r ON r.slug = 'admin.modules'
WHERE g.slug='admin';
