# Contributing to ModularAlliance

Thanks for helping improve ModularAlliance.

This project aims to stay maintainable over the long haul. To keep velocity high and regressions low, please follow the guardrails below.

---

## 1) Ground Rules

- Keep changes modular: prefer `modules/<slug>/...` over core edits.
- Avoid global functions. Use namespaces/classes.
- Database changes **must** be migrations (core or module) and must be tracked by `migration_log`.
- Anything that calls ESI must go through the cache layer (no page-load ESI calls).

---

## 2) Development Workflow

1. Fork the repo (or create a branch if you have push access)
2. Create a feature branch:
   - `feat/<name>` for new features
   - `fix/<name>` for bug fixes
3. Keep commits small and descriptive.
4. Run migrations locally before testing.

---

## 3) Coding Standards (Pragmatic)

- PHP 8.3+
- Strict types where appropriate
- Prefer explicit return types
- Escape output (`htmlspecialchars`) for HTML rendering
- Keep routes thin; put logic in `src/` or a module service class

---

## 4) Database & Migrations

- Core migrations: `core/migrations/*.sql`
- Module migrations: `modules/<slug>/migrations/*.sql`
- File naming: `NNN_description.sql` (e.g., `012_add_user_theme.sql`)
- Migrations should be additive and safe (avoid destructive changes unless coordinated).

---

## 5) Contributor License Agreement (CLA)

By submitting a Pull Request, you agree to the terms in **CLA.md**.

We keep this lightweight: it is intended to confirm you have the rights to contribute the code and that the project may distribute it under the project license.

---

## 6) DCO-Style Sign-off (Required)

All commits must include a sign-off line:

```
Signed-off-by: Your Name <you@example.com>
```

You can add this automatically:

```
git commit -s
```

---

## 7) Pull Request Checklist

- [ ] Code compiles / routes load
- [ ] No secrets committed (`config.php`, tokens, passwords)
- [ ] New DB changes are migrations
- [ ] UI does not expose raw EVE IDs (resolve to names)
- [ ] Cron jobs do heavy lifting; pages read from DB
- [ ] Updated docs/README as needed

---

## 8) Security

If you find a security issue, please open a private report or contact the maintainer before posting publicly.
