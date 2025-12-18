<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<string, array{handler:callable, meta:array}>> */
    private array $routes = [];

    /** @var null|callable(Request,array):(?Response) */
    private $guard = null;

    /**
     * Set a global guard (middleware) for all routes.
     * Signature: function(Request $req, array $meta): ?Response
     */
    public function setGuard(callable $guard): void
    {
        $this->guard = $guard;
    }

    public function get(string $path, callable $handler, array $meta = []): void
    {
        $this->routes['GET'][$this->norm($path)] = ['handler' => $handler, 'meta' => $meta];
    }

    public function post(string $path, callable $handler, array $meta = []): void
    {
        $this->routes['POST'][$this->norm($path)] = ['handler' => $handler, 'meta' => $meta];
    }

    public function dispatch(Request $req): Response
    {
        $m = $req->method;
        $p = $this->norm($req->path);

        $route = $this->routes[$m][$p] ?? null;
        if (!$route) {
            return Response::text("Not Found", 404);
        }

        if ($this->guard) {
            $block = ($this->guard)($req, $route['meta'] ?? []);
            if ($block instanceof Response) return $block;
        }

        $handler = $route['handler'];

        $out = $handler($req);
        if ($out instanceof Response) return $out;

        // allow simple strings for fast iteration
        return Response::text((string)$out, 200);
    }

    private function norm(string $p): string
    {
        $p = '/' . ltrim($p, '/');
        if ($p !== '/' && str_ends_with($p, '/')) $p = rtrim($p, '/');
        return $p;
    }
}
