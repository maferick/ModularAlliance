# Universe Resolver

The Universe Resolver translates IDs into names (and optionally icons) and stores results locally.

Design objectives:
- UI never displays raw IDs
- cache-first behavior
- predictable TTL strategy
- auto-priming of related entities

## Supported Entity Types

- `system`
- `constellation`
- `region`
- `type`
- `group`
- `category`
- `station`
- `structure` (token-gated)

## Endpoint Mapping

| Entity | Endpoint | Notes |
|---|---|---|
| system | `/latest/universe/systems/{id}/` | public |
| constellation | `/latest/universe/constellations/{id}/` | public |
| region | `/latest/universe/regions/{id}/` | public |
| type | `/latest/universe/types/{id}/` | public; primes group/category |
| group | `/latest/universe/groups/{id}/` | public; primes category |
| category | `/latest/universe/categories/{id}/` | public |
| station | `/latest/universe/stations/{id}/` | public; includes system_id |
| structure | `/latest/universe/structures/{id}/` | requires scope `esi-universe.read_structures.v1` |

## TTL Strategy

Recommended defaults:
- systems: 7 days
- regions/constellations/types/groups/categories: 30 days
- stations: 30 days
- structures: 1–7 days depending on operational needs

## Auto-Priming Rules

- system → constellation → region
- type → group → category
- station → system (and therefore constellation/region)

## CLI Examples

Resolve Jita (`system` 30000142) and Tritanium (`type` 34):

```bash
php -d opcache.enable_cli=0 -r 'require "core/bootstrap.php"; $u=new \App\Core\Universe(\App\Core\App::boot()->db); echo $u->name("system",30000142), PHP_EOL;'
php -d opcache.enable_cli=0 -r 'require "core/bootstrap.php"; $u=new \App\Core\Universe(\App\Core\App::boot()->db); echo $u->name("type",34), PHP_EOL;'
```

## Failure Modes

- If ESI returns invalid payload or access is denied (structures), the resolver uses a safe fallback name and caches appropriately to avoid repeated calls.


## Notes

Universe Resolver uses MariaDB (`universe_entities`) as its cache of record. Optional Redis is intended for ESI response acceleration and does not replace universe persistence.
