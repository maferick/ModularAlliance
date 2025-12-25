<?php
declare(strict_types=1);

namespace App\Securegroups\Providers;

use App\Core\Db;
use App\Securegroups\ProviderInterface;
use App\Securegroups\RuleComparator;

final class CorptoolsActivityProvider implements ProviderInterface
{
    public function __construct(private readonly Db $db) {}

    public function getKey(): string
    {
        return 'corptools.activity';
    }

    public function getDisplayName(): string
    {
        return 'CorpTools: Activity';
    }

    public function getAvailableRules(): array
    {
        return [
            [
                'key' => 'days_since_audit_main',
                'label' => 'Days Since Last Audit (Main)',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Days since the main character was audited.',
            ],
            [
                'key' => 'days_since_audit_any',
                'label' => 'Days Since Last Audit (Any Linked)',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Days since any linked character was audited.',
            ],
        ];
    }

    public function evaluateRule(int $userId, array $rule, array $context = []): array
    {
        $ruleKey = (string)($rule['rule_key'] ?? '');
        $operator = (string)($rule['operator'] ?? '>=');
        $expected = is_numeric($rule['value'] ?? null) ? (int)$rule['value'] : 0;

        $mainId = (int)($context['main_character_id'] ?? 0);
        $altIds = $context['alt_character_ids'] ?? [];
        $characterIds = array_values(array_filter(array_merge([$mainId], is_array($altIds) ? $altIds : [])));

        if ($ruleKey === 'days_since_audit_main') {
            if ($mainId <= 0) {
                return $this->unknown('Missing main character id.');
            }
            $row = $this->db->one(
                "SELECT last_audit_at FROM module_corptools_character_summary WHERE character_id=? LIMIT 1",
                [$mainId]
            );
            if (!$row || !$row['last_audit_at']) {
                return $this->unknown('Missing audit data for main character.');
            }
            $days = $this->daysSince((string)$row['last_audit_at']);
            return $this->result(RuleComparator::compare($operator, $days, $expected), $days, $expected);
        }

        if ($ruleKey === 'days_since_audit_any') {
            if (empty($characterIds)) {
                return $this->unknown('No linked characters found.');
            }
            $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
            $rows = $this->db->all(
                "SELECT last_audit_at FROM module_corptools_character_summary WHERE character_id IN ({$placeholders})",
                $characterIds
            );
            if (empty($rows)) {
                return $this->unknown('Missing audit data for linked characters.');
            }
            $minDays = null;
            foreach ($rows as $row) {
                if (!$row['last_audit_at']) {
                    continue;
                }
                $days = $this->daysSince((string)$row['last_audit_at']);
                if ($minDays === null || $days < $minDays) {
                    $minDays = $days;
                }
            }
            if ($minDays === null) {
                return $this->unknown('Missing audit data for linked characters.');
            }
            return $this->result(RuleComparator::compare($operator, $minDays, $expected), $minDays, $expected);
        }

        return $this->unknown('Unknown rule key.');
    }

    private function daysSince(string $timestamp): int
    {
        return (int)floor((time() - strtotime($timestamp)) / 86400);
    }

    private function result(bool $passed, int $actual, int $expected): array
    {
        return [
            'status' => $passed ? 'pass' : 'fail',
            'reason' => $passed ? 'Rule passed.' : 'Rule failed.',
            'actual' => $actual,
            'expected' => $expected,
            'evidence' => ['days_since' => $actual],
        ];
    }

    private function unknown(string $reason): array
    {
        return [
            'status' => 'unknown',
            'reason' => $reason,
            'evidence' => [],
        ];
    }
}
