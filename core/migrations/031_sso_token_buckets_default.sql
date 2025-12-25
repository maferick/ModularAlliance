INSERT IGNORE INTO eve_token_buckets (
  user_id,
  character_id,
  bucket,
  org_type,
  org_id,
  access_token,
  refresh_token,
  expires_at,
  scopes_json,
  token_json,
  status,
  last_refresh_at,
  error_last,
  created_at,
  updated_at
)
SELECT
  user_id,
  character_id,
  'default',
  '',
  0,
  access_token,
  refresh_token,
  expires_at,
  scopes_json,
  token_json,
  status,
  last_refresh_at,
  error_last,
  COALESCE(updated_at, NOW()),
  COALESCE(updated_at, NOW())
FROM eve_tokens;

DROP TABLE IF EXISTS eve_tokens;
