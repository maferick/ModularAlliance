<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server
    ) {}

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Also support legacy ?route= style
        if (!empty($_GET['route'])) {
            $route = (string)$_GET['route'];
            if ($route[0] !== '/') $route = '/' . $route;
            $path = $route;
        }

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }
}
