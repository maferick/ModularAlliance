<?php
declare(strict_types=1);

namespace App\Core;

final class AccessLog
{
    public static function write(array $entry): void
    {
        $path = rtrim((string)APP_ROOT, '/') . '/logs/access.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!isset($entry['ts'])) {
            $entry['ts'] = gmdate('c');
        }
        if (!isset($entry['ip'])) {
            $entry['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        if (!isset($entry['user_id'])) {
            $entry['user_id'] = (int)($_SESSION['user_id'] ?? 0);
        }
        if (!isset($entry['character_id'])) {
            $entry['character_id'] = (int)($_SESSION['character_id'] ?? 0);
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = '{"ts":"' . gmdate('c') . '","error":"json_encode_failed"}';
        }

        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
