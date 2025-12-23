# Corp Tools (corptools)

Corp Tools provides corp dashboards inspired by AllianceAuth CorpTools: invoices, moon tracking, industry, audit snapshots, and pinger/webhook ingest.

## Installation
1. Apply migrations:

```bash
php bin/migrate.php
```

Relevant migrations:
- `core/migrations/023_corptools_tables.sql`
- `core/migrations/025_corptools_core_ecosystem.sql`

2. Configure your corporation or alliance in **Admin → Settings** (`site.identity.type` + `site.identity.id`).

3. Configure CorpTools in **Admin → Corp Tools**.

## Cron / Jobs
Corp Tools uses the platform cron runner (`bin/cron.php`). Add a cron entry similar to:

```bash
* * * * * php /var/www/bin/cron.php
```

Registered jobs:
- `corptools.invoice_sync` — pulls wallet journals for invoice tracking
- `corptools.audit_refresh` — refreshes character audits and summaries
- `corptools.corp_audit_refresh` — refreshes corp-level audit dashboards
- `corptools.cleanup` — retention cleanup for audit/pinger data

## Settings Overview
CorpTools settings are stored in `module_corptools_settings` and configured via **Admin → Corp Tools**.

- **General**: holding wallet divisions, retention window
- **Audit Scopes**: toggle per-character collectors
- **Corp Audit**: toggle corp-level collectors
- **Invoices**: wallet divisions to watch
- **Moons**: default tax rate
- **Indy Dash**: enable/disable industry dashboards
- **Pinger**: webhook URL + optional shared secret
- **Filters**: default filter baselines for member queries

## Audit Collectors (Scopes)
Each audit domain is a discrete collector with its own scopes. Enable the collectors you need.

Common scopes:
- Assets: `esi-assets.read_assets.v1`
- Clones: `esi-clones.read_clones.v1`
- Implants: `esi-clones.read_implants.v1`
- Location: `esi-location.read_location.v1`
- Ship: `esi-location.read_ship_type.v1`
- Skills: `esi-skills.read_skills.v1`
- Wallet: `esi-wallet.read_character_wallet.v1`
- Roles: `esi-characters.read_corporation_roles.v1`

## Corp Audit Scopes
Corp audit collectors use corp-level tokens (director permissions):
- Wallets: `esi-wallet.read_corporation_wallets.v1`
- Structures: `esi-corporations.read_structures.v1`
- Corp Assets: `esi-assets.read_corporation_assets.v1`

## Health Check
A basic health check endpoint is available at:

```
GET /corptools/health
```

## Troubleshooting
- If dashboards show empty data, confirm the cron job is running and scopes are linked in the Character Link Hub.
- If corp data is empty, ensure a director token with the required corp scopes is linked.
- If pinger ingestion is rejected, verify the shared secret header `X-CorpTools-Token`.
