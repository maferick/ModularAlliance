cat > /var/www/ModularAlliance/docs/ai_context.md <<'MD'
# ModularAlliance – Continuation Capsule

## Current Status
- Site is live on: killsineve.online
- Ubuntu 24.04, PHP 8.3-FPM, nginx
- App boot/routing is stable; `/` and `/me` work
- EVE SSO v2 is working with metadata discovery + PKCE
- Tokens + JWT payload are persisted; ESI identity warmup is cached in DB

## Non-negotiables
- **NO numeric IDs in the UI**. IDs are internal-only. Everything displayed must be resolved to names/tickers/icons via cached resolvers.
- Modules must not declare global functions; use closures/classes.
- All external fetches (ESI etc.) must be cached in DB with TTL.
- Migrations are tracked in `migration_log` (module_slug, file_path, checksum).
- Admin group must have a hard override and must never be lockable via menu rights (to implement).

## Data Layer
- MariaDB 10.11.13, DB currently used: `eve_portal`
- Key tables: `esi_cache`, `eve_users`, `eve_tokens`, `oauth_provider_cache`, `sso_*`, plus legacy `users/groups/rights/menu_items/...`

## ESI Cache (working)
Cached scopes seen:
- `char:<id>`, `corp:<id>`, `alliance:<id>`

Cached endpoints:
- characters/{id}, portrait
- corporations/{id}, icons
- alliances/{id}, icons

TTLs:
- profile ~3600s
- images ~86400s

## UI / Menu
- Homepage uses Layout: left menu + Admin dropdown (top).
- Menu is registry+override, supports nesting; rights filter currently placeholder.
- Menu wishes:
  - Left menu = dashboard + non-admin modules user has rights for
  - Admin = top button only (no duplicates on left)
  - Deep nesting + ordering + rights gating; admin-proof

## Key Code Files
- `core/bootstrap.php`
- `src/App/Core/App.php`
- `src/App/Core/Db.php` (includes `all()` helper)
- `src/App/Core/Migrator.php`
- `src/App/Core/Menu.php`
- `src/App/Core/Layout.php`
- `src/App/Core/Universe.php` (name/icon resolver from cache)
- `src/App/Core/EveSso.php`
- `modules/auth/module.php`

## Next Implementation Targets
1. Rights system + admin override
2. Core facade contract files in `/core/*.php`
3. Universe resolver expansion for ship/module/system/type IDs → names/icons (and never show IDs)
4. Admin pages: cache console, menu editor, users/groups/rights
5. Cron registry + runner
6. JWT signature validation via JWKS
MD

