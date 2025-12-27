ALTER TABLE eve_users
  ADD COLUMN IF NOT EXISTS public_id CHAR(16) NULL AFTER id;

UPDATE eve_users
SET public_id = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 16)
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE eve_users
  MODIFY COLUMN public_id CHAR(16) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_eve_users_public_id
  ON eve_users(public_id);

ALTER TABLE corp_scope_policies
  ADD COLUMN IF NOT EXISTS public_id CHAR(16) NULL AFTER id;

UPDATE corp_scope_policies
SET public_id = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 16)
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE corp_scope_policies
  MODIFY COLUMN public_id CHAR(16) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_scope_policy_public_id
  ON corp_scope_policies(public_id);

ALTER TABLE corp_scope_policy_overrides
  ADD COLUMN IF NOT EXISTS public_id CHAR(16) NULL AFTER id;

UPDATE corp_scope_policy_overrides
SET public_id = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 16)
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE corp_scope_policy_overrides
  MODIFY COLUMN public_id CHAR(16) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_scope_override_public_id
  ON corp_scope_policy_overrides(public_id);
