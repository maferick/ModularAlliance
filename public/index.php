<?php
declare(strict_types=1);

use App\Core\App;
use App\Http\Response;

require __DIR__ . '/../core/bootstrap.php';

$app = App::boot();
$response = $app->handleHttp();

if (!$response instanceof Response) {
    $response = Response::html('Internal error: invalid response', 500);
}

$response->send();
