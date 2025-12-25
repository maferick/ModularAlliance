<?php
declare(strict_types=1);

namespace App\Securegroups;

final class RuleComparator
{
    public static function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'contains' => self::contains($actual, $expected),
            'not_contains' => !self::contains($actual, $expected),
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            'in' => self::inList($actual, $expected),
            'not_in' => !self::inList($actual, $expected),
            'before' => self::asTimestamp($actual) < self::asTimestamp($expected),
            'after' => self::asTimestamp($actual) > self::asTimestamp($expected),
            default => false,
        };
    }

    private static function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, true);
        }
        return str_contains((string)$actual, (string)$expected);
    }

    private static function inList(mixed $actual, mixed $expected): bool
    {
        $list = is_array($expected) ? $expected : self::parseList((string)$expected);
        foreach ($list as $item) {
            if ($actual == $item) return true;
        }
        return false;
    }

    /** @return array<int, string> */
    private static function parseList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_map('strval', $decoded);
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    }

    private static function asTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime((string)$value);
        return $ts !== false ? $ts : 0;
    }
}
