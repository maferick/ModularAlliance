<?php
declare(strict_types=1);

namespace App\Securegroups;

use App\Core\Db;

final class SecureGroupService
{
    public function __construct(
        private readonly Db $db,
        private readonly ProviderRegistry $providers
    ) {}

    /** @return array<string, mixed> */
    public function evaluateGroup(int $userId, array $group, array $rules): array
    {
        $context = $this->buildUserContext($userId);
        $groupRules = array_values(array_filter($rules, fn($r) => (int)($r['enabled'] ?? 1) === 1));

        if (empty($groupRules)) {
            return [
                'status' => 'in',
                'reason' => 'No rules configured; default allow.',
                'evidence' => [],
            ];
        }

        $grouped = [];
        foreach ($groupRules as $rule) {
            $logicGroup = (int)($rule['logic_group'] ?? 0);
            $grouped[$logicGroup][] = $rule;
        }

        $unknownHandling = (string)($group['unknown_data_handling'] ?? 'fail');
        $unknownHandling = in_array($unknownHandling, ['fail', 'ignore', 'defer'], true) ? $unknownHandling : 'fail';

        $overallUnknown = false;
        $allEvidence = [];
        $reasonSummary = '';

        foreach ($grouped as $logicGroup => $rulesInGroup) {
            $groupStatus = 'pass';
            $groupEvidence = [];
            foreach ($rulesInGroup as $rule) {
                $providerKey = (string)($rule['provider_key'] ?? '');
                $provider = $this->providers->get($providerKey);
                if (!$provider) {
                    $result = $this->resultUnknown('Provider missing', [
                        'provider_key' => $providerKey,
                        'rule_key' => (string)($rule['rule_key'] ?? ''),
                    ]);
                } else {
                    $result = $provider->evaluateRule($userId, $rule, $context);
                }

                $groupEvidence[] = $this->normalizeEvidence($rule, $result);

                if ($result['status'] === 'fail') {
                    $groupStatus = 'fail';
                    if ($reasonSummary === '') {
                        $reasonSummary = (string)($result['reason'] ?? 'Rule failed');
                    }
                    break;
                }
                if ($result['status'] === 'unknown') {
                    if ($unknownHandling === 'fail') {
                        $groupStatus = 'fail';
                        if ($reasonSummary === '') {
                            $reasonSummary = (string)($result['reason'] ?? 'Unknown data treated as failure');
                        }
                        break;
                    }
                    if ($unknownHandling === 'defer') {
                        $groupStatus = 'unknown';
                        $overallUnknown = true;
                        if ($reasonSummary === '') {
                            $reasonSummary = (string)($result['reason'] ?? 'Unknown data; pending evaluation');
                        }
                    }
                }
            }

            $allEvidence[] = [
                'logic_group' => $logicGroup,
                'status' => $groupStatus,
                'rules' => $groupEvidence,
            ];

            if ($groupStatus === 'pass') {
                return [
                    'status' => 'in',
                    'reason' => $reasonSummary !== '' ? $reasonSummary : 'All rules satisfied.',
                    'evidence' => $allEvidence,
                ];
            }
        }

        if ($overallUnknown && $unknownHandling === 'defer') {
            return [
                'status' => 'pending',
                'reason' => $reasonSummary !== '' ? $reasonSummary : 'Unknown data; pending.',
                'evidence' => $allEvidence,
            ];
        }

        return [
            'status' => 'out',
            'reason' => $reasonSummary !== '' ? $reasonSummary : 'Rules not satisfied.',
            'evidence' => $allEvidence,
        ];
    }

    /** @return array<string, mixed> */
    public function buildUserContext(int $userId): array
    {
        $identityRows = $this->db->all(
            "SELECT character_id, is_main FROM core_character_identities WHERE user_id=?",
            [$userId]
        );
        $mainCharacterId = 0;
        $altIds = [];
        foreach ($identityRows as $row) {
            $cid = (int)($row['character_id'] ?? 0);
            if ($cid <= 0) continue;
            if ((int)($row['is_main'] ?? 0) === 1) {
                $mainCharacterId = $cid;
            } else {
                $altIds[] = $cid;
            }
        }

        if ($mainCharacterId === 0) {
            $main = $this->db->one(
                "SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1",
                [$userId]
            );
            $mainCharacterId = (int)($main['character_id'] ?? 0);
        }

        $mainNameRow = $this->db->one(
            "SELECT character_name FROM eve_users WHERE id=? LIMIT 1",
            [$userId]
        );
        $mainName = (string)($mainNameRow['character_name'] ?? '');

        return [
            'user_id' => $userId,
            'main_character_id' => $mainCharacterId,
            'main_character_name' => $mainName,
            'alt_character_ids' => $altIds,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeEvidence(array $rule, array $result): array
    {
        return [
            'provider_key' => (string)($rule['provider_key'] ?? ''),
            'rule_key' => (string)($rule['rule_key'] ?? ''),
            'operator' => (string)($rule['operator'] ?? ''),
            'value' => $rule['value'] ?? null,
            'status' => (string)($result['status'] ?? 'unknown'),
            'reason' => (string)($result['reason'] ?? ''),
            'actual' => $result['actual'] ?? null,
            'expected' => $result['expected'] ?? null,
            'evidence' => $result['evidence'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function resultUnknown(string $reason, array $evidence = []): array
    {
        return [
            'status' => 'unknown',
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
