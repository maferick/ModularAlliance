<?php
declare(strict_types=1);

use App\Core\IdentityResolver;
use App\Core\Universe;

function core_display_org_name(array $org, string $type, string $fallback = 'Unknown'): string
{
    if (($org['org_status'] ?? '') !== 'fresh') {
        return $fallback;
    }

    $key = $type === 'corporation' ? 'corporation' : 'alliance';
    $name = (string)($org[$key]['name'] ?? '');
    return $name !== '' ? $name : $fallback;
}

function core_resolve_org_display(IdentityResolver $resolver, int $characterId): array
{
    $org = $resolver->resolveCharacter($characterId);

    return [
        'org' => $org,
        'corp_name' => core_display_org_name($org, 'corporation'),
        'alliance_name' => core_display_org_name($org, 'alliance'),
    ];
}

function core_universe_name(Universe $universe, string $type, int $id, string $fallback = 'Unknown'): string
{
    return $universe->nameOrUnknown($type, $id, $fallback);
}
