<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;

$app = App::boot();
$response = $app->handleHttp();
$response->send();
