# Roadmap

This roadmap is intentionally operational and milestone-driven.

## Phase 1: Platform Baseline (Done)

- SSO login
- Token storage
- ESI cache contract (MariaDB `esi_cache` with `fetched_at + ttl_seconds`)
- Rights + groups (admin override)
- Menu registry + overrides
- Dark theme baseline layout

## Phase 2: Operational Cache Tooling (Done)

- Admin cache console (`/admin/cache`, right `admin.cache`)
  - remove expired, purge, and safety checks
- Optional Redis support
  - Redis status indicator + prefix-scoped flush
  - Redis as L1 cache-aside in front of MariaDB (fail-safe)

## Phase 3: Universe Resolver Expansion (In Progress)

- Extend supported entity types (structures, stations, etc.)
- Improve pre-priming strategies (chain prime)
- Admin read-only viewer (latest entities, search)

## Phase 4: Admin UX Improvements (Planned)

- Menu editor improvements (drag/drop; rights gating)
- ESI cache tools:
  - purge by scope_key
  - refresh/warm common endpoints
  - hit/miss counters (Redis + DB) and simple telemetry

## Phase 5: Cron Control Plane (Planned)

- Cron admin tools:
  - run now
  - last status/errors
  - safe re-run with locking

## Phase 6: Corp/Alliance Mode (Planned)

- Auto-detect from first login
- Settings override
- Alliance-wide dashboards and modules
