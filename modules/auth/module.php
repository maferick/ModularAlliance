<?php
declare(strict_types=1);

/*
Module Name: Authentication
Description: EVE SSO authentication and profile routes.
Version: 1.0.0
*/

use App\Core\EveSso;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Settings;
use App\Core\Universe;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->route('GET', '/auth/login', function () use ($app): Response {
        $cfg = $app->config['eve_sso'] ?? [];
        $sso = new EveSso($app->db, $cfg);

        $url = $sso->beginLogin();
        return Response::redirect($url);
    }, ['public' => true]);

    $registry->route('GET', '/auth/callback', function (Request $req) use ($app): Response {
        $code  = $req->query['code']  ?? null;
        $state = $req->query['state'] ?? null;

        if (!is_string($code) || $code === '' || !is_string($state) || $state === '') {
            return Response::text("Missing code/state\n", 400);
        }

        try {
            $cfg = $app->config['eve_sso'] ?? [];
            $sso = new EveSso($app->db, $cfg);
            $sso->handleCallback($code, $state);

            $redirect = $_SESSION['charlink_redirect'] ?? null;
            if (is_string($redirect) && $redirect !== '') {
                unset($_SESSION['charlink_redirect']);
                return Response::redirect($redirect);
            }

            // ✅ Directly land on dashboard
            return Response::redirect('/');
        } catch (\Throwable $e) {
            return Response::text("SSO failed: " . $e->getMessage() . "\n", 500);
        }
    }, ['public' => true]);

    $registry->route('GET', '/auth/logout', function (): Response {
        session_destroy();
        return Response::redirect('/');
    }, ['public' => true]);

    $registry->route('GET', '/me', function () use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid <= 0) return Response::redirect('/auth/login');

        $u = new Universe($app->db);
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
        if ($portrait) $html .= "<img src='" . htmlspecialchars($portrait) . "' width='96' height='96' style='border-radius:10px;'>";
        $html .= "<div><div style='font-size:22px;font-weight:700;'>{$charName}</div></div>";
        $html .= "</div>";

        $html .= "<h2>Corporation</h2>";
        $html .= "<div style='display:flex;gap:12px;align-items:center;margin:8px 0;'>";
        if ($corpIcon) $html .= "<img src='" . htmlspecialchars($corpIcon) . "' width='64' height='64' style='border-radius:10px;'>";
        $html .= "<div><div style='font-size:18px;font-weight:700;'>{$corpName}</div>";
        if ($corpTicker !== '') $html .= "<div style='color:#666;'>[{$corpTicker}]</div>";
        $html .= "</div></div>";

        $html .= "<h2>Alliance</h2>";
        $html .= "<div style='display:flex;gap:12px;align-items:center;margin:8px 0;'>";
        if ($allIcon) $html .= "<img src='" . htmlspecialchars($allIcon) . "' width='64' height='64' style='border-radius:10px;'>";
        $html .= "<div><div style='font-size:18px;font-weight:700;'>{$allName}</div>";
        if ($allTicker !== '') $html .= "<div style='color:#666;'>[{$allTicker}]</div>";
        $html .= "</div></div>";

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $rightsNote = '';
        $rightsRows = [];
        if ($uid > 0) {
            $isSuper = $app->db->one("SELECT 1 FROM eve_users WHERE id=? AND is_superadmin=1 LIMIT 1", [$uid]);
            $isAdmin = $app->db->one(
                "SELECT 1
                 FROM eve_user_groups ug
                 JOIN groups g ON g.id = ug.group_id
                 WHERE ug.user_id=? AND g.is_admin=1
                 LIMIT 1",
                [$uid]
            );

            if ($isSuper || $isAdmin) {
                $rightsNote = $isSuper ? 'Superadmin: all rights enabled.' : 'Admin group: all rights enabled.';
                $rightsRows = $app->db->all(
                    "SELECT slug, description
                     FROM rights
                     ORDER BY module_slug ASC, slug ASC"
                );
            } else {
                $rightsRows = $app->db->all(
                    "SELECT DISTINCT r.slug, r.description
                     FROM eve_user_groups ug
                     JOIN group_rights gr ON gr.group_id = ug.group_id
                     JOIN rights r ON r.id = gr.right_id
                     WHERE ug.user_id=?
                     ORDER BY r.module_slug ASC, r.slug ASC",
                    [$uid]
                );
            }
        }

        $html .= "<h2>Active Rights</h2>";
        if ($rightsNote !== '') {
            $html .= "<div class='text-muted'>" . htmlspecialchars($rightsNote) . "</div>";
        }

        if (!empty($rightsRows)) {
            $html .= "<ul style='margin-top:6px;'>";
            foreach ($rightsRows as $row) {
                $slug = htmlspecialchars((string)($row['slug'] ?? ''));
                $desc = htmlspecialchars((string)($row['description'] ?? ''));
                if ($desc !== '') {
                    $html .= "<li><code>{$slug}</code> – {$desc}</li>";
                } else {
                    $html .= "<li><code>{$slug}</code></li>";
                }
            }
            $html .= "</ul>";
        } else {
            $html .= "<div class='text-muted' style='margin-top:6px;'>No rights assigned.</div>";
        }

        // Rights gate (admin hard override is inside Rights)
        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        // Menus
        $leftTree  = $app->menu->tree('left', $hasRight);
        $adminTree = $app->menu->tree('admin_top', $hasRight);
        $userTree  = $app->menu->tree('user_top', fn(string $r) => true);

        // Logged in => hide "Login"
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

        // Brand (keep head consistent with admin)
        $settings = new Settings($app->db);

        $brandName = $settings->get('site.brand.name', 'killsineve.online') ?? 'killsineve.online';
        $type = $settings->get('site.identity.type', 'corporation') ?? 'corporation'; // corporation|alliance
        $id = (int)($settings->get('site.identity.id', '0') ?? '0');

        // If not configured, infer from logged-in character (best-effort)
        if ($id <= 0) {
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid > 0) {
                $u = new Universe($app->db);
                $p2 = $u->characterProfile($cid);
                if ($type === 'alliance' && !empty($p2['alliance']['id'])) {
                    $id = (int)$p2['alliance']['id'];
                    if ($brandName === 'killsineve.online' && !empty($p2['alliance']['name'])) {
                        $brandName = (string)$p2['alliance']['name'];
                    }
                } elseif (!empty($p2['corporation']['id'])) {
                    $id = (int)$p2['corporation']['id'];
                    if ($brandName === 'killsineve.online' && !empty($p2['corporation']['name'])) {
                        $brandName = (string)$p2['corporation']['name'];
                    }
                }
            }
        }

        $brand = htmlspecialchars($brandName);
        $html = "<div class='d-flex align-items-center gap-3 mb-4'>"
            . "<div><div class='text-muted'>Identity</div><div class='fs-4 fw-bold'>" . $brand . "</div></div>"
            . "</div>" . $html;

        return Response::html(\App\Core\Layout::page('Profile', $html, $leftTree, $adminTree, $userTree), 200);
    });
};
