<?php
declare(strict_types=1);

/*
Module Name: Character Linker
Description: Link multiple EVE characters to a single user account.
Version: 1.0.0
Module Slug: charlink
*/

use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Universe;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('charlink.view', 'Access the character link hub.');
    $registry->right('charlink.audit', 'Audit character link targets.');
    $registry->right('charlink.admin', 'Manage character links and link targets.');

    $registry->menu([
        'slug' => 'charlink.hub',
        'title' => 'Character Link Hub',
        'url' => '/charlink',
        'sort_order' => 24,
        'area' => 'left',
        'right_slug' => 'charlink.view',
    ]);

    $registry->menu([
        'slug' => 'charlink',
        'title' => 'Linked Characters',
        'url' => '/user/alts',
        'sort_order' => 25,
        'area' => 'left',
    ]);

    $registry->menu([
        'slug' => 'admin.charlink',
        'title' => 'Character Linker',
        'url' => '/admin/charlink',
        'sort_order' => 35,
        'area' => 'admin_top',
        'right_slug' => 'charlink.admin',
    ]);

    $registry->menu([
        'slug' => 'admin.charlink.audit',
        'title' => 'Link Audit',
        'url' => '/charlink/audit',
        'sort_order' => 36,
        'area' => 'admin_top',
        'parent_slug' => 'admin.charlink',
        'right_slug' => 'charlink.audit',
    ]);

    $registry->menu([
        'slug' => 'admin.charlink.targets',
        'title' => 'Link Targets',
        'url' => '/admin/charlink/targets',
        'sort_order' => 37,
        'area' => 'admin_top',
        'parent_slug' => 'admin.charlink',
        'right_slug' => 'charlink.admin',
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

    $defaultTargets = [
        'wallet' => [
            'name' => 'Wallet Access',
            'description' => 'Character wallet balances and transactions.',
            'scopes' => ['esi-wallet.read_character_wallet.v1'],
        ],
        'mining' => [
            'name' => 'Mining Ledger',
            'description' => 'Mining ledger and yield reports.',
            'scopes' => ['esi-industry.read_character_mining.v1'],
        ],
        'assets' => [
            'name' => 'Assets',
            'description' => 'Character assets and inventory snapshots.',
            'scopes' => ['esi-assets.read_assets.v1'],
        ],
        'contracts' => [
            'name' => 'Contracts',
            'description' => 'Personal contracts and deliveries.',
            'scopes' => ['esi-contracts.read_character_contracts.v1'],
        ],
        'notifications' => [
            'name' => 'Notifications',
            'description' => 'In-game notification feed.',
            'scopes' => ['esi-characters.read_notifications.v1'],
        ],
        'structures' => [
            'name' => 'Structures',
            'description' => 'Private structure access and services.',
            'scopes' => ['esi-universe.read_structures.v1'],
        ],
    ];

    $configuredTargets = $app->config['charlink']['targets'] ?? [];
    if (is_array($configuredTargets) && !empty($configuredTargets)) {
        foreach ($configuredTargets as $slug => $target) {
            if (!is_string($slug) || $slug === '' || !is_array($target)) continue;
            $defaultTargets[$slug] = array_merge(
                $defaultTargets[$slug] ?? [],
                [
                    'name' => (string)($target['name'] ?? $slug),
                    'description' => (string)($target['description'] ?? ''),
                    'scopes' => is_array($target['scopes'] ?? null) ? $target['scopes'] : [],
                ]
            );
        }
    }

    $loadTargets = function () use ($app, $defaultTargets): array {
        $rows = $app->db->all(
            "SELECT slug, name, description, scopes_json, is_enabled, is_ignored
             FROM module_charlink_targets"
        );

        $dbTargets = [];
        foreach ($rows as $row) {
            $slug = (string)($row['slug'] ?? '');
            if ($slug === '') continue;
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $dbTargets[$slug] = [
                'slug' => $slug,
                'name' => (string)($row['name'] ?? $slug),
                'description' => (string)($row['description'] ?? ''),
                'scopes' => $scopes,
                'is_enabled' => (int)($row['is_enabled'] ?? 1),
                'is_ignored' => (int)($row['is_ignored'] ?? 0),
            ];
        }

        $missing = [];
        foreach ($defaultTargets as $slug => $target) {
            if (!isset($dbTargets[$slug])) {
                $missing[$slug] = $target;
            }
        }

        foreach ($missing as $slug => $target) {
            $app->db->run(
                "INSERT INTO module_charlink_targets (slug, name, description, scopes_json, is_enabled, is_ignored, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())",
                [
                    $slug,
                    (string)$target['name'],
                    (string)$target['description'],
                    json_encode($target['scopes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]
            );
        }

        if (!empty($missing)) {
            $rows = $app->db->all(
                "SELECT slug, name, description, scopes_json, is_enabled, is_ignored
                 FROM module_charlink_targets"
            );
            $dbTargets = [];
            foreach ($rows as $row) {
                $slug = (string)($row['slug'] ?? '');
                if ($slug === '') continue;
                $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
                if (!is_array($scopes)) $scopes = [];
                $dbTargets[$slug] = [
                    'slug' => $slug,
                    'name' => (string)($row['name'] ?? $slug),
                    'description' => (string)($row['description'] ?? ''),
                    'scopes' => $scopes,
                    'is_enabled' => (int)($row['is_enabled'] ?? 1),
                    'is_ignored' => (int)($row['is_ignored'] ?? 0),
                ];
            }
        }

        $final = [];
        foreach ($defaultTargets as $slug => $target) {
            $row = $dbTargets[$slug] ?? null;
            $final[] = [
                'slug' => $slug,
                'name' => $row['name'] ?? $target['name'],
                'description' => $row['description'] ?? $target['description'],
                'scopes' => $row['scopes'] ?? $target['scopes'],
                'is_enabled' => $row['is_enabled'] ?? 1,
                'is_ignored' => $row['is_ignored'] ?? 0,
            ];
        }

        foreach ($dbTargets as $slug => $row) {
            if (isset($defaultTargets[$slug])) continue;
            $final[] = $row;
        }

        usort($final, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        return $final;
    };

    $tokenInfo = function (int $characterId) use ($app): array {
        $row = $app->db->one(
            "SELECT scopes_json, expires_at
             FROM eve_tokens
             WHERE character_id=? LIMIT 1",
            [$characterId]
        );
        $scopes = [];
        $expired = false;
        if ($row) {
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
            if ($expiresAt !== null && time() > $expiresAt) {
                $expired = true;
            }
        }
        return ['scopes' => $scopes, 'expired' => $expired];
    };

    $registry->route('GET', '/charlink', function () use ($app, $renderPage, $loadTargets, $tokenInfo): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $targets = array_values(array_filter($loadTargets(), fn($t) => (int)($t['is_ignored'] ?? 0) !== 1));

        $linkRow = $app->db->one(
            "SELECT enabled_targets_json
             FROM module_charlink_links
             WHERE user_id=? AND character_id=? LIMIT 1",
            [$uid, $cid]
        );
        $enabledTargets = [];
        if ($linkRow) {
            $enabledTargets = json_decode((string)($linkRow['enabled_targets_json'] ?? '[]'), true);
            if (!is_array($enabledTargets)) $enabledTargets = [];
        }

        $tokenData = $tokenInfo($cid);
        $tokenScopes = $tokenData['scopes'];
        $tokenExpired = (bool)$tokenData['expired'];

        $u = new Universe($app->db);
        $profile = $u->characterProfile($cid);
        $charName = htmlspecialchars($profile['character']['name'] ?? 'Unknown');
        $portrait = $profile['character']['portrait']['px128x128']
            ?? $profile['character']['portrait']['px64x64']
            ?? null;
        $corpName = htmlspecialchars($profile['corporation']['name'] ?? 'Unknown');
        $corpTicker = htmlspecialchars($profile['corporation']['ticker'] ?? '');

        $flash = $_SESSION['charlink_hub_flash'] ?? null;
        unset($_SESSION['charlink_hub_flash']);
        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type}'>{$message}</div>";
            }
        }

        $rowsHtml = '';
        foreach ($targets as $target) {
            $slug = (string)($target['slug'] ?? '');
            if ($slug === '') continue;
            $name = htmlspecialchars((string)($target['name'] ?? $slug));
            $desc = htmlspecialchars((string)($target['description'] ?? ''));
            $scopes = is_array($target['scopes'] ?? null) ? $target['scopes'] : [];
            $isEnabled = in_array($slug, $enabledTargets, true);
            $requiredScopes = array_values(array_unique(array_filter($scopes, 'is_string')));
            $missingScopes = array_values(array_diff($requiredScopes, $tokenScopes));

            $status = 'Not linked';
            $badgeClass = 'bg-secondary';
            if ($tokenExpired) {
                $status = 'Token expired';
                $badgeClass = 'bg-warning text-dark';
            } elseif ($isEnabled && empty($missingScopes)) {
                $status = 'Linked';
                $badgeClass = 'bg-success';
            } elseif ($isEnabled && !empty($missingScopes)) {
                $status = 'Missing scopes';
                $badgeClass = 'bg-danger';
            } elseif ((int)($target['is_enabled'] ?? 1) !== 1) {
                $status = 'Disabled';
                $badgeClass = 'bg-secondary';
            }

            $scopeBadges = '';
            if (!empty($requiredScopes)) {
                foreach ($requiredScopes as $scope) {
                    $scopeBadges .= "<span class='badge bg-dark me-1'>" . htmlspecialchars($scope) . "</span>";
                }
            } else {
                $scopeBadges = "<span class='text-muted'>No scopes required</span>";
            }

            $checked = $isEnabled ? 'checked' : '';

            $rowsHtml .= "<div class='card card-body mb-3'>
                <div class='d-flex flex-wrap justify-content-between align-items-start gap-3'>
                  <div>
                    <div class='fw-semibold'>{$name}</div>
                    <div class='text-muted small'>{$desc}</div>
                    <div class='mt-2'>{$scopeBadges}</div>
                  </div>
                  <div class='text-end'>
                    <span class='badge {$badgeClass}'>{$status}</span>
                    <div class='form-check mt-2'>
                      <input class='form-check-input' type='checkbox' name='targets[]' value='" . htmlspecialchars($slug) . "' id='target-{$slug}' {$checked}>
                      <label class='form-check-label small' for='target-{$slug}'>Enable</label>
                    </div>
                  </div>
                </div>
              </div>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<div class='text-muted'>No link targets configured.</div>";
        }

        $portraitHtml = $portrait ? "<img src='" . htmlspecialchars($portrait) . "' width='64' height='64' style='border-radius:10px;'>" : '';
        $corpLabel = $corpTicker !== '' ? "{$corpName} [{$corpTicker}]" : $corpName;

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3'>
                    <div>
                      <h1 class='mb-1'>Character Link Hub</h1>
                      <div class='text-muted'>Select the features you want to enable for this character.</div>
                    </div>
                    <a class='btn btn-outline-light' href='/user/alts'>Manage linked characters</a>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='d-flex align-items-center gap-3'>
                      {$portraitHtml}
                      <div>
                        <div class='fw-semibold'>{$charName}</div>
                        <div class='text-muted small'>{$corpLabel}</div>
                      </div>
                    </div>
                  </div>
                  <div class='mt-3'>{$flashHtml}</div>
                  <form method='post' action='/charlink/link'>
                    {$rowsHtml}
                    <div class='d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3'>
                      <div class='text-muted small'>Scopes will be requested via EVE SSO for the selected targets.</div>
                      <button class='btn btn-primary'>Link / Update character</button>
                    </div>
                  </form>";

        return Response::html($renderPage('Character Link Hub', $body), 200);
    }, ['right' => 'charlink.view']);

    $registry->route('POST', '/charlink/link', function (Request $req) use ($app, $loadTargets): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $targets = $loadTargets();
        $allowed = [];
        $scopes = [];
        foreach ($targets as $target) {
            $slug = (string)($target['slug'] ?? '');
            if ($slug === '') continue;
            if ((int)($target['is_enabled'] ?? 1) !== 1 || (int)($target['is_ignored'] ?? 0) === 1) {
                continue;
            }
            $allowed[$slug] = $target;
        }

        $selected = $req->post['targets'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_unique(array_filter($selected, fn($s) => is_string($s) && isset($allowed[$s]))));

        if (empty($selected)) {
            $_SESSION['charlink_hub_flash'] = ['type' => 'warning', 'message' => 'Select at least one target to link.'];
            return Response::redirect('/charlink');
        }

        foreach ($selected as $slug) {
            $targetScopes = $allowed[$slug]['scopes'] ?? [];
            if (is_array($targetScopes)) {
                foreach ($targetScopes as $scope) {
                    if (is_string($scope) && $scope !== '') {
                        $scopes[] = $scope;
                    }
                }
            }
        }

        $scopes = array_values(array_unique($scopes));
        $_SESSION['sso_scopes_override'] = $scopes;
        $_SESSION['charlink_pending_targets'] = $selected;
        $_SESSION['charlink_redirect'] = '/charlink';

        return Response::redirect('/auth/login');
    }, ['right' => 'charlink.view']);

    $registry->route('GET', '/charlink/audit', function (Request $req) use ($app, $renderPage, $loadTargets): Response {
        $targets = $loadTargets();
        $targetMap = [];
        foreach ($targets as $t) {
            $slug = (string)($t['slug'] ?? '');
            if ($slug === '') continue;
            $targetMap[$slug] = $t;
        }

        $filterTarget = (string)($req->query['target'] ?? '');
        $filterGroup = (string)($req->query['group'] ?? '');
        $filterCorp = (string)($req->query['corp'] ?? '');

        $rows = $app->db->all(
            "SELECT l.user_id, l.character_id, l.enabled_targets_json, l.updated_at
             FROM module_charlink_links l
             ORDER BY l.updated_at DESC
             LIMIT 500"
        );

        $groupRows = $app->db->all(
            "SELECT ug.user_id, g.name
             FROM eve_user_groups ug
             JOIN groups g ON g.id = ug.group_id"
        );
        $groupMap = [];
        foreach ($groupRows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $name = (string)($row['name'] ?? '');
            if ($userId <= 0 || $name === '') continue;
            $groupMap[$userId][] = $name;
        }

        $u = new Universe($app->db);
        $corpOptions = [];
        $groupOptions = [];
        $targetOptions = [];

        foreach ($groupMap as $names) {
            foreach ($names as $name) {
                $groupOptions[$name] = true;
            }
        }

        foreach ($targetMap as $slug => $t) {
            $targetOptions[$slug] = (string)($t['name'] ?? $slug);
        }

        $filtered = [];
        foreach ($rows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $characterId = (int)($row['character_id'] ?? 0);
            if ($userId <= 0 || $characterId <= 0) continue;

            $enabledTargets = json_decode((string)($row['enabled_targets_json'] ?? '[]'), true);
            if (!is_array($enabledTargets)) $enabledTargets = [];

            if ($filterTarget !== '' && !in_array($filterTarget, $enabledTargets, true)) {
                continue;
            }

            $groups = $groupMap[$userId] ?? [];
            if ($filterGroup !== '' && !in_array($filterGroup, $groups, true)) {
                continue;
            }

            $profile = $u->characterProfile($characterId);
            $corpName = (string)($profile['corporation']['name'] ?? '');
            $corpTicker = (string)($profile['corporation']['ticker'] ?? '');
            $corpLabel = $corpTicker !== '' ? "{$corpName} [{$corpTicker}]" : $corpName;

            if ($corpLabel !== '') {
                $corpOptions[$corpLabel] = true;
            }

            if ($filterCorp !== '' && strcasecmp($corpLabel, $filterCorp) !== 0) {
                continue;
            }

            $charName = (string)($profile['character']['name'] ?? 'Unknown');
            $filtered[] = [
                'character' => $charName,
                'corp' => $corpLabel !== '' ? $corpLabel : 'Unknown',
                'groups' => $groups,
                'targets' => $enabledTargets,
            ];
        }

        $targetSelect = "<option value=''>All targets</option>";
        foreach ($targetOptions as $slug => $name) {
            $selected = $filterTarget === $slug ? 'selected' : '';
            $targetSelect .= "<option value='" . htmlspecialchars($slug) . "' {$selected}>" . htmlspecialchars($name) . "</option>";
        }

        $groupSelect = "<option value=''>All groups</option>";
        foreach (array_keys($groupOptions) as $name) {
            $selected = $filterGroup === $name ? 'selected' : '';
            $groupSelect .= "<option value='" . htmlspecialchars($name) . "' {$selected}>" . htmlspecialchars($name) . "</option>";
        }

        $corpSelect = "<option value=''>All corporations</option>";
        foreach (array_keys($corpOptions) as $name) {
            $selected = $filterCorp === $name ? 'selected' : '';
            $corpSelect .= "<option value='" . htmlspecialchars($name) . "' {$selected}>" . htmlspecialchars($name) . "</option>";
        }

        $rowsHtml = '';
        foreach ($filtered as $row) {
            $charName = htmlspecialchars($row['character']);
            $corpLabel = htmlspecialchars($row['corp']);
            $groups = $row['groups'] ?: ['None'];
            $targetsList = [];
            foreach ($row['targets'] as $slug) {
                $targetsList[] = $targetMap[$slug]['name'] ?? $slug;
            }
            $groupsHtml = htmlspecialchars(implode(', ', $groups));
            $targetsHtml = htmlspecialchars(implode(', ', $targetsList));

            $rowsHtml .= "<tr>
                <td>{$charName}</td>
                <td>{$corpLabel}</td>
                <td>{$groupsHtml}</td>
                <td>{$targetsHtml}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='4' class='text-muted'>No matching links found.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Character Link Audit</h1>
                      <div class='text-muted'>Review which targets are enabled across linked characters.</div>
                    </div>
                  </div>
                  <form method='get' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-4'>
                        <label class='form-label'>Target</label>
                        <select class='form-select' name='target'>{$targetSelect}</select>
                      </div>
                      <div class='col-md-4'>
                        <label class='form-label'>Group</label>
                        <select class='form-select' name='group'>{$groupSelect}</select>
                      </div>
                      <div class='col-md-4'>
                        <label class='form-label'>Corporation</label>
                        <select class='form-select' name='corp'>{$corpSelect}</select>
                      </div>
                    </div>
                    <div class='mt-3'>
                      <button class='btn btn-primary'>Filter</button>
                      <a class='btn btn-outline-secondary ms-2' href='/charlink/audit'>Reset</a>
                    </div>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle mb-0'>
                        <thead>
                          <tr>
                            <th>Character</th>
                            <th>Corporation</th>
                            <th>Groups</th>
                            <th>Targets</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Character Link Audit', $body), 200);
    }, ['right' => 'charlink.audit']);

    $registry->route('GET', '/admin/charlink/targets', function () use ($app, $renderPage, $loadTargets): Response {
        $flash = $_SESSION['charlink_targets_flash'] ?? null;
        unset($_SESSION['charlink_targets_flash']);
        $targets = $loadTargets();
        $rowsHtml = '';
        foreach ($targets as $target) {
            $slug = (string)($target['slug'] ?? '');
            if ($slug === '') continue;
            $name = htmlspecialchars((string)($target['name'] ?? $slug));
            $desc = htmlspecialchars((string)($target['description'] ?? ''));
            $scopes = is_array($target['scopes'] ?? null) ? $target['scopes'] : [];
            $scopeText = htmlspecialchars(implode(', ', array_filter($scopes, 'is_string')));
            $enabledChecked = ((int)($target['is_enabled'] ?? 1) === 1) ? 'checked' : '';
            $ignoredChecked = ((int)($target['is_ignored'] ?? 0) === 1) ? 'checked' : '';
            $rowsHtml .= "<tr>
                <td><strong>{$name}</strong><div class='text-muted small'>{$desc}</div></td>
                <td class='small'>{$scopeText}</td>
                <td class='text-center'>
                  <input type='checkbox' class='form-check-input' name='enabled[{$slug}]' {$enabledChecked}>
                </td>
                <td class='text-center'>
                  <input type='checkbox' class='form-check-input' name='ignored[{$slug}]' {$ignoredChecked}>
                </td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='4' class='text-muted'>No targets registered.</td></tr>";
        }

        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type}'>{$message}</div>";
            }
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Link Targets</h1>
                      <div class='text-muted'>Enable, disable, or ignore link targets globally.</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$flashHtml}</div>
                  <form method='post' action='/admin/charlink/targets/update' class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle mb-0'>
                        <thead>
                          <tr>
                            <th>Target</th>
                            <th>Scopes</th>
                            <th class='text-center'>Enabled</th>
                            <th class='text-center'>Ignored</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                    <div class='mt-3'>
                      <button class='btn btn-primary'>Save changes</button>
                    </div>
                  </form>";

        return Response::html($renderPage('Link Targets', $body), 200);
    }, ['right' => 'charlink.admin']);

    $registry->route('POST', '/admin/charlink/targets/update', function (Request $req) use ($app, $loadTargets): Response {
        $targets = $loadTargets();
        $enabled = $req->post['enabled'] ?? [];
        $ignored = $req->post['ignored'] ?? [];
        if (!is_array($enabled)) $enabled = [];
        if (!is_array($ignored)) $ignored = [];

        foreach ($targets as $target) {
            $slug = (string)($target['slug'] ?? '');
            if ($slug === '') continue;
            $isEnabled = array_key_exists($slug, $enabled) ? 1 : 0;
            $isIgnored = array_key_exists($slug, $ignored) ? 1 : 0;
            $app->db->run(
                "UPDATE module_charlink_targets
                 SET is_enabled=?, is_ignored=?, updated_at=NOW()
                 WHERE slug=?",
                [$isEnabled, $isIgnored, $slug]
            );
        }

        $_SESSION['charlink_targets_flash'] = ['type' => 'success', 'message' => 'Targets updated.'];
        return Response::redirect('/admin/charlink/targets');
    }, ['right' => 'charlink.admin']);

    $registry->route('POST', '/user/alts/link-start', function () use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $_SESSION['charlink_link_user'] = $uid;
        return Response::redirect('/auth/login');
    });

    $registry->route('GET', '/charlink/activate', function (Request $req) use ($renderPage): Response {
        $token = $req->query['token'] ?? '';
        if (!is_string($token) || $token === '') {
            $body = "<h1>Character Linker</h1>
                     <div class='alert alert-warning mt-3'>Missing link token. Ask your admin or main character to generate a new link token.</div>";
            return Response::html($renderPage('Character Linker', $body), 200);
        }

        $_SESSION['charlink_token'] = $token;
        return Response::redirect('/auth/login');
    }, ['public' => true]);

    $registry->route('POST', '/user/alts/token', function () use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $tokenPrefix = substr($rawToken, 0, 8);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

        $app->db->run(
            "INSERT INTO character_link_tokens (user_id, token_hash, token_prefix, expires_at)
             VALUES (?, ?, ?, ?)",
            [$uid, $tokenHash, $tokenPrefix, $expiresAt]
        );

        $_SESSION['charlink_new_token'] = [
            'token' => $rawToken,
            'expires_at' => $expiresAt,
        ];

        return Response::redirect('/user/alts');
    });

    $registry->route('POST', '/user/alts/revoke', function (Request $req) use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $characterId = (int)($req->post['character_id'] ?? 0);
        if ($characterId > 0) {
            $app->db->run(
                "UPDATE character_links
                 SET status='revoked', revoked_at=NOW(), revoked_by_user_id=?
                 WHERE user_id=? AND character_id=?",
                [$uid, $uid, $characterId]
            );
        }

        return Response::redirect('/user/alts');
    });

    $registry->route('POST', '/user/alts/token-delete', function (Request $req) use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $tokenId = (int)($req->post['token_id'] ?? 0);
        if ($tokenId > 0) {
            $app->db->run(
                "DELETE FROM character_link_tokens WHERE id=? AND user_id=?",
                [$tokenId, $uid]
            );
        }

        return Response::redirect('/user/alts');
    });

    $registry->route('GET', '/user/alts', function () use ($app, $renderPage): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $flash = $_SESSION['charlink_flash'] ?? null;
        unset($_SESSION['charlink_flash']);
        $newToken = $_SESSION['charlink_new_token'] ?? null;
        unset($_SESSION['charlink_new_token']);

        $primary = $app->db->one("SELECT id, character_id, character_name FROM eve_users WHERE id=? LIMIT 1", [$uid]);
        $links = $app->db->all(
            "SELECT character_id, character_name, linked_at
             FROM character_links
             WHERE user_id=? AND status='linked'
             ORDER BY linked_at ASC",
            [$uid]
        );
        $tokens = $app->db->all(
            "SELECT id, token_prefix, expires_at, used_at, used_character_id
             FROM character_link_tokens
             WHERE user_id=?
             ORDER BY created_at DESC
             LIMIT 50",
            [$uid]
        );

        $u = new Universe($app->db);
        $getPortrait = function (int $characterId) use ($u): ?string {
            $profile = $u->characterProfile($characterId);
            return $profile['character']['portrait']['px128x128']
                ?? $profile['character']['portrait']['px64x64']
                ?? null;
        };

        $cards = '';
        if ($primary && isset($primary['character_id'])) {
            $primaryId = (int)$primary['character_id'];
            $primaryName = htmlspecialchars((string)($primary['character_name'] ?? 'Unknown'));
            $portrait = $getPortrait($primaryId);
            $badge = $primaryId === $cid ? "<span class='badge bg-success ms-2'>Current</span>" : "<span class='badge bg-primary ms-2'>Main</span>";

            $cards .= "<div class='col-md-6'>
                <div class='card card-body'>
                  <div class='d-flex align-items-center gap-3'>";
            if ($portrait) {
                $cards .= "<img src='" . htmlspecialchars($portrait) . "' width='64' height='64' style='border-radius:12px;'>";
            }
            $cards .= "<div>
                    <div class='fw-semibold'>{$primaryName}{$badge}</div>
                  </div>
                </div>
              </div>
            </div>";
        }

        foreach ($links as $link) {
            $linkId = (int)($link['character_id'] ?? 0);
            if ($linkId <= 0) continue;
            $linkName = htmlspecialchars((string)($link['character_name'] ?? 'Unknown'));
            $portrait = $getPortrait($linkId);
            $isCurrent = $linkId === $cid;
            $badge = $isCurrent ? "<span class='badge bg-success ms-2'>Current</span>" : '';
            $linkedAt = htmlspecialchars((string)($link['linked_at'] ?? ''));

            $cards .= "<div class='col-md-6'>
                <div class='card card-body'>
                  <div class='d-flex align-items-center justify-content-between gap-3'>
                    <div class='d-flex align-items-center gap-3'>";
            if ($portrait) {
                $cards .= "<img src='" . htmlspecialchars($portrait) . "' width='64' height='64' style='border-radius:12px;'>";
            }
            $cards .= "<div>
                        <div class='fw-semibold'>{$linkName}{$badge}</div>
                        <div class='text-muted small'>Linked {$linkedAt}</div>
                      </div>
                    </div>
                    <form method='post' action='/user/alts/revoke' onsubmit=\"return confirm('Unlink {$linkName}?');\">
                      <input type='hidden' name='character_id' value='{$linkId}'>
                      <button class='btn btn-sm btn-outline-danger'>Unlink</button>
                    </form>
                  </div>
                </div>
              </div>";
        }

        if ($cards === '') {
            $cards = "<div class='col-12'><div class='text-muted'>No linked characters yet.</div></div>";
        }

        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type}'>{$message}</div>";
            }
        }

        $tokenHtml = '';
        if (is_array($newToken)) {
            $token = htmlspecialchars((string)($newToken['token'] ?? ''));
            $expiresAt = htmlspecialchars((string)($newToken['expires_at'] ?? ''));
            if ($token !== '') {
                $linkUrl = '/charlink/activate?token=' . urlencode((string)($newToken['token'] ?? ''));
                $tokenHtml = "<div class='alert alert-info'>
                    <div class='fw-semibold'>New link token</div>
                    <div class='mt-2'><code>{$token}</code></div>
                    <div class='small text-muted mt-1'>Expires at {$expiresAt} UTC. Share this with your alt.</div>
                    <div class='mt-2'><a class='btn btn-sm btn-primary' href='" . htmlspecialchars($linkUrl) . "'>Start linking</a></div>
                  </div>";
            }
        }

        $tokenRows = '';
        foreach ($tokens as $tokenRow) {
            $tokenId = (int)($tokenRow['id'] ?? 0);
            $prefix = htmlspecialchars((string)($tokenRow['token_prefix'] ?? ''));
            $expiresAt = htmlspecialchars((string)($tokenRow['expires_at'] ?? ''));
            $usedAt = htmlspecialchars((string)($tokenRow['used_at'] ?? ''));
            $status = $usedAt !== '' ? "Used" : 'Active';

            $tokenRows .= "<tr>
                <td>{$prefix}</td>
                <td>{$expiresAt}</td>
                <td>{$status}</td>
                <td>
                  <form method='post' action='/user/alts/token-delete' onsubmit=\"return confirm('Delete token {$prefix}?');\">
                    <input type='hidden' name='token_id' value='{$tokenId}'>
                    <button class='btn btn-sm btn-outline-secondary'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($tokenRows === '') {
            $tokenRows = "<tr><td colspan='4' class='text-muted'>No tokens issued.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3'>
                    <div>
                      <h1 class='mb-1'>Linked Characters</h1>
                      <div class='text-muted'>Manage your alt characters and link new pilots.</div>
                    </div>
                    <div class='d-flex gap-2'>
                      <form method='post' action='/user/alts/link-start'>
                        <button class='btn btn-outline-primary'>Link New Character</button>
                      </form>
                      <form method='post' action='/user/alts/token'>
                        <button class='btn btn-primary'>Generate Link Token</button>
                      </form>
                    </div>
                  </div>
                  <div class='mt-3'>{$flashHtml}{$tokenHtml}</div>
                  <div class='row g-3 mt-1'>{$cards}</div>
                  <div class='card card-body mt-4'>
                    <div class='fw-semibold mb-2'>Issued tokens</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle mb-0'>
                        <thead>
                          <tr>
                            <th>Token prefix</th>
                            <th>Expires at</th>
                            <th>Status</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$tokenRows}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-4'>
                    <div class='fw-semibold'>How it works</div>
                    <ol class='mb-0 mt-2'>
                      <li>Generate a link token.</li>
                      <li>Open the link token URL in another browser (or share with your alt).</li>
                      <li>Log in with the character you want to link.</li>
                    </ol>
                  </div>";

        return Response::html($renderPage('Linked Characters', $body), 200);
    });

    $registry->route('GET', '/admin/charlink', function () use ($app, $renderPage): Response {
        $flash = $_SESSION['charlink_admin_flash'] ?? null;
        unset($_SESSION['charlink_admin_flash']);

        $links = $app->db->all(
            "SELECT cl.character_id, cl.character_name, cl.user_id, cl.linked_at, eu.character_name AS user_name
             FROM character_links cl
             JOIN eve_users eu ON eu.id = cl.user_id
             WHERE cl.status='linked'
             ORDER BY cl.linked_at DESC
             LIMIT 200"
        );

        $tokens = $app->db->all(
            "SELECT t.id, t.token_prefix, t.expires_at, t.used_at, t.used_character_id, eu.character_name AS user_name
             FROM character_link_tokens t
             JOIN eve_users eu ON eu.id = t.user_id
             ORDER BY t.created_at DESC
             LIMIT 200"
        );

        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type}'>{$message}</div>";
            }
        }

        $linkRows = '';
        foreach ($links as $link) {
            $linkId = (int)($link['character_id'] ?? 0);
            $linkName = htmlspecialchars((string)($link['character_name'] ?? ''));
            $userName = htmlspecialchars((string)($link['user_name'] ?? ''));
            $linkedAt = htmlspecialchars((string)($link['linked_at'] ?? ''));
            $linkRows .= "<tr>
                <td>{$linkName}</td>
                <td>{$userName}</td>
                <td>{$linkedAt}</td>
                <td>
                  <form method='post' action='/admin/charlink/revoke' onsubmit=\"return confirm('Revoke link for {$linkName}?');\">
                    <input type='hidden' name='character_id' value='{$linkId}'>
                    <button class='btn btn-sm btn-outline-danger'>Revoke</button>
                  </form>
                </td>
              </tr>";
        }
        if ($linkRows === '') {
            $linkRows = "<tr><td colspan='4' class='text-muted'>No active links.</td></tr>";
        }

        $tokenRows = '';
        foreach ($tokens as $token) {
            $tokenId = (int)($token['id'] ?? 0);
            $prefix = htmlspecialchars((string)($token['token_prefix'] ?? ''));
            $userName = htmlspecialchars((string)($token['user_name'] ?? ''));
            $expiresAt = htmlspecialchars((string)($token['expires_at'] ?? ''));
            $usedAt = htmlspecialchars((string)($token['used_at'] ?? ''));
            $status = $usedAt !== '' ? "Used" : 'Active';
            $tokenRows .= "<tr>
                <td>{$prefix}</td>
                <td>{$userName}</td>
                <td>{$expiresAt}</td>
                <td>{$status}</td>
                <td>
                  <form method='post' action='/admin/charlink/token-revoke' onsubmit=\"return confirm('Delete token {$prefix}?');\">
                    <input type='hidden' name='token_id' value='{$tokenId}'>
                    <button class='btn btn-sm btn-outline-secondary'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($tokenRows === '') {
            $tokenRows = "<tr><td colspan='5' class='text-muted'>No tokens issued.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Character Linker</h1>
                      <div class='text-muted'>Review linked characters and issued link tokens.</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$flashHtml}</div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Active Links</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Character</th>
                            <th>Primary</th>
                            <th>Linked at</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$linkRows}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Link Tokens</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Token prefix</th>
                            <th>Primary</th>
                            <th>Expires at</th>
                            <th>Status</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$tokenRows}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Character Linker', $body), 200);
    }, ['right' => 'charlink.admin']);

    $registry->route('POST', '/admin/charlink/revoke', function (Request $req) use ($app): Response {
        $characterId = (int)($req->post['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($characterId > 0) {
            $app->db->run(
                "UPDATE character_links
                 SET status='revoked', revoked_at=NOW(), revoked_by_user_id=?
                 WHERE character_id=?",
                [$uid, $characterId]
            );
            $_SESSION['charlink_admin_flash'] = ['type' => 'info', 'message' => 'Link revoked.'];
        }
        return Response::redirect('/admin/charlink');
    }, ['right' => 'charlink.admin']);

    $registry->route('POST', '/admin/charlink/token-revoke', function (Request $req) use ($app): Response {
        $tokenId = (int)($req->post['token_id'] ?? 0);
        if ($tokenId > 0) {
            $app->db->run("DELETE FROM character_link_tokens WHERE id=?", [$tokenId]);
            $_SESSION['charlink_admin_flash'] = ['type' => 'info', 'message' => 'Token deleted.'];
        }
        return Response::redirect('/admin/charlink');
    }, ['right' => 'charlink.admin']);
};
