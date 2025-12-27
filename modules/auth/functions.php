<?php
declare(strict_types=1);

function auth_display_org_name(?array $org, string $type): string
{
    if (!$org) {
        return 'Unknown';
    }

    return core_display_org_name($org, $type);
}
