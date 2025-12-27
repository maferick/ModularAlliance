<?php
declare(strict_types=1);

function core_module_org_labels(array $org): array
{
    return [
        'corp' => core_display_org_name($org, 'corporation'),
        'alliance' => core_display_org_name($org, 'alliance'),
    ];
}
