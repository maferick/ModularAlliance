<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;

$app = App::boot();

$m = $app->migrator;
$m->ensureLogTable();

echo "[MIGRATE] core: " . APP_ROOT . "/core/migrations\n";
$m->applyDir('core', APP_ROOT . '/core/migrations');

echo "[OK] Migrations complete.\n";
