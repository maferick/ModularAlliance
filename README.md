# ModularAlliance (baseline)

This is a clean baseline for Ubuntu 24.04 + nginx + PHP 8.3-FPM.

## Server layout (recommended)
- Repo/app root: `/var/www/ModularAlliance`
- Web docroot: `/var/www/ModularAlliance/public`
- Server-only config (not in git): `/var/www/config.php`

## First-time bring-up (on server)
```bash
cd /var/www/ModularAlliance
php -v
php bin/migrate.php
```

## Cron (every 5 minutes)
```bash
*/5 * * * * www-data php /var/www/ModularAlliance/bin/cron.php >/dev/null 2>&1
```

## Auth routes
- `/auth/login`
- `/auth/callback`
- `/auth/logout`

This baseline intentionally keeps the core small and contract-driven:
- No global function soup
- `App\` namespacing + autoloading
- Real migration tracking with checksums
