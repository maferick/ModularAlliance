<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:callable}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][] = ['pattern' => $this->norm($path), 'handler' => $handler];
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][] = ['pattern' => $this->norm($path), 'handler' => $handler];
    }

    public function dispatch(Request $req): Response
    {
        $method = strtoupper($req->method);
        $path = $this->norm($req->path);

        foreach ($this->routes[$method] ?? [] as $r) {
            if ($r['pattern'] === $path) {
                $out = ($r['handler'])($req);
                return $out instanceof Response ? $out : Response::html('Handler did not return Response', 500);
            }
        }

        return Response::html('Not Found', 404);
    }

    private function norm(string $p): string
    {
        if ($p === '') return '/';
        if ($p[0] !== '/') $p = '/' . $p;
        // no trailing slash except root
        if (strlen($p) > 1) $p = rtrim($p, '/');
        return $p;
    }
}
