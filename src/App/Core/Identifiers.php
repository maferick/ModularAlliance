<?php
declare(strict_types=1);

namespace App\Core;

final class Identifiers
{
    public static function generatePublicId(Db $db, string $table, string $column = 'public_id', int $bytes = 8): string
    {
        $attempts = 0;
        do {
            $candidate = bin2hex(random_bytes($bytes));
            $row = $db->one("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1", [$candidate]);
            $attempts++;
        } while ($row && $attempts < 10);

        return $candidate;
    }

    public static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        $text = trim((string)$text, '-');
        return $text !== '' ? $text : 'item';
    }

    public static function generateSlug(Db $db, string $table, string $column, string $text): string
    {
        $base = self::slugify($text);
        $candidate = $base;
        $attempts = 0;

        while ($attempts < 10) {
            $row = $db->one("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1", [$candidate]);
            if (!$row) {
                return $candidate;
            }
            $candidate = $base . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $attempts++;
        }

        return $candidate;
    }
}
