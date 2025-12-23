<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\AdminRoutes\Cache;
use App\Core\AdminRoutes\Home;
use App\Core\AdminRoutes\Menu;
use App\Core\AdminRoutes\Rights;
use App\Core\AdminRoutes\Settings;
use App\Core\Settings as CoreSettings;
use App\Core\AdminRoutes\Users;
use App\Http\Response;

final class AdminRoutes
{
    public static function register(App $app, callable $hasRight): void
    {
        // Admin renderer (shared)
        $render = function (string $title, string $bodyHtml) use ($app, $hasRight): Response {
            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

            // Brand (settings-driven, safe fallbacks)
            $settings = new CoreSettings($app->db);

            $brandName = $settings->get('site.brand.name', 'killsineve.online') ?? 'killsineve.online';
            $type = $settings->get('site.identity.type', 'corporation') ?? 'corporation'; // corporation|alliance
            $id = (int)($settings->get('site.identity.id', '0') ?? '0');

            // If not configured, infer from logged-in character (best-effort)
            if ($id <= 0) {
                $cid = (int)($_SESSION['character_id'] ?? 0);
                if ($cid > 0) {
                    $u = new Universe($app->db);
                    $p = $u->characterProfile($cid);
                    if ($type === 'alliance' && !empty($p['alliance']['id'])) {
                        $id = (int)$p['alliance']['id'];
                        if ($brandName === 'killsineve.online' && !empty($p['alliance']['name'])) $brandName = (string)$p['alliance']['name'];
                    } elseif (!empty($p['corporation']['id'])) {
                        $id = (int)$p['corporation']['id'];
                        if ($brandName === 'killsineve.online' && !empty($p['corporation']['name'])) $brandName = (string)$p['corporation']['name'];
                    }
                }
            }

            $brandLogoUrl = null;
            if ($id > 0) {
                $brandLogoUrl = ($type === 'alliance')
                    ? "https://images.evetech.net/alliances/{$id}/logo?size=64"
                    : "https://images.evetech.net/corporations/{$id}/logo?size=64";
            }

            return Response::html(Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree, $brandName, $brandLogoUrl), 200);
        };

        Home::register($app, $render);
        Settings::register($app, $render);
        Cache::register($app, $render);
        Rights::register($app, $render);
        Users::register($app, $render);
        Menu::register($app, $render);
    }
}
