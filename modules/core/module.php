<?php
declare(strict_types=1);

/*
Module Name: Core
Description: Core routes, menus, and rights.
Version: 1.0.0
Module Slug: core
*/

use App\Core\AdminRoutes;
use App\Core\App;
use App\Core\IdentityResolver;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\SdeImporter;
use App\Core\Universe;
use App\Corptools\Cron\JobRegistry;
use App\Http\Response;

require_once APP_ROOT . '/src/App/Core/functiondb.php';
require_once __DIR__ . '/functions.php';

return function (ModuleRegistry $registry): void {
    $app = $registry->app();
    $universeShared = new Universe($app->db);
    $identityResolver = new IdentityResolver($app->db, $universeShared);

    $registry->right('admin.access', 'Access admin dashboard');
    $registry->right('admin.settings', 'Manage site settings');
    $registry->right('admin.cache', 'Manage cache');
    $registry->right('admin.rights', 'Manage rights & groups');
    $registry->right('admin.users', 'Manage users & groups');
    $registry->right('admin.menu', 'Edit menu overrides');

    $registry->menu(['slug' => 'home', 'title' => 'Dashboard', 'url' => '/', 'sort_order' => 10, 'area' => 'left_member']);
    $registry->menu(['slug' => 'profile', 'title' => 'Profile', 'url' => '/me', 'sort_order' => 20, 'area' => 'left_member']);

    $registry->menu(['slug' => 'admin.root', 'title' => 'Admin Home', 'url' => '/admin', 'sort_order' => 10, 'area' => 'site_admin_top', 'right_slug' => 'admin.access']);
    $registry->menu(['slug' => 'admin.settings', 'title' => 'Settings', 'url' => '/admin/settings', 'sort_order' => 15, 'area' => 'site_admin_top', 'right_slug' => 'admin.settings']);
    $registry->menu(['slug' => 'admin.cache', 'title' => 'ESI Cache', 'url' => '/admin/cache', 'sort_order' => 20, 'area' => 'site_admin_top', 'right_slug' => 'admin.cache']);
    $registry->menu(['slug' => 'admin.rights', 'title' => 'Rights', 'url' => '/admin/rights', 'sort_order' => 25, 'area' => 'site_admin_top', 'right_slug' => 'admin.rights']);
    $registry->menu(['slug' => 'admin.users', 'title' => 'Users & Groups', 'url' => '/admin/users', 'sort_order' => 30, 'area' => 'site_admin_top', 'right_slug' => 'admin.users']);
    $registry->menu(['slug' => 'admin.menu', 'title' => 'Menu Editor', 'url' => '/admin/menu', 'sort_order' => 40, 'area' => 'site_admin_top', 'right_slug' => 'admin.menu']);

    $rights = new Rights($app->db);
    $hasRight = function (string $right) use ($rights): bool {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return false;
        return $rights->userHasRight($uid, $right);
    };

    $registry->route('GET', '/health', fn() => Response::text("OK\n", 200), ['public' => true]);

    JobRegistry::register([
        'key' => 'core.sde_refresh',
        'name' => 'SDE Refresh',
        'description' => 'Refresh local SDE tables from Fuzzwork.',
        'schedule' => 86400,
        'handler' => function (App $app, array $context = []): array {
            $importer = new SdeImporter($app->db);
            return $importer->refresh($context);
        },
    ]);
    JobRegistry::register([
        'key' => 'universe.repair_unknowns',
        'name' => 'Universe Repair Unknowns',
        'description' => 'Refresh missing or unknown universe entity names via SDE/ESI.',
        'schedule' => 3600,
        'handler' => function (App $app, array $context = []): array {
            $limit = (int)($context['limit'] ?? 200);
            $universe = new Universe($app->db);
            $result = $universe->repairUnknowns($limit);
            return [
                'message' => "Universe repair complete. Repaired {$result['repaired']} of {$result['attempted']} entities.",
                'metrics' => $result,
            ];
        },
    ]);
    JobRegistry::sync($app->db);

    $registry->route('GET', '/', function () use ($app, $hasRight, $universeShared, $identityResolver): Response {
        $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
        $menus = $app->menu->layoutMenus($_SERVER['REQUEST_URI'] ?? '/', $hasRight, $loggedIn);

        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid <= 0) {
            $body = "<h1>Dashboard</h1>
                     <p>You are not logged in.</p>
                     <p><a href='/auth/login'>Login with EVE SSO</a></p>";
            return Response::html(Layout::page('Dashboard', $body, $menus['left_member'], $menus['left_admin'], $menus['site_admin'], $menus['user'], $menus['module']), 200);
        }

        $p = $universeShared->characterProfile($cid);
        $org = $identityResolver->resolveCharacter($cid);
        $orgLabels = core_module_org_labels($org);

        $char = htmlspecialchars($p['character']['name'] ?? 'Unknown');
        $corp = htmlspecialchars($orgLabels['corp']);
        $all  = htmlspecialchars($orgLabels['alliance']);

        $body = "<h1>Dashboard</h1>
                 <p>Welcome back, <strong>{$char}</strong>.</p>
                 <p>Corporation: <strong>{$corp}</strong></p>
                 <p>Alliance: <strong>{$all}</strong></p>";

        return Response::html(Layout::page('Dashboard', $body, $menus['left_member'], $menus['left_admin'], $menus['site_admin'], $menus['user'], $menus['module']), 200);
    });

    AdminRoutes::register($app, $registry, $hasRight);
};
