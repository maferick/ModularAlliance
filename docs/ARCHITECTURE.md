# Architecture

ModularAlliance is built on a simple operating model:

**Front Controller → Router → Module Routes → Core Services → DB/Cache → Views**

The design goal is operational stability at scale: predictable behavior with many modules and many users.

## Core Contracts

Core contract names are stable and should not be renamed casually.

- `core/bootstrap.php` – loads config, starts session, autoload, core wiring
- `src/App/Core/Db.php` – PDO + helpers
- `src/App/Core/Migrator.php` – migration tracking + checksums
- `src/App/Core/Menu.php` – menu registry + override merge + tree rendering
- `src/App/Core/EsiCache.php` – cached ESI fetch contract
- `src/App/Core/EsiClient.php` – HTTP client for ESI (status-aware)
- `src/App/Core/Universe.php` – resolver for names/icons from IDs

## Request Lifecycle

1. Nginx routes to `public/index.php`
2. Bootstrap initializes:
   - config
   - session
   - autoload
   - DB
3. Router dispatches by path
4. Module handler returns HTML response
5. Layout renders navigation based on:
   - left menu tree
   - user menu tree
   - admin menu tree (only when authorized)

## Modules

Each module lives under:

- `modules/<slug>/module.php`

Modules must register:
- routes
- menu items
- rights (if any)
- cron jobs (if needed)

### Module Rules

- No global function declarations
- No direct schema patches
- Use core services; do not duplicate DB helpers or ESI access

## Data and Caching

### ESI Cache Contract

All ESI requests should flow through the cache layer:
- stable cache keys
- TTL enforced
- payload stored in DB for traceability

### Universe Resolver

Universe Resolver is mandatory for UI output:
- IDs are internal only
- UI displays names
- resolver caches results in `universe_entities` and primes related entities

## Cron

Cron runs expensive work out-of-band:
- scheduled refreshes
- rollups
- pre-priming caches

HTTP pages should read from DB and avoid doing heavy processing on request.
