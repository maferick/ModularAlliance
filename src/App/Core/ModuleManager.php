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

        foreach (glob($dir . '/*/module.php') ?: [] as $file) {
            $mod = require $file;

            // Backwards compatibility: module.php can return a callable($app)
            if (is_callable($mod)) {
                $mod($app);
                $this->manifests[] = [
                    'slug' => (string)basename(dirname($file)),
                ];
                continue;
            }

            // New convention: module.php returns an array manifest
            if (!is_array($mod)) continue;

            $slug = (string)($mod['slug'] ?? basename(dirname($file)));
            $mod['slug'] = $slug;
            $this->manifests[] = $mod;

            // 1) Register rights declared by the module (idempotent)
            if (!empty($mod['rights']) && is_array($mod['rights'])) {
                foreach ($mod['rights'] as $r) {
                    if (!is_array($r)) continue;
                    $rightSlug = (string)($r['slug'] ?? '');
                    $desc = (string)($r['description'] ?? $rightSlug);
                    if ($rightSlug === '') continue;
                    $app->db->run(
                        "INSERT INTO rights (slug, description, module_slug) VALUES (?, ?, ?)\n"
                        . "ON DUPLICATE KEY UPDATE description=VALUES(description), module_slug=VALUES(module_slug)",
                        [$rightSlug, $desc, $slug]
                    );
                }
            }

            // 2) Menu items can be declared by the module (idempotent)
            if (!empty($mod['menu']) && is_array($mod['menu'])) {
                foreach ($mod['menu'] as $item) {
                    if (!is_array($item)) continue;
                    $app->menu->register($item);
                }
            }

            // 3) Routes can be declared by the module (simple registry)
            if (!empty($mod['routes']) && is_array($mod['routes'])) {
                foreach ($mod['routes'] as $rt) {
                    if (!is_array($rt)) continue;
                    $method = strtoupper((string)($rt['method'] ?? 'GET'));
                    $path   = (string)($rt['path'] ?? '/');
                    $handler = $rt['handler'] ?? null;
                    if (!is_callable($handler)) continue;
                    $meta = [];
                    if (!empty($rt['right'])) $meta['right'] = (string)$rt['right'];
                    if (!empty($rt['public'])) $meta['public'] = true;

                    if ($method === 'POST') $app->router->post($path, $handler, $meta);
                    else $app->router->get($path, $handler, $meta);
                }
            }

            // 4) Optional boot callback
            if (!empty($mod['boot']) && is_callable($mod['boot'])) {
                ($mod['boot'])($app);
            }
        }
    }

    public function getManifests(): array
    {
        return $this->manifests;
    }
}
