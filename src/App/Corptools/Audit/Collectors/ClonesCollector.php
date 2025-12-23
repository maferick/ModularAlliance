<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class ClonesCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'clones';
    }

    public function scopes(): array
    {
        return ['esi-clones.read_clones.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return ["/latest/characters/{$characterId}/clones/"];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $clones = $payloads[0] ?? [];
        $home = $clones['home_location'] ?? [];
        $jumpClones = $clones['jump_clones'] ?? [];
        $jumpLocationId = 0;
        if (is_array($jumpClones) && !empty($jumpClones[0])) {
            $jumpLocationId = (int)($jumpClones[0]['location_id'] ?? 0);
        }

        return [
            'home_station_id' => (int)($home['location_id'] ?? 0),
            'death_clone_location_id' => 0,
            'jump_clone_location_id' => $jumpLocationId,
        ];
    }
}
