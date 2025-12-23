<?php
declare(strict_types=1);

namespace App\Corptools\Cron;

use App\Core\Db;

final class JobRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $definitions = [];

    /** @param array<string, mixed> $definition */
    public static function register(array $definition): void
    {
        $key = (string)($definition['key'] ?? '');
        if ($key === '') {
            return;
        }
        self::$definitions[$key] = $definition;
    }

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_values(self::$definitions);
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitionsByKey(): array
    {
        return self::$definitions;
    }

    public static function sync(Db $db): void
    {
        if (!self::tablesReady($db)) {
            return;
        }

        foreach (self::$definitions as $definition) {
            $key = (string)($definition['key'] ?? '');
            $name = (string)($definition['name'] ?? $key);
            $description = (string)($definition['description'] ?? '');
            $schedule = (int)($definition['schedule'] ?? 60);
            $enabled = (int)($definition['enabled'] ?? 1);

            if ($key === '' || $schedule <= 0) {
                continue;
            }

            $db->run(
                "INSERT INTO module_corptools_jobs\n"
                . " (job_key, name, description, schedule_seconds, is_enabled, last_status, next_run_at)\n"
                . " VALUES (?, ?, ?, ?, ?, 'never', NOW())\n"
                . " ON DUPLICATE KEY UPDATE\n"
                . " name=VALUES(name), description=VALUES(description), schedule_seconds=VALUES(schedule_seconds)",
                [$key, $name, $description, $schedule, $enabled]
            );
        }
    }

    private static function tablesReady(Db $db): bool
    {
        $row = $db->one("SHOW TABLES LIKE 'module_corptools_jobs'");
        return $row !== null;
    }
}
