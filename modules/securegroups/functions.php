<?php
declare(strict_types=1);

use App\Core\Universe;

function securegroups_resolve_org_names(Universe $universe, int $corpId, int $allianceId, array $config): array
{
    $corpName = $corpId > 0 ? $universe->nameOrUnknown('corporation', $corpId) : '';
    $allianceName = $allianceId > 0 ? $universe->nameOrUnknown('alliance', $allianceId) : '';

    return [$corpName, $allianceName];
}
