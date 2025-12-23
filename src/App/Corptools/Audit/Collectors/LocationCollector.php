<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class LocationCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'location';
    }

    public function scopes(): array
    {
        return ['esi-location.read_location.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return ["/latest/characters/{$characterId}/location/"];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $location = $payloads[0] ?? [];
        return [
            'location_system_id' => (int)($location['solar_system_id'] ?? 0),
        ];
    }
}
