<?php
declare(strict_types=1);

/*
Module Name: Secure Groups
Description: Rule-based, auditable group membership using data providers.
Version: 1.0.0
Module Slug: securegroups
*/

use App\Core\App;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Corptools\Cron\JobRegistry;
use App\Corptools\Cron\JobRunner;
use App\Http\Request;
use App\Http\Response;
use App\Securegroups\ProviderRegistry;
use App\Securegroups\SecureGroupService;
use App\Securegroups\Providers\CorptoolsActivityProvider;
use App\Securegroups\Providers\CorptoolsMemberProvider;
use App\Securegroups\Providers\CorptoolsWalletProvider;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('securegroups.admin', 'Manage Secure Groups configuration and enforcement.');

    $registry->menu([
        'slug' => 'securegroups.member',
        'title' => 'Secure Groups',
        'url' => '/securegroups',
        'sort_order' => 60,
        'area' => 'left',
    ]);

    $registry->menu([
        'slug' => 'securegroups.admin_tools',
        'title' => 'Secure Groups Admin',
        'url' => '/admin/securegroups',
        'sort_order' => 70,
        'area' => 'left',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.groups',
        'title' => 'Groups',
        'url' => '/admin/securegroups',
        'sort_order' => 71,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.logs',
        'title' => 'Enforcement Logs',
        'url' => '/admin/securegroups/logs',
        'sort_order' => 72,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.overrides',
        'title' => 'Manual Overrides',
        'url' => '/admin/securegroups/overrides',
        'sort_order' => 73,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.securegroups',
        'title' => 'Secure Groups',
        'url' => '/admin/securegroups',
        'sort_order' => 50,
        'area' => 'admin_top',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.securegroups.logs',
        'title' => 'Secure Groups Logs',
        'url' => '/admin/securegroups/logs',
        'sort_order' => 51,
        'area' => 'admin_top',
        'parent_slug' => 'admin.securegroups',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.cron',
        'title' => 'Cron Manager',
        'url' => '/admin/cron',
        'sort_order' => 55,
        'area' => 'admin_top',
        'right_slug' => 'securegroups.admin',
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

    $requireLogin = function (): ?Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($cid <= 0) {
            return Response::redirect('/auth/login');
        }
        return null;
    };

    $requireRight = function (string $right) use ($app): ?Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $rights = new Rights($app->db);
        if ($uid <= 0 || !$rights->userHasRight($uid, $right)) {
            return Response::text('403 Forbidden', 403);
        }
        return null;
    };

    $providerRegistry = new ProviderRegistry();
    $providerRegistry->register(new CorptoolsMemberProvider($app->db));
    $providerRegistry->register(new CorptoolsWalletProvider($app->db));
    $providerRegistry->register(new CorptoolsActivityProvider($app->db));
    $secureGroups = new SecureGroupService($app->db, $providerRegistry);

    $ruleCatalog = $providerRegistry->rulesCatalog();

    $logJobRun = function (string $jobKey, array $data) use ($app): void {
        $app->db->run(
            "INSERT INTO module_securegroups_job_runs\n"
            . " (job_key, started_at, ended_at, success, processed_count, changed_count, error_count, log_excerpt, details_json)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $jobKey,
                $data['started_at'] ?? date('Y-m-d H:i:s'),
                $data['ended_at'] ?? date('Y-m-d H:i:s'),
                $data['success'] ?? 1,
                $data['processed_count'] ?? 0,
                $data['changed_count'] ?? 0,
                $data['error_count'] ?? 0,
                $data['log_excerpt'] ?? '',
                json_encode($data['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    };

    $evaluateGroups = function (App $app, SecureGroupService $service): array {
        $start = microtime(true);
        $groups = $app->db->all("SELECT * FROM module_securegroups_groups WHERE enabled=1 ORDER BY id ASC");
        $users = $app->db->all("SELECT id FROM eve_users ORDER BY id ASC");
        $processed = 0;
        $logLines = [];

        foreach ($groups as $group) {
            $groupId = (int)($group['id'] ?? 0);
            if ($groupId <= 0) continue;
            $rules = $app->db->all(
                "SELECT * FROM module_securegroups_rules WHERE group_id=? ORDER BY id ASC",
                [$groupId]
            );

            foreach ($users as $user) {
                $userId = (int)($user['id'] ?? 0);
                if ($userId <= 0) continue;
                $result = $service->evaluateGroup($userId, $group, $rules);
                $processed++;
                $app->db->run(
                    "INSERT INTO module_securegroups_membership\n"
                    . " (group_id, user_id, status, source, eval_status, eval_reason_summary, eval_evidence_json, last_evaluated_at, updated_at)\n"
                    . " VALUES (?, ?, 'out', 'auto', ?, ?, ?, NOW(), NOW())\n"
                    . " ON DUPLICATE KEY UPDATE\n"
                    . " eval_status=VALUES(eval_status),\n"
                    . " eval_reason_summary=VALUES(eval_reason_summary),\n"
                    . " eval_evidence_json=VALUES(eval_evidence_json),\n"
                    . " last_evaluated_at=NOW(),\n"
                    . " updated_at=NOW()",
                    [
                        $groupId,
                        $userId,
                        $result['status'],
                        $result['reason'],
                        json_encode($result['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]
                );
            }

            $logLines[] = "Evaluated group {$groupId} with " . count($users) . " users.";
        }

        $durationMs = (int)round((microtime(true) - $start) * 1000);
        return [
            'status' => 'success',
            'message' => "Evaluated {$processed} memberships.",
            'metrics' => ['processed' => $processed, 'duration_ms' => $durationMs],
            'log_lines' => $logLines,
        ];
    };

    $applyGroups = function (App $app): array {
        $start = microtime(true);
        $groups = $app->db->all("SELECT * FROM module_securegroups_groups WHERE enabled=1 ORDER BY id ASC");
        $processed = 0;
        $changed = 0;
        $logLines = [];
        $changes = [];

        foreach ($groups as $group) {
            $groupId = (int)($group['id'] ?? 0);
            if ($groupId <= 0) continue;

            $mode = (string)($group['enforcement_mode'] ?? 'dry-run');
            $isApply = $mode === 'apply';

            $rows = $app->db->all(
                "SELECT * FROM module_securegroups_membership WHERE group_id=?",
                [$groupId]
            );
            foreach ($rows as $row) {
                $processed++;
                $userId = (int)($row['user_id'] ?? 0);
                $current = (string)($row['status'] ?? 'out');
                $evaluated = (string)($row['eval_status'] ?? 'pending');
                $source = (string)($row['source'] ?? 'auto');
                $manualSticky = (int)($row['manual_sticky'] ?? 0) === 1;

                if (!in_array($evaluated, ['in', 'out'], true)) {
                    continue;
                }
                if ($manualSticky && $source === 'manual') {
                    continue;
                }
                if ($evaluated === $current) {
                    continue;
                }

                $changed++;
                $changes[] = [
                    'group_id' => $groupId,
                    'user_id' => $userId,
                    'from' => $current,
                    'to' => $evaluated,
                    'reason' => (string)($row['eval_reason_summary'] ?? ''),
                ];

                if ($isApply) {
                    $app->db->run(
                        "UPDATE module_securegroups_membership\n"
                        . " SET status=?, source='auto', reason_summary=?, evidence_json=?, last_changed_at=NOW(), updated_at=NOW()\n"
                        . " WHERE id=?",
                        [
                            $evaluated,
                            $row['eval_reason_summary'] ?? '',
                            $row['eval_evidence_json'] ?? null,
                            (int)($row['id'] ?? 0),
                        ]
                    );
                }
            }

            $logLines[] = "Applied group {$groupId} ({$mode}) processed {$processed}.";
        }

        $durationMs = (int)round((microtime(true) - $start) * 1000);
        return [
            'status' => 'success',
            'message' => $changed > 0 ? "Applied {$changed} membership changes." : 'No membership changes needed.',
            'metrics' => ['processed' => $processed, 'changed' => $changed, 'duration_ms' => $durationMs],
            'log_lines' => $logLines,
            'changes' => $changes,
        ];
    };

    $jobDefinitions = [
        [
            'key' => 'securegroups.evaluate',
            'name' => 'Secure Groups: Evaluate memberships',
            'description' => 'Evaluate all Secure Group rules and store evidence.',
            'schedule' => 3600,
            'enabled' => 1,
            'handler' => function (App $app, array $context = []) use ($secureGroups, $evaluateGroups, $logJobRun): array {
                $started = date('Y-m-d H:i:s');
                $result = $evaluateGroups($app, $secureGroups);
                $logJobRun('securegroups.evaluate', [
                    'started_at' => $started,
                    'ended_at' => date('Y-m-d H:i:s'),
                    'success' => ($result['status'] ?? '') === 'success' ? 1 : 0,
                    'processed_count' => (int)($result['metrics']['processed'] ?? 0),
                    'changed_count' => 0,
                    'error_count' => ($result['status'] ?? '') === 'success' ? 0 : 1,
                    'log_excerpt' => substr((string)($result['message'] ?? ''), 0, 255),
                    'details' => $result,
                ]);
                return $result;
            },
        ],
        [
            'key' => 'securegroups.apply',
            'name' => 'Secure Groups: Apply memberships',
            'description' => 'Apply Secure Group membership changes.',
            'schedule' => 3600,
            'enabled' => 1,
            'handler' => function (App $app, array $context = []) use ($applyGroups, $logJobRun): array {
                $started = date('Y-m-d H:i:s');
                $result = $applyGroups($app);
                $logJobRun('securegroups.apply', [
                    'started_at' => $started,
                    'ended_at' => date('Y-m-d H:i:s'),
                    'success' => ($result['status'] ?? '') === 'success' ? 1 : 0,
                    'processed_count' => (int)($result['metrics']['processed'] ?? 0),
                    'changed_count' => (int)($result['metrics']['changed'] ?? 0),
                    'error_count' => ($result['status'] ?? '') === 'success' ? 0 : 1,
                    'log_excerpt' => substr((string)($result['message'] ?? ''), 0, 255),
                    'details' => $result,
                ]);
                return $result;
            },
        ],
    ];

    foreach ($jobDefinitions as $definition) {
        JobRegistry::register($definition);
    }
    JobRegistry::sync($app->db);

    $registry->route('GET', '/securegroups', function () use ($app, $renderPage, $requireLogin): Response {
        if ($resp = $requireLogin()) return $resp;
        $uid = (int)($_SESSION['user_id'] ?? 0);

        $rights = new Rights($app->db);
        $canAdmin = $uid > 0 && $rights->userHasRight($uid, 'securegroups.admin');

        $groups = $app->db->all(
            "SELECT g.id, g.name, g.description, g.visibility, m.status, m.source, m.reason_summary, m.last_evaluated_at\n"
            . " FROM module_securegroups_groups g\n"
            . " LEFT JOIN module_securegroups_membership m ON m.group_id=g.id AND m.user_id=?\n"
            . " WHERE g.enabled=1 AND (g.visibility='member_visible' OR ?=1)\n"
            . " ORDER BY g.name ASC",
            [$uid, $canAdmin ? 1 : 0]
        );

        $rows = '';
        foreach ($groups as $group) {
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($group['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($group['description'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($group['status'] ?? 'unknown')) . "</td>
                <td>" . htmlspecialchars((string)($group['source'] ?? 'auto')) . "</td>
                <td>" . htmlspecialchars((string)($group['reason_summary'] ?? '—')) . "</td>
                <td>" . htmlspecialchars((string)($group['last_evaluated_at'] ?? '—')) . "</td>
            </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='6' class='text-muted'>No secure groups visible.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Secure Groups</h1>
            <p class='text-muted'>These groups are evaluated automatically. Contact HR if you think something is missing.</p>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Group</th><th>Description</th><th>Status</th><th>Source</th><th>Reason</th><th>Last Evaluated</th>
                  </tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Groups', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $search = trim((string)($req->query['search'] ?? ''));
        $enabledFilter = (string)($req->query['enabled'] ?? '');
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = "g.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($enabledFilter !== '') {
            $where[] = "g.enabled=?";
            $params[] = (int)($enabledFilter === '1');
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $groups = $app->db->all(
            "SELECT g.*, SUM(CASE WHEN m.status='in' THEN 1 ELSE 0 END) AS members_in\n"
            . " FROM module_securegroups_groups g\n"
            . " LEFT JOIN module_securegroups_membership m ON m.group_id=g.id\n"
            . " {$whereSql}\n"
            . " GROUP BY g.id\n"
            . " ORDER BY g.name ASC",
            $params
        );

        $rows = '';
        foreach ($groups as $group) {
            $gid = (int)($group['id'] ?? 0);
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($group['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($group['description'] ?? '')) . "</td>
                <td>" . ((int)($group['enabled'] ?? 0) === 1 ? 'Yes' : 'No') . "</td>
                <td>" . htmlspecialchars((string)($group['members_in'] ?? 0)) . "</td>
                <td>
                  <a class='btn btn-sm btn-outline-primary' href='/admin/securegroups/group/{$gid}'>Edit</a>
                  <a class='btn btn-sm btn-outline-secondary' href='/admin/securegroups/group/{$gid}/preview'>Preview</a>
                </td>
            </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='5' class='text-muted'>No groups found.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Secure Groups</h1>
            <form class='row g-2 mb-3' method='get'>
              <div class='col-md-4'><input class='form-control' name='search' placeholder='Search groups' value='" . htmlspecialchars($search) . "'></div>
              <div class='col-md-3'>
                <select class='form-select' name='enabled'>
                  <option value=''>All statuses</option>
                  <option value='1'" . ($enabledFilter === '1' ? ' selected' : '') . ">Enabled</option>
                  <option value='0'" . ($enabledFilter === '0' ? ' selected' : '') . ">Disabled</option>
                </select>
              </div>
              <div class='col-md-2'><button class='btn btn-primary w-100'>Filter</button></div>
              <div class='col-md-3 text-end'><a class='btn btn-success' href='/admin/securegroups/group/new'>Create Group</a></div>
            </form>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Name</th><th>Description</th><th>Enabled</th><th>Members In</th><th>Actions</th>
                  </tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Groups', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/group/new', function () use ($app, $renderPage, $requireLogin, $requireRight, $ruleCatalog): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $catalogJson = htmlspecialchars(json_encode($ruleCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $body = "<h1 class='mb-3'>Create Secure Group</h1>
            <form method='post'>
              <div class='mb-3'>
                <label class='form-label'>Name</label>
                <input class='form-control' name='name' required>
              </div>
              <div class='mb-3'>
                <label class='form-label'>Description</label>
                <textarea class='form-control' name='description' rows='3'></textarea>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Visibility</label>
                  <select class='form-select' name='visibility'>
                    <option value='admin_only'>Admin Only</option>
                    <option value='member_visible'>Member Visible</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Unknown Data Handling</label>
                  <select class='form-select' name='unknown_data_handling'>
                    <option value='fail'>Fail</option>
                    <option value='ignore'>Ignore</option>
                    <option value='defer'>Defer</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Enforcement Mode</label>
                  <select class='form-select' name='enforcement_mode'>
                    <option value='dry-run'>Dry Run</option>
                    <option value='apply'>Apply</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Enabled</label>
                  <select class='form-select' name='enabled'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
              </div>
              <button class='btn btn-success'>Create Group</button>
            </form>
            <script>window.__secureRuleCatalog = {$catalogJson};</script>";

        return Response::html($renderPage('Create Secure Group', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/group/new', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $name = trim((string)($req->post['name'] ?? ''));
        if ($name === '') {
            return Response::text('Name is required', 422);
        }
        $desc = trim((string)($req->post['description'] ?? ''));
        $visibility = (string)($req->post['visibility'] ?? 'admin_only');
        $unknownHandling = (string)($req->post['unknown_data_handling'] ?? 'fail');
        $enforcement = (string)($req->post['enforcement_mode'] ?? 'dry-run');
        $enabled = (int)($req->post['enabled'] ?? 1);

        $app->db->run(
            "INSERT INTO module_securegroups_groups\n"
            . " (name, description, enabled, visibility, unknown_data_handling, enforcement_mode, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$name, $desc, $enabled, $visibility, $unknownHandling, $enforcement]
        );
        $row = $app->db->one("SELECT LAST_INSERT_ID() AS id");
        $gid = (int)($row['id'] ?? 0);

        return Response::redirect('/admin/securegroups/group/' . $gid);
    });

    $registry->route('GET', '/admin/securegroups/group/{id}', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $ruleCatalog): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $gid = (int)($req->params['id'] ?? 0);
        $group = $app->db->one("SELECT * FROM module_securegroups_groups WHERE id=? LIMIT 1", [$gid]);
        if (!$group) {
            return Response::text('Group not found', 404);
        }

        $rules = $app->db->all(
            "SELECT * FROM module_securegroups_rules WHERE group_id=? ORDER BY id ASC",
            [$gid]
        );

        $ruleRows = '';
        foreach ($rules as $rule) {
            $rid = (int)($rule['id'] ?? 0);
            $ruleRows .= "<tr>
                <td>" . htmlspecialchars((string)($rule['provider_key'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($rule['rule_key'] ?? '')) . "</td>
                <td><input class='form-control form-control-sm' name='rules[{$rid}][operator]' value='" . htmlspecialchars((string)($rule['operator'] ?? '')) . "'></td>
                <td><input class='form-control form-control-sm' name='rules[{$rid}][value]' value='" . htmlspecialchars((string)($rule['value'] ?? '')) . "'></td>
                <td><input class='form-control form-control-sm' name='rules[{$rid}][logic_group]' value='" . htmlspecialchars((string)($rule['logic_group'] ?? 0)) . "'></td>
                <td class='text-center'><input type='checkbox' name='rules[{$rid}][enabled]' value='1'" . ((int)($rule['enabled'] ?? 1) === 1 ? ' checked' : '') . "></td>
                <td>
                  <button class='btn btn-sm btn-outline-danger' formaction='/admin/securegroups/group/{$gid}/rules/{$rid}/delete' formmethod='post' onclick='return confirm(\"Delete rule?\")'>Delete</button>
                </td>
            </tr>";
        }
        if ($ruleRows === '') {
            $ruleRows = "<tr><td colspan='7' class='text-muted'>No rules added yet.</td></tr>";
        }

        $catalogJson = htmlspecialchars(json_encode($ruleCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $body = "<h1 class='mb-3'>Edit Secure Group</h1>
            <form method='post' class='mb-4'>
              <div class='mb-3'>
                <label class='form-label'>Name</label>
                <input class='form-control' name='name' value='" . htmlspecialchars((string)($group['name'] ?? '')) . "' required>
              </div>
              <div class='mb-3'>
                <label class='form-label'>Description</label>
                <textarea class='form-control' name='description' rows='3'>" . htmlspecialchars((string)($group['description'] ?? '')) . "</textarea>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Visibility</label>
                  <select class='form-select' name='visibility'>
                    <option value='admin_only'" . (($group['visibility'] ?? '') === 'admin_only' ? ' selected' : '') . ">Admin Only</option>
                    <option value='member_visible'" . (($group['visibility'] ?? '') === 'member_visible' ? ' selected' : '') . ">Member Visible</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Unknown Data Handling</label>
                  <select class='form-select' name='unknown_data_handling'>
                    <option value='fail'" . (($group['unknown_data_handling'] ?? '') === 'fail' ? ' selected' : '') . ">Fail</option>
                    <option value='ignore'" . (($group['unknown_data_handling'] ?? '') === 'ignore' ? ' selected' : '') . ">Ignore</option>
                    <option value='defer'" . (($group['unknown_data_handling'] ?? '') === 'defer' ? ' selected' : '') . ">Defer</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Enforcement Mode</label>
                  <select class='form-select' name='enforcement_mode'>
                    <option value='dry-run'" . (($group['enforcement_mode'] ?? '') === 'dry-run' ? ' selected' : '') . ">Dry Run</option>
                    <option value='apply'" . (($group['enforcement_mode'] ?? '') === 'apply' ? ' selected' : '') . ">Apply</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Enabled</label>
                  <select class='form-select' name='enabled'>
                    <option value='1'" . ((int)($group['enabled'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['enabled'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
              </div>
              <button class='btn btn-primary'>Save Group</button>
              <a class='btn btn-outline-secondary' href='/admin/securegroups/group/{$gid}/preview'>Preview Changes</a>
            </form>

            <div class='card mb-4'>
              <div class='card-header'>Rules</div>
              <div class='card-body'>
                <form method='post' action='/admin/securegroups/group/{$gid}/rules/save'>
                  <div class='table-responsive'>
                    <table class='table table-sm table-striped'>
                      <thead><tr>
                        <th>Provider</th><th>Rule</th><th>Operator</th><th>Value</th><th>Logic Group</th><th>Enabled</th><th>Actions</th>
                      </tr></thead>
                      <tbody>{$ruleRows}</tbody>
                    </table>
                  </div>
                  <button class='btn btn-primary'>Save Rules</button>
                </form>
              </div>
            </div>

            <div class='card'>
              <div class='card-header'>Add Rule</div>
              <div class='card-body'>
                <form method='post' action='/admin/securegroups/group/{$gid}/rules/add'>
                  <div class='row g-2 align-items-end'>
                    <div class='col-md-3'>
                      <label class='form-label'>Provider</label>
                      <select class='form-select' id='sg-provider' name='provider_key'></select>
                    </div>
                    <div class='col-md-3'>
                      <label class='form-label'>Rule</label>
                      <select class='form-select' id='sg-rule' name='rule_key'></select>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Operator</label>
                      <select class='form-select' id='sg-operator' name='operator'></select>
                    </div>
                    <div class='col-md-3'>
                      <label class='form-label'>Value</label>
                      <input class='form-control' id='sg-value' name='value'>
                      <small class='text-muted' id='sg-help'></small>
                    </div>
                    <div class='col-md-1'>
                      <button class='btn btn-success w-100'>Add</button>
                    </div>
                  </div>
                  <input type='hidden' name='logic_group' value='0'>
                </form>
              </div>
            </div>
            <script>
              const catalog = {$catalogJson};
              const providerSelect = document.getElementById('sg-provider');
              const ruleSelect = document.getElementById('sg-rule');
              const operatorSelect = document.getElementById('sg-operator');
              const valueInput = document.getElementById('sg-value');
              const helpText = document.getElementById('sg-help');
              function buildProviders() {
                providerSelect.innerHTML = '';
                Object.keys(catalog).forEach(key => {
                  const opt = document.createElement('option');
                  opt.value = key;
                  opt.textContent = key;
                  providerSelect.appendChild(opt);
                });
              }
              function buildRules() {
                const rules = catalog[providerSelect.value] || [];
                ruleSelect.innerHTML = '';
                rules.forEach(rule => {
                  const opt = document.createElement('option');
                  opt.value = rule.key;
                  opt.textContent = rule.label;
                  ruleSelect.appendChild(opt);
                });
                buildOperators();
              }
              function buildOperators() {
                const rules = catalog[providerSelect.value] || [];
                const rule = rules.find(r => r.key === ruleSelect.value);
                operatorSelect.innerHTML = '';
                (rule?.operators || []).forEach(op => {
                  const opt = document.createElement('option');
                  opt.value = op;
                  opt.textContent = op;
                  operatorSelect.appendChild(opt);
                });
                helpText.textContent = rule?.help || '';
                const type = rule?.value_type || 'text';
                valueInput.type = type === 'number' ? 'number' : 'text';
              }
              providerSelect.addEventListener('change', buildRules);
              ruleSelect.addEventListener('change', buildOperators);
              buildProviders();
              buildRules();
            </script>";

        return Response::html($renderPage('Edit Secure Group', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/group/{id}', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $gid = (int)($req->params['id'] ?? 0);
        $name = trim((string)($req->post['name'] ?? ''));
        if ($gid <= 0 || $name === '') {
            return Response::text('Invalid group', 422);
        }

        $desc = trim((string)($req->post['description'] ?? ''));
        $visibility = (string)($req->post['visibility'] ?? 'admin_only');
        $unknownHandling = (string)($req->post['unknown_data_handling'] ?? 'fail');
        $enforcement = (string)($req->post['enforcement_mode'] ?? 'dry-run');
        $enabled = (int)($req->post['enabled'] ?? 1);

        $app->db->run(
            "UPDATE module_securegroups_groups\n"
            . " SET name=?, description=?, enabled=?, visibility=?, unknown_data_handling=?, enforcement_mode=?, updated_at=NOW()\n"
            . " WHERE id=?",
            [$name, $desc, $enabled, $visibility, $unknownHandling, $enforcement, $gid]
        );

        return Response::redirect('/admin/securegroups/group/' . $gid);
    });

    $registry->route('POST', '/admin/securegroups/group/{id}/rules/add', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $gid = (int)($req->params['id'] ?? 0);
        $provider = (string)($req->post['provider_key'] ?? '');
        $ruleKey = (string)($req->post['rule_key'] ?? '');
        $operator = (string)($req->post['operator'] ?? 'equals');
        $value = (string)($req->post['value'] ?? '');
        $logicGroup = (int)($req->post['logic_group'] ?? 0);

        if ($gid <= 0 || $provider === '' || $ruleKey === '') {
            return Response::text('Invalid rule data', 422);
        }

        $app->db->run(
            "INSERT INTO module_securegroups_rules\n"
            . " (group_id, provider_key, rule_key, operator, value, logic_group, enabled, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
            [$gid, $provider, $ruleKey, $operator, $value, $logicGroup]
        );

        return Response::redirect('/admin/securegroups/group/' . $gid);
    });

    $registry->route('POST', '/admin/securegroups/group/{id}/rules/save', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $gid = (int)($req->params['id'] ?? 0);
        $rules = $req->post['rules'] ?? [];
        if (!is_array($rules)) $rules = [];

        foreach ($rules as $ruleId => $payload) {
            $rid = (int)$ruleId;
            if ($rid <= 0 || !is_array($payload)) continue;
            $operator = (string)($payload['operator'] ?? 'equals');
            $value = (string)($payload['value'] ?? '');
            $logicGroup = (int)($payload['logic_group'] ?? 0);
            $enabled = isset($payload['enabled']) ? 1 : 0;
            $app->db->run(
                "UPDATE module_securegroups_rules\n"
                . " SET operator=?, value=?, logic_group=?, enabled=?, updated_at=NOW()\n"
                . " WHERE id=? AND group_id=?",
                [$operator, $value, $logicGroup, $enabled, $rid, $gid]
            );
        }

        return Response::redirect('/admin/securegroups/group/' . $gid);
    });

    $registry->route('POST', '/admin/securegroups/group/{id}/rules/{ruleId}/delete', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $gid = (int)($req->params['id'] ?? 0);
        $rid = (int)($req->params['ruleId'] ?? 0);
        if ($gid > 0 && $rid > 0) {
            $app->db->run("DELETE FROM module_securegroups_rules WHERE id=? AND group_id=?", [$rid, $gid]);
        }
        return Response::redirect('/admin/securegroups/group/' . $gid);
    });

    $registry->route('GET', '/admin/securegroups/group/{id}/preview', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $gid = (int)($req->params['id'] ?? 0);
        $group = $app->db->one("SELECT * FROM module_securegroups_groups WHERE id=? LIMIT 1", [$gid]);
        if (!$group) {
            return Response::text('Group not found', 404);
        }

        $page = max(1, (int)($req->query['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $filter = (string)($req->query['filter'] ?? '');
        $search = trim((string)($req->query['search'] ?? ''));
        $where = ["m.group_id=?"];
        $params = [$gid];

        if ($search !== '') {
            $where[] = "u.character_name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($filter === 'adds') {
            $where[] = "m.eval_status='in' AND m.status='out'";
        } elseif ($filter === 'removes') {
            $where[] = "m.eval_status='out' AND m.status='in'";
        } elseif ($filter === 'pending') {
            $where[] = "m.eval_status='pending'";
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $app->db->all(
            "SELECT m.*, u.character_name\n"
            . " FROM module_securegroups_membership m\n"
            . " JOIN eve_users u ON u.id=m.user_id\n"
            . " {$whereSql}\n"
            . " ORDER BY u.character_name ASC\n"
            . " LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $tableRows = '';
        foreach ($rows as $row) {
            $delta = ((string)($row['eval_status'] ?? '') !== (string)($row['status'] ?? ''))
                ? "<span class='badge bg-warning text-dark'>Change</span>"
                : "<span class='badge bg-success'>No change</span>";
            $reason = htmlspecialchars((string)($row['eval_reason_summary'] ?? '—'));
            $evidence = htmlspecialchars(json_encode(json_decode((string)($row['eval_evidence_json'] ?? '[]'), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $tableRows .= "<tr>
                <td>" . htmlspecialchars((string)($row['character_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['status'] ?? 'out')) . "</td>
                <td>" . htmlspecialchars((string)($row['eval_status'] ?? 'pending')) . "</td>
                <td>{$delta}</td>
                <td>{$reason}</td>
                <td>
                  <details>
                    <summary>Why?</summary>
                    <pre class='small bg-light p-2 mt-2'>{$evidence}</pre>
                  </details>
                </td>
            </tr>";
        }
        if ($tableRows === '') {
            $tableRows = "<tr><td colspan='6' class='text-muted'>No results found.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Membership Preview: " . htmlspecialchars((string)($group['name'] ?? '')) . "</h1>
            <form class='row g-2 mb-3' method='get'>
              <div class='col-md-4'><input class='form-control' name='search' placeholder='Search member' value='" . htmlspecialchars($search) . "'></div>
              <div class='col-md-3'>
                <select class='form-select' name='filter'>
                  <option value=''>All</option>
                  <option value='adds'" . ($filter === 'adds' ? ' selected' : '') . ">Adds</option>
                  <option value='removes'" . ($filter === 'removes' ? ' selected' : '') . ">Removes</option>
                  <option value='pending'" . ($filter === 'pending' ? ' selected' : '') . ">Pending</option>
                </select>
              </div>
              <div class='col-md-2'><button class='btn btn-primary w-100'>Filter</button></div>
            </form>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Member</th><th>Current</th><th>Evaluated</th><th>Delta</th><th>Reason</th><th>Evidence</th>
                  </tr></thead>
                  <tbody>{$tableRows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Group Preview', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/logs', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $logs = $app->db->all(
            "SELECT * FROM module_securegroups_job_runs ORDER BY started_at DESC LIMIT 100"
        );
        $rows = '';
        foreach ($logs as $log) {
            $id = (int)($log['id'] ?? 0);
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($log['job_key'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($log['started_at'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($log['ended_at'] ?? '')) . "</td>
                <td>" . ((int)($log['success'] ?? 0) === 1 ? 'Yes' : 'No') . "</td>
                <td>" . htmlspecialchars((string)($log['processed_count'] ?? 0)) . "</td>
                <td>" . htmlspecialchars((string)($log['changed_count'] ?? 0)) . "</td>
                <td><a class='btn btn-sm btn-outline-secondary' href='/admin/securegroups/logs/{$id}'>View</a></td>
            </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='7' class='text-muted'>No job runs yet.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Secure Groups Job Runs</h1>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Job</th><th>Started</th><th>Ended</th><th>Success</th><th>Processed</th><th>Changed</th><th>Details</th>
                  </tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Group Logs', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/logs/{id}', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $id = (int)($req->params['id'] ?? 0);
        $log = $app->db->one("SELECT * FROM module_securegroups_job_runs WHERE id=? LIMIT 1", [$id]);
        if (!$log) {
            return Response::text('Log not found', 404);
        }

        $details = json_decode((string)($log['details_json'] ?? '[]'), true);
        if (!is_array($details)) $details = [];
        $detailsPretty = htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $changeRows = '';
        if (isset($details['changes']) && is_array($details['changes'])) {
            foreach ($details['changes'] as $change) {
                if (!is_array($change)) continue;
                $changeRows .= "<tr>
                    <td>" . htmlspecialchars((string)($change['group_id'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($change['user_id'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($change['from'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($change['to'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($change['reason'] ?? '')) . "</td>
                </tr>";
            }
        }
        if ($changeRows === '') {
            $changeRows = "<tr><td colspan='5' class='text-muted'>No membership changes recorded.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Job Run Details</h1>
            <div class='card mb-3'>
              <div class='card-body'>
                <dl class='row mb-0'>
                  <dt class='col-sm-3'>Job</dt><dd class='col-sm-9'>" . htmlspecialchars((string)($log['job_key'] ?? '')) . "</dd>
                  <dt class='col-sm-3'>Started</dt><dd class='col-sm-9'>" . htmlspecialchars((string)($log['started_at'] ?? '')) . "</dd>
                  <dt class='col-sm-3'>Ended</dt><dd class='col-sm-9'>" . htmlspecialchars((string)($log['ended_at'] ?? '')) . "</dd>
                  <dt class='col-sm-3'>Success</dt><dd class='col-sm-9'>" . ((int)($log['success'] ?? 0) === 1 ? 'Yes' : 'No') . "</dd>
                  <dt class='col-sm-3'>Processed</dt><dd class='col-sm-9'>" . htmlspecialchars((string)($log['processed_count'] ?? 0)) . "</dd>
                  <dt class='col-sm-3'>Changed</dt><dd class='col-sm-9'>" . htmlspecialchars((string)($log['changed_count'] ?? 0)) . "</dd>
                  <dt class='col-sm-3'>Log</dt><dd class='col-sm-9'>" . htmlspecialchars((string)($log['log_excerpt'] ?? '—')) . "</dd>
                </dl>
              </div>
            </div>
            <div class='card mb-3'>
              <div class='card-header'>Membership Changes</div>
              <div class='table-responsive'>
                <table class='table table-sm table-striped mb-0'>
                  <thead><tr>
                    <th>Group ID</th><th>User ID</th><th>From</th><th>To</th><th>Reason</th>
                  </tr></thead>
                  <tbody>{$changeRows}</tbody>
                </table>
              </div>
            </div>
            <div class='card'>
              <div class='card-header'>Raw Details</div>
              <div class='card-body'>
                <pre class='small bg-light p-3'>{$detailsPretty}</pre>
              </div>
            </div>";

        return Response::html($renderPage('Secure Group Log', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/overrides', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $search = trim((string)($req->query['search'] ?? ''));
        $groups = $app->db->all("SELECT id, name FROM module_securegroups_groups ORDER BY name ASC");

        $searchResults = [];
        if ($search !== '') {
            $searchResults = $app->db->all(
                "SELECT id, character_name FROM eve_users WHERE character_name LIKE ? ORDER BY character_name ASC LIMIT 20",
                ['%' . $search . '%']
            );
        }

        $overrideRows = $app->db->all(
            "SELECT m.id, m.status, m.manual_note, m.manual_sticky, m.group_id, g.name AS group_name, u.character_name\n"
            . " FROM module_securegroups_membership m\n"
            . " JOIN module_securegroups_groups g ON g.id=m.group_id\n"
            . " JOIN eve_users u ON u.id=m.user_id\n"
            . " WHERE m.source='manual'\n"
            . " ORDER BY g.name ASC, u.character_name ASC"
        );

        $overrideRowsHtml = '';
        foreach ($overrideRows as $row) {
            $id = (int)($row['id'] ?? 0);
            $overrideRowsHtml .= "<tr>
                <td>" . htmlspecialchars((string)($row['group_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['character_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['status'] ?? '')) . "</td>
                <td>" . ((int)($row['manual_sticky'] ?? 0) === 1 ? 'Yes' : 'No') . "</td>
                <td>" . htmlspecialchars((string)($row['manual_note'] ?? '')) . "</td>
                <td>
                  <form method='post' action='/admin/securegroups/overrides/{$id}/remove' onsubmit='return confirm(\"Remove override?\")'>
                    <button class='btn btn-sm btn-outline-danger'>Remove</button>
                  </form>
                </td>
            </tr>";
        }
        if ($overrideRowsHtml === '') {
            $overrideRowsHtml = "<tr><td colspan='6' class='text-muted'>No manual overrides.</td></tr>";
        }

        $groupOptions = '';
        foreach ($groups as $group) {
            $groupOptions .= "<option value='" . (int)($group['id'] ?? 0) . "'>" . htmlspecialchars((string)($group['name'] ?? '')) . "</option>";
        }

        $searchRows = '';
        foreach ($searchResults as $user) {
            $uid = (int)($user['id'] ?? 0);
            $searchRows .= "<tr>
                <td>" . htmlspecialchars((string)($user['character_name'] ?? '')) . "</td>
                <td>
                  <input type='hidden' name='user_id' value='{$uid}'>
                  <button class='btn btn-sm btn-outline-primary' form='override-form' onclick=\"document.getElementById('override-user').value='{$uid}'\">Select</button>
                </td>
            </tr>";
        }
        if ($search !== '' && $searchRows === '') {
            $searchRows = "<tr><td colspan='2' class='text-muted'>No users found.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Manual Overrides</h1>
            <div class='card mb-4'>
              <div class='card-header'>Add Override</div>
              <div class='card-body'>
                <form class='row g-2 mb-3' method='get'>
                  <div class='col-md-6'><input class='form-control' name='search' placeholder='Search user by name' value='" . htmlspecialchars($search) . "'></div>
                  <div class='col-md-2'><button class='btn btn-outline-secondary w-100'>Search</button></div>
                </form>
                <div class='table-responsive mb-3'>
                  <table class='table table-sm'>
                    <thead><tr><th>User</th><th>Select</th></tr></thead>
                    <tbody>{$searchRows}</tbody>
                  </table>
                </div>
                <form id='override-form' method='post' action='/admin/securegroups/overrides/add'>
                  <input type='hidden' id='override-user' name='user_id' value=''>
                  <div class='row g-2'>
                    <div class='col-md-4'>
                      <label class='form-label'>Group</label>
                      <select class='form-select' name='group_id' required>{$groupOptions}</select>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Status</label>
                      <select class='form-select' name='status'>
                        <option value='in'>In</option>
                        <option value='out'>Out</option>
                      </select>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Sticky</label>
                      <select class='form-select' name='manual_sticky'>
                        <option value='1'>Yes</option>
                        <option value='0'>No</option>
                      </select>
                    </div>
                    <div class='col-md-4'>
                      <label class='form-label'>Note (required)</label>
                      <input class='form-control' name='manual_note' required>
                    </div>
                  </div>
                  <div class='mt-3'>
                    <button class='btn btn-success'>Add Override</button>
                  </div>
                </form>
              </div>
            </div>
            <div class='card'>
              <div class='card-header'>Current Overrides</div>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Group</th><th>User</th><th>Status</th><th>Sticky</th><th>Note</th><th>Actions</th>
                  </tr></thead>
                  <tbody>{$overrideRowsHtml}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Manual Overrides', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/overrides/add', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $userId = (int)($req->post['user_id'] ?? 0);
        $groupId = (int)($req->post['group_id'] ?? 0);
        $status = (string)($req->post['status'] ?? 'in');
        $note = trim((string)($req->post['manual_note'] ?? ''));
        $sticky = (int)($req->post['manual_sticky'] ?? 1);

        if ($userId <= 0 || $groupId <= 0 || $note === '') {
            return Response::text('Invalid override data', 422);
        }

        $app->db->run(
            "INSERT INTO module_securegroups_membership\n"
            . " (group_id, user_id, status, source, manual_note, manual_sticky, last_changed_at, updated_at)\n"
            . " VALUES (?, ?, ?, 'manual', ?, ?, NOW(), NOW())\n"
            . " ON DUPLICATE KEY UPDATE\n"
            . " status=VALUES(status), source='manual', manual_note=VALUES(manual_note), manual_sticky=VALUES(manual_sticky), last_changed_at=NOW(), updated_at=NOW()",
            [$groupId, $userId, $status, $note, $sticky]
        );

        return Response::redirect('/admin/securegroups/overrides');
    });

    $registry->route('POST', '/admin/securegroups/overrides/{id}/remove', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $id = (int)($req->params['id'] ?? 0);
        if ($id > 0) {
            $app->db->run(
                "UPDATE module_securegroups_membership\n"
                . " SET source='auto', manual_note=NULL, manual_sticky=0, updated_at=NOW()\n"
                . " WHERE id=?",
                [$id]
            );
        }
        return Response::redirect('/admin/securegroups/overrides');
    });

    $registry->route('GET', '/admin/cron', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        JobRegistry::sync($app->db);
        $jobs = $app->db->all(
            "SELECT job_key, name, schedule_seconds, is_enabled, last_run_at, last_status, last_message\n"
            . " FROM module_corptools_jobs ORDER BY job_key ASC"
        );

        $rows = '';
        foreach ($jobs as $job) {
            $key = htmlspecialchars((string)($job['job_key'] ?? ''));
            $rows .= "<tr>
                <td>{$key}</td>
                <td>" . htmlspecialchars((string)($job['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['schedule_seconds'] ?? '')) . "s</td>
                <td>" . htmlspecialchars((string)($job['last_status'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['last_run_at'] ?? '—')) . "</td>
                <td>" . htmlspecialchars((string)($job['last_message'] ?? '')) . "</td>
                <td>
                  <form method='post' action='/admin/cron/run'>
                    <input type='hidden' name='job_key' value='{$key}'>
                    <button class='btn btn-sm btn-outline-primary'>Run Now</button>
                  </form>
                </td>
            </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='7' class='text-muted'>No cron jobs registered.</td></tr>";
        }

        $snippet = "* * * * * flock -n /tmp/modularalliance.cron.lock cd /var/www/ModularAlliance && /usr/bin/php bin/cron.php run --due >> /var/log/modularalliance-cron.log 2>&1";

        $body = "<h1 class='mb-3'>Cron Job Manager</h1>
            <p class='text-muted'>Use the shared cron runner to execute all module jobs.</p>
            <div class='card mb-4'>
              <div class='card-body'>
                <h5 class='card-title'>Ubuntu Crontab Snippet</h5>
                <pre class='bg-light p-3'>{$snippet}</pre>
                <ul class='text-muted small'>
                  <li>Use absolute paths for PHP and the repo.</li>
                  <li>Ensure the log file path is writable.</li>
                  <li>Use flock to avoid overlapping runs.</li>
                </ul>
              </div>
            </div>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Job Key</th><th>Name</th><th>Schedule</th><th>Last Status</th><th>Last Run</th><th>Message</th><th>Action</th>
                  </tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Cron Manager', $body), 200);
    });

    $registry->route('POST', '/admin/cron/run', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $jobKey = (string)($req->post['job_key'] ?? '');
        if ($jobKey !== '') {
            $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
            $runner->runJob($app, $jobKey, ['trigger' => 'ui']);
        }
        return Response::redirect('/admin/cron');
    });
};
