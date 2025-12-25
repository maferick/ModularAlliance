<?php
declare(strict_types=1);

namespace App\Fittings;

use App\Core\Db;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Universe;

final class TypeResolver
{
    public function __construct(
        private readonly Db $db,
        private readonly ?EsiCache $cache = null,
        private readonly ?EsiClient $client = null
    ) {}

    public function resolveTypeId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        $row = $this->db->one(
            "SELECT type_id FROM module_fittings_type_names WHERE original_name=? LIMIT 1",
            [$name]
        );
        if ($row && isset($row['type_id']) && (int)$row['type_id'] > 0) {
            return (int)$row['type_id'];
        }

        $typeId = $this->searchTypeId($name);
        if ($typeId) {
            $this->db->run(
                "INSERT INTO module_fittings_type_names\n"
                . " (type_id, original_name, current_name, last_seen_at, created_at, updated_at)\n"
                . " VALUES (?, ?, ?, NOW(), NOW(), NOW())\n"
                . " ON DUPLICATE KEY UPDATE\n"
                . " original_name=VALUES(original_name), last_seen_at=NOW(), updated_at=NOW()",
                [$typeId, $name, $name]
            );
        } else {
            $this->db->run(
                "INSERT IGNORE INTO module_fittings_type_names\n"
                . " (type_id, original_name, current_name, last_seen_at, created_at, updated_at)\n"
                . " VALUES (NULL, ?, ?, NOW(), NOW(), NOW())",
                [$name, $name]
            );
        }

        return $typeId;
    }

    public function refreshTypeName(int $typeId): ?string
    {
        if ($typeId <= 0) return null;
        $universe = new Universe($this->db);
        $entity = $universe->entity('type', $typeId);
        $name = (string)($entity['name'] ?? '');
        if ($name === '') return null;

        $row = $this->db->one(
            "SELECT original_name, current_name FROM module_fittings_type_names WHERE type_id=? LIMIT 1",
            [$typeId]
        );
        $original = (string)($row['original_name'] ?? $name);
        $current = (string)($row['current_name'] ?? $name);
        $renamedAt = null;
        if ($current !== '' && $current !== $name) {
            $renamedAt = date('Y-m-d H:i:s');
        }

        $this->db->run(
            "INSERT INTO module_fittings_type_names\n"
            . " (type_id, original_name, current_name, last_seen_at, renamed_at, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, NOW(), ?, NOW(), NOW())\n"
            . " ON DUPLICATE KEY UPDATE\n"
            . " current_name=VALUES(current_name), last_seen_at=NOW(),\n"
            . " renamed_at=IF(VALUES(current_name) <> current_name, NOW(), renamed_at),\n"
            . " updated_at=NOW()",
            [$typeId, $original, $name, $renamedAt]
        );

        return $name;
    }

    private function searchTypeId(string $name): ?int
    {
        $client = $this->client ?? new EsiClient(new HttpClient(), 'https://esi.evetech.net');
        $cache = $this->cache ?? new EsiCache($this->db, $client);
        $key = "fittings:search:type:" . strtolower($name);
        $path = '/latest/search/';
        $urlKey = "GET {$path}?categories=inventory_type&search=" . urlencode($name) . "&strict=true";

        $payload = $cache->getCached(
            $key,
            $urlKey,
            86400,
            fn() => $client->get($path, null, [
                'categories' => 'inventory_type',
                'search' => $name,
                'strict' => 'true',
            ])
        );

        if (!is_array($payload)) return null;
        $ids = $payload['inventory_type'] ?? [];
        if (!is_array($ids) || empty($ids)) return null;
        $id = (int)$ids[0];
        return $id > 0 ? $id : null;
    }
}
