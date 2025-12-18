# Contributing to ModularAlliance

This project targets operational reliability first: predictable migrations, stable core contracts, and zero UI ID leakage.

## Development Principles

- **Modular by default:** new features belong in `modules/<slug>/`.
- **Core stays small:** core provides contracts, modules provide functionality.
- **Cache-first:** any ESI access must go through the cache layer (`EsiCache`).
- **No UI IDs:** templates must not print numeric IDs; resolve to names via Universe Resolver.
- **Fail-safe ops:** optional dependencies (Redis) must degrade gracefully.

## Practical Guidelines

- If you change schema, add a migration and keep code compatible with existing installs.
- Prefer HEREDOC (or view templates) for large HTML blocks in PHP to avoid quote/escape bugs.
- When adding admin actions, use explicit rights and confirm prompts for destructive actions.
- Keep Redis usage namespaced (prefix) and never rely on Redis for correctness.

## Pull Request Checklist

- Migrations included (if schema changes)
- `php -l` passes on changed PHP files
- No new raw numeric IDs rendered in views
- Cache behavior documented (if changed)
