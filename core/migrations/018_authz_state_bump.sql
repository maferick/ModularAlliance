-- ==================================================================
-- Authz cache invalidation after rights changes
-- ==================================================================

INSERT IGNORE INTO authz_state (id, version) VALUES (1, 1);
UPDATE authz_state SET version=version+1, updated_at=NOW() WHERE id=1;
