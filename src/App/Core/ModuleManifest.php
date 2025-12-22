<?php
declare(strict_types=1);

namespace App\Core;

final class ModuleManifest
{
    /**
     * @param array<int, array{slug:string, description:string}> $rights
     * @param array<int, array<string, mixed>> $menu
     * @param array<int, array<string, mixed>> $routes
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly array $rights = [],
        public readonly array $menu = [],
        public readonly array $routes = [],
    ) {}

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'rights' => $this->rights,
            'menu' => $this->menu,
            'routes' => $this->routes,
        ];
    }
}
