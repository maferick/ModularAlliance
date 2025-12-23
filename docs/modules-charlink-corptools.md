# Enabling Charlink and Corp Tools

This guide describes how to enable the Charlink and Corp Tools modules in ModularAlliance.

## Enable modules
Modules are filesystem plugins under `modules/<slug>/`. To enable them:

1. Ensure the module directories exist:
   - `modules/charlink/`
   - `modules/corptools/`
2. Confirm they are not listed in the disabled plugins setting:
   - **Admin → Settings → plugins.disabled**

## Configuration
Update `/var/www/config.php` with module configuration sections:

```php
return [
    // ...
    'charlink' => [
        // Optional: customize link targets
        'targets' => [
            'wallet' => [
                'name' => 'Wallet Access',
                'description' => 'Character wallet balances and transactions.',
                'scopes' => ['esi-wallet.read_character_wallet.v1'],
            ],
        ],
    ],
    'corptools' => [
        'corp_ids' => [123456789],
        'wallet_divisions' => [1, 2, 3],
    ],
];
```

## Required EVE SSO scopes
Configure your EVE SSO app with the scopes needed by the features you enable:

### Charlink targets (defaults)
- Wallet Access — `esi-wallet.read_character_wallet.v1`
- Mining Ledger — `esi-industry.read_character_mining.v1`
- Assets — `esi-assets.read_assets.v1`
- Contracts — `esi-contracts.read_character_contracts.v1`
- Notifications — `esi-characters.read_notifications.v1`
- Structures — `esi-universe.read_structures.v1`

### Corp Tools
- Invoices (wallet journals) — `esi-wallet.read_corporation_wallets.v1`
- Industry structures — `esi-corporations.read_structures.v1`
- Notifications — `esi-characters.read_notifications.v1`

## Database migrations
Run migrations once after updating the codebase:

```bash
php bin/migrate.php
```

## Cron
Enable the platform cron runner:

```bash
* * * * * php /var/www/bin/cron.php
```

This ensures `corptools.invoice_sync` runs on schedule.
