<?php
declare(strict_types=1);

namespace App\Corptools;

use App\Core\Db;

final class Settings
{
    public function __construct(private Db $db) {}

    public static function defaults(): array
    {
        return [
            'general' => [
                'corp_ids' => [],
                'corp_context_id' => 0,
                'allow_context_switch' => false,
                'holding_wallet_divisions' => [1],
                'holding_wallet_label' => 'Holding Wallet',
                'retention_days' => 30,
            ],
            'audit_scopes' => [
                'assets' => true,
                'clones' => true,
                'implants' => false,
                'contacts' => false,
                'contracts' => false,
                'corp_history' => true,
                'location' => true,
                'ship' => true,
                'loyalty' => false,
                'markets' => false,
                'mining' => false,
                'notifications' => false,
                'roles' => true,
                'skills' => true,
                'standings' => false,
                'wallet' => true,
                'activity' => false,
            ],
            'corp_audit' => [
                'wallets' => true,
                'structures' => true,
                'assets' => false,
                'sov' => false,
                'jump_bridges' => false,
                'fuel' => true,
                'metenox' => false,
            ],
            'invoices' => [
                'enabled' => true,
                'wallet_divisions' => [1],
            ],
            'moons' => [
                'default_tax_rate' => 0,
            ],
            'indy' => [
                'enabled' => true,
            ],
            'pinger' => [
                'webhook_url' => '',
                'shared_secret' => '',
                'dedupe_seconds' => 900,
            ],
            'filters' => [
                'asset_value_min' => 0,
                'audit_loaded_only' => false,
            ],
        ];
    }

    public function get(): array
    {
        $row = $this->db->one(
            "SELECT settings_json FROM module_corptools_settings WHERE scope_type='global' LIMIT 1"
        );
        $settings = [];
        if ($row) {
            $settings = json_decode((string)($row['settings_json'] ?? '[]'), true);
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        return $this->mergeRecursive(self::defaults(), $settings);
    }

    public function save(array $settings): void
    {
        $payload = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->db->run(
            "INSERT INTO module_corptools_settings (scope_type, scope_id, settings_json, created_at, updated_at)
             VALUES ('global', 0, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=NOW()",
            [$payload]
        );
    }

    public function updateSection(string $section, array $values): array
    {
        $settings = $this->get();
        $settings[$section] = $this->mergeRecursive($settings[$section] ?? [], $values);
        $this->save($settings);
        return $settings;
    }

    private function mergeRecursive(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
