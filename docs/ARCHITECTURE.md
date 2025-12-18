# Architecture

ModularAlliance is built on a simple operating model:

**Front Controller → Router → Module Routes → Core Services → Cache Layers → Views**

The design goal is operational stability at scale: predictable behavior with many modules and many users.

## Core Contracts

Core contract names are stable and should not be renamed casually.

- `core/bootstrap.php` – loads config, starts session, autoload, core wiring
- `src/App/Core/App.php` – app composition root, router registration, guard wiring
- `src/App/Core/Db.php` – PDO + helpers
- `src/App/Core/Migrator.php` – migration tracking + checksums
- `src/App/Core/Menu.php` – menu registry + DB overrides + trees
- `src/App/Core/Rights.php` – RBAC, admin override, requireRight()
- `src/App/Core/EsiCache.php` – the only supported way to call ESI (via cache)
- `src/App/Core/Universe.php` – universe resolver client (always cached)
- `src/App/Core/RedisCache.php` – optional Redis wrapper (best-effort, fail-safe)

## Configuration Source of Truth

Runtime config is server-only (not in git):

- `/var/www/config.php`

The application is expected to read config via `app_config()` (loaded by bootstrap).

## Cache Layers

### L2: MariaDB (authoritative cache)

- `esi_cache` – ESI response cache
  - expiry contract: `DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND)`
  - payload stored in `payload_json`
- `universe_entities` – universe resolver cache (IDs → name/ticker/icon metadata)

### L1: Redis (optional accelerator)

Redis is an optimization layer only.

Design rules:
- Redis must never be required for correctness.
- Redis failures must degrade gracefully (silent disable / fallback to MariaDB).
- Keys must be namespaced with a prefix (default `portal:`).

Suggested key shapes:
- ESI: `portal:esi:{scope_key}:{cache_key}`

Admin controls:
- `/admin/cache` (right `admin.cache`) shows Redis status and offers a prefix-scoped flush.

## Universe Resolver

Universe Resolver is mandatory for UI output:
- IDs are internal only
- UI displays names
- resolver caches results in `universe_entities` and primes related entities

## Cron

Cron runs expensive work out-of-band:
- scheduled refreshes
- rollups
- pre-priming caches

HTTP pages should read from cache/DB and avoid doing heavy processing on request.
