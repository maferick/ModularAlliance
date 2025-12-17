# Roadmap

This roadmap is intentionally operational and milestone-driven.

## Phase 1: Platform Baseline (Done)

- SSO login
- Token storage
- ESI cache contract
- Rights + groups
- Menu registry + overrides
- Dark theme baseline layout

## Phase 2: Universe Resolver Expansion (In Progress)

Done:
- systems/constellations/regions
- types/groups/categories
- stations
- structures (token-aware)

Next:
- icon pipelines for `type`
- bulk priming via cron

## Phase 3: Killboard Enrichment

- Killmail ingestion (source TBD)
- Resolve:
  - ship type
  - fitted items
  - solar system
  - station/structure locations
- DB rollups for dashboards

## Phase 4: Fittings & Doctrine

- Fit browser (read-only first)
- Import (EFT/DNA) as a later enhancement
- Resolve and display all item names/icons

## Phase 5: Admin Tooling

- Menu editor improvements (drag/drop; rights gating)
- ESI cache admin tools:
  - scope flush
  - stale refresh
- Cron admin tools:
  - run now
  - last status/errors

## Phase 6: Corp/Alliance Mode

- Auto-detect from first login
- Settings override
- Alliance-wide dashboards and modules
