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

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Db::fromConfig($config['db'] ?? []);
        $this->migrator = new Migrator($this->db);
        $this->router = new Router();
        $this->modules = new ModuleManager();
    }

    public static function boot(): self
    {
        $cfg = \app_config();
        $app = new self($cfg);

        // Core routes that MUST always exist
        $app->router->get('/', fn() => Response::html("<h1>ModularAlliance is up</h1>", 200));
        $app->router->get('/health', fn() => Response::text("OK\n", 200));

        // Load modules (auth, etc.)
        $app->modules->loadAll($app);

        return $app;
    }

    public function handleHttp(): Response
    {
        $req = Request::fromGlobals();
        return $this->router->dispatch($req);
    }
}
