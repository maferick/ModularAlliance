<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<string, array{handler:callable, meta:array}>> */
    private array $routes = [];

    /** @var array<string, array<int, array{pattern:string, params:array, handler:callable, meta:array}>> */
    private array $dynamicRoutes = [];

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
        $this->register('GET', $path, $handler, $meta);
    }

    public function post(string $path, callable $handler, array $meta = []): void
    {
        $this->register('POST', $path, $handler, $meta);
    }

    public function dispatch(Request $req): Response
    {
        $m = $req->method;
        $p = $this->norm($req->path);

        $route = $this->routes[$m][$p] ?? null;
        if (!$route && !empty($this->dynamicRoutes[$m])) {
            foreach ($this->dynamicRoutes[$m] as $candidate) {
                if (preg_match($candidate['pattern'], $p, $matches)) {
                    $params = [];
                    foreach ($candidate['params'] as $param) {
                        if (isset($matches[$param])) {
                            $params[$param] = $matches[$param];
                        }
                    }
                    $route = $candidate;
                    $req = new Request($req->method, $req->path, $req->query, $req->post, $req->server, $params);
                    break;
                }
            }
        }
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

    private function register(string $method, string $path, callable $handler, array $meta): void
    {
        $path = $this->norm($path);
        if (str_contains($path, '{')) {
            [$pattern, $params] = $this->compileDynamicPath($path);
            $this->dynamicRoutes[$method][] = [
                'pattern' => $pattern,
                'params' => $params,
                'handler' => $handler,
                'meta' => $meta,
            ];
            return;
        }

        $this->routes[$method][$path] = ['handler' => $handler, 'meta' => $meta];
    }

    private function norm(string $p): string
    {
        $p = '/' . ltrim($p, '/');
        if ($p !== '/' && str_ends_with($p, '/')) $p = rtrim($p, '/');
        return $p;
    }

    /** @return array{string, array<int, string>} */
    private function compileDynamicPath(string $path): array
    {
        $params = [];
        $segments = explode('/', trim($path, '/'));
        $patternParts = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $segment, $matches)) {
                $params[] = $matches[1];
                $patternParts[] = '(?P<' . $matches[1] . '>[^/]+)';
            } else {
                $patternParts[] = preg_quote($segment, '#');
            }
        }

        $pattern = '#^/' . implode('/', $patternParts) . '$#';
        return [$pattern, $params];
    }
}
