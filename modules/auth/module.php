<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\EveSso;
use App\Http\Request;
use App\Http\Response;

return function (App $app): void {

    $app->router->get('/auth/login', function () use ($app): Response {
        $cfg = $app->config['eve_sso'] ?? [];
        $sso = new EveSso($app->db, $cfg);

        $url = $sso->beginLogin();
        return Response::redirect($url);
    });

    $app->router->get('/auth/callback', function (Request $req) use ($app): Response {
        $code  = $req->query['code']  ?? null;
        $state = $req->query['state'] ?? null;

        if (!is_string($code) || $code === '' || !is_string($state) || $state === '') {
            return Response::text("Missing code/state\n", 400);
        }

        try {
            $cfg = $app->config['eve_sso'] ?? [];
            $sso = new EveSso($app->db, $cfg);
            $result = $sso->handleCallback($code, $state);

            return Response::html(
                "<h1>SSO OK</h1>" .
                "<p>Character: " . htmlspecialchars($result['character_name']) . " (" . (int)$result['character_id'] . ")</p>" .
                "<p><a href='/'>Continue</a></p>",
                200
            );
        } catch (\Throwable $e) {
            return Response::text("SSO failed: " . $e->getMessage() . "\n", 500);
        }
    });

    $app->router->get('/auth/logout', function (): Response {
        session_destroy();
        return Response::redirect('/');
    });
};
