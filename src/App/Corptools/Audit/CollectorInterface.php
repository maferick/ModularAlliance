<?php
declare(strict_types=1);

namespace App\Corptools\Audit;

interface CollectorInterface
{
    public function key(): string;

    /** @return array<int, string> */
    public function scopes(): array;

    /** @return array<int, string> */
    public function endpoints(int $characterId): array;

    public function ttlSeconds(): int;

    /** @return array<string, mixed> */
    public function summarize(int $characterId, array $payloads): array;
}
