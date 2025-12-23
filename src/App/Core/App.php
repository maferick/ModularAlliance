<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Core\AccessLog;

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

        // Global route guard: deny-by-default for routes declaring a required right
        $app->router->setGuard(function (Request $req, array $meta) use ($rights): ?Response {
            if (!empty($meta['public'])) return null;

            $need = $meta['right'] ?? null;
            if ($need) {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if ($uid <= 0) {
                    AccessLog::write([
                        'method' => $req->method,
                        'path' => $req->path,
                        'status' => 302,
                        'decision' => 'deny',
                        'reason' => 'login_required',
                        'right' => (string)$need,
                    ]);
                    return Response::redirect('/auth/login');
                }

                $hasRight = $rights->userHasRight($uid, (string)$need);
                AccessLog::write([
                    'method' => $req->method,
                    'path' => $req->path,
                    'status' => $hasRight ? 200 : 403,
                    'decision' => $hasRight ? 'allow' : 'deny',
                    'reason' => $hasRight ? 'right_granted' : 'missing_right',
                    'right' => (string)$need,
                ]);

                if (!$hasRight) {
                    return Response::text('403 Forbidden', 403);
                }
            }
            return null;
        });

        $app->modules->loadAll($app);
        return $app;
    }

    public function handleHttp(): Response
    {
        $req = Request::fromGlobals();
        return $this->router->dispatch($req);
    }
}
