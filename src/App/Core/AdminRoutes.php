<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\AdminRoutes\Cache;
use App\Core\AdminRoutes\Home;
use App\Core\AdminRoutes\MenuBuilder;
use App\Core\AdminRoutes\Rights;
use App\Core\AdminRoutes\Settings;
use App\Core\IdentityResolver;
use App\Core\Settings as CoreSettings;
use App\Core\AdminRoutes\Users;
use App\Http\Response;
use App\Core\ModuleRegistry;

final class AdminRoutes
{
    public static function register(App $app, ModuleRegistry $registry, callable $hasRight): void
    {
        // Admin renderer (shared)
        $render = function (string $title, string $bodyHtml) use ($app, $hasRight): Response {
            $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
            $menus = $app->menu->layoutMenus($_SERVER['REQUEST_URI'] ?? '/', $hasRight, $loggedIn);

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
                    $identityResolver = new IdentityResolver($app->db, $u);
                    $org = $identityResolver->resolveCharacter($cid);
                    if ($type === 'alliance' && !empty($org['alliance_id'])) {
                        $id = (int)$org['alliance_id'];
                        if ($brandName === 'killsineve.online') $brandName = $u->name('alliance', $id);
                    } elseif (!empty($org['corp_id'])) {
                        $id = (int)$org['corp_id'];
                        if ($brandName === 'killsineve.online') $brandName = $u->name('corporation', $id);
                    }
                }
            }

            $brandLogoUrl = null;
            if ($id > 0) {
                $brandLogoUrl = ($type === 'alliance')
                    ? "https://images.evetech.net/alliances/{$id}/logo?size=64"
                    : "https://images.evetech.net/corporations/{$id}/logo?size=64";
            }

            return Response::html(Layout::page($title, $bodyHtml, $menus['left'], $menus['admin_top'], $menus['user'], $menus['top_left'], $brandName, $brandLogoUrl), 200);
        };

        Home::register($app, $registry, $render);
        Settings::register($app, $registry, $render);
        Cache::register($app, $registry, $render);
        Rights::register($app, $registry, $render);
        Users::register($app, $registry, $render);
        MenuBuilder::register($app, $registry, $render);
    }
}
