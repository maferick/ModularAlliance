<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;

$app = App::boot(false);

$m = $app->migrator;
$m->ensureLogTable();

echo "[MIGRATE] core: " . APP_ROOT . "/core/migrations\n";
$m->applyDir('core', APP_ROOT . '/core/migrations');

foreach (glob(APP_ROOT . '/modules/*/migrations') ?: [] as $dir) {
    if (!is_dir($dir)) continue;
    $slug = basename(dirname($dir));
    echo "[MIGRATE] {$slug}: {$dir}\n";
    $m->applyDir($slug, $dir);
}

echo "[OK] Migrations complete.\n";
