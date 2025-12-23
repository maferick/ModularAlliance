<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;

final class ModuleRegistry
{
    /** @var array<int, array{method:string, path:string, handler:callable, meta:array}> */
    private array $routes = [];

    /** @var array<int, array<string, mixed>> */
    private array $manifestRoutes = [];

    /** @var array<int, array{slug:string, description:string}> */
    private array $rights = [];

    /** @var array<int, array<string, mixed>> */
    private array $menu = [];

    public function __construct(
        private readonly App $app,
        private string $slug,
        private string $name,
        private string $description,
        private string $version,
    ) {}

    public function app(): App
    {
        return $this->app;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function right(string $slug, string $description): void
    {
        if ($slug === '') return;
        $this->rights[] = ['slug' => $slug, 'description' => $description];
    }

    /** @param array<string, mixed> $item */
    public function menu(array $item): void
    {
        $this->menu[] = $item;
    }

    /**
     * @param callable(Request):Response $handler
     * @param array<string, mixed> $meta
     */
    public function route(string $method, string $path, callable $handler, array $meta = []): void
    {
        $method = strtoupper($method);
        $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler, 'meta' => $meta];

        $manifestRoute = ['method' => $method, 'path' => $path];
        if (isset($meta['right'])) $manifestRoute['right'] = $meta['right'];
        if (!empty($meta['public'])) $manifestRoute['public'] = true;
        $this->manifestRoutes[] = $manifestRoute;
    }

    /** @return array<int, array{method:string, path:string, handler:callable, meta:array}> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            slug: $this->slug,
            name: $this->name,
            description: $this->description,
            version: $this->version,
            rights: $this->rights,
            menu: $this->menu,
            routes: $this->manifestRoutes,
        );
    }
}
