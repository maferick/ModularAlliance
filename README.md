# ModularAlliance
*A modular EVE Online portal framework — built for corporations, alliances, and long-term sanity.*

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
- left (main navigation)
- user_top (profile, alts, logout)
- admin_top (admin tools)

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
- Dark mode default
- Modern control-panel aesthetic
- No JS frameworks

---

## 🟢 Status

Core foundation is complete:
- Bootstrap
- Migrations
- EVE SSO
- ESI cache
- Rights
- Menus
- Dark UI

---

## 🧑‍🚀 Intended Audience

- Corp / alliance leadership
- Developers tired of spaghetti portals
- Anyone planning to maintain a tool long-term

---

## 🧰 Getting Started

### Requirements

- Ubuntu 24.04
- PHP 8.3 + FPM
- Nginx
- MariaDB 10.11+
- EVE Online developer application (SSO)

### 1. Clone the repository

```
git clone https://github.com/maferick/ModularAlliance.git
cd ModularAlliance
```

### 2. Create configuration

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

### 3. Install database schema

```
php bin/migrate.php
```

### 4. Configure nginx

Point your document root to:

```
/var/www/ModularAlliance/public
```

Ensure PHP-FPM is enabled.

### 5. Run cron

```
*/5 * * * * www-data php /var/www/ModularAlliance/bin/cron.php
```

### 6. Login

Visit `/auth/login` and authenticate with EVE SSO.
The first user automatically becomes admin.

---

## 📜 License

MIT

---

*From ore to fleets, from fleets to forges — everything circles back to creation.*
