<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;
use App\Core\MigrationCatalog;

$app = App::boot(false);

$m = $app->migrator;
$m->ensureLogTable();

function show_help(): void
{
    echo <<<TXT
[USAGE] php bin/migrate.php <command>

Commands:
  apply                Apply pending migrations (default).
  status               Show applied/pending/mismatch status.
  doctor               Show mismatches and suggested fix statements.
  repair --accept-checksum <module> <file>
                       Accept updated checksum for an applied migration.
  recreate             Drop all tables and re-apply latest migrations.
  help                 Show this help text.

Examples:
  php bin/migrate.php apply
  php bin/migrate.php status
  php bin/migrate.php doctor
  php bin/migrate.php repair --accept-checksum core 001_init.sql
  php bin/migrate.php recreate
TXT;
}

$command = strtolower((string)($argv[1] ?? 'apply'));

if (in_array($command, ['help', '--help', '-h'], true)) {
    show_help();
    exit(0);
}

if ($command === 'status') {
    $applied = $m->appliedMigrations();
    $entries = MigrationCatalog::migrationEntries();
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
    $entries = MigrationCatalog::migrationEntries();
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

    $path = MigrationCatalog::resolveMigrationFile($module, $file);
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

if ($command === 'recreate') {
    $result = $m->recreateDatabase();
    if (!($result['ok'] ?? false)) {
        echo "[ERROR] {$result['message']}\n";
        exit(1);
    }
    echo "[OK] {$result['message']}\n";
    exit(0);
}

echo "[MIGRATE] core: " . APP_ROOT . "/core/migrations\n";
$m->applyDir('core', APP_ROOT . '/core/migrations');

foreach (MigrationCatalog::migrationDirs() as [$slug, $dir]) {
    if ($slug === 'core') {
        continue;
    }
    echo "[MIGRATE] {$slug}: {$dir}\n";
    $m->applyDir($slug, $dir);
}

echo "[OK] Migrations complete.\n";
