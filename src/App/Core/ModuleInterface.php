<?php
declare(strict_types=1);

namespace App\Core;

interface ModuleInterface
{
    public function manifest(): ModuleManifest;

    public function register(App $app): void;
}
