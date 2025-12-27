<?php
declare(strict_types=1);

namespace App\Securegroups\Providers;

use App\Core\Db;
use App\Core\IdentityResolver;
use App\Core\Universe;
use App\Securegroups\ProviderInterface;
use App\Securegroups\RuleComparator;

final class CorptoolsMemberProvider implements ProviderInterface
{
    public function __construct(private readonly Db $db) {}

    public function getKey(): string
    {
        return 'corptools.member';
    }

    public function getDisplayName(): string
    {
        return 'CorpTools: Member Summary';
    }

    public function getAvailableRules(): array
    {
        return [
            [
                'key' => 'corp_id',
                'label' => 'Corporation ID',
                'value_type' => 'number',
                'operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'help' => 'Match the member main corporation id.',
            ],
            [
                'key' => 'alliance_id',
                'label' => 'Alliance ID',
                'value_type' => 'number',
                'operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'help' => 'Match the member alliance id.',
            ],
            [
                'key' => 'highest_sp',
                'label' => 'Highest Skill Points',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Compare highest SP across linked characters.',
            ],
            [
                'key' => 'last_login_days',
                'label' => 'Days Since Last Login',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Days since the user last logged into the portal.',
            ],
            [
                'key' => 'corp_join_days',
                'label' => 'Days Since Corp Joined',
                'value_type' => 'number',
                'operators' => ['>=', '<=', '>', '<'],
                'help' => 'Days since main character joined their current corporation.',
            ],
            [
                'key' => 'audit_loaded',
                'label' => 'Audit Data Loaded',
                'value_type' => 'boolean',
                'operators' => ['equals'],
                'help' => 'Whether CorpTools audit data is available.',
            ],
            [
                'key' => 'main_character_name',
                'label' => 'Main Character Name',
                'value_type' => 'text',
                'operators' => ['contains', 'equals', 'not_contains'],
                'help' => 'Match against the main character name.',
            ],
        ];
    }

    public function evaluateRule(int $userId, array $rule, array $context = []): array
    {
        $summary = db_one($this->db, 
            "SELECT main_character_id, highest_sp, last_login_at, corp_joined_at, audit_loaded, main_character_name\n"
            . " FROM module_corptools_member_summary WHERE user_id=? LIMIT 1",
            [$userId]
        );

        if (!$summary) {
            return $this->unknown('No CorpTools member summary available.');
        }

        $identityResolver = new IdentityResolver($this->db, new Universe($this->db));
        $mainCharacterId = (int)($summary['main_character_id'] ?? 0);
        $org = $mainCharacterId > 0 ? $identityResolver->resolveCharacter($mainCharacterId) : [];
        $corpId = (int)($org['corp_id'] ?? 0);
        $allianceId = (int)($org['alliance_id'] ?? 0);

        $ruleKey = (string)($rule['rule_key'] ?? '');
        $operator = (string)($rule['operator'] ?? 'equals');
        $expected = $this->normalizeValue($rule['value'] ?? null, $ruleKey);

        return match ($ruleKey) {
            'corp_id' => $this->compareRule($operator, $corpId, $expected, 'corp_id'),
            'alliance_id' => $this->compareRule($operator, $allianceId, $expected, 'alliance_id'),
            'highest_sp' => $this->compareRule($operator, (int)($summary['highest_sp'] ?? 0), $expected, 'highest_sp'),
            'last_login_days' => $this->compareDaysSince($operator, $summary['last_login_at'] ?? null, $expected, 'last_login_at'),
            'corp_join_days' => $this->compareDaysSince($operator, $summary['corp_joined_at'] ?? null, $expected, 'corp_joined_at'),
            'audit_loaded' => $this->compareRule($operator, (int)($summary['audit_loaded'] ?? 0), (int)$expected, 'audit_loaded'),
            'main_character_name' => $this->compareRule($operator, (string)($summary['main_character_name'] ?? ''), (string)$expected, 'main_character_name'),
            default => $this->unknown('Unknown rule key.'),
        };
    }

    private function compareDaysSince(string $operator, ?string $timestamp, mixed $expected, string $field): array
    {
        if (!$timestamp) {
            return $this->unknown("Missing {$field} data.");
        }
        $actualDays = (int)floor((time() - strtotime($timestamp)) / 86400);
        $passed = RuleComparator::compare($operator, $actualDays, (int)$expected);
        return $this->result($passed, [
            'actual' => $actualDays,
            'expected' => (int)$expected,
            'field' => $field,
        ]);
    }

    private function compareRule(string $operator, mixed $actual, mixed $expected, string $field): array
    {
        $passed = RuleComparator::compare($operator, $actual, $expected);
        return $this->result($passed, [
            'actual' => $actual,
            'expected' => $expected,
            'field' => $field,
        ]);
    }

    private function normalizeValue(mixed $value, string $ruleKey): mixed
    {
        if ($value === null) return null;
        return match ($ruleKey) {
            'main_character_name' => (string)$value,
            'audit_loaded' => in_array((string)$value, ['1', 'true', 'yes'], true) ? 1 : 0,
            default => is_numeric($value) ? (int)$value : $value,
        };
    }

    private function result(bool $passed, array $evidence): array
    {
        return [
            'status' => $passed ? 'pass' : 'fail',
            'reason' => $passed ? 'Rule passed.' : 'Rule failed.',
            'actual' => $evidence['actual'] ?? null,
            'expected' => $evidence['expected'] ?? null,
            'evidence' => $evidence,
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
