# AI Context

This file is kept for compatibility. The canonical continuity document is:

- `docs/AI_CONTEXT.md`

Last updated: **2025-12-18**

## Quick pointers

- Config lives at `/var/www/config.php` (server-only, not in git).
- ESI cache expiry is `fetched_at + ttl_seconds`.
- Redis (optional) can act as L1 cache-aside; ensure prefix is set (default `portal:`).
- Admin cache console lives at `/admin/cache` (right `admin.cache`).
