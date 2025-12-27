<?php
declare(strict_types=1);

namespace App\Core;

final class IdentityResolver
{
    private const ORG_TTL_SECONDS = 86400;

    public function __construct(private Db $db, private Universe $universe) {}

    public function resolveCharacter(int $characterId): array
    {
        $results = $this->resolveCharacters([$characterId]);
        return $results[$characterId] ?? [
            'character_id' => $characterId,
            'user_id' => 0,
            'is_main' => false,
            'last_verified_at' => null,
            'corp_id' => 0,
            'alliance_id' => 0,
            'org_verified_at' => null,
            'org_status' => 'missing',
            'corporation' => ['id' => 0, 'name' => '—'],
            'alliance' => ['id' => 0, 'name' => '—'],
        ];
    }

    public function resolveCharacters(array $characterIds): array
    {
        $characterIds = array_values(array_unique(array_filter(array_map('intval', $characterIds), fn(int $id) => $id > 0)));
        if (empty($characterIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        $rows = $this->db->all(
            "SELECT ci.character_id, ci.user_id, ci.is_main, ci.last_verified_at, co.corp_id, co.alliance_id, co.verified_at AS org_verified_at
             FROM core_character_identities ci
             LEFT JOIN core_character_orgs co ON co.character_id=ci.character_id
             WHERE ci.character_id IN ({$placeholders})",
            $characterIds
        );

        $map = [];
        $corpIds = [];
        $allianceIds = [];
        foreach ($rows as $row) {
            $characterId = (int)($row['character_id'] ?? 0);
            if ($characterId <= 0) continue;
            $orgStatus = $this->orgStatus($row['org_verified_at'] ?? null);
            $corpId = $orgStatus === 'fresh' ? (int)($row['corp_id'] ?? 0) : 0;
            $allianceId = $orgStatus === 'fresh' ? (int)($row['alliance_id'] ?? 0) : 0;
            if ($corpId > 0) {
                $corpIds[$corpId] = true;
            }
            if ($allianceId > 0) {
                $allianceIds[$allianceId] = true;
            }
            $map[$characterId] = [
                'character_id' => $characterId,
                'user_id' => (int)($row['user_id'] ?? 0),
                'is_main' => (int)($row['is_main'] ?? 0) === 1,
                'last_verified_at' => $row['last_verified_at'] ?? null,
                'corp_id' => $corpId,
                'alliance_id' => $allianceId,
                'org_verified_at' => $row['org_verified_at'] ?? null,
                'org_status' => $orgStatus,
            ];
        }

        $corpNames = [];
        foreach (array_keys($corpIds) as $corpId) {
            $corpNames[$corpId] = $this->resolveName('corporation', $corpId);
        }
        $allianceNames = [];
        foreach (array_keys($allianceIds) as $allianceId) {
            $allianceNames[$allianceId] = $this->resolveName('alliance', $allianceId);
        }

        foreach ($map as $characterId => $entry) {
            $corpId = (int)($entry['corp_id'] ?? 0);
            $allianceId = (int)($entry['alliance_id'] ?? 0);
            $map[$characterId]['corporation'] = [
                'id' => $corpId,
                'name' => $corpId > 0 ? ($corpNames[$corpId] ?? 'Unknown') : '—',
            ];
            $map[$characterId]['alliance'] = [
                'id' => $allianceId,
                'name' => $allianceId > 0 ? ($allianceNames[$allianceId] ?? 'Unknown') : '—',
            ];
        }

        return $map;
    }

    public function upsertIdentity(int $characterId, int $userId, bool $isMain, ?string $verifiedAt = null): void
    {
        if ($characterId <= 0 || $userId <= 0) {
            return;
        }

        $verifiedAt = $verifiedAt ?? gmdate('Y-m-d H:i:s');
        $this->db->run(
            "INSERT INTO core_character_identities (character_id, user_id, is_main, last_verified_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               user_id=VALUES(user_id),
               is_main=VALUES(is_main),
               last_verified_at=VALUES(last_verified_at)",
            [$characterId, $userId, $isMain ? 1 : 0, $verifiedAt]
        );

        if ($isMain) {
            $this->db->run(
                "UPDATE core_character_identities SET is_main=0 WHERE user_id=? AND character_id<>?",
                [$userId, $characterId]
            );
        }
    }

    public function upsertOrgMapping(int $characterId, int $corpId, int $allianceId, ?string $verifiedAt = null): void
    {
        if ($characterId <= 0) {
            return;
        }

        $verifiedAt = $verifiedAt ?? gmdate('Y-m-d H:i:s');
        $this->db->run(
            "INSERT INTO core_character_orgs (character_id, corp_id, alliance_id, verified_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               corp_id=VALUES(corp_id),
               alliance_id=VALUES(alliance_id),
               verified_at=VALUES(verified_at)",
            [$characterId, max(0, $corpId), max(0, $allianceId), $verifiedAt]
        );
    }

    private function resolveName(string $type, int $id): string
    {
        if ($id <= 0) {
            return '—';
        }
        $name = $this->universe->name($type, $id);
        $idMarker = '#' . $id;
        if ($name === '' || str_contains($name, $idMarker)) {
            return 'Unknown';
        }
        return $name;
    }

    private function orgStatus(?string $verifiedAt): string
    {
        if (!$verifiedAt) {
            return 'missing';
        }
        $ts = strtotime($verifiedAt) ?: 0;
        if ($ts <= 0) {
            return 'missing';
        }
        if ($ts < (time() - self::ORG_TTL_SECONDS)) {
            return 'stale';
        }
        return 'fresh';
    }
}
