<?php
declare(strict_types=1);

function charlink_org_name(array $org, string $type): string
{
    return core_display_org_name($org, $type);
}

function charlink_corp_label(array $org): string
{
    return core_display_org_name($org, 'corporation');
}
