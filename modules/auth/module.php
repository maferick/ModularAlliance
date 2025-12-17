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
        
        $app->router->get('/me', function () use ($app): Response {
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) return Response::redirect('/auth/login');

            $u = new \App\Core\Universe($app->db);
            $p = $u->characterProfile($cid);

            $charName = htmlspecialchars($p['character']['name'] ?? 'Unknown');
            $portrait = $p['character']['portrait']['px256x256'] ?? $p['character']['portrait']['px128x128'] ?? null;

            $corpName = htmlspecialchars($p['corporation']['name'] ?? '—');
            $corpTicker = htmlspecialchars($p['corporation']['ticker'] ?? '');
            $corpIcon = $p['corporation']['icons']['px128x128'] ?? $p['corporation']['icons']['px64x64'] ?? null;

            $allName = htmlspecialchars($p['alliance']['name'] ?? '—');
            $allTicker = htmlspecialchars($p['alliance']['ticker'] ?? '');
            $allIcon = $p['alliance']['icons']['px128x128'] ?? $p['alliance']['icons']['px64x64'] ?? null;

            $html = "<h1>Profile</h1>";

            $html .= "<div style='display:flex;gap:16px;align-items:center;margin:12px 0;'>";
            if ($portrait) $html .= "<img src='".htmlspecialchars($portrait)."' width='96' height='96' style='border-radius:10px;'>";
            $html .= "<div><div style='font-size:22px;font-weight:700;'>{$charName}</div></div>";
            $html .= "</div>";

            $html .= "<h2>Corporation</h2>";
            $html .= "<div style='display:flex;gap:12px;align-items:center;margin:8px 0;'>";
            if ($corpIcon) $html .= "<img src='".htmlspecialchars($corpIcon)."' width='64' height='64' style='border-radius:10px;'>";
            $html .= "<div><div style='font-size:18px;font-weight:700;'>{$corpName}</div>";
            if ($corpTicker !== '') $html .= "<div style='color:#666;'>[{$corpTicker}]</div>";
            $html .= "</div></div>";

            $html .= "<h2>Alliance</h2>";
            $html .= "<div style='display:flex;gap:12px;align-items:center;margin:8px 0;'>";
            if ($allIcon) $html .= "<img src='".htmlspecialchars($allIcon)."' width='64' height='64' style='border-radius:10px;'>";
            $html .= "<div><div style='font-size:18px;font-weight:700;'>{$allName}</div>";
            if ($allTicker !== '') $html .= "<div style='color:#666;'>[{$allTicker}]</div>";
            $html .= "</div></div>";

            return Response::html($html, 200);
        });


};
