<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Router;
use App\Http\Request;
use App\Http\Response;

final class App
{
    public readonly array $config;
    public readonly Db $db;
    public readonly Router $router;
    public readonly ModuleManager $modules;

    private function __construct(array $config, Db $db, Router $router, ModuleManager $modules)
    {
        $this->config  = $config;
        $this->db      = $db;
        $this->router  = $router;
        $this->modules = $modules;
    }

    public static function boot(): self
    {
        $config = (array)($GLOBALS['APP_CONFIG'] ?? []);
        $db     = Db::fromConfig($config['db'] ?? []);
        $router = new Router();

        $modules = new ModuleManager(APP_ROOT . '/modules', $db);
        $modules->registerAll($router);

        // Core health endpoint
        $router->get('/health', static fn(Request $r) => Response::json(['ok' => true, 'ts' => time()]));

        return new self($config, $db, $router, $modules);
    }

    public function handleHttp(): Response
    {
        $req = Request::fromGlobals();
        return $this->router->dispatch($req);
    }
}
