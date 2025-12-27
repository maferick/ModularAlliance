<?php
declare(strict_types=1);

use App\Core\Universe;

function securegroups_resolve_org_names(Universe $universe, int $corpId, int $allianceId, array $config): array
{
    $corpName = (string)($config['corp_name'] ?? '');
    $allianceName = (string)($config['alliance_name'] ?? '');

    if ($corpId > 0 && $corpName === '') {
        $corpName = $universe->nameOrUnknown('corporation', $corpId);
    }
    if ($allianceId > 0 && $allianceName === '') {
        $allianceName = $universe->nameOrUnknown('alliance', $allianceId);
    }

    return [$corpName, $allianceName];
}
