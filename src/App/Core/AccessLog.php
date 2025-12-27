<?php
declare(strict_types=1);

namespace App\Core;

final class AccessLog
{
    private static ?Db $db = null;
    private static bool $dbFailed = false;

    public static function write(array $entry): void
    {
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

        $contextJson = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($contextJson === false) {
            $contextJson = '{"ts":"' . gmdate('c') . '","error":"json_encode_failed"}';
        }

        $db = self::db();
        if ($db) {
            $userId = (int)($entry['user_id'] ?? 0);
            $characterId = (int)($entry['character_id'] ?? 0);
            $status = isset($entry['status']) ? (int)$entry['status'] : null;
            $decision = isset($entry['decision']) ? (string)$entry['decision'] : null;
            $reason = isset($entry['reason']) ? (string)$entry['reason'] : null;
            $method = isset($entry['method']) ? (string)$entry['method'] : null;
            $path = isset($entry['path']) ? (string)$entry['path'] : null;
            $ip = isset($entry['ip']) ? (string)$entry['ip'] : null;

            $userId = $userId > 0 ? $userId : null;
            $characterId = $characterId > 0 ? $characterId : null;
            $status = $status !== null && $status > 0 ? $status : null;
            $ip = $ip !== '' ? $ip : null;

            try {
                db_exec($db, 
                    "INSERT INTO access_log (user_id, character_id, ip, method, path, status, decision, reason, context_json, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [
                        $userId,
                        $characterId,
                        $ip,
                        $method,
                        $path,
                        $status,
                        $decision,
                        $reason,
                        $contextJson,
                    ]
                );
                return;
            } catch (\Throwable $e) {
                self::$dbFailed = true;
            }
        }

        $path = rtrim((string)APP_ROOT, '/') . '/logs/access.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($path, $contextJson . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function db(): ?Db
    {
        if (self::$dbFailed) return null;
        if (self::$db) return self::$db;

        try {
            $cfg = app_config();
            $db = Db::fromConfig($cfg['db'] ?? []);
            self::$db = $db;
            return $db;
        } catch (\Throwable $e) {
            self::$dbFailed = true;
            return null;
        }
    }
}
