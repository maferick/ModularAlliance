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

            if (!$mod instanceof ModuleInterface) continue;

            $manifest = $mod->manifest();
            $this->manifests[] = $manifest->toArray();

            $this->registerRights($app, $manifest);
            $this->registerMenu($app, $manifest);

            $mod->register($app);
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
            $app->db->run(
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
}
