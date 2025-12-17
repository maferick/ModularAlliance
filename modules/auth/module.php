<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Core\Db;

return [
    'slug' => 'auth',
    'name' => 'Authentication (EVE SSO)',
    'version' => '0.1.0',

    'register' => function (Router $router, Db $db): void {
        $router->get('/auth/login', function (Request $r) use ($db): Response {
            // Placeholder: real SSO flow will be implemented next step.
            return Response::html('<h1>Auth Login</h1><p>SSO flow not implemented yet.</p>');
        });

        $router->get('/auth/callback', function (Request $r) use ($db): Response {
            return Response::html('<h1>Auth Callback</h1><p>SSO callback handler not implemented yet.</p>');
        });

        $router->get('/auth/logout', function (Request $r) use ($db): Response {
            session_destroy();
            return Response::redirect('/');
        });
    },
];
