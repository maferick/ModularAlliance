<?php
declare(strict_types=1);

namespace App\Core;

final class Settings
{
    private Db $db;
    private array $cache = [];

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Reads a setting value by key.
     * Backed by the existing schema: settings(key, value, updated_at)
     */
    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $row = $this->db->one("SELECT `value` FROM settings WHERE `key`=? LIMIT 1", [$key]);
        $val = $row ? (string)$row['value'] : $default;
        $this->cache[$key] = $val;
        return $val;
    }

    /**
     * Writes a setting value by key (idempotent).
     * Uses INSERT..ON DUPLICATE KEY to preserve your existing PK on `key`.
     */
    public function set(string $key, string $value): void
    {
        $this->db->run(
            "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            [$key, $value]
        );
        $this->cache[$key] = $value;
    }
}
