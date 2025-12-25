# Character Link Hub (charlink)

The Character Link Hub lets pilots link a character once and enable access for multiple internal features using one EVE SSO flow. It also provides audit and admin utilities for reviewing enabled targets.

## Configuration
Add optional settings in `/var/www/config.php` under the `charlink` namespace:

```php
return [
    // ...
    'charlink' => [
        // Optional: extend or override link targets
        'targets' => [
            'wallet' => [
                'name' => 'Wallet Access',
                'description' => 'Character wallet balances and transactions.',
                'scopes' => ['esi-wallet.read_character_wallet.v1'],
            ],
        ],
    ],
];
```

## Database migration
Run the migrations to create module tables:

```bash
php bin/migrate.php
```

Relevant migration: `core/migrations/022_charlink_targets.sql`.

## Cron
No cron jobs are required for Charlink.

## Rights
The module registers these rights:

- `charlink.view` — access the Character Link Hub.
- `charlink.audit` — view audit dashboards.
- `charlink.admin` — manage link targets and existing links.

Assign rights via **Admin → Rights & Groups**.

## Routes
- `/charlink` — Character Link Hub (link/update character scopes)
- `/charlink/audit` — Audit view
- `/admin/charlink/targets` — Target registry (admin)
- `/user/alts` — legacy linked-character management

## Required scopes by target
Targets are configured via `module_charlink_targets` and the `charlink.targets` config override. Defaults include:

- Wallet Access — `esi-wallet.read_character_wallet.v1`
- Mining Ledger — `esi-industry.read_character_mining.v1`
- Assets — `esi-assets.read_assets.v1`
- Contracts — `esi-contracts.read_character_contracts.v1`
- Notifications — `esi-characters.read_notifications.v1`
- Structures — `esi-universe.read_structures.v1`

## Notes
- The hub requests the union of selected target scopes in a single SSO login.
- Tokens are stored in `eve_token_buckets` (bucket `default`); per-character enabled targets are stored in `module_charlink_links`.
