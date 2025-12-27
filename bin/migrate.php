<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;

$app = App::boot(false);

$m = $app->migrator;
$m->ensureLogTable();

function migration_dirs(): array
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

function resolve_migration_file(string $module, string $file): ?string
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

function migration_entries(): array
{
    $entries = [];
    foreach (migration_dirs() as [$module, $dir]) {
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

$command = $argv[1] ?? 'apply';

if ($command === 'status') {
    $applied = $m->appliedMigrations();
    $entries = migration_entries();
    $counts = ['applied' => 0, 'pending' => 0, 'mismatch' => 0];

    echo "[STATUS] Migration status\n";
    foreach ($entries as $entry) {
        $key = $entry['module'] . '::' . $entry['path'];
        if (!isset($applied[$key])) {
            $counts['pending']++;
            echo "[PENDING] {$entry['module']}: {$entry['path']}\n";
            continue;
        }

        $appliedChecksum = $applied[$key]['checksum'];
        if ($appliedChecksum === $entry['checksum']) {
            $counts['applied']++;
            echo "[APPLIED] {$entry['module']}: {$entry['path']}\n";
            continue;
        }

        $counts['mismatch']++;
        $m->logMismatch($entry['module'], $entry['path'], $entry['checksum'], $appliedChecksum);
        echo "[MISMATCH] {$entry['module']}: {$entry['path']} (applied {$appliedChecksum}, current {$entry['checksum']})\n";
    }

    echo "[SUMMARY] applied={$counts['applied']} pending={$counts['pending']} mismatch={$counts['mismatch']}\n";
    exit(0);
}

if ($command === 'doctor') {
    $applied = $m->appliedMigrations();
    $entries = migration_entries();
    $hasMismatch = false;

    echo "[DOCTOR] Checking for migration mismatches\n";

    foreach ($entries as $entry) {
        $key = $entry['module'] . '::' . $entry['path'];
        if (!isset($applied[$key])) {
            continue;
        }

        $appliedChecksum = $applied[$key]['checksum'];
        if ($appliedChecksum === $entry['checksum']) {
            continue;
        }

        $hasMismatch = true;
        $m->logMismatch($entry['module'], $entry['path'], $entry['checksum'], $appliedChecksum);
        echo "[MISMATCH] {$entry['module']}: {$entry['path']} (applied {$appliedChecksum}, current {$entry['checksum']})\n";

        $diffs = $m->schemaDiffStatements($entry['full']);
        if ($diffs === []) {
            echo "  [MATCH] Schema already matches current migration contents.\n";
            echo "  [HINT] Run: php bin/migrate.php repair --accept-checksum {$entry['module']} {$entry['path']}\n";
            continue;
        }

        echo "  [SUGGEST] Apply the following statements as a new migration:\n";
        foreach ($diffs as $statement) {
            echo "    {$statement}\n";
        }
    }

    if (!$hasMismatch) {
        echo "[OK] No mismatches detected.\n";
    }
    exit(0);
}

if ($command === 'repair') {
    $flag = $argv[2] ?? '';
    $module = $argv[3] ?? '';
    $file = $argv[4] ?? '';

    if ($flag !== '--accept-checksum' || $module === '' || $file === '') {
        echo "[ERROR] Usage: php bin/migrate.php repair --accept-checksum <module> <file>\n";
        exit(1);
    }

    $path = resolve_migration_file($module, $file);
    if ($path === null) {
        echo "[ERROR] Migration file not found for module {$module}: {$file}\n";
        exit(1);
    }

    $result = $m->repairChecksum($module, $path);
    if (!($result['ok'] ?? false)) {
        echo "[ERROR] {$result['message']}\n";
        if (!empty($result['diffs'])) {
            foreach ($result['diffs'] as $statement) {
                echo "  {$statement}\n";
            }
        }
        exit(1);
    }

    echo "[OK] {$result['message']}\n";
    exit(0);
}

echo "[MIGRATE] core: " . APP_ROOT . "/core/migrations\n";
$m->applyDir('core', APP_ROOT . '/core/migrations');

foreach (migration_dirs() as [$slug, $dir]) {
    if ($slug === 'core') {
        continue;
    }
    echo "[MIGRATE] {$slug}: {$dir}\n";
    $m->applyDir($slug, $dir);
}

echo "[OK] Migrations complete.\n";
