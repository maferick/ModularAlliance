<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

final class App
{
    public readonly array $config;
    public readonly Db $db;
    public readonly Migrator $migrator;
    public readonly Router $router;
    public readonly ModuleManager $modules;
    public readonly Menu $menu;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Db::fromConfig($config['db'] ?? []);
        $this->migrator = new Migrator($this->db);
        $this->router = new Router();
        $this->modules = new ModuleManager();
        $this->menu = new Menu($this->db);
    }

    public static function boot(): self
    {
        $cfg = \app_config();
        $app = new self($cfg);

        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        // Core menu defaults (idempotent)
        $app->menu->register(['slug'=>'home','title'=>'Dashboard','url'=>'/','sort_order'=>10,'area'=>'left']);
        $app->menu->register(['slug'=>'profile','title'=>'Profile','url'=>'/me','sort_order'=>20,'area'=>'left']);

        $app->menu->register(['slug'=>'admin.root','title'=>'Admin Home','url'=>'/admin','sort_order'=>10,'area'=>'admin_top','right_slug'=>'admin.access']);
        $app->menu->register(['slug'=>'admin.cache','title'=>'ESI Cache','url'=>'/admin/cache','sort_order'=>20,'area'=>'admin_top','right_slug'=>'admin.cache']);
        $app->menu->register(['slug'=>'admin.users','title'=>'Users & Groups','url'=>'/admin/users','sort_order'=>30,'area'=>'admin_top','right_slug'=>'admin.users']);
        $app->menu->register(['slug'=>'admin.menu','title'=>'Menu Editor','url'=>'/admin/menu','sort_order'=>40,'area'=>'admin_top','right_slug'=>'admin.menu']);

        // Routes
        $app->router->get('/health', fn() => Response::text("OK\n", 200));

        $app->router->get('/', function () use ($app, $hasRight): Response {
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

        // User alts placeholder
        $app->router->get('/user/alts', function () use ($app, $hasRight): Response {
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) return Response::redirect('/auth/login');

            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

            $body = "<h1>Linked Characters</h1>
                     <p>This will allow linking multiple EVE characters (alts) to one account.</p>";

            return Response::html(Layout::page('Linked Characters', $body, $leftTree, $adminTree, $userTree), 200);
        });

        // Admin placeholders
        $app->router->get('/admin', fn() => Response::text("Admin Home (next)\n", 200));
        $app->router->get('/admin/cache', fn() => Response::text("Cache console (next)\n", 200));
        $app->router->get('/admin/users', fn() => Response::text("Users & Groups (next)\n", 200));
        $app->router->get('/admin/menu', fn() => Response::text("Menu editor (next)\n", 200));

        $app->modules->loadAll($app);
        return $app;
    }

    public function handleHttp(): Response
    {
        $req = Request::fromGlobals();
        return $this->router->dispatch($req);
    }
}
