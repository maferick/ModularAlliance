<?php
declare(strict_types=1);

namespace App\Core;

final class Universe
{
    public function __construct(private Db $db) {}

    public function name(string $type, int $id): string
    {
        $e = $this->entity($type, $id);
        return $e['name'] ?? 'Unknown';
    }

    public function icon(string $type, int $id, int $size = 64): ?string
    {
        $e = $this->entity($type, $id);
        if (empty($e['icon_json'])) return null;
        $icons = json_decode((string)$e['icon_json'], true);
        return is_array($icons) ? ($icons["px{$size}x{$size}"] ?? null) : null;
    }

    public function entity(string $type, int $id): array
    {
        $row = $this->db->one(
            "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
            [$type, $id]
        );

        if ($row && !$this->isStale($row)) {
            return $row;
        }

        return $this->refresh($type, $id);
    }

    private function isStale(array $row): bool
    {
        $fetched = strtotime((string)($row['fetched_at'] ?? '')) ?: 0;
        $ttl = (int)($row['ttl_seconds'] ?? 0);
        return $fetched <= 0 || (time() > ($fetched + max(60, $ttl)));
    }

    private function refresh(string $type, int $id): array
    {
        [$path, $ttl] = $this->endpointFor($type, $id);
        if (!$path) {
            return $this->fallback($type, $id);
        }

        $client = new EsiClient(new HttpClient(), 'https://esi.evetech.net');
        $cache  = new EsiCache($this->db, $client);

        if ($type === 'structure') {
            $token = $this->bestEffortAccessToken();
            if (!$token) {
                return $this->fallback($type, $id);
            }

            $payload = $cache->getCachedAuth(
                "universe:$type:$id",
                $path,
                $ttl,
                $token,
                [403, 404]
            );

            if (!is_array($payload) || !isset($payload['name'])) {
                return $this->fallback($type, $id);
            }

            if (!empty($payload['solar_system_id'])) {
                $this->prime('system', (int)$payload['solar_system_id']);
            }

            $this->upsertEntity($type, $id, (string)$payload['name'], $payload, $ttl);

            return $this->db->one(
                "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
                [$type, $id]
            ) ?? [];
        }

        $payload = $cache->getCached(
            "universe:$type:$id",
            $path,
            $ttl
        );

        if (!is_array($payload) || !isset($payload['name'])) {
            throw new \RuntimeException("Universe resolver: invalid ESI payload for {$type} {$id}");
        }

        if ($type === 'system' && !empty($payload['constellation_id'])) {
            $this->prime('constellation', (int)$payload['constellation_id']);
        }
        if ($type === 'constellation' && !empty($payload['region_id'])) {
            $this->prime('region', (int)$payload['region_id']);
        }

        if ($type === 'type' && !empty($payload['group_id'])) {
            $this->prime('group', (int)$payload['group_id']);
        }
        if ($type === 'group' && !empty($payload['category_id'])) {
            $this->prime('category', (int)$payload['category_id']);
        }

        if ($type === 'station' && !empty($payload['system_id'])) {
            $this->prime('system', (int)$payload['system_id']);
        }

        $this->upsertEntity($type, $id, (string)$payload['name'], $payload, $ttl);

        return $this->db->one(
            "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
            [$type, $id]
        ) ?? [];
    }

    private function upsertEntity(string $type, int $id, string $name, array $payload, int $ttl): void
    {
        $this->db->run(
            "INSERT INTO universe_entities (entity_type, entity_id, name, extra_json, fetched_at, ttl_seconds)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
               name=VALUES(name),
               extra_json=VALUES(extra_json),
               fetched_at=NOW(),
               ttl_seconds=VALUES(ttl_seconds)",
            [
                $type,
                $id,
                $name,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ttl,
            ]
        );
    }

    public function prime(string $type, int $id): void
    {
        if ($id <= 0) return;
        $this->entity($type, $id);
    }

    private function endpointFor(string $type, int $id): array
    {
        return match ($type) {
            'system' => ["/latest/universe/systems/{$id}/", 604800],
            'constellation' => ["/latest/universe/constellations/{$id}/", 2592000],
            'region' => ["/latest/universe/regions/{$id}/", 2592000],

            'type' => ["/latest/universe/types/{$id}/", 2592000],
            'group' => ["/latest/universe/groups/{$id}/", 2592000],
            'category' => ["/latest/universe/categories/{$id}/", 2592000],

            'station' => ["/latest/universe/stations/{$id}/", 2592000],
            'structure' => ["/latest/universe/structures/{$id}/", 86400],

            default => [null, 0],
        };
    }

    private function fallback(string $type, int $id): array
    {
        $name = ucfirst($type) . ' #' . $id;

        $this->db->run(
            "INSERT INTO universe_entities (entity_type, entity_id, name, ttl_seconds, fetched_at)
             VALUES (?, ?, ?, 3600, NOW())
             ON DUPLICATE KEY UPDATE
               name=VALUES(name),
               fetched_at=NOW(),
               ttl_seconds=VALUES(ttl_seconds)",
            [$type, $id, $name]
        );

        return $this->db->one(
            "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
            [$type, $id]
        ) ?? [];
    }

    private function bestEffortAccessToken(): ?string
    {
        $candidates = [
            $_SESSION['access_token'] ?? null,
            $_SESSION['sso_access_token'] ?? null,
            $_SESSION['esi_access_token'] ?? null,
        ];
        foreach ($candidates as $t) {
            if (is_string($t) && $t !== '') return $t;
        }

        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid > 0) {
            $row = $this->db->one("SELECT access_token FROM eve_tokens WHERE character_id=? LIMIT 1", [$cid])
                ?? $this->db->one("SELECT access_token FROM tokens WHERE character_id=? LIMIT 1", [$cid]);
            if ($row && !empty($row['access_token'])) {
                return (string)$row['access_token'];
            }
        }

        return null;
    }
}
