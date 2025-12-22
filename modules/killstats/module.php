<?php
declare(strict_types=1);

use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\Rights;
use App\Core\Settings;
use App\Core\Universe;
use App\Http\Response;

return [
    'slug' => 'killstats',
    'menu' => [
        [
            'slug' => 'killstats',
            'title' => 'Kill Stats',
            'url' => '/killstats',
            'sort_order' => 30,
            'area' => 'left',
        ],
    ],
    'cron' => [
        [
            'name' => 'refresh_stats',
            'every' => 300,
            'handler' => function ($app): void {
                $settings = new Settings($app->db);
                $identityType = $settings->get('site.identity.type', 'corporation') ?? 'corporation';
                $identityType = $identityType === 'alliance' ? 'alliance' : 'corporation';
                $identityId = (int)($settings->get('site.identity.id', '0') ?? '0');

                if ($identityId <= 0) return;

                $client = new EsiClient(new HttpClient());
                $cache = new EsiCache($app->db, $client);
                $url = 'https://zkillboard.com/api/stats/' . $identityType . 'ID/' . $identityId . '/';
                $cache->getCached(
                    'zkillstats:' . $identityType . ':' . $identityId,
                    $url,
                    900,
                    fn() => HttpClient::getJson($url, 12)
                );
            },
        ],
    ],
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/killstats',
            'handler' => function () use ($app): Response {
                $cid = (int)($_SESSION['character_id'] ?? 0);
                if ($cid <= 0) return Response::redirect('/auth/login');

                $u = new Universe($app->db);
                $profile = $u->characterProfile($cid);

                $settings = new Settings($app->db);
                $identityType = $settings->get('site.identity.type', 'corporation') ?? 'corporation';
                $identityType = $identityType === 'alliance' ? 'alliance' : 'corporation';
                $identityId = (int)($settings->get('site.identity.id', '0') ?? '0');

                $memberId = $identityType === 'alliance'
                    ? (int)($profile['alliance']['id'] ?? 0)
                    : (int)($profile['corporation']['id'] ?? 0);

                $scopeId = $identityId > 0 ? $identityId : $memberId;

                $scopeName = $identityType === 'alliance'
                    ? (string)($profile['alliance']['name'] ?? 'Alliance')
                    : (string)($profile['corporation']['name'] ?? 'Corporation');

                $renderPage = function (string $title, string $bodyHtml) use ($app): string {
                    $rights = new Rights($app->db);
                    $hasRight = function (string $right) use ($rights): bool {
                        $uid = (int)($_SESSION['user_id'] ?? 0);
                        if ($uid <= 0) return false;
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
                };

                if ($identityId > 0 && $memberId !== $identityId) {
                    $body = "<h1>Kill Stats</h1>
                             <div class='alert alert-danger mt-3'>This view is restricted to active members of the {$identityType}.</div>";

                    return Response::html($renderPage('Kill Stats', $body), 403);
                }

                if ($scopeId <= 0) {
                    $body = "<h1>Kill Stats</h1>
                             <div class='alert alert-warning mt-3'>Unable to determine the {$identityType} scope for kill statistics.</div>";

                    return Response::html($renderPage('Kill Stats', $body), 200);
                }

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
                            return number_format((float)$entry[$key], 0);
                        }
                    }
                    return '—';
                };

                $killerBoards = [];
                $lossBoards = [];
                $otherBoards = [];
                if (is_array($stats['topLists'] ?? null)) {
                    foreach ($stats['topLists'] as $listName => $entries) {
                        if (!is_array($entries)) continue;
                        $rows = [];
                        foreach (array_slice($entries, 0, 6) as $entry) {
                            if (!is_array($entry)) continue;
                            $rows[] = [
                                'label' => $getListLabel($entry),
                                'value' => $getListValue($entry),
                            ];
                        }
                        if (!$rows) continue;

                        $title = ucwords(str_replace('_', ' ', (string)$listName));
                        $normalized = strtolower((string)$listName);
                        $payload = [
                            'title' => $title,
                            'rows' => $rows,
                        ];

                        if (str_contains($normalized, 'kill')) {
                            $killerBoards[] = $payload;
                        } elseif (str_contains($normalized, 'loss')) {
                            $lossBoards[] = $payload;
                        } else {
                            $otherBoards[] = $payload;
                        }
                    }
                }

                $shipsDestroyed = isset($stats['shipsDestroyed']) ? (float)$stats['shipsDestroyed'] : null;
                $shipsLost = isset($stats['shipsLost']) ? (float)$stats['shipsLost'] : null;
                $iskDestroyed = isset($stats['iskDestroyed']) ? (float)$stats['iskDestroyed'] : null;
                $iskLost = isset($stats['iskLost']) ? (float)$stats['iskLost'] : null;
                $pointsDestroyed = isset($stats['pointsDestroyed']) ? (float)$stats['pointsDestroyed'] : null;
                $pointsLost = isset($stats['pointsLost']) ? (float)$stats['pointsLost'] : null;
                $soloKills = isset($stats['soloKills']) ? (float)$stats['soloKills'] : null;
                $soloLosses = isset($stats['soloLosses']) ? (float)$stats['soloLosses'] : null;

                $efficiency = null;
                if ($iskDestroyed !== null || $iskLost !== null) {
                    $totalIsk = ($iskDestroyed ?? 0.0) + ($iskLost ?? 0.0);
                    if ($totalIsk > 0) $efficiency = (($iskDestroyed ?? 0.0) / $totalIsk) * 100.0;
                }

                $scopeLabel = $identityType === 'alliance' ? 'Alliance' : 'Corporation';
                $scopeTicker = $identityType === 'alliance'
                    ? (string)($profile['alliance']['ticker'] ?? '')
                    : (string)($profile['corporation']['ticker'] ?? '');
                $scopeText = htmlspecialchars($scopeName) . ($scopeTicker !== '' ? ' [' . htmlspecialchars($scopeTicker) . ']' : '');

                $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
                            <div>
                              <h1 class='mb-1'>Kill Stats</h1>
                              <div class='text-muted'>Scope: {$scopeLabel} – {$scopeText}</div>
                            </div>
                            <div class='text-muted small'>Source: zKillboard (cached)</div>
                         </div>";

                if ($statsError) {
                    $body .= "<div class='alert alert-warning'>Unable to load kill stats right now. Please try again later.</div>";
                }

                $cards = [
                    ['Ships Destroyed', $formatCompact($shipsDestroyed)],
                    ['Ships Lost', $formatCompact($shipsLost)],
                    ['ISK Destroyed', $formatCompact($iskDestroyed)],
                    ['ISK Lost', $formatCompact($iskLost)],
                    ['Points Destroyed', $formatCompact($pointsDestroyed)],
                    ['Points Lost', $formatCompact($pointsLost)],
                    ['Solo Kills', $formatCompact($soloKills)],
                    ['Solo Losses', $formatCompact($soloLosses)],
                    ['Efficiency', $formatPercent($efficiency)],
                ];

                $body .= "<div class='row g-3 mb-4'>";
                foreach ($cards as [$label, $value]) {
                    $body .= "<div class='col-12 col-md-6 col-xl-3'>
                                <div class='card h-100'>
                                  <div class='card-body'>
                                    <div class='text-muted small mb-1'>" . htmlspecialchars($label) . "</div>
                                    <div class='fs-4 fw-semibold'>" . htmlspecialchars($value) . "</div>
                                  </div>
                                </div>
                              </div>";
                }
                $body .= "</div>";

                if ($killerBoards || $lossBoards) {
                    $body .= "<div class='row g-3 mb-3'>";
                    $renderBoard = function (string $heading, array $boards): string {
                        if (!$boards) {
                            return "<div class='col-12 col-lg-6'>
                                      <div class='card h-100'>
                                        <div class='card-body'>
                                          <h5 class='card-title'>{$heading}</h5>
                                          <p class='text-muted mb-0'>No data available yet.</p>
                                        </div>
                                      </div>
                                    </div>";
                        }

                        $html = '';
                        foreach ($boards as $board) {
                            $html .= "<div class='card h-100 mb-3'>
                                        <div class='card-body'>
                                          <h5 class='card-title'>" . htmlspecialchars($board['title']) . "</h5>
                                          <ul class='list-group list-group-flush'>";
                            foreach ($board['rows'] as $row) {
                                $html .= "<li class='list-group-item d-flex justify-content-between align-items-center'>
                                            <span>" . htmlspecialchars($row['label']) . "</span>
                                            <span class='text-muted'>" . htmlspecialchars($row['value']) . "</span>
                                          </li>";
                            }
                            $html .= "</ul>
                                        </div>
                                      </div>";
                        }

                        return "<div class='col-12 col-lg-6'>
                                  <div class='card h-100'>
                                    <div class='card-body'>
                                      <h4 class='card-title mb-3'>{$heading}</h4>
                                      {$html}
                                    </div>
                                  </div>
                                </div>";
                    };

                    $body .= $renderBoard('Top Killers', $killerBoards);
                    $body .= $renderBoard('Top Losses', $lossBoards);
                    $body .= "</div>";
                }

                if ($otherBoards) {
                    $body .= "<div class='row g-3'>";
                    foreach ($otherBoards as $board) {
                        $body .= "<div class='col-12 col-lg-6 col-xl-4'>
                                    <div class='card h-100'>
                                      <div class='card-body'>
                                        <h5 class='card-title'>" . htmlspecialchars($board['title']) . "</h5>
                                        <ul class='list-group list-group-flush'>";
                        foreach ($board['rows'] as $row) {
                            $body .= "<li class='list-group-item d-flex justify-content-between align-items-center'>
                                        <span>" . htmlspecialchars($row['label']) . "</span>
                                        <span class='text-muted'>" . htmlspecialchars($row['value']) . "</span>
                                      </li>";
                        }
                        $body .= "</ul>
                                      </div>
                                    </div>
                                  </div>";
                    }
                    $body .= "</div>";
                } elseif (!$killerBoards && !$lossBoards) {
                    $body .= "<div class='card'>
                                <div class='card-body'>
                                  <h5 class='card-title'>Leaderboards</h5>
                                  <p class='text-muted mb-0'>Leaderboards will appear here once zKillboard provides ranking data for this scope.</p>
                                </div>
                              </div>";
                }

                return Response::html($renderPage('Kill Stats', $body), 200);
            },
        ],
    ],
];
