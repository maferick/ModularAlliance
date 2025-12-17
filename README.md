# ModularAlliance
*A modular EVE Online portal framework — built for corporations, alliances, and long-term sanity.*

![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4.svg)
![MariaDB](https://img.shields.io/badge/MariaDB-10.11%2B-003545.svg)
![ESI](https://img.shields.io/badge/EVE%20ESI-SSO%20%2B%20Cache-00AEEF.svg)
![Theme](https://img.shields.io/badge/Theme-Dark%20by%20default-111827.svg)

> “There is no endgame — only maintenance.”

---

## 🚀 What is this?

ModularAlliance is a **self-hosted, modular web portal** for **EVE Online corporations and alliances**.
It is designed to be *boring in the best possible way*: predictable, traceable, and maintainable over years.

---

## 🧠 Design Philosophy

- Contracts over convenience
- Modular by default
- No global functions
- ESI is always cached
- Admin can never lock themselves out

If you've ever thought *“this should have been designed properly from day one”* — this project exists for you.

---

## 🧱 Architecture Overview

```
/public        → Web root (index.php only)
/src           → Namespaced application code
/core          → Bootstrap, DB, auth, rights, menu, cron, migrator
/modules       → Feature modules
/bin           → CLI tools
/docs          → Documentation
```

### Core Contracts (Stable)
- core/bootstrap.php
- core/db.php
- core/auth.php
- core/rights.php
- core/menu.php
- core/esi.php
- core/esi_cache.php
- core/cron.php
- core/migrator.php

---

## 🗄️ Database & Migrations

- Core migrations: `core/migrations/*.sql`
- Module migrations: `modules/<slug>/migrations/*.sql`
- Tracking table: `migration_log`

Every migration is checksummed and tracked. No silent schema drift.

---

## 🔐 Authentication & ESI

- EVE Online SSO
- Character, corp, alliance resolution
- Portraits and icons cached
- Tokens stored securely
- IDs never shown in UI (names only)

---

## 🧭 Menus & Rights

Menu areas:
- `left` (main navigation)
- `user_top` (profile, alts, logout)
- `admin_top` (admin tools)

Menus are:
- module-registered
- DB-overridable
- rights-gated
- nestable

Admin override is absolute.

---

## 🕒 Cron Model

Heavy work never runs during page loads.

```
php bin/cron.php
```

Designed to run every 5 minutes via system cron.

---

## 🎨 UI

- Bootstrap 5.3
- Dark mode by default
- Modern control-panel aesthetic
- No JS frameworks

---

## 🧰 Getting Started

### Requirements

- Ubuntu 24.04
- PHP 8.3 + FPM
- Nginx
- MariaDB 10.11+
- EVE Online developer application (SSO)

### 1) Clone the repository

```
git clone https://github.com/maferick/ModularAlliance.git
cd ModularAlliance
```

### 2) Create configuration

Create `/var/www/ModularAlliance/config.php` (not tracked by git):

```php
<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    'database' => 'eve_portal',
    'user' => 'eve_user',
    'password' => 'changeme',
    'charset' => 'utf8mb4',
  ],
  'eve' => [
    'client_id' => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_SECRET',
    'callback_url' => 'https://yourdomain/auth/callback',
    'metadata_url' => 'https://login.eveonline.com/.well-known/oauth-authorization-server',
  ],
];
```

### 3) Run migrations

```
php bin/migrate.php
```

### 4) Configure nginx

Point your document root to:

```
/var/www/ModularAlliance/public
```

Ensure PHP-FPM is enabled.

### 5) Run cron

```
*/5 * * * * www-data php /var/www/ModularAlliance/bin/cron.php
```

### 6) Login

Visit `/auth/login` and authenticate with EVE SSO.
The first user automatically becomes admin.

---

## 🖼️ Screenshots

> Add screenshots to `docs/screenshots/` and reference them here.

- Dashboard (placeholder): `docs/screenshots/dashboard.png`
- Profile / Identity (placeholder): `docs/screenshots/profile.png`
- Admin → ESI Cache (placeholder): `docs/screenshots/admin-cache.png`

Example markdown once screenshots exist:

```md
![Dashboard](docs/screenshots/dashboard.png)
```

---

## 🧩 Modules

Current baseline modules and core features (subject to change as the project stabilizes):

- **auth** — EVE SSO login, token storage, identity bootstrap
- **user** — linked characters (alts) management (WIP)
- **admin** — ESI cache inspection, users/groups/rights, menu editor (incremental)

Planned modules (see Roadmap):
- killboard & killfeed ingestion
- industry / blueprints / jobs
- corp/alliance dashboards
- fleet tooling & pings
- audits / activity / reporting

---

## 🗺️ Roadmap

**Phase 1 — Foundation (Now)**
- [x] Bootstrap + routing
- [x] Tracked migrations (checksums)
- [x] EVE SSO login flow
- [x] ESI cache contract (TTL + scope_key)
- [x] Rights + groups + admin override
- [x] Menu registry/overrides (left, user_top, admin_top)
- [x] Dark UI baseline

**Phase 2 — Identity & Operations**
- [ ] Multi-character (alts) linking UX + constraints
- [ ] Settings: corp/alliance mode + branding
- [ ] Admin: menu editor UX + safety rails
- [ ] Admin: cache tooling (flush by scope, refresh stale)

**Phase 3 — Gameplay Value**
- [ ] Killboard module (cron-driven ingestion + rollups)
- [ ] Industry/Blueprints module
- [ ] Fleet doctrine library + fittings
- [ ] Corp metrics dashboards

---

## ✅ Contributing

Please read **CONTRIBUTING.md** before opening a PR.
We use a lightweight CLA process (see `CLA.md`) and require sign-offs (DCO-style).

---

## ™ CCP / EVE Online Disclaimer

EVE Online and related trademarks are the property of **CCP hf**.
This project is an **independent, fan-made tool** and is **not affiliated with or endorsed by CCP**.
Any EVE Online imagery or data used should comply with CCP’s applicable third‑party / fan site policies.

---

## 📜 License

MIT

---

*From ore to fleets, from fleets to forges — everything circles back to creation.*
