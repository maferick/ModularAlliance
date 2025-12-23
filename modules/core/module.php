<?php
declare(strict_types=1);

/*
Module Name: Core
Description: Core routes, menus, and rights.
Version: 1.0.0
Module Slug: core
*/

use App\Core\AdminRoutes;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Universe;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('admin.access', 'Access admin dashboard');
    $registry->right('admin.settings', 'Manage site settings');
    $registry->right('admin.cache', 'Manage cache');
    $registry->right('admin.rights', 'Manage rights & groups');
    $registry->right('admin.users', 'Manage users & groups');
    $registry->right('admin.menu', 'Edit menu overrides');

    $registry->menu(['slug' => 'home', 'title' => 'Dashboard', 'url' => '/', 'sort_order' => 10, 'area' => 'left']);
    $registry->menu(['slug' => 'profile', 'title' => 'Profile', 'url' => '/me', 'sort_order' => 20, 'area' => 'left']);

    $registry->menu(['slug' => 'admin.root', 'title' => 'Admin Home', 'url' => '/admin', 'sort_order' => 10, 'area' => 'admin_top', 'right_slug' => 'admin.access']);
    $registry->menu(['slug' => 'admin.settings', 'title' => 'Settings', 'url' => '/admin/settings', 'sort_order' => 15, 'area' => 'admin_top', 'right_slug' => 'admin.settings']);
    $registry->menu(['slug' => 'admin.cache', 'title' => 'ESI Cache', 'url' => '/admin/cache', 'sort_order' => 20, 'area' => 'admin_top', 'right_slug' => 'admin.cache']);
    $registry->menu(['slug' => 'admin.rights', 'title' => 'Rights', 'url' => '/admin/rights', 'sort_order' => 25, 'area' => 'admin_top', 'right_slug' => 'admin.rights']);
    $registry->menu(['slug' => 'admin.users', 'title' => 'Users & Groups', 'url' => '/admin/users', 'sort_order' => 30, 'area' => 'admin_top', 'right_slug' => 'admin.users']);
    $registry->menu(['slug' => 'admin.menu', 'title' => 'Menu Editor', 'url' => '/admin/menu', 'sort_order' => 40, 'area' => 'admin_top', 'right_slug' => 'admin.menu']);

    $rights = new Rights($app->db);
    $hasRight = function (string $right) use ($rights): bool {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return false;
        return $rights->userHasRight($uid, $right);
    };

    $registry->route('GET', '/health', fn() => Response::text("OK\n", 200), ['public' => true]);

    $registry->route('GET', '/', function () use ($app, $hasRight): Response {
        $leftTree  = $app->menu->tree('left', $hasRight);
        $adminTree = $app->menu->tree('admin_top', $hasRight);
        $userTree  = $app->menu->tree('user_top', fn(string $r) => true);

        $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
        if ($loggedIn) {
            $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
        } else {
            $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
        }

        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid <= 0) {
            $body = "<h1>Dashboard</h1>
                     <p>You are not logged in.</p>
                     <p><a href='/auth/login'>Login with EVE SSO</a></p>";
            return Response::html(Layout::page('Dashboard', $body, $leftTree, $adminTree, $userTree), 200);
        }

        $u = new Universe($app->db);
        $p = $u->characterProfile($cid);

        $char = htmlspecialchars($p['character']['name'] ?? 'Unknown');
        $corp = htmlspecialchars($p['corporation']['name'] ?? '—');
        $corpT = htmlspecialchars($p['corporation']['ticker'] ?? '');
        $all  = htmlspecialchars($p['alliance']['name'] ?? '—');
        $allT = htmlspecialchars($p['alliance']['ticker'] ?? '');

        $body = "<h1>Dashboard</h1>
                 <p>Welcome back, <strong>{$char}</strong>.</p>
                 <p>Corporation: <strong>{$corp}</strong>" . ($corpT !== '' ? " [{$corpT}]" : "") . "</p>
                 <p>Alliance: <strong>{$all}</strong>" . ($allT !== '' ? " [{$allT}]" : "") . "</p>";

        return Response::html(Layout::page('Dashboard', $body, $leftTree, $adminTree, $userTree), 200);
    });

    AdminRoutes::register($app, $registry, $hasRight);
};
