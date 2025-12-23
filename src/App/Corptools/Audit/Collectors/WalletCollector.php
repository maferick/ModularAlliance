<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class WalletCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'wallet';
    }

    public function scopes(): array
    {
        return ['esi-wallet.read_character_wallet.v1'];
    }

    public function endpoints(int $characterId): array
    {
        return ["/latest/characters/{$characterId}/wallet/"];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $wallet = $payloads[0] ?? 0;
        return [
            'wallet_balance' => (float)$wallet,
        ];
    }
}
