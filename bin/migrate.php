#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Migrator;
use App\Core\ModuleManager;

require __DIR__ . '/../core/bootstrap.php';

$config = (array)($GLOBALS['APP_CONFIG'] ?? []);
$db = Db::fromConfig($config['db'] ?? []);

$migrator = new Migrator($db);
$migrator->ensureLogTable();

$mm = new ModuleManager(APP_ROOT . '/modules', $db);

// Apply core then modules
$dirs = $mm->migrationDirs();

foreach ($dirs as $slug => $dir) {
    fwrite(STDOUT, "[MIGRATE] {$slug}: {$dir}
");
    $migrator->applyDir($slug, $dir);
}

fwrite(STDOUT, "[OK] Migrations complete.
");
