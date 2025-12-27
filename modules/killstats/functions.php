<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Layout;
use App\Core\Rights;
use App\Core\Universe;

function killstats_render_page(App $app, string $title, string $bodyHtml): string
{
    $rights = new Rights($app->db);
    $hasRight = function (string $right) use ($rights): bool {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        return $rights->userHasRight($uid, $right);
    };

    $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
    $menus = $app->menu->layoutMenus($_SERVER['REQUEST_URI'] ?? '/', $hasRight, $loggedIn);

    return Layout::page($title, $bodyHtml, $menus['left_member'], $menus['left_admin'], $menus['site_admin'], $menus['user'], $menus['module']);
}

function killstats_scope_name(Universe $universe, string $identityType, int $memberId): string
{
    if ($identityType === 'alliance') {
        return $memberId > 0 ? $universe->nameOrUnknown('alliance', $memberId, 'Alliance') : 'Alliance';
    }

    return $memberId > 0 ? $universe->nameOrUnknown('corporation', $memberId, 'Corporation') : 'Corporation';
}
