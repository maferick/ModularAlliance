<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Router;

final class ModuleManager
{
    public function __construct(
        private readonly string $modulesDir,
        private readonly Db $db
    ) {}

    /**
     * Registers all modules found on disk.
     * (Enable/disable via DB can be layered later; this is the stable baseline.)
     */
    public function registerAll(Router $router): void
    {
        if (!is_dir($this->modulesDir)) return;

        foreach (glob($this->modulesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $moduleFile = $dir . '/module.php';
            if (!is_file($moduleFile)) continue;

            $def = require $moduleFile;
            if (!is_array($def) || empty($def['slug'])) continue;

            $slug = (string)$def['slug'];
            if (isset($def['register']) && is_callable($def['register'])) {
                ($def['register'])($router, $this->db);
            }
        }
    }

    public function migrationDirs(): array
    {
        $dirs = [
            'core' => APP_ROOT . '/core/migrations',
        ];
        foreach (glob($this->modulesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $moduleFile = $dir . '/module.php';
            if (!is_file($moduleFile)) continue;
            $def = require $moduleFile;
            if (!is_array($def) || empty($def['slug'])) continue;
            $slug = (string)$def['slug'];
            $mig = $dir . '/migrations';
            if (is_dir($mig)) {
                $dirs[$slug] = $mig;
            }
        }
        return $dirs;
    }
}
