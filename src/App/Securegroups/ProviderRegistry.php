<?php
declare(strict_types=1);

namespace App\Securegroups;

final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function register(ProviderInterface $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;
    }

    /** @return array<int, ProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function get(string $key): ?ProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function rulesCatalog(): array
    {
        $catalog = [];
        foreach ($this->providers as $provider) {
            $catalog[$provider->getKey()] = $provider->getAvailableRules();
        }
        return $catalog;
    }
}
