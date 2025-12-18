Extract into /var/www/ModularAlliance (repo root), overwriting files.
Then run: php -l src/App/Core/App.php && systemctl restart php8.3-fpm (or php8.2-fpm).
Adds Redis status + flush to /admin/cache and keeps existing DB cache actions.
