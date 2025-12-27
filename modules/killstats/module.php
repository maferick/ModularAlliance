<?php
declare(strict_types=1);

/*
Module Name: Kill Stats
Description: Killboard stats for the configured alliance or corporation.
Version: 1.0.0
*/

use App\Core\AccessLog;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\IdentityResolver;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Settings;
use App\Core\Universe;
use App\Http\Response;

require_once __DIR__ . '/functions.php';

return function (ModuleRegistry $registry): void {
    $app = $registry->app();
    $universeShared = new Universe($app->db);
    $identityResolver = new IdentityResolver($app->db, $universeShared);

    $registry->right('killstats.view_all', 'View kill stats outside the configured identity scope.');

    $registry->menu([
        'slug' => 'killstats',
        'title' => 'Kill Stats',
        'url' => '/killstats',
        'sort_order' => 30,
        'area' => 'left_member',
    ]);

    $registry->menu([
        'slug' => 'module.killstats',
        'title' => 'Kill Stats',
        'url' => '/killstats',
        'sort_order' => 10,
        'area' => 'module_top',
    ]);
    $registry->menu([
        'slug' => 'module.killstats.overview',
        'title' => 'Overview',
        'url' => '/killstats',
        'sort_order' => 11,
        'area' => 'module_top',
        'parent_slug' => 'module.killstats',
    ]);

    $registry->route('GET', '/killstats', function () use ($app, $universeShared, $identityResolver): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid <= 0) return Response::redirect('/auth/login');

        $settings = new Settings($app->db);
        $identityType = $settings->get('site.identity.type', 'corporation');
        if ($identityType === null) {
            $identityType = 'corporation';
        }
        $identityType = $identityType === 'alliance' ? 'alliance' : 'corporation';
        $identityIdValue = $settings->get('site.identity.id', '0');
        if ($identityIdValue === null) {
            $identityIdValue = '0';
        }
        $identityId = (int)$identityIdValue;

        $org = $identityResolver->resolveCharacter($cid);
        $memberId = $identityType === 'alliance'
            ? (int)($org['alliance_id'] ?? 0)
            : (int)($org['corp_id'] ?? 0);

        $scopeId = $identityId > 0 ? $identityId : $memberId;

        $scopeName = killstats_scope_name($universeShared, $identityType, $memberId);

        $renderPage = function (string $title, string $bodyHtml) use ($app): string {
            return killstats_render_page($app, $title, $bodyHtml);
        };

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $rights = new Rights($app->db);
        $canBypassScope = $uid > 0 && $rights->userHasRight($uid, 'killstats.view_all');

        if ($identityId > 0 && $memberId !== $identityId && !$canBypassScope) {
            AccessLog::write([
                'method' => 'GET',
                'path' => '/killstats',
                'status' => 403,
                'decision' => 'deny',
                'reason' => 'scope_mismatch',
                'identity_type' => $identityType,
                'identity_id' => $identityId,
                'member_id' => $memberId,
            ]);
            $body = "<div class='card'>
                        <div class='card-body'>
                          <div class='d-flex flex-wrap justify-content-between align-items-start gap-3'>
                            <div>
                              <h1 class='mb-2'>Kill Stats</h1>
                              <div class='text-muted'>Access restricted to active members of the configured {$identityType}.</div>
                            </div>
                            <span class='badge bg-warning text-dark'>Access required</span>
                          </div>
                          <hr>
                          <p class='mb-2'>If you should have access, ask an admin to do one of the following:</p>
                          <ul class='mb-0'>
                            <li>Grant the <code>killstats.view_all</code> right in <strong>Admin → Rights &amp; Groups</strong>.</li>
                            <li>Update the configured {$identityType} in <strong>Admin → Settings</strong> so it matches your membership.</li>
                          </ul>
                        </div>
                      </div>";

            return Response::html($renderPage('Kill Stats', $body), 200);
        }

        if ($scopeId <= 0) {
            $body = "<h1>Kill Stats</h1>
                     <div class='alert alert-warning mt-3'>Unable to determine the {$identityType} scope for kill statistics.</div>";

            return Response::html($renderPage('Kill Stats', $body), 200);
        }

        AccessLog::write([
            'method' => 'GET',
            'path' => '/killstats',
            'status' => 200,
            'decision' => 'allow',
            'reason' => 'scope_allowed',
            'identity_type' => $identityType,
            'identity_id' => $scopeId,
        ]);

        $stats = null;
        $statsError = null;
        try {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            $url = 'https://zkillboard.com/api/stats/' . $identityType . 'ID/' . $scopeId . '/';
            $stats = $cache->getCached(
                'zkillstats:' . $identityType . ':' . $scopeId,
                $url,
                900,
                fn() => HttpClient::getJson($url, 12)
            );
        } catch (\Throwable $e) {
            $statsError = $e->getMessage();
        }

        $stats = is_array($stats) ? $stats : [];

        $formatCompact = function (?float $value): string {
            if ($value === null) return '—';
            $abs = abs($value);
            if ($abs >= 1_000_000_000_000) return number_format($value / 1_000_000_000_000, 1) . 'T';
            if ($abs >= 1_000_000_000) return number_format($value / 1_000_000_000, 1) . 'B';
            if ($abs >= 1_000_000) return number_format($value / 1_000_000, 1) . 'M';
            if ($abs >= 1_000) return number_format($value / 1_000, 1) . 'K';
            return number_format($value, 0);
        };

        $formatPercent = function (?float $value): string {
            if ($value === null) return '—';
            return number_format($value, 1) . '%';
        };

        $getListLabel = function (array $entry): string {
            $labelKeys = ['name', 'characterName', 'corporationName', 'allianceName', 'shipName', 'groupName'];
            foreach ($labelKeys as $key) {
                if (!empty($entry[$key]) && is_string($entry[$key])) {
                    $label = trim($entry[$key]);
                    if ($label !== '' && !preg_match('/^\d+$/', $label)) return $label;
                }
            }
            return 'Unknown';
        };

        $getListValue = function (array $entry): string {
            $valueKeys = ['kills', 'shipsDestroyed', 'shipsLost', 'points', 'isk', 'value', 'count'];
            foreach ($valueKeys as $key) {
                if (isset($entry[$key]) && is_numeric($entry[$key])) {
                    return $formatCompact((float)$entry[$key]);
                }
            }
            return '—';
        };

        $statsRows = '';
        $sections = [
            'shipsDestroyed' => 'Ships Destroyed',
            'shipsLost' => 'Ships Lost',
            'iskDestroyed' => 'ISK Destroyed',
            'iskLost' => 'ISK Lost',
            'pointsDestroyed' => 'Points Destroyed',
            'pointsLost' => 'Points Lost',
        ];

        $statsRows .= '<div class="row g-3">';
        foreach ($sections as $key => $label) {
            $value = isset($stats[$key]) && is_numeric($stats[$key]) ? $formatCompact((float)$stats[$key]) : '—';
            $statsRows .= '<div class="col-6 col-md-4">'
                . '<div class="card card-body">'
                . '<div class="text-muted">' . htmlspecialchars($label) . '</div>'
                . '<div class="fs-4 fw-bold">' . htmlspecialchars($value) . '</div>'
                . '</div>'
                . '</div>';
        }
        $statsRows .= '</div>';

        $ratioDestroyed = isset($stats['shipsDestroyed']) && isset($stats['shipsLost']) && $stats['shipsLost'] > 0
            ? (float)$stats['shipsDestroyed'] / (float)$stats['shipsLost']
            : null;
        $ratioIsk = isset($stats['iskDestroyed']) && isset($stats['iskLost']) && $stats['iskLost'] > 0
            ? (float)$stats['iskDestroyed'] / (float)$stats['iskLost']
            : null;

        $ratioHtml = '<div class="row g-3 mt-3">'
            . '<div class="col-md-6">'
            . '<div class="card card-body">'
            . '<div class="text-muted">Kill/Loss Ratio</div>'
            . '<div class="fs-4 fw-bold">' . htmlspecialchars($ratioDestroyed === null ? '—' : number_format($ratioDestroyed, 2)) . '</div>'
            . '</div>'
            . '</div>'
            . '<div class="col-md-6">'
            . '<div class="card card-body">'
            . '<div class="text-muted">ISK Efficiency</div>'
            . '<div class="fs-4 fw-bold">' . htmlspecialchars($ratioIsk === null ? '—' : $formatPercent($ratioIsk * 100)) . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';

        $topLists = [
            'topShips' => 'Top Ships',
            'topCharacters' => 'Top Pilots',
            'topCorporations' => 'Top Corporations',
            'topAlliances' => 'Top Alliances',
        ];

        $topHtml = '<div class="row g-3 mt-3">';
        foreach ($topLists as $key => $label) {
            $entries = is_array($stats[$key] ?? null) ? $stats[$key] : [];
            $listHtml = '';
            foreach (array_slice($entries, 0, 5) as $entry) {
                if (!is_array($entry)) continue;
                $listHtml .= '<li class="d-flex justify-content-between">'
                    . '<span>' . htmlspecialchars($getListLabel($entry)) . '</span>'
                    . '<span class="text-muted">' . htmlspecialchars($getListValue($entry)) . '</span>'
                    . '</li>';
            }
            if ($listHtml === '') {
                $listHtml = '<li class="text-muted">No data.</li>';
            }
            $topHtml .= '<div class="col-md-6">'
                . '<div class="card card-body">'
                . '<div class="fw-semibold">' . htmlspecialchars($label) . '</div>'
                . '<ul class="list-unstyled mt-2 mb-0">' . $listHtml . '</ul>'
                . '</div>'
                . '</div>';
        }
        $topHtml .= '</div>';

        $body = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">'
            . '<div>'
            . '<h1 class="mb-1">Kill Stats</h1>'
            . '<div class="text-muted">Scope: ' . htmlspecialchars($scopeName) . '</div>'
            . '</div>'
            . '</div>';

        if ($statsError) {
            $body .= '<div class="alert alert-warning mt-3">Unable to load kill stats: '
                . htmlspecialchars($statsError) . '</div>';
        }

        $body .= $statsRows . $ratioHtml . $topHtml;

        return Response::html($renderPage('Kill Stats', $body), 200);
    });
};
