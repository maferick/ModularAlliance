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

## CharLink + Corp Tools Setup

### Migration plan

1. Deploy code updates.
2. Apply migrations (adds `module_charlink_states` and keeps schema additive):
   ```bash
   php bin/migrate.php
   ```
3. Configure the site identity in **Admin → Settings** (corp ID drives Corp Tools context).
4. Relink characters as needed in **Linked Characters** if you want to grant additional scopes.

### Required scopes (least privilege)

**CharLink**
- Base login: `publicData`
- Optional targets (only if you enable them in the Character Link Hub):
  - Wallet: `esi-wallet.read_character_wallet.v1`
  - Mining: `esi-industry.read_character_mining.v1`
  - Assets: `esi-assets.read_assets.v1`
  - Contracts: `esi-contracts.read_character_contracts.v1`
  - Notifications: `esi-characters.read_notifications.v1`
  - Structures: `esi-universe.read_structures.v1`

**Corp Tools**
- Corp wallets: `esi-wallet.read_corporation_wallets.v1`
- Corp structures: `esi-corporations.read_structures.v1`
- Members: `esi-corporations.read_corporation_membership.v1`
- Roles: `esi-corporations.read_corporation_roles.v1`
- Titles: `esi-corporations.read_titles.v1`
- Character notifications (dashboard/notifications): `esi-characters.read_notifications.v1`

### Troubleshooting

- **/user/alts errors**: verify migrations ran; the linking flow now uses `module_charlink_states` instead of the removed `character_link_tokens` table.
- **Corp Tools shows the wrong corp**: ensure **Admin → Settings** has the correct corporation ID; corp context no longer comes from the last SSO character.
- **Missing scopes**: the UI shows missing scopes per feature and links you to the Character Link Hub to add them.
- **Link token failures**: tokens expire after one hour; regenerate in **Linked Characters** if needed.

### Root-cause summary (why this broke)

- The `character_link_tokens` table was dropped in migration `021_drop_charlink_tokens.sql`, but the CharLink UI and SSO flow still read/write that table.
- Corp Tools used the last logged-in character’s corp as the default context, overriding site settings.

### Preventing regression

- CharLink now stores link tokens in `module_charlink_states` (new migration) and blocks linking from creating unintended user records.
- Corp Tools always derives corp context from **Admin → Settings** (`site.identity.*`) and searches for a matching token for that corp.

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
