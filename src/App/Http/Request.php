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
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path === '') $path = '/';

        // normalize /index.php => /
        if ($path === '/index.php') $path = '/';

        // legacy ?route= override
        if (!empty($_GET['route']) && is_string($_GET['route'])) {
            $path = '/' . ltrim($_GET['route'], '/');
        }

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }
}
