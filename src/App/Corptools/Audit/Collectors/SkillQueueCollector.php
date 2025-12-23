<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class SkillQueueCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'skill_queue';
    }

    public function scopes(): array
    {
        return ['esi-skills.read_skillqueue.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return ["/latest/characters/{$characterId}/skillqueue/"];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $queue = $payloads[0] ?? [];
        $count = is_array($queue) ? count($queue) : 0;
        return [
            'skill_queue_count' => $count,
        ];
    }
}
