# ModularAlliance

A modular, cache-first EVE Online portal built for long-term maintainability: **SSO → Users/Rights → Menu → ESI Cache → Cron → Modules**.

This repo is intentionally designed as a platform, not a one-off website. Every module plugs into stable core contracts, migrations are tracked, and UI never leaks raw IDs—**everything resolves to names**.

## Stack Snapshot

**PHP 8.3** · **Nginx** · **MariaDB 10.11** · **EVE SSO** · **ESI Cache** · **Dark Mode (Bootstrap 5.3)**

## Key Capabilities

- **EVE SSO login** with token storage (refresh-capable)
- **Rights + Groups** with a hard Admin override (cannot lock yourself out)
- **Menu system** with:
  - registry defaults (module-owned)
  - override layer in DB (admin-owned)
  - multiple areas (left / user_top / admin_top)
- **ESI caching contract** for all remote calls (TTL-based, DB-backed)
- **Universe Resolver** (name-first)
  - systems, constellations, regions
  - types, groups, categories (fittings/items)
  - stations
  - structures (token-gated, graceful fallback)
- **Cron runner** for heavy lifting (pages read rollups; cron does work)

## Non-Negotiable Project Rules

- **No raw IDs in the UI.** IDs exist only as internal keys; pages must display names via the Universe Resolver.
- **No global functions in modules.** Modules register routes/menus/rights via core APIs only.
- **Migrations are the source of truth.** No “schema.php patches”; everything is tracked with checksums.
- **Cache-first for ESI.** ESI requests must go through the cache layer to prevent rate-limit chaos.
- **Cron does the heavy work.** HTTP requests are not allowed to do expensive processing on request.

## Repository Layout

- `public/` – front controller (`index.php`)
- `core/` – bootstrap + migrations
- `src/` – namespaced application code
- `modules/` – modular features (`/modules/<slug>/module.php`)
- `bin/` – CLI tools (migrate, cron)
- `docs/` – technical documentation and AI continuity files

## Getting Started

### 1) Requirements

- Ubuntu 24.04+
- PHP 8.3-FPM + extensions commonly required for PDO/cURL/JSON
- Nginx
- MariaDB 10.11+
- A database user with permissions to create/alter tables in the app DB

### 2) Install

1. Check out the repo into your desired path (example):
   - `/var/www/ModularAlliance`
2. Create writable directories:
   - `storage/` and `tmp/` (owned by your web user, e.g. `www-data`)
3. Create your `config.php` **outside** the docroot (not committed to git).
4. Run migrations:
   - `php bin/migrate.php`

### 3) Configure Nginx

Use the example in `docs/nginx-site.conf.example` (or your own). The docroot is:

- `root /var/www/ModularAlliance/public;`

### 4) First Login (SSO)

- Log in via the SSO route in the UI.
- Confirm that:
  - user exists in `eve_users`
  - token is stored in `eve_tokens`
  - `esi_cache` starts filling (character/corp/alliance + icons)

## Screenshots

> Screenshots are intentionally deferred until the UI is finalized.  
> Add images to `docs/screenshots/` and reference them using relative paths.

Planned:
- Dashboard (dark theme)
- Profile (character/corp/alliance panels)
- Admin menu editor
- ESI cache admin view
- Users & Groups admin view

## Roadmap

See `docs/ROADMAP.md`.

## Contributing

See `CONTRIBUTING.md`.

## Legal / IP

EVE Online and all related trademarks, logos, and intellectual property are the property of CCP hf.  
This project is community-made and is not endorsed by CCP hf.
