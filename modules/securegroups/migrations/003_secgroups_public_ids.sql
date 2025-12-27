ALTER TABLE module_secgroups_filters
  ADD COLUMN IF NOT EXISTS public_id CHAR(16) NULL AFTER id;

UPDATE module_secgroups_filters
SET public_id = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 16)
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE module_secgroups_filters
  MODIFY COLUMN public_id CHAR(16) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_secgroups_filter_public_id
  ON module_secgroups_filters(public_id);

ALTER TABLE module_secgroups_requests
  ADD COLUMN IF NOT EXISTS public_id CHAR(16) NULL AFTER id;

UPDATE module_secgroups_requests
SET public_id = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 16)
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE module_secgroups_requests
  MODIFY COLUMN public_id CHAR(16) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_secgroups_request_public_id
  ON module_secgroups_requests(public_id);
