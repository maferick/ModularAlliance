# Contributing to ModularAlliance

This project targets operational reliability first: predictable migrations, stable core contracts, and zero UI ID leakage.

## Development Principles

- **Modular by default:** new features belong in `modules/<slug>/`.
- **Core stays small:** core provides contracts, modules provide functionality.
- **Cache-first:** any ESI access must go through the cache layer.
- **Cron-first for heavy tasks:** never do expensive work during HTTP requests.
- **No raw IDs in UI:** resolve names via Universe Resolver.

## Code Standards

- PHP 8.3
- Strict types where practical
- Namespaces under `App\\...`
- Avoid side-effect includes in modules; register through `module.php`

## Migrations Policy (Mandatory)

- Core migrations: `core/migrations/*.sql`
- Module migrations: `modules/<slug>/migrations/*.sql`
- Migrations must be **deterministic**:
  - no interactive prompts
  - no environment-dependent logic
- Never “hotfix” schema with manual SQL outside migrations.

## Pull Request Checklist

- [ ] Migration added if schema changes
- [ ] No new global functions
- [ ] UI surfaces names, not IDs
- [ ] ESI calls use cache contract
- [ ] Docs updated if behavior changed

## Contributor License / Rights (CLA-style Notice)

By submitting a contribution (code, documentation, design assets) you agree that:

1. You have the right to submit the work.
2. You grant the project maintainers a perpetual, worldwide, non-exclusive license to use, modify, and redistribute your contribution as part of the project.
3. You understand your contribution may be redistributed under the project’s license terms.

If your employer has IP policies, ensure you are authorized before contributing.
