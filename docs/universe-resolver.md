# Universe Resolver – Fittings + Stations + Structures

This update extends the canonical `Universe` resolver so **no IDs ever leak into UI**.

## Supported entity types
- `system`, `constellation`, `region`
- `type`, `group`, `category` (fittings / items)
- `station` (NPC stations)
- `structure` (Upwell structures, token-gated)

## Install
Copy files into your repo:
- `src/App/Core/Universe.php`
- `src/App/Core/EsiClient.php`
- `src/App/Core/EsiCache.php`

Apply migration:
- `core/migrations/010_universe_station_structure.sql`

Then run:
```bash
php bin/migrate.php
```

## Quick tests
```bash
php -d opcache.enable_cli=0 -r 'require "core/bootstrap.php"; $u=new \App\Core\Universe(\App\Core\App::boot()->db); echo $u->name("system",30000142), PHP_EOL; echo $u->name("type",34), PHP_EOL;'
```
