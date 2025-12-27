<?php
declare(strict_types=1);

namespace App\Core;

final class Universe
{
    private static ?bool $sdeReady = null;

    private const LIST_ENTITY_TYPES = [
        'race' => [
            'endpoint' => '/latest/universe/races/',
            'id_key' => 'race_id',
            'ttl' => 2592000,
        ],
        'bloodline' => [
            'endpoint' => '/latest/universe/bloodlines/',
            'id_key' => 'bloodline_id',
            'ttl' => 2592000,
        ],
        'faction' => [
            'endpoint' => '/latest/universe/factions/',
            'id_key' => 'faction_id',
            'ttl' => 2592000,
        ],
    ];

    public function __construct(private Db $db) {}

    public function name(string $type, int $id): string
    {
        $e = $this->entity($type, $id);
        return $this->normalizeName($e['name'] ?? null);
    }

    public function nameOrUnknown(string $type, int $id, string $fallback = 'Unknown'): string
    {
        if ($id <= 0) {
            return $fallback;
        }
        $name = $this->name($type, $id);
        return $name !== '' ? $name : $fallback;
    }

    /** @return array<int, string> */
    public function names(string $type, array $ids): array
    {
        $results = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id <= 0) {
                continue;
            }
            $results[$id] = $this->nameOrUnknown($type, $id);
        }
        return $results;
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
        if ($id <= 0) {
            return [];
        }
        $sde = $this->sdeEntity($type, $id);
        if ($sde) {
            $payload = [];
            if (!empty($sde['extra_json'])) {
                $decoded = json_decode((string)$sde['extra_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            if (empty($payload)) {
                $payload = ['name' => $sde['name']];
            }
            $this->upsertEntity($type, $id, (string)($sde['name'] ?? ''), $payload, 2592000);
            return db_one($this->db, 
                "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
                [$type, $id]
            ) ?? $sde;
        }

        $row = db_one($this->db, 
            "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
            [$type, $id]
        );

        if ($row && !$this->isStale($row) && $this->normalizeName($row['name'] ?? null) !== '') {
            return $row;
        }

        return $this->refresh($type, $id, $row ?: null);
    }

    private function isStale(array $row): bool
    {
        $fetched = strtotime((string)($row['fetched_at'] ?? '')) ?: 0;
        $ttl = (int)($row['ttl_seconds'] ?? 0);
        return $fetched <= 0 || (time() > ($fetched + max(60, $ttl)));
    }

    private function refresh(string $type, int $id, ?array $existing = null): array
    {
        if (isset(self::LIST_ENTITY_TYPES[$type])) {
            return $this->refreshFromList($type, $id, $existing);
        }

        [$path, $ttl] = $this->endpointFor($type, $id);
        if (!$path) {
            $this->recordFailure($type, $id, 'Missing ESI endpoint');
            return $existing ?? [];
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
                $this->recordFailure($type, $id, 'ESI response missing name');
                return $existing ?? [];
            }

            if (!empty($payload['solar_system_id'])) {
                $this->prime('system', (int)$payload['solar_system_id']);
            }

            $name = (string)$payload['name'];
            if ($this->normalizeName($name) === '') {
                $this->recordFailure($type, $id, 'ESI returned empty name');
                return $existing ?? [];
            }
            $this->upsertEntity($type, $id, $name, $payload, $ttl);

            return db_one($this->db, 
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
            $this->recordFailure($type, $id, 'ESI response missing name');
            return $existing ?? [];
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

        $name = (string)$payload['name'];
        if ($this->normalizeName($name) === '') {
            $this->recordFailure($type, $id, 'ESI returned empty name');
            return $existing ?? [];
        }
        $this->upsertEntity($type, $id, $name, $payload, $ttl);

        return db_one($this->db, 
            "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
            [$type, $id]
        ) ?? [];
    }

    private function refreshFromList(string $type, int $id, ?array $existing = null): array
    {
        $config = self::LIST_ENTITY_TYPES[$type] ?? null;
        if (!$config) {
            $this->recordFailure($type, $id, 'Missing list config');
            return $existing ?? [];
        }

        $client = new EsiClient(new HttpClient(), 'https://esi.evetech.net');
        $cache  = new EsiCache($this->db, $client);

        $payload = $cache->getCached(
            "universe:list:{$type}",
            (string)$config['endpoint'],
            (int)$config['ttl']
        );

        if (is_array($payload)) {
            foreach ($payload as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((int)($row[$config['id_key']] ?? 0) !== $id) {
                    continue;
                }
                $name = (string)($row['name'] ?? '');
                if ($this->normalizeName($name) === '') {
                    break;
                }
                $this->upsertEntity($type, $id, $name, $row, (int)$config['ttl']);
                return db_one($this->db, 
                    "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
                    [$type, $id]
                ) ?? [];
            }
        }

        $this->recordFailure($type, $id, 'List lookup missing');
        return $existing ?? [];
    }

    private function upsertEntity(string $type, int $id, string $name, array $payload, int $ttl): void
    {
        if ($this->normalizeName($name) === '') {
            $this->recordFailure($type, $id, 'Attempted upsert with empty name');
            return;
        }
        db_exec($this->db, 
            "INSERT INTO universe_entities (entity_type, entity_id, name, extra_json, fetched_at, ttl_seconds, last_attempt_at)
             VALUES (?, ?, ?, ?, NOW(), ?, NOW())
             ON DUPLICATE KEY UPDATE
               name=VALUES(name),
               extra_json=VALUES(extra_json),
               fetched_at=NOW(),
               ttl_seconds=VALUES(ttl_seconds),
               last_error=NULL,
               fail_count=0,
               last_attempt_at=NOW()",
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
            'corporation' => ["/latest/corporations/{$id}/", 86400],
            'alliance' => ["/latest/alliances/{$id}/", 86400],
            'character' => ["/latest/characters/{$id}/", 86400],
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
        error_log("Universe: missing {$type} {$id} after SDE+ESI lookup.");

        $this->recordFailure($type, $id, 'SDE+ESI lookup failed');

        return db_one($this->db, 
            "SELECT * FROM universe_entities WHERE entity_type=? AND entity_id=?",
            [$type, $id]
        ) ?? [];
    }

    private function recordFailure(string $type, int $id, string $message): void
    {
        if ($id <= 0) {
            return;
        }

        db_exec($this->db, 
            "INSERT INTO universe_entities (entity_type, entity_id, name, ttl_seconds, last_error, fail_count, last_attempt_at)
             VALUES (?, ?, NULL, 3600, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               last_error=VALUES(last_error),
               fail_count=fail_count + 1,
               last_attempt_at=NOW()",
            [$type, $id, $message]
        );
    }

    private function sdeEntity(string $type, int $id): ?array
    {
        if (!self::sdeTablesReady($this->db)) {
            return null;
        }

        if ($id <= 0) {
            return null;
        }

        $table = null;
        $column = null;
        $extra = [];

        switch ($type) {
            case 'category':
                $table = 'sde_inv_categories';
                $column = 'category_id';
                break;
            case 'group':
                $table = 'sde_inv_groups';
                $column = 'group_id';
                $extra = ['category_id'];
                break;
            case 'type':
                $table = 'sde_inv_types';
                $column = 'type_id';
                $extra = ['group_id'];
                break;
            case 'region':
                $table = 'sde_map_regions';
                $column = 'region_id';
                break;
            case 'constellation':
                $table = 'sde_map_constellations';
                $column = 'constellation_id';
                $extra = ['region_id'];
                break;
            case 'system':
                $table = 'sde_map_solar_systems';
                $column = 'solar_system_id';
                $extra = ['constellation_id', 'region_id'];
                break;
            case 'station':
                $table = 'sde_sta_stations';
                $column = 'station_id';
                $extra = ['solar_system_id', 'constellation_id', 'region_id'];
                break;
            default:
                return null;
        }

        $fields = array_merge([$column, 'name'], $extra);
        $row = db_one($this->db, 
            "SELECT " . implode(', ', $fields) . " FROM {$table} WHERE {$column}=?",
            [$id]
        );

        if (!$row || empty($row['name'])) {
            return null;
        }

        return [
            'entity_type' => $type,
            'entity_id' => $id,
            'name' => $row['name'],
            'extra_json' => !empty($extra) ? json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'fetched_at' => null,
            'ttl_seconds' => 0,
        ];
    }

    private static function sdeTablesReady(Db $db): bool
    {
        if (self::$sdeReady !== null) {
            return self::$sdeReady;
        }
        $row = db_one($db, "SHOW TABLES LIKE 'sde_inv_categories'");
        self::$sdeReady = $row !== null;
        return self::$sdeReady;
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

    public function repairUnknowns(int $limit = 200): array
    {
        $rows = db_all($this->db, 
            "SELECT entity_type, entity_id FROM universe_entities
             WHERE name IS NULL OR name IN ('', 'Unknown')
             ORDER BY COALESCE(last_attempt_at, fetched_at) ASC
             LIMIT ?",
            [$limit]
        );

        $repaired = 0;
        $attempted = 0;
        foreach ($rows as $row) {
            $type = (string)($row['entity_type'] ?? '');
            $id = (int)($row['entity_id'] ?? 0);
            if ($type === '' || $id <= 0) {
                continue;
            }
            $attempted++;
            $before = $this->entity($type, $id);
            $name = $this->normalizeName($before['name'] ?? null);
            if ($name !== '') {
                $repaired++;
            }
        }

        return [
            'attempted' => $attempted,
            'repaired' => $repaired,
        ];
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $trimmed = trim($name);
        if ($trimmed === '' || $trimmed === 'Unknown') {
            return '';
        }
        return $trimmed;
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
                $token = $sso->getAccessTokenForCharacter($cid, 'default');
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
                            'default'
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
