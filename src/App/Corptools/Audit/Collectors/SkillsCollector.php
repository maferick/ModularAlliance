<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class SkillsCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'skills';
    }

    public function scopes(): array
    {
        return ['esi-skills.read_skills.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return [
            "/latest/characters/{$characterId}/skills/",
            "/latest/characters/{$characterId}/skillqueue/",
        ];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $skills = $payloads[0] ?? [];
        return [
            'total_sp' => (int)($skills['total_sp'] ?? 0),
        ];
    }
}
