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

    $leftTree = $app->menu->tree('left', $hasRight);
    $adminTree = $app->menu->tree('admin_top', $hasRight);
    $userTree = $app->menu->tree('user_top', fn(string $r) => true);

    $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
    if ($loggedIn) {
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
    } else {
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
    }

    return Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree);
}

function killstats_scope_name(Universe $universe, string $identityType, int $memberId): string
{
    if ($identityType === 'alliance') {
        return $memberId > 0 ? $universe->nameOrUnknown('alliance', $memberId, 'Alliance') : 'Alliance';
    }

    return $memberId > 0 ? $universe->nameOrUnknown('corporation', $memberId, 'Corporation') : 'Corporation';
}
