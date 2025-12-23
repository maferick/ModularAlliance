<?php
declare(strict_types=1);

namespace App\Corptools\Audit;

abstract class AbstractCollector implements CollectorInterface
{
    public function ttlSeconds(): int
    {
        return 900;
    }

    public function summarize(int $characterId, array $payloads): array
    {
        return [];
    }
}
