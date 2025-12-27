<?php
declare(strict_types=1);

namespace App\Core;

final class MigrationCatalog
{
    /** @return array<int, array{0:string,1:string}> */
    public static function migrationDirs(): array
    {
        $dirs = [
            ['core', APP_ROOT . '/core/migrations'],
        ];

        foreach (glob(APP_ROOT . '/modules/*/migrations') ?: [] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $slug = basename(dirname($dir));
            $dirs[] = [$slug, $dir];
        }

        return $dirs;
    }

    public static function resolveMigrationFile(string $module, string $file): ?string
    {
        $file = ltrim($file, '/');
        if (str_contains($file, '/')) {
            $path = APP_ROOT . '/' . $file;
        } else {
            $base = $module === 'core'
                ? APP_ROOT . '/core/migrations'
                : APP_ROOT . '/modules/' . $module . '/migrations';
            $path = $base . '/' . $file;
        }

        return is_file($path) ? $path : null;
    }

    /** @return array<int, array{module:string,path:string,full:string,checksum:string}> */
    public static function migrationEntries(): array
    {
        $entries = [];
        foreach (self::migrationDirs() as [$module, $dir]) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $sql = trim((string)file_get_contents($file));
                if ($sql === '') {
                    continue;
                }
                $entries[] = [
                    'module' => $module,
                    'path' => str_starts_with($file, APP_ROOT . '/') ? substr($file, strlen(APP_ROOT) + 1) : $file,
                    'full' => $file,
                    'checksum' => hash('sha256', $sql),
                ];
            }
        }
        return $entries;
    }
}
