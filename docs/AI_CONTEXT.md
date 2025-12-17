# AI Context (Continuity File)

This file exists to help resume development safely in a new AI session.

## Current Platform State

- Ubuntu 24.04, PHP 8.3-FPM, Nginx, MariaDB 10.11
- Project is a modular portal with:
  - EVE SSO login + token storage
  - Rights/groups with Admin override
  - Menu registry + DB overrides, multiple areas (left/user_top/admin_top)
  - ESI cache contract in DB (`esi_cache`)
  - Universe Resolver in DB (`universe_entities`)

## Non-Negotiable Rules

- No raw IDs in UI. Resolve names through `Universe`.
- No global functions in modules.
- Migrations only; no manual schema patching.
- ESI access goes through cache.
- Cron does heavy work; HTTP pages remain lightweight.

## Known DB Tables (minimum)

- users, tokens (core auth)
- eve_users, eve_tokens (EVE SSO)
- groups, rights, group_rights, user_groups
- menu_registry, menu_overrides
- esi_cache
- universe_entities
- migration_log
- cron_jobs

## Universe Resolver Entity Types

- system, constellation, region
- type, group, category
- station, structure

## UI Direction

- Dark theme default (Bootstrap 5.3 + app.css overrides)
- Top bar: User menu always, Admin menu only if admin
- Left sidebar: non-admin modules only (admin functionality lives under Admin menu)

## Operational Notes

- Avoid breaking schema contracts; update migrations and code together.
- If adding new universe types later (factions, NPC corps, solar systems extras), extend enum and endpoint mapping in a migration.
