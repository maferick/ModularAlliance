<?php
declare(strict_types=1);

namespace App\Corptools;

use App\Core\Db;
use App\Core\Universe;

final class ScopePolicy
{
    public function __construct(private Db $db, private Universe $universe) {}

    public function listPolicies(): array
    {
        return $this->db->all(
            "SELECT id, name, description, is_active, applies_to, required_scopes_json, optional_scopes_json, updated_at
             FROM corp_scope_policies
             ORDER BY updated_at DESC, id DESC"
        );
    }

    public function getActivePolicyForContext(int $corpId, int $allianceId): ?array
    {
        $policies = $this->db->all(
            "SELECT id, name, description, is_active, applies_to, required_scopes_json, optional_scopes_json
             FROM corp_scope_policies
             WHERE is_active=1
             ORDER BY updated_at DESC, id DESC"
        );

        $matches = [
            'alliance_members' => [],
            'corp_members' => [],
            'all_users' => [],
        ];

        foreach ($policies as $policy) {
            $applies = (string)($policy['applies_to'] ?? 'all_users');
            if (!isset($matches[$applies])) continue;
            $matches[$applies][] = $this->normalizePolicy($policy);
        }

        if ($allianceId > 0 && !empty($matches['alliance_members'])) {
            return $matches['alliance_members'][0];
        }
        if ($corpId > 0 && !empty($matches['corp_members'])) {
            return $matches['corp_members'][0];
        }
        if (!empty($matches['all_users'])) {
            return $matches['all_users'][0];
        }

        return null;
    }

    public function getEffectiveScopesForUser(int $userId): array
    {
        $user = $this->db->one("SELECT character_id FROM eve_users WHERE id=? LIMIT 1", [$userId]);
        $mainCharacterId = (int)($user['character_id'] ?? 0);
        $corpId = 0;
        $allianceId = 0;

        if ($mainCharacterId > 0) {
            $profile = $this->universe->characterProfile($mainCharacterId);
            $corpId = (int)($profile['corporation']['id'] ?? 0);
            $allianceId = (int)($profile['alliance']['id'] ?? 0);
        }

        return $this->getEffectiveScopesForContext($userId, $corpId, $allianceId);
    }

    public function getEffectiveScopesForContext(int $userId, int $corpId, int $allianceId): array
    {
        $policy = $this->getActivePolicyForContext($corpId, $allianceId);
        if (!$policy) {
            return [
                'policy' => null,
                'required' => [],
                'optional' => [],
            ];
        }

        $required = $policy['required_scopes'];
        $optional = $policy['optional_scopes'];

        $overrides = $this->collectOverrides((int)$policy['id'], $userId);
        foreach ($overrides as $override) {
            $required = array_values(array_unique(array_merge($required, $override['required_scopes'])));
            $optional = array_values(array_unique(array_merge($optional, $override['optional_scopes'])));
        }

        return [
            'policy' => $policy,
            'required' => $required,
            'optional' => $optional,
        ];
    }

    public function getDefaultPolicy(): ?array
    {
        return $this->getActivePolicyForContext(0, 0);
    }

    public function normalizeScopes(?string $json): array
    {
        $scopes = [];
        if ($json) {
            $scopes = json_decode($json, true);
            if (!is_array($scopes)) $scopes = [];
        }
        $scopes = array_values(array_unique(array_filter($scopes, 'is_string')));
        sort($scopes);
        return $scopes;
    }

    private function normalizePolicy(array $policy): array
    {
        return [
            'id' => (int)($policy['id'] ?? 0),
            'name' => (string)($policy['name'] ?? ''),
            'description' => (string)($policy['description'] ?? ''),
            'applies_to' => (string)($policy['applies_to'] ?? 'all_users'),
            'required_scopes' => $this->normalizeScopes($policy['required_scopes_json'] ?? null),
            'optional_scopes' => $this->normalizeScopes($policy['optional_scopes_json'] ?? null),
        ];
    }

    private function collectOverrides(int $policyId, int $userId): array
    {
        if ($policyId <= 0 || $userId <= 0) return [];

        $groupRows = $this->db->all(
            "SELECT group_id FROM eve_user_groups WHERE user_id=?",
            [$userId]
        );
        $groupIds = [];
        foreach ($groupRows as $row) {
            $gid = (int)($row['group_id'] ?? 0);
            if ($gid > 0) $groupIds[] = $gid;
        }

        $overrides = $this->db->all(
            "SELECT target_type, target_id, required_scopes_json, optional_scopes_json
             FROM corp_scope_policy_overrides
             WHERE policy_id=?
             ORDER BY updated_at DESC, id DESC",
            [$policyId]
        );

        $matches = [];
        foreach ($overrides as $override) {
            $type = (string)($override['target_type'] ?? '');
            $targetId = (int)($override['target_id'] ?? 0);
            $apply = false;
            if ($type === 'user' && $targetId === $userId) {
                $apply = true;
            }
            if (($type === 'group' || $type === 'role') && in_array($targetId, $groupIds, true)) {
                $apply = true;
            }
            if (!$apply) continue;
            $matches[] = [
                'required_scopes' => $this->normalizeScopes($override['required_scopes_json'] ?? null),
                'optional_scopes' => $this->normalizeScopes($override['optional_scopes_json'] ?? null),
            ];
        }

        return $matches;
    }
}
