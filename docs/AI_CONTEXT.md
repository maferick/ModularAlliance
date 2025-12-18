# AI Context (Continuity File)

This file exists to help resume development safely in a new AI session.

Last updated: **2025-12-18**

## Current Platform State

- Ubuntu 24.04, PHP 8.3-FPM, Nginx, MariaDB 10.11
- Optional Redis is available and may be used as an L1 cache (best-effort).
- Project is a modular portal with:
  - EVE SSO login + token storage
  - Rights/groups with Admin override
  - Menu registry + DB overrides, multiple areas (`left`, `user_top`, `admin_top`)
  - ESI cache in DB (`esi_cache`) using `fetched_at + ttl_seconds`
  - Universe Resolver in DB (`universe_entities`)

## Non-Negotiable Rules

- **No numeric IDs in UI output.** Resolve everything to names/tickers/icons via cached resolvers.
- **All ESI access must go through EsiCache.** No direct `file_get_contents()` or raw HTTP in modules.
- **Migrations are source-of-truth.** If schema changes, add a migration and keep code compatible.

## Admin Operations

- `/admin/cache` (right `admin.cache`):
  - shows ESI and universe cache table stats
  - remove expired ESI rows (based on `fetched_at + ttl_seconds`)
  - purge caches
  - optional Redis status + prefix flush

## Redis Integration Notes

- Redis is optional. It must degrade gracefully.
- Keys must use a prefix (default `portal:`).
- Cache-aside pattern:
  - Read: Redis → MariaDB → ESI
  - Write: MariaDB then populate Redis best-effort
  - TTL should match ESI expiry window

## UI Direction

- Dark theme default (Bootstrap 5.3 + app.css overrides)
- Top bar: User menu always, Admin menu only if admin items are visible
- Left sidebar: non-admin modules only (admin functionality lives under Admin menu)

## Operational Notes

- If behavior changes don’t appear, restart PHP-FPM to clear OPcache.
- Keep admin actions safe by default (confirm prompts, prefix-scoped flush, no destructive defaults).
