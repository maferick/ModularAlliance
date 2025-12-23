<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\AccessLog;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\ModuleInterface;
use App\Core\ModuleManifest;
use App\Core\Rights;
use App\Core\Settings;
use App\Core\Universe;
use App\Http\Response;

final class KillstatsModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            slug: 'killstats',
            name: 'Kill Stats',
            description: 'Killboard stats for the configured alliance or corporation.',
            version: '1.0.0',
            rights: [
                [
                    'slug' => 'killstats.view_all',
                    'description' => 'View kill stats outside the configured identity scope.',
                ],
            ],
            menu: [
                [
                    'slug' => 'killstats',
                    'title' => 'Kill Stats',
                    'url' => '/killstats',
                    'sort_order' => 30,
                    'area' => 'left',
                ],
            ],
            routes: [
                ['method' => 'GET', 'path' => '/killstats'],
            ]
        );
    }

    public function register(App $app): void
    {
        $app->router->get('/killstats', function () use ($app): Response {
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) return Response::redirect('/auth/login');

            $u = new Universe($app->db);
            $profile = $u->characterProfile($cid);

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
                        return number_format((float)$entry[$key], 0);
                    }
                }
                return '—';
            };

            $summary = $stats['summary'] ?? [];
            $summaryRows = [
                ['label' => 'Ships Destroyed', 'value' => $formatCompact(isset($summary['shipsDestroyed']) ? (float)$summary['shipsDestroyed'] : null)],
                ['label' => 'Ships Lost', 'value' => $formatCompact(isset($summary['shipsLost']) ? (float)$summary['shipsLost'] : null)],
                ['label' => 'ISK Destroyed', 'value' => $formatCompact(isset($summary['iskDestroyed']) ? (float)$summary['iskDestroyed'] : null)],
                ['label' => 'ISK Lost', 'value' => $formatCompact(isset($summary['iskLost']) ? (float)$summary['iskLost'] : null)],
                ['label' => 'Efficiency', 'value' => $formatPercent(isset($summary['iskEfficiency']) ? (float)$summary['iskEfficiency'] : null)],
                ['label' => 'Points Destroyed', 'value' => $formatCompact(isset($summary['pointsDestroyed']) ? (float)$summary['pointsDestroyed'] : null)],
                ['label' => 'Points Lost', 'value' => $formatCompact(isset($summary['pointsLost']) ? (float)$summary['pointsLost'] : null)],
            ];

            $topRows = function ($items, string $emptyText) use ($getListLabel, $getListValue): string {
                if (!is_array($items) || $items === []) {
                    return "<tr><td colspan='2' class='text-muted'>{$emptyText}</td></tr>";
                }
                $rows = '';
                foreach ($items as $entry) {
                    if (!is_array($entry)) continue;
                    $label = htmlspecialchars($getListLabel($entry));
                    $value = htmlspecialchars($getListValue($entry));
                    $rows .= "<tr><td>{$label}</td><td class='text-end'>{$value}</td></tr>";
                }
                return $rows;
            };

            $summaryHtml = "<div class='row g-3'>";
            foreach ($summaryRows as $row) {
                $summaryHtml .= "<div class='col-sm-6 col-lg-3'>
                                  <div class='card h-100'>
                                    <div class='card-body'>
                                      <div class='text-muted small'>{$row['label']}</div>
                                      <div class='fs-4 fw-semibold'>{$row['value']}</div>
                                    </div>
                                  </div>
                                </div>";
            }
            $summaryHtml .= "</div>";

            $topShips = $topRows($stats['shipsDestroyed'] ?? null, 'No ship data found.');
            $topSystems = $topRows($stats['systems'] ?? null, 'No system data found.');
            $topAllies = $topRows($stats['allies'] ?? null, 'No ally data found.');
            $topEnemies = $topRows($stats['enemies'] ?? null, 'No enemy data found.');

            $listsHtml = "<div class='row g-3 mt-1'>
                            <div class='col-lg-6'>
                              <div class='card h-100'>
                                <div class='card-header'>Top Ships</div>
                                <div class='table-responsive'>
                                  <table class='table table-sm mb-0'>
                                    <tbody>{$topShips}</tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                            <div class='col-lg-6'>
                              <div class='card h-100'>
                                <div class='card-header'>Top Systems</div>
                                <div class='table-responsive'>
                                  <table class='table table-sm mb-0'>
                                    <tbody>{$topSystems}</tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                            <div class='col-lg-6'>
                              <div class='card h-100'>
                                <div class='card-header'>Top Allies</div>
                                <div class='table-responsive'>
                                  <table class='table table-sm mb-0'>
                                    <tbody>{$topAllies}</tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                            <div class='col-lg-6'>
                              <div class='card h-100'>
                                <div class='card-header'>Top Enemies</div>
                                <div class='table-responsive'>
                                  <table class='table table-sm mb-0'>
                                    <tbody>{$topEnemies}</tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>";

            $header = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3'>
                          <div>
                            <h1 class='mb-1'>Kill Stats</h1>
                            <div class='text-muted'>Scope: {$scopeName}</div>
                          </div>
                          <a class='btn btn-sm btn-outline-primary' href='https://zkillboard.com/{$identityType}/{$scopeId}/' target='_blank' rel='noopener'>View on zKillboard</a>
                        </div>";

            $errorHtml = '';
            if ($statsError) {
                $errorHtml = "<div class='alert alert-warning mt-3'>Unable to fetch killboard stats: " . htmlspecialchars($statsError) . "</div>";
            }

            $body = $header . $errorHtml . "<div class='mt-3'>" . $summaryHtml . $listsHtml . "</div>";

            return Response::html($renderPage('Kill Stats', $body), 200);
        });
    }
}

return new KillstatsModule();
