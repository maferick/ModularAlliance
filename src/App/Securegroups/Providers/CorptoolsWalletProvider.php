<?php
declare(strict_types=1);

namespace App\Securegroups\Providers;

use App\Core\Db;
use App\Securegroups\ProviderInterface;
use App\Securegroups\RuleComparator;

final class CorptoolsWalletProvider implements ProviderInterface
{
    public function __construct(private readonly Db $db) {}

    public function getKey(): string
    {
        return 'corptools.wallet';
    }

    public function getDisplayName(): string
    {
        return 'CorpTools: Wallet';
    }

    public function getAvailableRules(): array
    {
        return [
            [
                'key' => 'wallet_balance_main',
                'label' => 'Main Character Wallet Balance',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Compare wallet balance for the main character.',
            ],
            [
                'key' => 'wallet_balance_any',
                'label' => 'Any Character Wallet Balance',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Compare the highest wallet balance across linked characters.',
            ],
        ];
    }

    public function evaluateRule(int $userId, array $rule, array $context = []): array
    {
        $ruleKey = (string)($rule['rule_key'] ?? '');
        $operator = (string)($rule['operator'] ?? '>=');
        $expected = is_numeric($rule['value'] ?? null) ? (float)$rule['value'] : 0.0;

        $mainId = (int)($context['main_character_id'] ?? 0);
        $altIds = $context['alt_character_ids'] ?? [];
        $characterIds = array_values(array_filter(array_merge([$mainId], is_array($altIds) ? $altIds : [])));

        if ($mainId <= 0) {
            return $this->unknown('Missing main character id.');
        }

        if ($ruleKey === 'wallet_balance_main') {
            $row = $this->db->one(
                "SELECT wallet_balance FROM module_corptools_character_summary WHERE character_id=? LIMIT 1",
                [$mainId]
            );
            if (!$row) {
                return $this->unknown('No wallet data for main character.');
            }
            $actual = (float)($row['wallet_balance'] ?? 0);
            return $this->result(RuleComparator::compare($operator, $actual, $expected), $actual, $expected);
        }

        if ($ruleKey === 'wallet_balance_any') {
            if (empty($characterIds)) {
                return $this->unknown('No linked characters found.');
            }
            $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
            $rows = $this->db->all(
                "SELECT wallet_balance FROM module_corptools_character_summary WHERE character_id IN ({$placeholders})",
                $characterIds
            );
            if (empty($rows)) {
                return $this->unknown('No wallet data for linked characters.');
            }
            $max = 0.0;
            foreach ($rows as $row) {
                $balance = (float)($row['wallet_balance'] ?? 0);
                if ($balance > $max) {
                    $max = $balance;
                }
            }
            return $this->result(RuleComparator::compare($operator, $max, $expected), $max, $expected);
        }

        return $this->unknown('Unknown rule key.');
    }

    private function result(bool $passed, float $actual, float $expected): array
    {
        return [
            'status' => $passed ? 'pass' : 'fail',
            'reason' => $passed ? 'Rule passed.' : 'Rule failed.',
            'actual' => $actual,
            'expected' => $expected,
            'evidence' => ['wallet_balance' => $actual],
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
