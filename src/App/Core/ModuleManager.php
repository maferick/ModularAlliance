<?php
declare(strict_types=1);

namespace App\Core;

final class ModuleManager
{
    private array $manifests = [];

    public function loadAll(App $app): void
    {
        $dir = APP_ROOT . '/modules';
        if (!is_dir($dir)) return;

        $disabled = [];
        try {
            $settings = new Settings($app->db);
            $raw = $settings->get('plugins.disabled', '') ?? '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $slug) {
                        if (is_string($slug) && $slug !== '') {
                            $disabled[] = $slug;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $disabled = [];
        }

        $protected = ['auth', 'core', 'plugins'];

        foreach (glob($dir . '/*/module.php') ?: [] as $file) {
            $slug = basename(dirname($file));
            if (in_array($slug, $disabled, true) && !in_array($slug, $protected, true)) {
                continue;
            }
            $header = $this->parseHeader($file);

            $registry = new ModuleRegistry(
                $app,
                $header['slug'] ?? $slug,
                $header['name'] ?? $slug,
                $header['description'] ?? '',
                $header['version'] ?? '0.0.0',
            );

            $result = require $file;
            if (is_callable($result)) {
                $result($registry);
            }

            $manifest = $registry->manifest();
            $this->manifests[] = $manifest->toArray();

            $this->registerRights($app, $manifest);
            $this->registerMenu($app, $manifest);
            $this->registerRoutes($app, $registry);
        }
    }

    public function getManifests(): array
    {
        return $this->manifests;
    }

    private function registerRights(App $app, ModuleManifest $manifest): void
    {
        foreach ($manifest->rights as $r) {
            if (!is_array($r)) continue;
            $rightSlug = (string)($r['slug'] ?? '');
            $desc = (string)($r['description'] ?? $rightSlug);
            if ($rightSlug === '') continue;
            db_exec($app->db, 
                "INSERT INTO rights (slug, description, module_slug) VALUES (?, ?, ?)\n"
                . "ON DUPLICATE KEY UPDATE description=VALUES(description), module_slug=VALUES(module_slug)",
                [$rightSlug, $desc, $manifest->slug]
            );
        }
    }

    private function registerMenu(App $app, ModuleManifest $manifest): void
    {
        foreach ($manifest->menu as $item) {
            if (!is_array($item)) continue;
            $app->menu->register($item);
        }
    }

    private function registerRoutes(App $app, ModuleRegistry $registry): void
    {
        foreach ($registry->routes() as $route) {
            $method = strtoupper((string)($route['method'] ?? ''));
            $path = (string)($route['path'] ?? '');
            $handler = $route['handler'] ?? null;
            $meta = is_array($route['meta'] ?? null) ? $route['meta'] : [];

            if ($path === '' || !is_callable($handler)) continue;

            if ($method === 'GET') {
                $app->router->get($path, $handler, $meta);
            } elseif ($method === 'POST') {
                $app->router->post($path, $handler, $meta);
            }
        }
    }

    /** @return array{name?:string, description?:string, version?:string, slug?:string} */
    private function parseHeader(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) return [];

        $headerBlock = null;
        if (preg_match('/\A\s*<\?php\s*\/\*([\s\S]*?)\*\//', $contents, $matches)) {
            $headerBlock = $matches[1];
        } elseif (preg_match('/\A\s*\/\*([\s\S]*?)\*\//', $contents, $matches)) {
            $headerBlock = $matches[1];
        }

        if ($headerBlock === null) return [];

        $fields = [
            'Module Name' => 'name',
            'Plugin Name' => 'name',
            'Description' => 'description',
            'Version' => 'version',
            'Module Slug' => 'slug',
        ];

        $out = [];
        foreach (preg_split('/\R/', $headerBlock) as $line) {
            $line = trim($line, " \t\n\r\0\x0B*");
            if ($line === '') continue;
            foreach ($fields as $label => $key) {
                if (stripos($line, $label . ':') === 0) {
                    $value = trim(substr($line, strlen($label) + 1));
                    if ($value !== '') $out[$key] = $value;
                }
            }
        }

        return $out;
    }
}
