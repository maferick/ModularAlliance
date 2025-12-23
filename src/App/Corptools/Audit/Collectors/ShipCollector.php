<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class ShipCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'ship';
    }

    public function scopes(): array
    {
        return ['esi-location.read_ship_type.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return ["/latest/characters/{$characterId}/ship/"];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $ship = $payloads[0] ?? [];
        return [
            'current_ship_type_id' => (int)($ship['ship_type_id'] ?? 0),
            'current_ship_name' => (string)($ship['ship_name'] ?? ''),
        ];
    }
}
