# Corp Tools (corptools)

Corp Tools provides corp dashboards inspired by AllianceAuth CorpTools: invoices, moon tracking, industry structures, and notifications.

## Configuration
Add settings in `/var/www/config.php` under the `corptools` namespace:

```php
return [
    // ...
    'corptools' => [
        // Corporations to track (optional). If empty, the logged-in character's corp is used.
        'corp_ids' => [123456789],

        // Wallet divisions to pull journal entries from.
        'wallet_divisions' => [1, 2, 3],
    ],
];
```

## Database migration
Run migrations to create module tables:

```bash
php bin/migrate.php
```

Relevant migration: `core/migrations/023_corptools_tables.sql`.

## Cron
Corp Tools uses the platform cron runner (`bin/cron.php`). Add a cron entry similar to:

```bash
* * * * * php /var/www/bin/cron.php
```

Registered job:

- `corptools.invoice_sync` — every 15 minutes, caches wallet journal entries for configured corps.

## Rights
- `corptools.view` — overview dashboard
- `corptools.director` — invoices, moons, industry, notifications
- `corptools.admin` — settings, notification rules

## Required scopes
These are the scopes used by the dashboards:

- Invoices (wallet journals): `esi-wallet.read_corporation_wallets.v1`
- Industry structures: `esi-corporations.read_structures.v1`
- Notifications: `esi-characters.read_notifications.v1`

If a scope is missing, the UI will prompt to link via the Character Link Hub.

## Notes
- Wallet journal entries are stored in `module_corptools_invoice_payments` for reporting.
- Moon tracking supports manual entry (with optional future ESI enrichment).
- Notification webhook dispatch is scaffolded in settings but not yet implemented.
