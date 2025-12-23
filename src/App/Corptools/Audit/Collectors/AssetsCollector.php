<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class AssetsCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'assets';
    }

    public function scopes(): array
    {
        return ['esi-assets.read_assets.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return ["/latest/characters/{$characterId}/assets/"];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $assets = $payloads[0] ?? [];
        return [
            'assets_count' => is_array($assets) ? count($assets) : 0,
        ];
    }
}
