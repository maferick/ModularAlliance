# ModularAlliance

A modular, cache-first EVE Online portal built for long-term maintainability: **SSO → Users/Rights → Menu → ESI Cache → Cron → Modules**.

This repo is intentionally designed as a platform, not a one-off site:
- Modules are filesystem plugins under `modules/<slug>/`
- Core stays small and stable (contracts + primitives)
- UI never leaks raw IDs — everything is resolved to names/tickers/icons via cached resolvers

## Stack Snapshot

**PHP 8.3** · **Nginx** · **MariaDB 10.11** · **(Optional) Redis** · **EVE SSO** · **ESI Cache** · **Bootstrap 5.3 (dark)**

## Key Capabilities

- **EVE SSO login** with token storage (refresh-capable)
- **Rights + Groups** with a hard Admin override (cannot lock yourself out)
- **Menu system**:
  - module-owned default registry entries
  - DB overrides (order, visibility, right gating)
  - multiple areas: `left`, `user_top`, `admin_top`
- **ESI cache** in MariaDB (`esi_cache`) using:
  - `fetched_at` + `ttl_seconds` as the expiry contract
  - payload stored in `payload_json` (longtext)
- **Universe Resolver** cache in MariaDB (`universe_entities`) to translate IDs → names/icons

## Configuration

The live site reads a server-only config file (not in git):

- `/var/www/config.php`

Key sections commonly used:

- `db.*` – MariaDB connection
- `eve_sso.*` – client id/secret, callback URL, scopes
- `redis.*` – optional L1 cache (see below)

## Caching Model

### L2: MariaDB (authoritative cache)
MariaDB is the durable cache layer. All ESI cache entries live in `esi_cache` and expire via:

```
expiry = DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND)
```

### L1: Redis (optional accelerator)
Redis can be enabled as an **L1** (cache-aside) layer in front of MariaDB:
- If Redis is available, reads check Redis first, then fall back to MariaDB.
- Writes always persist to MariaDB; Redis is populated/updated as a best-effort.
- If Redis is down, the site continues to run on MariaDB-only.

Namespace safety:
- All keys use a prefix (default: `portal:`)
- ESI keys are stored under `portal:esi:*`

## Admin Operations reminder

- **Admin → Cache** (`/admin/cache`, right `admin.cache`):
  - remove expired ESI rows (based on `fetched_at + ttl_seconds`)
  - purge ESI cache / Universe cache / all caches
  - show Redis status and flush Redis prefix namespace

## Roadmap

See `docs/ROADMAP.md`.

## Contributing

See `CONTRIBUTING.md`.

## Legal / IP

EVE Online and all related trademarks, logos, and intellectual property are the property of CCP hf.  
This project is community-made and is not endorsed by CCP hf.
