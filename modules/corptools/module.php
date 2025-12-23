<?php
declare(strict_types=1);

/*
Module Name: Corp Tools
Description: Corporation dashboards inspired by CorpTools.
Version: 1.0.0
Module Slug: corptools
*/

use App\Core\App;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Universe;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('corptools.view', 'Access the CorpTools dashboards.');
    $registry->right('corptools.director', 'Access director-level corp dashboards.');
    $registry->right('corptools.admin', 'Manage CorpTools settings and integrations.');

    $registry->menu([
        'slug' => 'corptools',
        'title' => 'Corp Tools',
        'url' => '/corptools',
        'sort_order' => 40,
        'area' => 'left',
        'right_slug' => 'corptools.view',
    ]);

    $registry->menu([
        'slug' => 'admin.corptools',
        'title' => 'Corp Tools',
        'url' => '/admin/corptools',
        'sort_order' => 45,
        'area' => 'admin_top',
        'right_slug' => 'corptools.admin',
    ]);

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

    $formatIsk = function (float $amount): string {
        return number_format($amount, 2) . ' ISK';
    };

    $tokenData = function (int $characterId) use ($app): array {
        $row = $app->db->one(
            "SELECT access_token, scopes_json, expires_at
             FROM eve_tokens
             WHERE character_id=? LIMIT 1",
            [$characterId]
        );
        $scopes = [];
        $accessToken = null;
        $expired = false;
        if ($row) {
            $accessToken = (string)($row['access_token'] ?? '');
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
            if ($expiresAt !== null && time() > $expiresAt) {
                $expired = true;
            }
        }
        return ['access_token' => $accessToken, 'scopes' => $scopes, 'expired' => $expired];
    };

    $hasScopes = function (array $tokenScopes, array $requiredScopes): bool {
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $tokenScopes, true)) return false;
        }
        return true;
    };

    $getCorpProfiles = function (array $corpIds) use ($app): array {
        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);

        $profiles = [];
        foreach ($corpIds as $corpId) {
            $corpId = (int)$corpId;
            if ($corpId <= 0) continue;
            $corp = $cache->getCached(
                "corp:{$corpId}",
                "GET /latest/corporations/{$corpId}/",
                3600,
                fn() => $client->get("/latest/corporations/{$corpId}/")
            );
            $icons = $cache->getCached(
                "corp:{$corpId}",
                "GET /latest/corporations/{$corpId}/icons/",
                86400,
                fn() => $client->get("/latest/corporations/{$corpId}/icons/")
            );
            $name = (string)($corp['name'] ?? 'Unknown');
            $ticker = (string)($corp['ticker'] ?? '');
            $label = $ticker !== '' ? "{$name} [{$ticker}]" : $name;
            $profiles[$label] = [
                'id' => $corpId,
                'name' => $name,
                'label' => $label,
                'icons' => $icons,
            ];
        }

        return $profiles;
    };

    $corpContext = function () use ($app, $getCorpProfiles): array {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $u = new Universe($app->db);
        $profile = $cid > 0 ? $u->characterProfile($cid) : [];
        $corpId = (int)($profile['corporation']['id'] ?? 0);

        $cfg = $app->config['corptools'] ?? [];
        $corpIds = $cfg['corp_ids'] ?? [];
        if (!is_array($corpIds)) $corpIds = [];
        $corpIds = array_values(array_filter($corpIds, fn($id) => is_numeric($id)));
        if (empty($corpIds) && $corpId > 0) {
            $corpIds = [$corpId];
        }

        $profiles = $getCorpProfiles($corpIds);
        $selected = (string)($_GET['corp'] ?? '');
        if ($selected === '' && !empty($profiles)) {
            $selected = array_key_first($profiles);
        }
        $corpProfile = $profiles[$selected] ?? null;

        return [
            'profiles' => $profiles,
            'selected' => $corpProfile,
            'character_profile' => $profile,
        ];
    };

    $registry->cron('invoice_sync', 900, function (App $app) use ($tokenData) {
        $cfg = $app->config['corptools'] ?? [];
        $corpIds = $cfg['corp_ids'] ?? [];
        if (!is_array($corpIds)) $corpIds = [];
        $corpIds = array_values(array_filter($corpIds, fn($id) => is_numeric($id)));
        if (empty($corpIds)) return;

        $walletDivisions = $cfg['wallet_divisions'] ?? [1];
        if (!is_array($walletDivisions) || empty($walletDivisions)) {
            $walletDivisions = [1];
        }

        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);

        foreach ($corpIds as $corpId) {
            $corpId = (int)$corpId;
            if ($corpId <= 0) continue;

            $tokenRow = $app->db->one(
                "SELECT character_id FROM eve_tokens WHERE JSON_CONTAINS(scopes_json, '" . '"' . "esi-wallet.read_corporation_wallets.v1" . '"' . "') LIMIT 1"
            );
            if (!$tokenRow) continue;

            $characterId = (int)($tokenRow['character_id'] ?? 0);
            if ($characterId <= 0) continue;

            $token = $tokenData($characterId);
            if (empty($token['access_token']) || $token['expired']) continue;

            $charData = $cache->getCached(
                "char:{$characterId}",
                "GET /latest/characters/{$characterId}/",
                3600,
                fn() => $client->get("/latest/characters/{$characterId}/")
            );
            $charCorpId = (int)($charData['corporation_id'] ?? 0);
            if ($charCorpId !== $corpId) continue;

            foreach ($walletDivisions as $division) {
                $division = (int)$division;
                if ($division <= 0) continue;

                $entries = $cache->getCachedAuth(
                    "corptools:wallet:{$corpId}:{$division}",
                    "GET /latest/corporations/{$corpId}/wallets/{$division}/journal/",
                    300,
                    (string)$token['access_token'],
                    [403, 404]
                );

                if (!is_array($entries)) continue;

                foreach ($entries as $entry) {
                    if (!is_array($entry)) continue;
                    $journalId = (int)($entry['id'] ?? 0);
                    if ($journalId <= 0) continue;
                    $amount = (float)($entry['amount'] ?? 0);
                    $balance = (float)($entry['balance'] ?? 0);
                    $refType = (string)($entry['ref_type'] ?? '');
                    $date = (string)($entry['date'] ?? '');
                    $firstParty = (int)($entry['first_party_id'] ?? 0);
                    $secondParty = (int)($entry['second_party_id'] ?? 0);
                    $reason = (string)($entry['reason'] ?? '');

                    $app->db->run(
                        "INSERT INTO module_corptools_invoice_payments
                         (corp_id, wallet_division, journal_id, ref_type, amount, balance, entry_date, first_party_id, second_party_id, reason, raw_json, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                           ref_type=VALUES(ref_type), amount=VALUES(amount), balance=VALUES(balance), entry_date=VALUES(entry_date),
                           first_party_id=VALUES(first_party_id), second_party_id=VALUES(second_party_id), reason=VALUES(reason), raw_json=VALUES(raw_json)",
                        [
                            $corpId,
                            $division,
                            $journalId,
                            $refType,
                            $amount,
                            $balance,
                            $date,
                            $firstParty,
                            $secondParty,
                            $reason,
                            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]
                    );
                }
            }
        }
    });

    $registry->route('GET', '/corptools', function () use ($app, $renderPage, $corpContext, $tokenData, $hasScopes, $formatIsk): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Corp Tools</h1><div class='alert alert-warning mt-3'>No corporation is configured for CorpTools.</div>";
            return Response::html($renderPage('Corp Tools', $body), 200);
        }

        $corpLabel = htmlspecialchars((string)$corp['label']);
        $icon = $corp['icons']['px64x64'] ?? null;
        $token = $tokenData($cid);
        $invoiceTotal = (float)($app->db->one(
            "SELECT COALESCE(SUM(amount),0) AS total
             FROM module_corptools_invoice_payments
             WHERE corp_id=? AND entry_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$corp['id']]
        )['total'] ?? 0);

        $miningTotal = (float)($app->db->one(
            "SELECT COALESCE(SUM(quantity),0) AS total
             FROM module_corptools_moon_events
             WHERE corp_id=? AND event_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$corp['id']]
        )['total'] ?? 0);

        $walletDelta = (float)($app->db->one(
            "SELECT COALESCE(SUM(amount),0) AS total
             FROM module_corptools_invoice_payments
             WHERE corp_id=? AND entry_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$corp['id']]
        )['total'] ?? 0);

        $notificationsCount = 0;
        $notificationsStatus = 'Needs scopes';
        $requiredNotifications = ['esi-characters.read_notifications.v1'];
        if (!$token['expired'] && $token['access_token'] && $hasScopes($token['scopes'], $requiredNotifications)) {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            $notifications = $cache->getCachedAuth(
                "corptools:notifications:{$cid}",
                "GET /latest/characters/{$cid}/notifications/",
                300,
                (string)$token['access_token'],
                [403, 404]
            );
            if (is_array($notifications)) {
                $notificationsCount = count($notifications);
                $notificationsStatus = 'Cached';
            } else {
                $notificationsStatus = 'Unavailable';
            }
        }

        $iconHtml = $icon ? "<img src='" . htmlspecialchars((string)$icon) . "' width='48' height='48' style='border-radius:10px;'>" : '';

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3'>
                    <div class='d-flex align-items-center gap-3'>
                      {$iconHtml}
                      <div>
                        <h1 class='mb-1'>Corp Tools</h1>
                        <div class='text-muted'>{$corpLabel}</div>
                      </div>
                    </div>
                    <div class='d-flex gap-2'>
                      <a class='btn btn-outline-light' href='/corptools/invoices'>Invoices</a>
                      <a class='btn btn-outline-light' href='/corptools/moons'>Moons</a>
                      <a class='btn btn-outline-light' href='/corptools/industry'>Industry</a>
                      <a class='btn btn-outline-light' href='/corptools/notifications'>Notifications</a>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Wallet delta (24h)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars($formatIsk($walletDelta)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Invoice total (7d)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars($formatIsk($invoiceTotal)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Mining total (7d)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars(number_format($miningTotal, 2)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Notifications</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$notificationsCount) . "</div>
                        <div class='text-muted small'>" . htmlspecialchars($notificationsStatus) . "</div>
                      </div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>At a glance</div>
                    <div class='text-muted'>Use the tabs above to drill into corp wallets, moon extractions, industry assets, and notifications.</div>
                  </div>";

        return Response::html($renderPage('Corp Tools', $body), 200);
    }, ['right' => 'corptools.view']);

    $registry->route('GET', '/corptools/invoices', function (Request $req) use ($app, $renderPage, $corpContext, $formatIsk, $tokenData, $hasScopes): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Invoices</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Invoices', $body), 200);
        }

        $start = (string)($req->query['start'] ?? '');
        $end = (string)($req->query['end'] ?? '');
        $params = [$corp['id']];
        $where = "corp_id=?";
        if ($start !== '') {
            $where .= " AND entry_date >= ?";
            $params[] = $start . ' 00:00:00';
        }
        if ($end !== '') {
            $where .= " AND entry_date <= ?";
            $params[] = $end . ' 23:59:59';
        }

        $token = $tokenData($cid);
        $requiredScopes = ['esi-wallet.read_corporation_wallets.v1'];
        $missingScopes = !$token['expired'] && $token['access_token'] && $hasScopes($token['scopes'], $requiredScopes) ? [] : $requiredScopes;

        $rows = $app->db->all(
            "SELECT entry_date, ref_type, amount, wallet_division
             FROM module_corptools_invoice_payments
             WHERE {$where}
             ORDER BY entry_date DESC
             LIMIT 200",
            $params
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $date = htmlspecialchars((string)($row['entry_date'] ?? ''));
            $refType = htmlspecialchars((string)($row['ref_type'] ?? ''));
            $amount = (float)($row['amount'] ?? 0);
            $division = (int)($row['wallet_division'] ?? 0);
            $rowsHtml .= "<tr>
                <td>{$date}</td>
                <td>{$refType}</td>
                <td>Division {$division}</td>
                <td>" . htmlspecialchars($formatIsk($amount)) . "</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='4' class='text-muted'>No invoice payments cached yet.</td></tr>";
        }

        $missingHtml = '';
        if (!empty($missingScopes)) {
            $missingList = htmlspecialchars(implode(', ', $missingScopes));
            $missingHtml = "<div class='alert alert-warning'>Missing scopes: {$missingList}. <a href='/charlink'>Link via Character Link Hub</a>.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Invoices</h1>
                      <div class='text-muted'>Wallet journal entries cached for invoice tracking.</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$missingHtml}</div>
                  <form class='card card-body mt-3' method='get'>
                    <div class='row g-2'>
                      <div class='col-md-4'>
                        <label class='form-label'>Start date</label>
                        <input type='date' class='form-control' name='start' value='" . htmlspecialchars($start) . "'>
                      </div>
                      <div class='col-md-4'>
                        <label class='form-label'>End date</label>
                        <input type='date' class='form-control' name='end' value='" . htmlspecialchars($end) . "'>
                      </div>
                      <div class='col-md-4 d-flex align-items-end'>
                        <button class='btn btn-primary me-2'>Filter</button>
                        <a class='btn btn-outline-secondary' href='/corptools/invoices'>Reset</a>
                      </div>
                    </div>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Division</th>
                            <th>Amount</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Invoices', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/moons', function () use ($app, $renderPage, $corpContext): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Moon Tracking</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Moon Tracking', $body), 200);
        }

        $rows = $app->db->all(
            "SELECT event_date, moon_name, pilot_name, ore_name, quantity, tax_rate
             FROM module_corptools_moon_events
             WHERE corp_id=?
             ORDER BY event_date DESC
             LIMIT 200",
            [$corp['id']]
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $date = htmlspecialchars((string)($row['event_date'] ?? ''));
            $moon = htmlspecialchars((string)($row['moon_name'] ?? 'Unknown'));
            $pilot = htmlspecialchars((string)($row['pilot_name'] ?? 'Unknown'));
            $ore = htmlspecialchars((string)($row['ore_name'] ?? 'Unknown'));
            $qty = htmlspecialchars((string)($row['quantity'] ?? '0'));
            $tax = htmlspecialchars((string)($row['tax_rate'] ?? '0'));
            $rowsHtml .= "<tr>
                <td>{$date}</td>
                <td>{$moon}</td>
                <td>{$pilot}</td>
                <td>{$ore}</td>
                <td>{$qty}</td>
                <td>{$tax}%</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='6' class='text-muted'>No moon events recorded yet.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Moon Tracking</h1>
                      <div class='text-muted'>Manual or imported moon extraction records.</div>
                    </div>
                  </div>
                  <form method='post' action='/corptools/moons/add' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-3'>
                        <label class='form-label'>Date</label>
                        <input type='date' class='form-control' name='event_date' required>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Moon</label>
                        <input type='text' class='form-control' name='moon_name' required>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Pilot</label>
                        <input type='text' class='form-control' name='pilot_name' required>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Ore</label>
                        <input type='text' class='form-control' name='ore_name' required>
                      </div>
                      <div class='col-md-1'>
                        <label class='form-label'>Qty</label>
                        <input type='number' step='0.01' class='form-control' name='quantity' required>
                      </div>
                      <div class='col-md-1'>
                        <label class='form-label'>Tax %</label>
                        <input type='number' step='0.01' class='form-control' name='tax_rate' value='0'>
                      </div>
                    </div>
                    <button class='btn btn-primary mt-3'>Add event</button>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>Moon</th>
                            <th>Pilot</th>
                            <th>Ore</th>
                            <th>Quantity</th>
                            <th>Tax</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Moon Tracking', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('POST', '/corptools/moons/add', function (Request $req) use ($app, $corpContext): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) return Response::redirect('/corptools/moons');

        $eventDate = (string)($req->post['event_date'] ?? '');
        $moonName = trim((string)($req->post['moon_name'] ?? ''));
        $pilotName = trim((string)($req->post['pilot_name'] ?? ''));
        $oreName = trim((string)($req->post['ore_name'] ?? ''));
        $quantity = (float)($req->post['quantity'] ?? 0);
        $taxRate = (float)($req->post['tax_rate'] ?? 0);

        if ($eventDate !== '' && $moonName !== '' && $pilotName !== '' && $oreName !== '') {
            $app->db->run(
                "INSERT INTO module_corptools_moon_events
                 (corp_id, event_date, moon_name, pilot_name, ore_name, quantity, tax_rate, created_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$corp['id'], $eventDate, $moonName, $pilotName, $oreName, $quantity, $taxRate, $uid]
            );
        }

        return Response::redirect('/corptools/moons');
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/industry', function () use ($app, $renderPage, $corpContext, $tokenData, $hasScopes): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Industry</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Industry', $body), 200);
        }

        $token = $tokenData($cid);
        $requiredScopes = ['esi-corporations.read_structures.v1'];
        $missingScopes = !$token['expired'] && $token['access_token'] && $hasScopes($token['scopes'], $requiredScopes) ? [] : $requiredScopes;

        $structures = [];
        $staleNote = '';
        if (empty($missingScopes)) {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            $structures = $cache->getCachedAuth(
                "corptools:structures:{$corp['id']}",
                "GET /latest/corporations/{$corp['id']}/structures/",
                3600,
                (string)$token['access_token'],
                [403, 404]
            );
            if (!is_array($structures)) $structures = [];
            if (empty($structures)) {
                $staleNote = "<div class='text-muted'>No structures cached yet or ESI returned no data.</div>";
            }
        }

        $rowsHtml = '';
        foreach ($structures as $structure) {
            if (!is_array($structure)) continue;
            $name = htmlspecialchars((string)($structure['name'] ?? 'Unknown structure'));
            $systemId = (int)($structure['system_id'] ?? 0);
            $systemName = 'Unknown system';
            if ($systemId > 0) {
                $u = new Universe($app->db);
                $systemNameRaw = $u->name('system', $systemId);
                $systemName = str_contains($systemNameRaw, '#') ? 'Unknown system' : $systemNameRaw;
            }
            $services = $structure['services'] ?? [];
            $serviceNames = [];
            if (is_array($services)) {
                foreach ($services as $service) {
                    if (!is_array($service)) continue;
                    $serviceNames[] = (string)($service['name'] ?? '');
                }
            }
            $serviceText = htmlspecialchars(implode(', ', array_filter($serviceNames)));
            if ($serviceText === '') $serviceText = '—';
            $rowsHtml .= "<tr>
                <td>{$name}</td>
                <td>" . htmlspecialchars($systemName) . "</td>
                <td>{$serviceText}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='3' class='text-muted'>No structure data available.</td></tr>";
        }

        $missingHtml = '';
        if (!empty($missingScopes)) {
            $missingList = htmlspecialchars(implode(', ', $missingScopes));
            $missingHtml = "<div class='alert alert-warning'>Missing scopes: {$missingList}. <a href='/charlink'>Link via Character Link Hub</a>.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Industry Dash</h1>
                      <div class='text-muted'>Structures, rigs, and services overview.</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$missingHtml}{$staleNote}</div>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Structure</th>
                            <th>System</th>
                            <th>Services</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Industry', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/notifications', function () use ($app, $renderPage, $tokenData, $hasScopes): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $token = $tokenData($cid);
        $requiredScopes = ['esi-characters.read_notifications.v1'];
        $missingScopes = !$token['expired'] && $token['access_token'] && $hasScopes($token['scopes'], $requiredScopes) ? [] : $requiredScopes;
        $notifications = [];

        if (empty($missingScopes)) {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            $notifications = $cache->getCachedAuth(
                "corptools:notifications:{$cid}",
                "GET /latest/characters/{$cid}/notifications/",
                300,
                (string)$token['access_token'],
                [403, 404]
            );
            if (!is_array($notifications)) $notifications = [];
        }

        $rowsHtml = '';
        foreach ($notifications as $note) {
            if (!is_array($note)) continue;
            $type = htmlspecialchars((string)($note['type'] ?? 'Unknown'));
            $timestamp = htmlspecialchars((string)($note['timestamp'] ?? ''));
            $rowsHtml .= "<tr>
                <td>{$timestamp}</td>
                <td>{$type}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='2' class='text-muted'>No notifications cached yet.</td></tr>";
        }

        $missingHtml = '';
        if (!empty($missingScopes)) {
            $missingList = htmlspecialchars(implode(', ', $missingScopes));
            $missingHtml = "<div class='alert alert-warning'>Missing scopes: {$missingList}. <a href='/charlink'>Link via Character Link Hub</a>.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Notifications</h1>
                      <div class='text-muted'>Character and corp pings (cached).</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$missingHtml}</div>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Timestamp</th>
                            <th>Type</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Notification rules</div>
                    <div class='text-muted'>Webhook dispatch is not yet implemented. Configure rules in Admin → Corp Tools.</div>
                  </div>";

        return Response::html($renderPage('Notifications', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/admin/corptools', function () use ($app, $renderPage): Response {
        $settingsRow = $app->db->one(
            "SELECT settings_json FROM module_corptools_settings WHERE scope_type='global' LIMIT 1"
        );
        $settings = [];
        if ($settingsRow) {
            $settings = json_decode((string)($settingsRow['settings_json'] ?? '[]'), true);
            if (!is_array($settings)) $settings = [];
        }

        $webhook = htmlspecialchars((string)($settings['webhook_url'] ?? ''));
        $rules = $app->db->all(
            "SELECT name, filters_json, is_enabled
             FROM module_corptools_notification_rules
             ORDER BY id DESC"
        );

        $ruleRows = '';
        foreach ($rules as $rule) {
            $name = htmlspecialchars((string)($rule['name'] ?? 'Rule'));
            $filters = json_decode((string)($rule['filters_json'] ?? '[]'), true);
            $filtersText = is_array($filters) ? implode(', ', array_filter($filters, 'is_string')) : '';
            $filtersText = htmlspecialchars($filtersText !== '' ? $filtersText : '—');
            $enabled = ((int)($rule['is_enabled'] ?? 0) === 1) ? 'Enabled' : 'Disabled';
            $ruleRows .= "<tr>
                <td>{$name}</td>
                <td>{$filtersText}</td>
                <td>{$enabled}</td>
              </tr>";
        }
        if ($ruleRows === '') {
            $ruleRows = "<tr><td colspan='3' class='text-muted'>No rules configured.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Corp Tools Settings</h1>
                      <div class='text-muted'>Configure webhooks and notification filters.</div>
                    </div>
                  </div>
                  <form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                    <label class='form-label'>Webhook URL (optional)</label>
                    <input class='form-control' name='webhook_url' value='{$webhook}' placeholder='https://...'>
                    <div class='form-text'>Webhook dispatch is staged for a future release.</div>
                    <button class='btn btn-primary mt-3'>Save settings</button>
                  </form>
                  <form method='post' action='/admin/corptools/rules' class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Add notification rule</div>
                    <div class='row g-2'>
                      <div class='col-md-4'>
                        <label class='form-label'>Rule name</label>
                        <input class='form-control' name='name' required>
                      </div>
                      <div class='col-md-6'>
                        <label class='form-label'>Filters (comma-separated)</label>
                        <input class='form-control' name='filters' placeholder='Region, System, Type'>
                      </div>
                      <div class='col-md-2 d-flex align-items-end'>
                        <button class='btn btn-outline-light'>Add</button>
                      </div>
                    </div>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Existing rules</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Name</th>
                            <th>Filters</th>
                            <th>Status</th>
                          </tr>
                        </thead>
                        <tbody>{$ruleRows}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Corp Tools Settings', $body), 200);
    }, ['right' => 'corptools.admin']);

    $registry->route('POST', '/admin/corptools/settings', function (Request $req) use ($app): Response {
        $webhook = trim((string)($req->post['webhook_url'] ?? ''));
        $settings = ['webhook_url' => $webhook];

        $app->db->run(
            "INSERT INTO module_corptools_settings (scope_type, scope_id, settings_json, created_at, updated_at)
             VALUES ('global', 0, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=NOW()",
            [json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );

        return Response::redirect('/admin/corptools');
    }, ['right' => 'corptools.admin']);

    $registry->route('POST', '/admin/corptools/rules', function (Request $req) use ($app): Response {
        $name = trim((string)($req->post['name'] ?? ''));
        $filtersRaw = trim((string)($req->post['filters'] ?? ''));
        if ($name !== '') {
            $filters = array_values(array_filter(array_map('trim', explode(',', $filtersRaw))));
            $app->db->run(
                "INSERT INTO module_corptools_notification_rules
                 (scope_type, scope_id, name, filters_json, is_enabled, created_at, updated_at)
                 VALUES ('global', 0, ?, ?, 1, NOW(), NOW())",
                [$name, json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
        }
        return Response::redirect('/admin/corptools');
    }, ['right' => 'corptools.admin']);
};
