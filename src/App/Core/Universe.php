<?php
declare(strict_types=1);

namespace App\Core;

final class Universe
{
    public function __construct(private readonly Db $db) {}

    public function characterProfile(int $characterId): array
    {
        $char = $this->cacheJson("char:{$characterId}", "GET /latest/characters/{$characterId}/");
        $portrait = $this->cacheJson("char:{$characterId}", "GET /latest/characters/{$characterId}/portrait/");

        $corpId = (int)($char['corporation_id'] ?? 0);
        $corp = $corpId ? $this->corporationProfile($corpId) : null;

        // Alliance can come from character payload (you have it) OR from corp payload
        $allianceId = (int)($char['alliance_id'] ?? 0);
        if ($allianceId <= 0 && $corpId) {
            $corpCore = $this->cacheJson("corp:{$corpId}", "GET /latest/corporations/{$corpId}/");
            $allianceId = (int)($corpCore['alliance_id'] ?? 0);
        }
        $alliance = $allianceId ? $this->allianceProfile($allianceId) : null;

        return [
            'character' => [
                'name' => (string)($char['name'] ?? 'Unknown'),
                'portrait' => $portrait,
            ],
            'corporation' => $corp,
            'alliance' => $alliance,
        ];
    }

    public function corporationProfile(int $corpId): array
    {
        $corp = $this->cacheJson("corp:{$corpId}", "GET /latest/corporations/{$corpId}/");
        $icons = $this->cacheJson("corp:{$corpId}", "GET /latest/corporations/{$corpId}/icons/");

        return [
            'name' => (string)($corp['name'] ?? 'Unknown Corporation'),
            'ticker' => (string)($corp['ticker'] ?? ''),
            'icons' => $icons,
        ];
    }

    public function allianceProfile(int $allianceId): array
    {
        $alliance = $this->cacheJson("alliance:{$allianceId}", "GET /latest/alliances/{$allianceId}/");
        $icons = $this->cacheJson("alliance:{$allianceId}", "GET /latest/alliances/{$allianceId}/icons/");

        return [
            'name' => (string)($alliance['name'] ?? 'Unknown Alliance'),
            'ticker' => (string)($alliance['ticker'] ?? ''),
            'icons' => $icons,
        ];
    }

    private function cacheJson(string $scopeKey, string $urlKey): array
    {
        $row = $this->db->one(
            "SELECT payload_json
             FROM esi_cache
             WHERE scope_key=? AND url=?
             ORDER BY fetched_at DESC
             LIMIT 1",
            [$scopeKey, $urlKey]
        );

        if (!$row) return [];
        $data = json_decode((string)$row['payload_json'], true);
        return is_array($data) ? $data : [];
    }
}

