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
            if (!$token || empty($token['access_token'])) {
                return $this->fallback($type, $id);
            }

            $payload = $cache->getCachedAuth(
                "universe:$type:$id",
                $path,
                $ttl,
                (string)$token['access_token'],
                [403, 404],
                $token['refresh_callback'] ?? null
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

    public function characterProfile(int $characterId): array
{
    $client = new EsiClient(new HttpClient(), 'https://esi.evetech.net');
    $cache  = new EsiCache($this->db, $client);

    // Character basics (public)
    $character = $cache->getCached(
        "char:{$characterId}",
        "GET /latest/characters/{$characterId}/",
        3600,
        fn () => $client->get("/latest/characters/{$characterId}/")
    );

    // Character portrait (public)
    $portrait = $cache->getCached(
        "char:{$characterId}",
        "GET /latest/characters/{$characterId}/portrait/",
        86400,
        fn () => $client->get("/latest/characters/{$characterId}/portrait/")
    );

    $corp = [];
    $corpIcons = [];
    $alliance = [];
    $allianceIcons = [];

    $corpId = (int)($character['corporation_id'] ?? 0);
    if ($corpId > 0) {
        $corp = $cache->getCached(
            "corp:{$corpId}",
            "GET /latest/corporations/{$corpId}/",
            3600,
            fn () => $client->get("/latest/corporations/{$corpId}/")
        );

        $corpIcons = $cache->getCached(
            "corp:{$corpId}",
            "GET /latest/corporations/{$corpId}/icons/",
            86400,
            fn () => $client->get("/latest/corporations/{$corpId}/icons/")
        );
    }

    $allianceId = (int)($character['alliance_id'] ?? 0);
    if ($allianceId > 0) {
        $alliance = $cache->getCached(
            "alliance:{$allianceId}",
            "GET /latest/alliances/{$allianceId}/",
            3600,
            fn () => $client->get("/latest/alliances/{$allianceId}/")
        );

        $allianceIcons = $cache->getCached(
            "alliance:{$allianceId}",
            "GET /latest/alliances/{$allianceId}/icons/",
            86400,
            fn () => $client->get("/latest/alliances/{$allianceId}/icons/")
        );
    }

    return [
        'character' => [
            'id' => $characterId,               // internal only; do not display in UI
            'name' => $character['name'] ?? null,
            'data' => $character,
            'portrait' => $portrait,
        ],
        'corporation' => [
            'id' => $corpId,                    // internal only; do not display in UI
            'name' => $corp['name'] ?? null,
            'ticker' => $corp['ticker'] ?? null,
            'data' => $corp,
            'icons' => $corpIcons,
        ],
        'alliance' => [
            'id' => $allianceId,                // internal only; do not display in UI
            'name' => $alliance['name'] ?? null,
            'ticker' => $alliance['ticker'] ?? null,
            'data' => $alliance,
            'icons' => $allianceIcons,
        ],
    ];
}

    private function bestEffortAccessToken(): ?array
    {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid > 0 && function_exists('app_config')) {
            $cfg = [];
            $all = \app_config();
            if (is_array($all) && isset($all['eve_sso']) && is_array($all['eve_sso'])) {
                $cfg = $all['eve_sso'];
            }
            if (!empty($cfg)) {
                $sso = new EveSso($this->db, $cfg);
                $token = $sso->getAccessTokenForCharacter($cid, 'basic');
                if (!empty($token['access_token'])) {
                    $token['refresh_callback'] = function () use (&$token, $sso, $cid): ?string {
                        $refreshToken = (string)($token['refresh_token'] ?? '');
                        if ($refreshToken === '') {
                            return null;
                        }
                        $refresh = $sso->refreshTokenForCharacter(
                            (int)($token['user_id'] ?? 0),
                            $cid,
                            $refreshToken,
                            'basic'
                        );
                        if (($refresh['status'] ?? '') === 'success') {
                            $token['access_token'] = (string)($refresh['token']['access_token'] ?? '');
                            $token['refresh_token'] = (string)($refresh['token']['refresh_token'] ?? $refreshToken);
                            return $token['access_token'];
                        }
                        return null;
                    };
                    return $token;
                }
            }
        }

        $candidates = [
            $_SESSION['access_token'] ?? null,
            $_SESSION['sso_access_token'] ?? null,
            $_SESSION['esi_access_token'] ?? null,
        ];
        foreach ($candidates as $t) {
            if (is_string($t) && $t !== '') {
                return ['access_token' => $t, 'refresh_callback' => null];
            }
        }

        return null;
    }
}
