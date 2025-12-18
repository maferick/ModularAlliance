-- ==================================================================
-- Add lockout-proof superadmin flag to eve_users
-- ==================================================================

ALTER TABLE eve_users
  ADD COLUMN IF NOT EXISTS is_superadmin TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_eve_users_is_superadmin ON eve_users(is_superadmin);
