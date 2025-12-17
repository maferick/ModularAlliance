<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$this->norm($path)] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$this->norm($path)] = $handler;
    }

    public function dispatch(Request $req): Response
    {
        $m = $req->method;
        $p = $this->norm($req->path);

        $handler = $this->routes[$m][$p] ?? null;
        if (!$handler) {
            return Response::text("Not Found", 404);
        }

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
