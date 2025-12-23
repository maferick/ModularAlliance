<?php
declare(strict_types=1);

namespace App\Corptools\Audit;

use App\Core\Db;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Universe;

final class Dispatcher
{
    public function __construct(private Db $db) {}

    /** @param array<int, CollectorInterface> $collectors */
    public function run(int $userId, int $characterId, string $characterName, array $token, array $collectors, array $enabledKeys, array $baseSummary = []): array
    {
        $runId = $this->startRun($userId, $characterId, $token['scopes'] ?? []);
        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($this->db, $client);
        $universe = new Universe($this->db);

        $missing = [];
        $summaryUpdates = array_merge([
            'character_name' => $characterName,
            'last_audit_at' => date('Y-m-d H:i:s'),
            'audit_loaded' => 1,
        ], $baseSummary);

        foreach ($collectors as $collector) {
            $key = $collector->key();
            if (!in_array($key, $enabledKeys, true)) {
                continue;
            }

            $scopes = $collector->scopes();
            if (!$this->hasScopes($token['scopes'] ?? [], $scopes)) {
                $missing[$key] = $scopes;
                $summaryUpdates['audit_loaded'] = 0;
                continue;
            }

            $payloads = [];
            foreach ($collector->endpoints($characterId) as $endpoint) {
                $payloads[] = $cache->getCachedAuth(
                    "corptools:audit:{$characterId}",
                    "GET {$endpoint}",
                    $collector->ttlSeconds(),
                    (string)($token['access_token'] ?? ''),
                    [403, 404]
                );
            }

            $this->storeAuditPayload($userId, $characterId, $key, $payloads);
            $summaryUpdates = array_merge($summaryUpdates, $collector->summarize($characterId, $payloads));

            if ($key === 'assets') {
                $this->storeAssets($userId, $characterId, $payloads[0] ?? [], $universe);
            }

            if ($key === 'skills') {
                $this->storeSkills($userId, $characterId, $payloads[0] ?? []);
            }

            if ($key === 'location') {
                $systemId = (int)($summaryUpdates['location_system_id'] ?? 0);
                if ($systemId > 0) {
                    $system = $universe->entity('system', $systemId);
                    $extra = json_decode((string)($system['extra_json'] ?? '[]'), true);
                    if (is_array($extra)) {
                        $summaryUpdates['location_region_id'] = (int)($extra['region_id'] ?? 0);
                    }
                }
            }
        }

        $this->upsertCharacterSummary($userId, $characterId, $characterName, $summaryUpdates);
        $this->finishRun($runId, empty($missing));

        return ['missing' => $missing];
    }

    private function hasScopes(array $tokenScopes, array $requiredScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $tokenScopes, true)) return false;
        }
        return true;
    }

    private function startRun(int $userId, int $characterId, array $scopes): int
    {
        $this->db->run(
            "INSERT INTO module_corptools_audit_runs (user_id, character_id, status, scopes_json, started_at)
             VALUES (?, ?, 'running', ?, NOW())",
            [$userId, $characterId, json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
        $row = $this->db->one("SELECT LAST_INSERT_ID() AS id");
        return (int)($row['id'] ?? 0);
    }

    private function finishRun(int $runId, bool $success): void
    {
        if ($runId <= 0) return;
        $status = $success ? 'completed' : 'partial';
        $this->db->run(
            "UPDATE module_corptools_audit_runs SET status=?, finished_at=NOW() WHERE id=?",
            [$status, $runId]
        );
    }

    private function storeAuditPayload(int $userId, int $characterId, string $key, array $payloads): void
    {
        $payload = json_encode($payloads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->db->run(
            "INSERT INTO module_corptools_character_audit (user_id, character_id, category, data_json, fetched_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE data_json=VALUES(data_json), updated_at=NOW()",
            [$userId, $characterId, $key, $payload]
        );
        $this->db->run(
            "INSERT INTO module_corptools_character_audit_snapshots
             (user_id, character_id, category, data_json, fetched_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$userId, $characterId, $key, $payload]
        );
    }

    private function upsertCharacterSummary(int $userId, int $characterId, string $characterName, array $summary): void
    {
        $fields = [
            'user_id' => $userId,
            'character_name' => $characterName,
            'is_main' => (int)($summary['is_main'] ?? 0),
            'corp_id' => (int)($summary['corp_id'] ?? 0),
            'alliance_id' => (int)($summary['alliance_id'] ?? 0),
            'home_station_id' => (int)($summary['home_station_id'] ?? 0),
            'death_clone_location_id' => (int)($summary['death_clone_location_id'] ?? 0),
            'jump_clone_location_id' => (int)($summary['jump_clone_location_id'] ?? 0),
            'location_system_id' => (int)($summary['location_system_id'] ?? 0),
            'location_region_id' => (int)($summary['location_region_id'] ?? 0),
            'current_ship_type_id' => (int)($summary['current_ship_type_id'] ?? 0),
            'current_ship_name' => (string)($summary['current_ship_name'] ?? ''),
            'wallet_balance' => (float)($summary['wallet_balance'] ?? 0),
            'total_sp' => (int)($summary['total_sp'] ?? 0),
            'assets_count' => (int)($summary['assets_count'] ?? 0),
            'assets_value' => (float)($summary['assets_value'] ?? 0),
            'corp_roles_json' => $summary['corp_roles_json'] ?? null,
            'corp_title' => (string)($summary['corp_title'] ?? ''),
            'last_audit_at' => $summary['last_audit_at'] ?? null,
            'audit_loaded' => (int)($summary['audit_loaded'] ?? 0),
        ];

        $this->db->run(
            "INSERT INTO module_corptools_character_summary
             (character_id, user_id, character_name, is_main, corp_id, alliance_id, home_station_id, death_clone_location_id,
              jump_clone_location_id, location_system_id, location_region_id, current_ship_type_id, current_ship_name,
              wallet_balance, total_sp, assets_count, assets_value, corp_roles_json, corp_title, last_audit_at, audit_loaded)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              user_id=VALUES(user_id), character_name=VALUES(character_name), is_main=VALUES(is_main), corp_id=VALUES(corp_id),
              alliance_id=VALUES(alliance_id), home_station_id=VALUES(home_station_id), death_clone_location_id=VALUES(death_clone_location_id),
              jump_clone_location_id=VALUES(jump_clone_location_id), location_system_id=VALUES(location_system_id),
              location_region_id=VALUES(location_region_id), current_ship_type_id=VALUES(current_ship_type_id),
              current_ship_name=VALUES(current_ship_name), wallet_balance=VALUES(wallet_balance), total_sp=VALUES(total_sp),
              assets_count=VALUES(assets_count), assets_value=VALUES(assets_value), corp_roles_json=VALUES(corp_roles_json),
              corp_title=VALUES(corp_title), last_audit_at=VALUES(last_audit_at), audit_loaded=VALUES(audit_loaded)",
            [
                $characterId,
                $fields['user_id'],
                $fields['character_name'],
                $fields['is_main'],
                $fields['corp_id'],
                $fields['alliance_id'],
                $fields['home_station_id'],
                $fields['death_clone_location_id'],
                $fields['jump_clone_location_id'],
                $fields['location_system_id'],
                $fields['location_region_id'],
                $fields['current_ship_type_id'],
                $fields['current_ship_name'],
                $fields['wallet_balance'],
                $fields['total_sp'],
                $fields['assets_count'],
                $fields['assets_value'],
                $fields['corp_roles_json'],
                $fields['corp_title'],
                $fields['last_audit_at'],
                $fields['audit_loaded'],
            ]
        );
    }

    private function storeAssets(int $userId, int $characterId, array $assets, Universe $universe): void
    {
        if (!is_array($assets)) return;
        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $itemId = (int)($asset['item_id'] ?? 0);
            if ($itemId <= 0) continue;
            $typeId = (int)($asset['type_id'] ?? 0);
            $groupId = 0;
            $categoryId = 0;
            if ($typeId > 0) {
                $type = $universe->entity('type', $typeId);
                $extra = json_decode((string)($type['extra_json'] ?? '[]'), true);
                if (is_array($extra)) {
                    $groupId = (int)($extra['group_id'] ?? 0);
                }
                if ($groupId > 0) {
                    $group = $universe->entity('group', $groupId);
                    $groupExtra = json_decode((string)($group['extra_json'] ?? '[]'), true);
                    if (is_array($groupExtra)) {
                        $categoryId = (int)($groupExtra['category_id'] ?? 0);
                    }
                }
            }

            $this->db->run(
                "INSERT INTO module_corptools_character_assets
                 (user_id, character_id, item_id, type_id, group_id, category_id, location_id, location_type, quantity, is_singleton, is_blueprint_copy)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                  type_id=VALUES(type_id), group_id=VALUES(group_id), category_id=VALUES(category_id), location_id=VALUES(location_id),
                  location_type=VALUES(location_type), quantity=VALUES(quantity), is_singleton=VALUES(is_singleton), is_blueprint_copy=VALUES(is_blueprint_copy)",
                [
                    $userId,
                    $characterId,
                    $itemId,
                    $typeId,
                    $groupId,
                    $categoryId,
                    (int)($asset['location_id'] ?? 0),
                    (string)($asset['location_type'] ?? ''),
                    (int)($asset['quantity'] ?? 0),
                    (int)($asset['is_singleton'] ?? 0),
                    (int)($asset['is_blueprint_copy'] ?? 0),
                ]
            );
        }
    }

    private function storeSkills(int $userId, int $characterId, array $payload): void
    {
        $skills = $payload['skills'] ?? [];
        if (!is_array($skills)) return;
        foreach ($skills as $skill) {
            if (!is_array($skill)) continue;
            $skillId = (int)($skill['skill_id'] ?? 0);
            if ($skillId <= 0) continue;
            $this->db->run(
                "INSERT INTO module_corptools_character_skills
                 (user_id, character_id, skill_id, trained_level, active_level, skillpoints_in_skill)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                  trained_level=VALUES(trained_level), active_level=VALUES(active_level), skillpoints_in_skill=VALUES(skillpoints_in_skill)",
                [
                    $userId,
                    $characterId,
                    $skillId,
                    (int)($skill['trained_skill_level'] ?? 0),
                    (int)($skill['active_skill_level'] ?? 0),
                    (int)($skill['skillpoints_in_skill'] ?? 0),
                ]
            );
        }
    }
}
