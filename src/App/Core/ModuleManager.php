<?php
declare(strict_types=1);

namespace App\Core;

final class ModuleManager
{
    public function loadAll(App $app): void
    {
        $dir = APP_ROOT . '/modules';
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*/module.php') ?: [] as $file) {
            $fn = require $file;
            if (is_callable($fn)) {
                $fn($app);
            }
        }
    }
}
