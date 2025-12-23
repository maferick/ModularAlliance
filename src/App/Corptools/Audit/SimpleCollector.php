<?php
declare(strict_types=1);

namespace App\Corptools\Audit;

final class SimpleCollector extends AbstractCollector
{
    /** @var array<int, string> */
    private array $scopes;

    /** @var array<int, string> */
    private array $paths;

    public function __construct(
        private string $key,
        array $scopes,
        array $paths,
        private int $ttlSeconds = 900
    ) {
        $this->scopes = $scopes;
        $this->paths = $paths;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function scopes(): array
    {
        return $this->scopes;
    }

    public function endpoints(int $characterId): array
    {
        return array_map(fn(string $path) => str_replace('{character_id}', (string)$characterId, $path), $this->paths);
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
