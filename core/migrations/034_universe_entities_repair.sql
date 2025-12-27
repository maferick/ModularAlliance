ALTER TABLE universe_entities
  MODIFY name VARCHAR(255) NULL,
  MODIFY fetched_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN last_error VARCHAR(255) NULL AFTER ttl_seconds,
  ADD COLUMN fail_count INT NOT NULL DEFAULT 0 AFTER last_error,
  ADD COLUMN last_attempt_at TIMESTAMP NULL DEFAULT NULL AFTER fail_count;

UPDATE universe_entities
SET name = NULL
WHERE name IS NOT NULL AND (name = '' OR name = 'Unknown');
