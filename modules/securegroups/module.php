<?php
declare(strict_types=1);

/*
Module Name: Secure Groups
Description: Rule-based, auditable group membership using smart filters.
Version: 2.0.0
Module Slug: securegroups
*/

use App\Core\App;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Identifiers;
use App\Corptools\Cron\JobRegistry;
use App\Corptools\Cron\JobRunner;
use App\Http\Request;
use App\Http\Response;

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
        'title' => 'Admin / HR Tools',
        'url' => '/admin/securegroups',
        'sort_order' => 70,
        'area' => 'left',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.dashboard',
        'title' => 'Secure Groups',
        'url' => '/admin/securegroups',
        'sort_order' => 71,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.groups',
        'title' => 'Groups',
        'url' => '/admin/securegroups/groups',
        'sort_order' => 72,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.requests',
        'title' => 'Requests',
        'url' => '/admin/securegroups/requests',
        'sort_order' => 73,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.logs',
        'title' => 'Enforcement Logs',
        'url' => '/admin/securegroups/logs',
        'sort_order' => 74,
        'area' => 'left',
        'parent_slug' => 'securegroups.admin_tools',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'securegroups.admin.overrides',
        'title' => 'Manual Overrides',
        'url' => '/admin/securegroups/overrides',
        'sort_order' => 75,
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
        'slug' => 'admin.securegroups.groups',
        'title' => 'Groups',
        'url' => '/admin/securegroups/groups',
        'sort_order' => 51,
        'area' => 'admin_top',
        'parent_slug' => 'admin.securegroups',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.securegroups.requests',
        'title' => 'Requests',
        'url' => '/admin/securegroups/requests',
        'sort_order' => 52,
        'area' => 'admin_top',
        'parent_slug' => 'admin.securegroups',
        'right_slug' => 'securegroups.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.system.cron',
        'title' => 'Cron Manager',
        'url' => '/admin/system/cron',
        'sort_order' => 53,
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

    $nowUtc = fn(): string => gmdate('Y-m-d H:i:s');

    $slugify = function (string $text): string {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? $text : 'group';
    };

    $generatePublicId = function (string $table) use ($app): string {
        return Identifiers::generatePublicId($app->db, $table);
    };

    $resolveUserId = function (string $publicId) use ($app): int {
        if ($publicId === '') return 0;
        $row = $app->db->one("SELECT id FROM eve_users WHERE public_id=? LIMIT 1", [$publicId]);
        return (int)($row['id'] ?? 0);
    };

    $resolveGroupId = function (string $slug) use ($app): int {
        if ($slug === '') return 0;
        $row = $app->db->one("SELECT id FROM module_secgroups_groups WHERE key_slug=? LIMIT 1", [$slug]);
        return (int)($row['id'] ?? 0);
    };

    $resolveFilterId = function (string $publicId) use ($app): int {
        if ($publicId === '') return 0;
        $row = $app->db->one("SELECT id FROM module_secgroups_filters WHERE public_id=? LIMIT 1", [$publicId]);
        return (int)($row['id'] ?? 0);
    };

    $resolveRequestId = function (string $publicId) use ($app): int {
        if ($publicId === '') return 0;
        $row = $app->db->one("SELECT id FROM module_secgroups_requests WHERE public_id=? LIMIT 1", [$publicId]);
        return (int)($row['id'] ?? 0);
    };

    $ensureRightsGroup = function (string $slug, string $name) use ($app, $nowUtc): int {
        $existing = $app->db->one("SELECT id FROM groups WHERE slug=? LIMIT 1", [$slug]);
        if ($existing) {
            return (int)($existing['id'] ?? 0);
        }
        $app->db->run(
            "INSERT INTO groups (slug, name, is_admin, created_at, updated_at) VALUES (?, ?, 0, ?, ?)",
            [$slug, $name, $nowUtc(), $nowUtc()]
        );
        $row = $app->db->one("SELECT LAST_INSERT_ID() AS id");
        return (int)($row['id'] ?? 0);
    };

    $syncUserRightsGroup = function (int $userId, int $groupId, bool $shouldHave) use ($app): void {
        if ($userId <= 0 || $groupId <= 0) return;
        if ($shouldHave) {
            $app->db->run("INSERT IGNORE INTO eve_user_groups (user_id, group_id) VALUES (?, ?)", [$userId, $groupId]);
        } else {
            $app->db->run("DELETE FROM eve_user_groups WHERE user_id=? AND group_id=?", [$userId, $groupId]);
        }
    };

    $getLinkedCharacterIds = function (int $userId) use ($app): array {
        $main = $app->db->one("SELECT character_id FROM eve_users WHERE id=? LIMIT 1", [$userId]);
        $mainId = (int)($main['character_id'] ?? 0);
        $rows = $app->db->all("SELECT character_id FROM module_charlink_links WHERE user_id=?", [$userId]);
        $ids = $mainId > 0 ? [$mainId] : [];
        foreach ($rows as $row) {
            $cid = (int)($row['character_id'] ?? 0);
            if ($cid > 0 && !in_array($cid, $ids, true)) {
                $ids[] = $cid;
            }
        }
        return $ids;
    };

    $evaluateFilter = function (int $userId, array $filter, array $context = []) use ($app, $getLinkedCharacterIds): array {
        $type = (string)($filter['type'] ?? '');
        $config = json_decode((string)($filter['config_json'] ?? '{}'), true);
        if (!is_array($config)) $config = [];
        $negate = (bool)($config['negate'] ?? false);

        $result = ['pass' => false, 'message' => 'Unknown filter', 'evidence' => ['type' => $type]];
        if ($type === 'alt_corp') {
            $corpId = (int)($config['corp_id'] ?? 0);
            $exemptions = $config['exemptions'] ?? [];
            if (!is_array($exemptions)) $exemptions = [];
            $charIds = $getLinkedCharacterIds($userId);
            if (empty($charIds) || $corpId <= 0) {
                $result = ['pass' => false, 'message' => 'Missing corp or character data', 'evidence' => ['corp_id' => $corpId]];
            } else {
                $placeholders = implode(',', array_fill(0, count($charIds), '?'));
                $rows = $app->db->all(
                    "SELECT character_id, corp_id FROM module_corptools_character_summary WHERE character_id IN ({$placeholders})",
                    $charIds
                );
                if (empty($rows)) {
                    $result = ['pass' => false, 'message' => 'Missing corp summary data', 'evidence' => ['corp_id' => $corpId]];
                } else {
                    $hit = false;
                    foreach ($rows as $row) {
                        $cid = (int)($row['character_id'] ?? 0);
                        if (in_array($cid, $exemptions, true)) continue;
                        if ((int)($row['corp_id'] ?? 0) === $corpId) {
                            $hit = true;
                            break;
                        }
                    }
                    $result = [
                        'pass' => $hit,
                        'message' => $hit ? 'Account has character in required corp.' : 'No character in required corp.',
                        'evidence' => ['corp_id' => $corpId, 'exemptions' => $exemptions],
                    ];
                }
            }
        } elseif ($type === 'alt_alliance') {
            $allianceId = (int)($config['alliance_id'] ?? 0);
            $exemptions = $config['exemptions'] ?? [];
            if (!is_array($exemptions)) $exemptions = [];
            $charIds = $getLinkedCharacterIds($userId);
            if (empty($charIds) || $allianceId <= 0) {
                $result = ['pass' => false, 'message' => 'Missing alliance or character data', 'evidence' => ['alliance_id' => $allianceId]];
            } else {
                $placeholders = implode(',', array_fill(0, count($charIds), '?'));
                $rows = $app->db->all(
                    "SELECT character_id, alliance_id FROM module_corptools_character_summary WHERE character_id IN ({$placeholders})",
                    $charIds
                );
                if (empty($rows)) {
                    $result = ['pass' => false, 'message' => 'Missing alliance summary data', 'evidence' => ['alliance_id' => $allianceId]];
                } else {
                    $hit = false;
                    foreach ($rows as $row) {
                        $cid = (int)($row['character_id'] ?? 0);
                        if (in_array($cid, $exemptions, true)) continue;
                        if ((int)($row['alliance_id'] ?? 0) === $allianceId) {
                            $hit = true;
                            break;
                        }
                    }
                    $result = [
                        'pass' => $hit,
                        'message' => $hit ? 'Account has character in required alliance.' : 'No character in required alliance.',
                        'evidence' => ['alliance_id' => $allianceId, 'exemptions' => $exemptions],
                    ];
                }
            }
        } elseif ($type === 'user_in_group') {
            $groupIds = $config['group_ids'] ?? [];
            if (!is_array($groupIds)) $groupIds = [];
            if (empty($groupIds)) {
                $result = ['pass' => false, 'message' => 'No target groups configured.', 'evidence' => []];
            } else {
                $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
                $params = array_merge([$userId], $groupIds);
                $row = $app->db->one(
                    "SELECT 1 FROM eve_user_groups WHERE user_id=? AND group_id IN ({$placeholders}) LIMIT 1",
                    $params
                );
                $hit = $row !== null;
                $result = [
                    'pass' => $hit,
                    'message' => $hit ? 'User is in one of the required groups.' : 'User is not in required groups.',
                    'evidence' => ['group_ids' => $groupIds],
                ];
            }
        } elseif ($type === 'expression') {
            $leftId = (int)($config['left_filter_id'] ?? 0);
            $rightId = (int)($config['right_filter_id'] ?? 0);
            $operator = strtolower((string)($config['operator'] ?? 'and'));
            $left = $leftId > 0 ? $app->db->one("SELECT * FROM module_secgroups_filters WHERE id=?", [$leftId]) : null;
            $right = $rightId > 0 ? $app->db->one("SELECT * FROM module_secgroups_filters WHERE id=?", [$rightId]) : null;
            if (!$left || !$right) {
                $result = ['pass' => false, 'message' => 'Missing filter expression operands.', 'evidence' => $config];
            } else {
                $leftResult = $context['evaluate_filter']($userId, $left, $context);
                $rightResult = $context['evaluate_filter']($userId, $right, $context);
                $pass = match ($operator) {
                    'xor' => (bool)$leftResult['pass'] xor (bool)$rightResult['pass'],
                    'or' => (bool)$leftResult['pass'] || (bool)$rightResult['pass'],
                    default => (bool)$leftResult['pass'] && (bool)$rightResult['pass'],
                };
                $result = [
                    'pass' => $pass,
                    'message' => $pass ? 'Expression matched.' : 'Expression failed.',
                    'evidence' => [
                        'operator' => $operator,
                        'left' => $leftResult,
                        'right' => $rightResult,
                    ],
                ];
            }
        }

        if ($negate) {
            $result['pass'] = !$result['pass'];
            $result['message'] = 'Negated: ' . $result['message'];
            $result['evidence']['negated'] = true;
        }

        return $result;
    };

    $evaluateGroupForUser = function (int $userId, array $group) use ($app, $evaluateFilter, $nowUtc): array {
        $filters = $app->db->all(
            "SELECT f.* FROM module_secgroups_filters f\n"
            . " JOIN module_secgroups_group_filters gf ON gf.filter_id=f.id\n"
            . " WHERE gf.group_id=? AND gf.enabled=1 ORDER BY gf.sort_order ASC, f.id ASC",
            [(int)($group['id'] ?? 0)]
        );

        if (empty($filters)) {
            return ['status' => 'IN', 'reason' => 'No enabled rules configured.', 'evidence' => []];
        }

        $context = ['evaluate_filter' => $evaluateFilter];
        $evidence = [];
        $pass = true;
        $failedFilterId = null;
        foreach ($filters as $filter) {
            $result = $evaluateFilter($userId, $filter, $context);
            $app->db->run(
                "UPDATE module_secgroups_group_filters\n"
                . " SET last_evaluated_at=?, last_pass=?, last_message=?, last_user_id=?\n"
                . " WHERE group_id=? AND filter_id=?",
                [
                    $nowUtc(),
                    $result['pass'] ? 1 : 0,
                    (string)($result['message'] ?? ''),
                    $userId,
                    (int)($group['id'] ?? 0),
                    (int)($filter['id'] ?? 0),
                ]
            );
            $evidence[] = [
                'filter_id' => (int)($filter['id'] ?? 0),
                'type' => (string)($filter['type'] ?? ''),
                'name' => (string)($filter['name'] ?? ''),
                'pass' => (bool)$result['pass'],
                'message' => (string)($result['message'] ?? ''),
                'evidence' => $result['evidence'] ?? [],
            ];
            if (!$result['pass']) {
                $pass = false;
                $failedFilterId = (int)($filter['id'] ?? 0);
                break;
            }
        }

        if ($pass) {
            return ['status' => 'IN', 'reason' => 'All rules passed.', 'evidence' => $evidence];
        }

        $canGrace = (int)($group['can_grace'] ?? 0) === 1;
        if ($canGrace) {
            $graceDays = (int)($group['grace_default_days'] ?? 0);
            if ($failedFilterId) {
                $filterRow = $app->db->one("SELECT grace_period_days FROM module_secgroups_filters WHERE id=?", [$failedFilterId]);
                if ($filterRow && (int)($filterRow['grace_period_days'] ?? 0) > 0) {
                    $graceDays = (int)($filterRow['grace_period_days'] ?? 0);
                }
            }
            if ($graceDays > 0) {
                $expires = gmdate('Y-m-d H:i:s', strtotime("+{$graceDays} days"));
                return [
                    'status' => 'GRACE',
                    'reason' => 'Grace period started.',
                    'grace_expires_at' => $expires,
                    'failed_filter_id' => $failedFilterId,
                    'evidence' => $evidence,
                ];
            }
        }

        return ['status' => 'OUT', 'reason' => 'One or more rules failed.', 'evidence' => $evidence];
    };

    $applyMembership = function (array $group, int $userId, array $result, string $source, ?int $overrideId = null) use ($app, $syncUserRightsGroup, $nowUtc): void {
        $groupId = (int)($group['id'] ?? 0);
        if ($groupId <= 0 || $userId <= 0) return;

        $status = $result['status'] ?? 'OUT';
        $reason = (string)($result['reason'] ?? '');
        $evidence = json_encode($result['evidence'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $graceExpires = $result['grace_expires_at'] ?? null;
        $failedFilterId = $result['failed_filter_id'] ?? null;
        if ($status === 'GRACE' && $graceExpires && strtotime($graceExpires) <= time()) {
            $status = 'OUT';
            $reason = 'Grace period expired.';
        }

        $stamp = $nowUtc();
        $existing = $app->db->one(
            "SELECT id, status FROM module_secgroups_memberships WHERE group_id=? AND user_id=?",
            [$groupId, $userId]
        );
        $prevStatus = $existing['status'] ?? 'OUT';

        if ($existing) {
            $app->db->run(
                "UPDATE module_secgroups_memberships\n"
                . " SET status=?, source=?, reason=?, evidence_json=?, last_evaluated_at=?, grace_expires_at=?, grace_filter_id=?, updated_at=?\n"
                . " WHERE id=?",
                [$status, $source, $reason, $evidence, $stamp, $graceExpires, $failedFilterId, $stamp, (int)$existing['id']]
            );
        } else {
            $app->db->run(
                "INSERT INTO module_secgroups_memberships\n"
                . " (group_id, user_id, status, source, reason, evidence_json, last_evaluated_at, grace_expires_at, grace_filter_id, created_at, updated_at)\n"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$groupId, $userId, $status, $source, $reason, $evidence, $stamp, $graceExpires, $failedFilterId, $stamp, $stamp]
            );
        }

        $rightsGroupId = (int)($group['rights_group_id'] ?? 0);
        if ($rightsGroupId > 0) {
            $syncUserRightsGroup($userId, $rightsGroupId, $status === 'IN');
        }

        if ($prevStatus !== $status) {
            $app->db->run(
                "INSERT INTO module_secgroups_logs\n"
                . " (group_id, user_id, action, source, message, created_at, meta_json)\n"
                . " VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $groupId,
                    $userId,
                    match ($status) {
                        'IN' => 'ADD',
                        'GRACE' => 'GRACE_START',
                        default => 'REMOVE',
                    },
                    $source,
                    $reason,
                    $stamp,
                    json_encode(['override_id' => $overrideId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]
            );
            $app->db->run(
                "INSERT INTO module_secgroups_notifications (group_id, user_id, message, created_at)\n"
                . " VALUES (?, ?, ?, ?)",
                [$groupId, $userId, $reason, $stamp]
            );
        }
    };

    $evaluateAllGroups = function (App $app) use ($evaluateGroupForUser, $applyMembership, $nowUtc): array {
        $groups = $app->db->all("SELECT * FROM module_secgroups_groups WHERE enabled=1 ORDER BY id ASC");
        $users = $app->db->all("SELECT id FROM eve_users ORDER BY id ASC");
        $processed = 0;

        foreach ($groups as $group) {
            $groupId = (int)($group['id'] ?? 0);
            if ($groupId <= 0) continue;

            foreach ($users as $user) {
                $userId = (int)($user['id'] ?? 0);
                if ($userId <= 0) continue;

                $override = $app->db->one(
                    "SELECT * FROM module_secgroups_overrides WHERE group_id=? AND user_id=? AND (expires_at IS NULL OR expires_at > ?) LIMIT 1",
                    [$groupId, $userId, $nowUtc()]
                );
                if ($override) {
                    $status = (string)($override['forced_state'] ?? 'OUT');
                    $applyMembership($group, $userId, [
                        'status' => $status,
                        'reason' => 'Manual override applied.',
                        'evidence' => ['override' => true],
                    ], 'OVERRIDE', (int)($override['id'] ?? 0));
                    $processed++;
                    continue;
                }

                $result = $evaluateGroupForUser($userId, $group);
                $applyMembership($group, $userId, $result, 'AUTO');
                $processed++;
            }
        }

        return ['status' => 'success', 'message' => "Evaluated {$processed} memberships.", 'metrics' => ['processed' => $processed]];
    };

    $evaluateSingleGroup = function (App $app, int $groupId) use ($evaluateGroupForUser, $applyMembership, $nowUtc): array {
        $group = $app->db->one("SELECT * FROM module_secgroups_groups WHERE id=?", [$groupId]);
        if (!$group) {
            return ['status' => 'failed', 'message' => 'Group not found'];
        }
        $users = $app->db->all("SELECT id FROM eve_users ORDER BY id ASC");
        $processed = 0;

        foreach ($users as $user) {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0) continue;

            $override = $app->db->one(
                "SELECT * FROM module_secgroups_overrides WHERE group_id=? AND user_id=? AND (expires_at IS NULL OR expires_at > ?) LIMIT 1",
                [$groupId, $userId, $nowUtc()]
            );
            if ($override) {
                $status = (string)($override['forced_state'] ?? 'OUT');
                $applyMembership($group, $userId, [
                    'status' => $status,
                    'reason' => 'Manual override applied.',
                    'evidence' => ['override' => true],
                ], 'OVERRIDE', (int)($override['id'] ?? 0));
                $processed++;
                continue;
            }

            $result = $evaluateGroupForUser($userId, $group);
            $applyMembership($group, $userId, $result, 'AUTO');
            $processed++;
        }

        return ['status' => 'success', 'message' => "Evaluated {$processed} users.", 'metrics' => ['processed' => $processed]];
    };

    $evaluateSingleUser = function (App $app, int $userId) use ($evaluateGroupForUser, $applyMembership, $nowUtc): array {
        $groups = $app->db->all("SELECT * FROM module_secgroups_groups WHERE enabled=1 ORDER BY id ASC");
        $processed = 0;

        foreach ($groups as $group) {
            $groupId = (int)($group['id'] ?? 0);
            if ($groupId <= 0) continue;

            $override = $app->db->one(
                "SELECT * FROM module_secgroups_overrides WHERE group_id=? AND user_id=? AND (expires_at IS NULL OR expires_at > ?) LIMIT 1",
                [$groupId, $userId, $nowUtc()]
            );
            if ($override) {
                $status = (string)($override['forced_state'] ?? 'OUT');
                $applyMembership($group, $userId, [
                    'status' => $status,
                    'reason' => 'Manual override applied.',
                    'evidence' => ['override' => true],
                ], 'OVERRIDE', (int)($override['id'] ?? 0));
                $processed++;
                continue;
            }

            $result = $evaluateGroupForUser($userId, $group);
            $applyMembership($group, $userId, $result, 'AUTO');
            $processed++;
        }

        return ['status' => 'success', 'message' => "Evaluated {$processed} groups for user.", 'metrics' => ['processed' => $processed]];
    };

    $jobDefinitions = [
        [
            'key' => 'securegroups.evaluate_all',
            'name' => 'Secure Groups: Evaluate all',
            'description' => 'Evaluate secure groups for all users.',
            'schedule' => 3600,
            'enabled' => 1,
            'handler' => function (App $app) use ($evaluateAllGroups): array {
                return $evaluateAllGroups($app);
            },
        ],
        [
            'key' => 'securegroups.evaluate_group',
            'name' => 'Secure Groups: Evaluate group',
            'description' => 'Evaluate a single group (on-demand).',
            'schedule' => 3600,
            'enabled' => 0,
            'handler' => function (App $app, array $context = []) use ($evaluateSingleGroup): array {
                $groupId = (int)($context['group_id'] ?? 0);
                return $groupId > 0 ? $evaluateSingleGroup($app, $groupId) : ['status' => 'failed', 'message' => 'Missing group id'];
            },
        ],
        [
            'key' => 'securegroups.evaluate_user',
            'name' => 'Secure Groups: Evaluate user',
            'description' => 'Evaluate all groups for a single user (on-demand).',
            'schedule' => 3600,
            'enabled' => 0,
            'handler' => function (App $app, array $context = []) use ($evaluateSingleUser): array {
                $userId = (int)($context['user_id'] ?? 0);
                return $userId > 0 ? $evaluateSingleUser($app, $userId) : ['status' => 'failed', 'message' => 'Missing user id'];
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

        $rows = $app->db->all(
            "SELECT g.id, g.key_slug, g.display_name, g.description, g.allow_applications, m.status, m.source, m.reason, m.last_evaluated_at\n"
            . " FROM module_secgroups_groups g\n"
            . " LEFT JOIN module_secgroups_memberships m ON m.group_id=g.id AND m.user_id=?\n"
            . " WHERE g.enabled=1\n"
            . " ORDER BY g.display_name ASC",
            [$uid]
        );

        $tableRows = '';
        foreach ($rows as $row) {
            $groupSlug = (string)($row['key_slug'] ?? '');
            $status = (string)($row['status'] ?? 'PENDING');
            $tableRows .= "<tr>
                <td>" . htmlspecialchars((string)($row['display_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['description'] ?? '')) . "</td>
                <td>" . htmlspecialchars($status) . "</td>
                <td>" . htmlspecialchars((string)($row['source'] ?? 'AUTO')) . "</td>
                <td>" . htmlspecialchars((string)($row['reason'] ?? '—')) . "</td>
                <td>" . htmlspecialchars((string)($row['last_evaluated_at'] ?? '—')) . "</td>
                <td><a class='btn btn-sm btn-outline-primary' href='/securegroups/group/" . htmlspecialchars($groupSlug) . "'>View details</a></td>
            </tr>";
        }
        if ($tableRows === '') {
            $tableRows = "<tr><td colspan='7' class='text-muted'>No secure groups available.</td></tr>";
        }

        $body = "<h1 class='mb-3'>My Secure Groups</h1>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr>
                    <th>Group</th><th>Description</th><th>Status</th><th>Source</th><th>Reason</th><th>Last Evaluated</th><th></th>
                  </tr></thead>
                  <tbody>{$tableRows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('My Secure Groups', $body), 200);
    });

    $registry->route('GET', '/securegroups/group/{slug}', function (Request $req) use ($app, $renderPage, $requireLogin, $resolveGroupId): Response {
        if ($resp = $requireLogin()) return $resp;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $slug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($slug);
        $group = $gid > 0 ? $app->db->one("SELECT * FROM module_secgroups_groups WHERE id=?", [$gid]) : null;
        if (!$group) {
            return Response::text('Group not found', 404);
        }
        $membership = $app->db->one(
            "SELECT * FROM module_secgroups_memberships WHERE group_id=? AND user_id=?",
            [$gid, $uid]
        );
        $requests = $app->db->all(
            "SELECT status, created_at, decided_at, note, admin_note\n"
            . " FROM module_secgroups_requests WHERE group_id=? AND user_id=? ORDER BY created_at DESC",
            [$gid, $uid]
        );
        $notifications = $app->db->all(
            "SELECT message, created_at FROM module_secgroups_notifications WHERE group_id=? AND user_id=? ORDER BY created_at DESC LIMIT 10",
            [$gid, $uid]
        );
        $history = $app->db->all(
            "SELECT action, source, message, created_at FROM module_secgroups_logs WHERE group_id=? AND user_id=? ORDER BY created_at DESC LIMIT 10",
            [$gid, $uid]
        );
        $evidence = [];
        if ($membership && !empty($membership['evidence_json'])) {
            $decoded = json_decode((string)($membership['evidence_json'] ?? '[]'), true);
            if (is_array($decoded)) $evidence = $decoded;
        }
        $evidenceRows = '';
        foreach ($evidence as $item) {
            if (!is_array($item)) continue;
            $passed = !empty($item['pass']);
            $evidenceRows .= "<tr>
                <td>" . htmlspecialchars((string)($item['name'] ?? $item['type'] ?? 'Rule')) . "</td>
                <td>" . ($passed ? 'Pass' : 'Fail') . "</td>
                <td>" . htmlspecialchars((string)($item['message'] ?? '')) . "</td>
              </tr>";
        }
        if ($evidenceRows === '') {
            $evidenceRows = "<tr><td colspan='3' class='text-muted'>No evaluation evidence yet.</td></tr>";
        }
        $requestRows = '';
        foreach ($requests as $request) {
            $requestRows .= "<tr>
                <td>" . htmlspecialchars((string)($request['status'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($request['created_at'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($request['decided_at'] ?? '—')) . "</td>
                <td>" . htmlspecialchars((string)($request['note'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($request['admin_note'] ?? '')) . "</td>
            </tr>";
        }
        if ($requestRows === '') {
            $requestRows = "<tr><td colspan='5' class='text-muted'>No requests yet.</td></tr>";
        }

        $noticeRows = '';
        foreach ($notifications as $notice) {
            $noticeRows .= "<tr><td>" . htmlspecialchars((string)($notice['message'] ?? '')) . "</td><td>" . htmlspecialchars((string)($notice['created_at'] ?? '')) . "</td></tr>";
        }
        if ($noticeRows === '') {
            $noticeRows = "<tr><td colspan='2' class='text-muted'>No notifications yet.</td></tr>";
        }

        $historyRows = '';
        foreach ($history as $entry) {
            $historyRows .= "<tr><td>" . htmlspecialchars((string)($entry['action'] ?? '')) . "</td><td>" . htmlspecialchars((string)($entry['message'] ?? '')) . "</td><td>" . htmlspecialchars((string)($entry['created_at'] ?? '')) . "</td></tr>";
        }
        if ($historyRows === '') {
            $historyRows = "<tr><td colspan='3' class='text-muted'>No history yet.</td></tr>";
        }

        $canApply = (int)($group['allow_applications'] ?? 0) === 1;
        $status = (string)($membership['status'] ?? 'PENDING');
        $groupSlug = (string)($group['key_slug'] ?? $slug ?? '');
        $applyForm = '';
        if ($canApply) {
            $applyForm = "<form method='post' action='/securegroups/group/" . htmlspecialchars($groupSlug) . "/apply' class='d-inline'>
                <button class='btn btn-sm btn-success'>Apply</button>
              </form>";
            $applyForm .= " <form method='post' action='/securegroups/group/" . htmlspecialchars($groupSlug) . "/withdraw' class='d-inline'>
                <button class='btn btn-sm btn-outline-secondary'>Withdraw</button>
              </form>";
        } else {
            $applyForm = "<span class='text-muted'>Applications closed.</span>";
        }

        $body = "<h1 class='mb-3'>" . htmlspecialchars((string)($group['display_name'] ?? 'Secure Group')) . "</h1>
            <p>" . htmlspecialchars((string)($group['description'] ?? '')) . "</p>
            <div class='mb-3'><strong>Status:</strong> " . htmlspecialchars($status) . "</div>
            <div class='mb-4'>" . $applyForm . "</div>
            <div class='card mb-4'>
              <div class='card-header'>Eligibility Details</div>
              <div class='table-responsive'>
                <table class='table table-sm mb-0'>
                  <thead><tr><th>Rule</th><th>Status</th><th>Message</th></tr></thead>
                  <tbody>{$evidenceRows}</tbody>
                </table>
              </div>
            </div>
            <div class='card mb-4'>
              <div class='card-header'>Request History</div>
              <div class='table-responsive'>
                <table class='table table-sm mb-0'>
                  <thead><tr><th>Status</th><th>Submitted</th><th>Decided</th><th>Note</th><th>Admin Note</th></tr></thead>
                  <tbody>{$requestRows}</tbody>
                </table>
              </div>
            </div>
            <div class='card mb-4'>
              <div class='card-header'>Notifications</div>
              <div class='table-responsive'>
                <table class='table table-sm mb-0'>
                  <thead><tr><th>Message</th><th>When</th></tr></thead>
                  <tbody>{$noticeRows}</tbody>
                </table>
              </div>
            </div>
            <div class='card'>
              <div class='card-header'>Status History</div>
              <div class='table-responsive'>
                <table class='table table-sm mb-0'>
                  <thead><tr><th>Action</th><th>Message</th><th>When</th></tr></thead>
                  <tbody>{$historyRows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Group Details', $body), 200);
    });

    $registry->route('POST', '/securegroups/group/{slug}/apply', function (Request $req) use ($app, $requireLogin, $nowUtc, $resolveGroupId, $generatePublicId): Response {
        if ($resp = $requireLogin()) return $resp;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $slug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($slug);
        $group = $app->db->one("SELECT allow_applications FROM module_secgroups_groups WHERE id=?", [$gid]);
        if (!$group || (int)($group['allow_applications'] ?? 0) !== 1) {
            return Response::text('Applications disabled.', 403);
        }
        $publicId = $generatePublicId('module_secgroups_requests');
        $app->db->run(
            "INSERT INTO module_secgroups_requests\n"
            . " (public_id, group_id, user_id, status, created_at, note) VALUES (?, ?, ?, 'PENDING', ?, ?)",
            [$publicId, $gid, $uid, $nowUtc(), 'User request']
        );
        return Response::redirect('/securegroups/group/' . rawurlencode($slug));
    });

    $registry->route('POST', '/securegroups/group/{slug}/withdraw', function (Request $req) use ($app, $requireLogin, $nowUtc, $resolveGroupId): Response {
        if ($resp = $requireLogin()) return $resp;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $slug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($slug);
        $app->db->run(
            "UPDATE module_secgroups_requests SET status='WITHDRAWN', decided_at=?, admin_note='User withdrew'\n"
            . " WHERE group_id=? AND user_id=? AND status='PENDING'",
            [$nowUtc(), $gid, $uid]
        );
        return Response::redirect('/securegroups/group/' . rawurlencode($slug));
    });

    $registry->route('GET', '/admin/securegroups', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $kpis = [
            'groups' => (int)($app->db->one("SELECT COUNT(*) AS total FROM module_secgroups_groups")['total'] ?? 0),
            'members' => (int)($app->db->one("SELECT COUNT(*) AS total FROM module_secgroups_memberships WHERE status='IN'")['total'] ?? 0),
            'pending_requests' => (int)($app->db->one("SELECT COUNT(*) AS total FROM module_secgroups_requests WHERE status='PENDING'")['total'] ?? 0),
            'grace' => (int)($app->db->one("SELECT COUNT(*) AS total FROM module_secgroups_memberships WHERE status='GRACE'")['total'] ?? 0),
        ];

        $lastRun = $app->db->one(
            "SELECT started_at, status, message FROM module_corptools_job_runs\n"
            . " WHERE job_key='securegroups.evaluate_all' ORDER BY started_at DESC LIMIT 1"
        );

        $body = "<h1 class='mb-3'>Secure Groups Dashboard</h1>
            <div class='row g-3 mb-4'>
              <div class='col-md-3'><div class='card'><div class='card-body'><div class='text-muted'>Groups</div><h3>{$kpis['groups']}</h3></div></div></div>
              <div class='col-md-3'><div class='card'><div class='card-body'><div class='text-muted'>Users In Groups</div><h3>{$kpis['members']}</h3></div></div></div>
              <div class='col-md-3'><div class='card'><div class='card-body'><div class='text-muted'>Pending Requests</div><h3>{$kpis['pending_requests']}</h3></div></div></div>
              <div class='col-md-3'><div class='card'><div class='card-body'><div class='text-muted'>Grace Records</div><h3>{$kpis['grace']}</h3></div></div></div>
            </div>
            <div class='card mb-4'>
              <div class='card-body'>
                <div class='d-flex justify-content-between align-items-center'>
                  <div>
                    <div class='text-muted'>Last Evaluation Run</div>
                    <div>" . htmlspecialchars((string)($lastRun['started_at'] ?? '—')) . "</div>
                    <div class='text-muted small'>" . htmlspecialchars((string)($lastRun['status'] ?? '—')) . " " . htmlspecialchars((string)($lastRun['message'] ?? '')) . "</div>
                  </div>
                  <form method='post' action='/admin/securegroups/run-evaluation'>
                    <button class='btn btn-primary'>Run evaluation now</button>
                  </form>
                </div>
              </div>
            </div>
            <div class='card'>
              <div class='card-body'>
                <div class='row g-3'>
                  <div class='col-md-6'>
                    <form method='post' action='/admin/securegroups/run-evaluation-group'>
                      <label class='form-label'>Re-evaluate group</label>
                      <div class='input-group'>
                        <input class='form-control' name='group_slug' placeholder='Group slug'>
                        <button class='btn btn-outline-primary'>Run</button>
                      </div>
                    </form>
                  </div>
                  <div class='col-md-6'>
                    <form method='post' action='/admin/securegroups/run-evaluation-user'>
                      <label class='form-label'>Re-evaluate user</label>
                      <div class='input-group'>
                        <input class='form-control' name='user_public_id' placeholder='Member ID'>
                        <button class='btn btn-outline-primary'>Run</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>";

        return Response::html($renderPage('Secure Groups Dashboard', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/run-evaluation', function () use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;
        $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
        $runner->runJob($app, 'securegroups.evaluate_all', ['trigger' => 'ui']);
        return Response::redirect('/admin/securegroups');
    });

    $registry->route('POST', '/admin/securegroups/run-evaluation-group', function (Request $req) use ($app, $requireLogin, $requireRight, $resolveGroupId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;
        $groupSlug = trim((string)($req->post['group_slug'] ?? ''));
        $groupId = $resolveGroupId($groupSlug);
        $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
        $runner->runJob($app, 'securegroups.evaluate_group', ['trigger' => 'ui', 'group_id' => $groupId]);
        return Response::redirect('/admin/securegroups');
    });

    $registry->route('POST', '/admin/securegroups/run-evaluation-user', function (Request $req) use ($app, $requireLogin, $requireRight, $resolveUserId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;
        $publicId = trim((string)($req->post['user_public_id'] ?? ''));
        $userId = $resolveUserId($publicId);
        $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
        $runner->runJob($app, 'securegroups.evaluate_user', ['trigger' => 'ui', 'user_id' => $userId]);
        return Response::redirect('/admin/securegroups');
    });

    $registry->route('GET', '/admin/securegroups/groups', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groups = $app->db->all("SELECT * FROM module_secgroups_groups ORDER BY display_name ASC");
        $rows = '';
        foreach ($groups as $group) {
            $groupSlug = (string)($group['key_slug'] ?? '');
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($group['display_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($group['key_slug'] ?? '')) . "</td>
                <td>" . ((int)($group['enabled'] ?? 0) === 1 ? 'Yes' : 'No') . "</td>
                <td>" . ((int)($group['allow_applications'] ?? 0) === 1 ? 'Yes' : 'No') . "</td>
                <td>
                  <a class='btn btn-sm btn-outline-primary' href='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/edit'>Edit</a>
                  <a class='btn btn-sm btn-outline-secondary' href='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules'>Rules</a>
                </td>
            </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='5' class='text-muted'>No groups found.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Secure Groups</h1>
            <div class='mb-3'><a class='btn btn-success' href='/admin/securegroups/groups/new'>Create Group</a></div>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Name</th><th>Key</th><th>Enabled</th><th>Applications</th><th>Actions</th></tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Groups', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/groups/new', function () use ($renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $body = "<h1 class='mb-3'>Create Group</h1>
            <form method='post'>
              <div class='mb-3'>
                <label class='form-label'>Name</label>
                <input class='form-control' name='display_name' required>
              </div>
              <div class='mb-3'>
                <label class='form-label'>Description</label>
                <textarea class='form-control' name='description'></textarea>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Enabled</label>
                  <select class='form-select' name='enabled'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Allow Applications</label>
                  <select class='form-select' name='allow_applications'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Auto Group</label>
                  <select class='form-select' name='auto_group'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Grace Enabled</label>
                  <select class='form-select' name='can_grace'>
                    <option value='0'>No</option>
                    <option value='1'>Yes</option>
                  </select>
                </div>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Grace Days</label>
                  <input class='form-control' type='number' name='grace_default_days' value='0'>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Include In Updates</label>
                  <select class='form-select' name='include_in_updates'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Notify on Add</label>
                  <select class='form-select' name='notify_on_add'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Notify on Remove</label>
                  <select class='form-select' name='notify_on_remove'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Notify on Grace</label>
                  <select class='form-select' name='notify_on_grace'>
                    <option value='1'>Yes</option>
                    <option value='0'>No</option>
                  </select>
                </div>
              </div>
              <button class='btn btn-success'>Create</button>
            </form>";

        return Response::html($renderPage('Create Secure Group', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/groups/new', function (Request $req) use ($app, $requireLogin, $requireRight, $slugify, $ensureRightsGroup, $nowUtc): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $name = trim((string)($req->post['display_name'] ?? ''));
        if ($name === '') {
            return Response::text('Name is required', 422);
        }
        $key = $slugify($name);
        $rightsSlug = 'securegroup:' . $key;
        $rightsGroupId = $ensureRightsGroup($rightsSlug, 'SecureGroup - ' . $name);

        $stamp = $nowUtc();
        $app->db->run(
            "INSERT INTO module_secgroups_groups\n"
            . " (key_slug, display_name, description, enabled, auto_group, include_in_updates, can_grace, grace_default_days,\n"
            . " allow_applications, notify_on_add, notify_on_remove, notify_on_grace, rights_group_id, last_update_at, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $key,
                $name,
                trim((string)($req->post['description'] ?? '')),
                (int)($req->post['enabled'] ?? 1),
                (int)($req->post['auto_group'] ?? 1),
                (int)($req->post['include_in_updates'] ?? 1),
                (int)($req->post['can_grace'] ?? 0),
                (int)($req->post['grace_default_days'] ?? 0),
                (int)($req->post['allow_applications'] ?? 0),
                (int)($req->post['notify_on_add'] ?? 0),
                (int)($req->post['notify_on_remove'] ?? 0),
                (int)($req->post['notify_on_grace'] ?? 0),
                $rightsGroupId,
                $stamp,
                $stamp,
                $stamp,
            ]
        );
        return Response::redirect('/admin/securegroups/groups/' . rawurlencode($key) . '/edit');
    });

    $registry->route('GET', '/admin/securegroups/groups/{slug}/edit', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $resolveGroupId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $group = $gid > 0 ? $app->db->one("SELECT * FROM module_secgroups_groups WHERE id=?", [$gid]) : null;
        if (!$group) {
            return Response::text('Group not found', 404);
        }

        $body = "<h1 class='mb-3'>Edit Group</h1>
            <form method='post'>
              <div class='mb-3'>
                <label class='form-label'>Name</label>
                <input class='form-control' name='display_name' value='" . htmlspecialchars((string)($group['display_name'] ?? '')) . "' required>
              </div>
              <div class='mb-3'>
                <label class='form-label'>Description</label>
                <textarea class='form-control' name='description'>" . htmlspecialchars((string)($group['description'] ?? '')) . "</textarea>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Enabled</label>
                  <select class='form-select' name='enabled'>
                    <option value='1'" . ((int)($group['enabled'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['enabled'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Allow Applications</label>
                  <select class='form-select' name='allow_applications'>
                    <option value='1'" . ((int)($group['allow_applications'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['allow_applications'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Auto Group</label>
                  <select class='form-select' name='auto_group'>
                    <option value='1'" . ((int)($group['auto_group'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['auto_group'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Grace Enabled</label>
                  <select class='form-select' name='can_grace'>
                    <option value='0'" . ((int)($group['can_grace'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                    <option value='1'" . ((int)($group['can_grace'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                  </select>
                </div>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Grace Days</label>
                  <input class='form-control' type='number' name='grace_default_days' value='" . htmlspecialchars((string)($group['grace_default_days'] ?? 0)) . "'>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Include In Updates</label>
                  <select class='form-select' name='include_in_updates'>
                    <option value='1'" . ((int)($group['include_in_updates'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['include_in_updates'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Notify on Add</label>
                  <select class='form-select' name='notify_on_add'>
                    <option value='1'" . ((int)($group['notify_on_add'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['notify_on_add'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
                <div class='col-md-3'>
                  <label class='form-label'>Notify on Remove</label>
                  <select class='form-select' name='notify_on_remove'>
                    <option value='1'" . ((int)($group['notify_on_remove'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['notify_on_remove'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
              </div>
              <div class='row g-2 mb-3'>
                <div class='col-md-3'>
                  <label class='form-label'>Notify on Grace</label>
                  <select class='form-select' name='notify_on_grace'>
                    <option value='1'" . ((int)($group['notify_on_grace'] ?? 0) === 1 ? ' selected' : '') . ">Yes</option>
                    <option value='0'" . ((int)($group['notify_on_grace'] ?? 0) === 0 ? ' selected' : '') . ">No</option>
                  </select>
                </div>
              </div>
              <button class='btn btn-primary'>Save</button>
            </form>";

        return Response::html($renderPage('Edit Secure Group', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/groups/{slug}/edit', function (Request $req) use ($app, $requireLogin, $requireRight, $slugify, $nowUtc, $resolveGroupId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;
        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $group = $gid > 0 ? $app->db->one("SELECT * FROM module_secgroups_groups WHERE id=?", [$gid]) : null;
        if (!$group) {
            return Response::text('Group not found', 404);
        }

        $name = trim((string)($req->post['display_name'] ?? ''));
        if ($name === '') {
            return Response::text('Name is required', 422);
        }
        $key = (string)($group['key_slug'] ?? $slugify($name));

        $stamp = $nowUtc();
        $app->db->run(
            "UPDATE module_secgroups_groups\n"
            . " SET display_name=?, description=?, enabled=?, auto_group=?, include_in_updates=?, can_grace=?, grace_default_days=?,\n"
            . " allow_applications=?, notify_on_add=?, notify_on_remove=?, notify_on_grace=?, last_update_at=?, updated_at=?\n"
            . " WHERE id=?",
            [
                $name,
                trim((string)($req->post['description'] ?? '')),
                (int)($req->post['enabled'] ?? 1),
                (int)($req->post['auto_group'] ?? 1),
                (int)($req->post['include_in_updates'] ?? 1),
                (int)($req->post['can_grace'] ?? 0),
                (int)($req->post['grace_default_days'] ?? 0),
                (int)($req->post['allow_applications'] ?? 0),
                (int)($req->post['notify_on_add'] ?? 0),
                (int)($req->post['notify_on_remove'] ?? 0),
                (int)($req->post['notify_on_grace'] ?? 0),
                $stamp,
                $stamp,
                $gid,
            ]
        );

        return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/edit');
    });

    $registry->route('GET', '/admin/securegroups/groups/{slug}/rules', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $resolveGroupId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $group = $gid > 0 ? $app->db->one("SELECT * FROM module_secgroups_groups WHERE id=?", [$gid]) : null;
        if (!$group) {
            return Response::text('Group not found', 404);
        }
        $filters = $app->db->all(
            "SELECT f.*, f.public_id AS filter_public_id, gf.sort_order, gf.enabled, gf.last_evaluated_at, gf.last_pass, gf.last_message,\n"
            . " lu.public_id AS last_user_public_id\n"
            . " FROM module_secgroups_filters f\n"
            . " JOIN module_secgroups_group_filters gf ON gf.filter_id=f.id\n"
            . " LEFT JOIN eve_users lu ON lu.id = gf.last_user_id\n"
            . " WHERE gf.group_id=? ORDER BY gf.sort_order ASC",
            [$gid]
        );

        $search = trim((string)($req->query['search'] ?? ''));
        $searchType = (string)($req->query['type'] ?? 'corporation');
        $groupSearch = trim((string)($req->query['group_search'] ?? ''));
        $searchResults = [];
        if ($search !== '') {
            $entityType = $searchType === 'alliance' ? 'alliance' : 'corporation';
            $searchResults = $app->db->all(
                "SELECT entity_id, name FROM universe_entities\n"
                . " WHERE entity_type=? AND name LIKE ? ORDER BY name ASC LIMIT 25",
                [$entityType, '%' . $search . '%']
            );
        }
        $groupResults = [];
        if ($groupSearch !== '') {
            $groupResults = $app->db->all(
                "SELECT id, slug, name FROM groups WHERE name LIKE ? OR slug LIKE ? ORDER BY name ASC LIMIT 25",
                ['%' . $groupSearch . '%', '%' . $groupSearch . '%']
            );
        }

        $rows = '';
        foreach ($filters as $index => $filter) {
            $filterPublicId = (string)($filter['filter_public_id'] ?? '');
            $enabled = (int)($filter['enabled'] ?? 0) === 1;
            $lastPass = $filter['last_pass'];
            $lastStatus = $lastPass === null ? '—' : ((int)$lastPass === 1 ? 'Pass' : 'Fail');
            $lastMessage = (string)($filter['last_message'] ?? '');
            $lastEvaluated = (string)($filter['last_evaluated_at'] ?? '—');
            $lastUserId = (string)($filter['last_user_public_id'] ?? '');
            $disableUp = $index === 0 ? ' disabled' : '';
            $disableDown = $index === (count($filters) - 1) ? ' disabled' : '';
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($filter['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($filter['type'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($filter['description'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($filter['grace_period_days'] ?? 0)) . "</td>
                <td>" . ($enabled ? 'Enabled' : 'Disabled') . "</td>
                <td>
                  <div class='fw-semibold'>{$lastStatus}</div>
                  <div class='text-muted small'>" . htmlspecialchars($lastMessage !== '' ? $lastMessage : 'No evaluation yet.') . "</div>
                  <div class='text-muted small'>User: " . htmlspecialchars($lastUserId !== '' ? $lastUserId : '—') . "</div>
                </td>
                <td>" . htmlspecialchars($lastEvaluated) . "</td>
                <td>
                  <div class='d-flex flex-wrap gap-1'>
                    <form method='post' action='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules/" . htmlspecialchars($filterPublicId) . "/move' class='d-inline'>
                      <input type='hidden' name='direction' value='up'>
                      <button class='btn btn-sm btn-outline-secondary'{$disableUp}>▲</button>
                    </form>
                    <form method='post' action='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules/" . htmlspecialchars($filterPublicId) . "/move' class='d-inline'>
                      <input type='hidden' name='direction' value='down'>
                      <button class='btn btn-sm btn-outline-secondary'{$disableDown}>▼</button>
                    </form>
                    <form method='post' action='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules/" . htmlspecialchars($filterPublicId) . "/toggle' class='d-inline'>
                      <input type='hidden' name='enabled' value='" . ($enabled ? '0' : '1') . "'>
                      <button class='btn btn-sm " . ($enabled ? 'btn-outline-warning' : 'btn-outline-success') . "'>" . ($enabled ? 'Disable' : 'Enable') . "</button>
                    </form>
                  </div>
                  <form method='post' action='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules/test' class='mt-2 d-flex gap-2'>
                    <input type='hidden' name='filter_public_id' value='" . htmlspecialchars($filterPublicId) . "'>
                    <input class='form-control form-control-sm' name='user_public_id' placeholder='Member ID' required>
                    <button class='btn btn-sm btn-outline-primary'>Test</button>
                  </form>
                </td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='8' class='text-muted'>No rules configured.</td></tr>";
        }

        $searchRows = '';
        foreach ($searchResults as $result) {
            $searchRows .= "<tr><td>" . htmlspecialchars((string)($result['name'] ?? '')) . "</td><td>" . htmlspecialchars((string)($result['entity_id'] ?? '')) . "</td></tr>";
        }
        if ($search !== '' && $searchRows === '') {
            $searchRows = "<tr><td colspan='2' class='text-muted'>No results found.</td></tr>";
        }

        $groupRows = '';
        foreach ($groupResults as $groupRow) {
            $groupRows .= "<tr><td>" . htmlspecialchars((string)($groupRow['name'] ?? '')) . "</td><td>" . htmlspecialchars((string)($groupRow['slug'] ?? '')) . "</td></tr>";
        }
        if ($groupSearch !== '' && $groupRows === '') {
            $groupRows = "<tr><td colspan='2' class='text-muted'>No groups found.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Rules for " . htmlspecialchars((string)($group['display_name'] ?? '')) . "</h1>
            <div class='card mb-4'>
              <div class='card-header'>Corp/Alliance Search</div>
              <div class='card-body'>
                <form method='get' class='row g-2'>
                  <div class='col-md-4'>
                    <input class='form-control' name='search' placeholder='Search name' value='" . htmlspecialchars($search) . "'>
                  </div>
                  <div class='col-md-3'>
                    <select class='form-select' name='type'>
                      <option value='corporation'" . ($searchType !== 'alliance' ? ' selected' : '') . ">Corporation</option>
                      <option value='alliance'" . ($searchType === 'alliance' ? ' selected' : '') . ">Alliance</option>
                    </select>
                  </div>
                  <div class='col-md-2'>
                    <button class='btn btn-outline-secondary w-100'>Search</button>
                  </div>
                </form>
                <div class='table-responsive mt-3'>
                  <table class='table table-sm mb-0'>
                    <thead><tr><th>Name</th><th>ID</th></tr></thead>
                    <tbody>{$searchRows}</tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class='card mb-4'>
              <div class='card-header'>Rights Group Search</div>
              <div class='card-body'>
                <form method='get' class='row g-2'>
                  <div class='col-md-6'>
                    <input class='form-control' name='group_search' placeholder='Search group name or slug' value='" . htmlspecialchars($groupSearch) . "'>
                  </div>
                  <div class='col-md-2'>
                    <button class='btn btn-outline-secondary w-100'>Search</button>
                  </div>
                </form>
                <div class='table-responsive mt-3'>
                  <table class='table table-sm mb-0'>
                    <thead><tr><th>Name</th><th>Slug</th></tr></thead>
                    <tbody>{$groupRows}</tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class='card mb-4'>
              <div class='card-body'>
                <form method='post' action='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules/add' id='secgroups-rule-form'>
                  <div class='row g-2'>
                    <div class='col-md-3'>
                      <label class='form-label'>Filter Type</label>
                      <select class='form-select' name='type' id='secgroups-rule-type'>
                        <option value='alt_corp'>Alt Corp Filter</option>
                        <option value='alt_alliance'>Alt Alliance Filter</option>
                        <option value='user_in_group'>User In Group Filter</option>
                        <option value='expression'>Filter Expression</option>
                      </select>
                    </div>
                    <div class='col-md-3'>
                      <label class='form-label'>Name</label>
                      <input class='form-control' name='name' required>
                    </div>
                    <div class='col-md-3'>
                      <label class='form-label'>Description</label>
                      <input class='form-control' name='description'>
                    </div>
                    <div class='col-md-3'>
                      <label class='form-label'>Grace Days</label>
                      <input class='form-control' type='number' name='grace_period_days' value='0'>
                    </div>
                  </div>
                  <div class='row g-2 mt-2'>
                    <div class='col-md-3 secgroups-rule-field' data-rule-types='alt_corp'>
                      <label class='form-label'>Corp ID</label>
                      <input class='form-control' name='corp_id' placeholder='For alt corp filter'>
                    </div>
                    <div class='col-md-3 secgroups-rule-field' data-rule-types='alt_alliance'>
                      <label class='form-label'>Alliance ID</label>
                      <input class='form-control' name='alliance_id' placeholder='For alt alliance filter'>
                    </div>
                    <div class='col-md-3 secgroups-rule-field' data-rule-types='user_in_group'>
                      <label class='form-label'>Group Slugs</label>
                      <input class='form-control' name='group_slugs' placeholder='Comma-separated'>
                    </div>
                    <div class='col-md-3 secgroups-rule-field' data-rule-types='alt_corp,alt_alliance'>
                      <label class='form-label'>Exempt Character IDs</label>
                      <input class='form-control' name='exemptions' placeholder='Comma-separated'>
                    </div>
                  </div>
                  <div class='row g-2 mt-2 secgroups-rule-field' data-rule-types='expression'>
                    <div class='col-md-4'>
                      <label class='form-label'>Expression Left Filter Public ID</label>
                      <input class='form-control' name='left_filter_public_id'>
                    </div>
                    <div class='col-md-4'>
                      <label class='form-label'>Expression Right Filter Public ID</label>
                      <input class='form-control' name='right_filter_public_id'>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Operator</label>
                      <select class='form-select' name='operator'>
                        <option value='and'>AND</option>
                        <option value='or'>OR</option>
                        <option value='xor'>XOR</option>
                      </select>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Negate</label>
                      <select class='form-select' name='negate'>
                        <option value='0'>No</option>
                        <option value='1'>Yes</option>
                      </select>
                    </div>
                  </div>
                  <button class='btn btn-success mt-3'>Add Filter</button>
                </form>
              </div>
            </div>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Name</th><th>Type</th><th>Description</th><th>Grace</th><th>Status</th><th>Last Result</th><th>Last Evaluated</th><th>Actions</th></tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>
            <script>
              (function () {
                var typeSelect = document.getElementById('secgroups-rule-type');
                var fields = document.querySelectorAll('.secgroups-rule-field');
                function updateFields() {
                  var type = typeSelect ? typeSelect.value : '';
                  fields.forEach(function (field) {
                    var types = (field.getAttribute('data-rule-types') || '').split(',');
                    var show = types.indexOf(type) !== -1;
                    field.style.display = show ? '' : 'none';
                  });
                }
                if (typeSelect) {
                  typeSelect.addEventListener('change', updateFields);
                  updateFields();
                }
              })();
            </script>";

        return Response::html($renderPage('Secure Group Rules', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/groups/{slug}/rules/add', function (Request $req) use ($app, $requireLogin, $requireRight, $nowUtc, $resolveGroupId, $generatePublicId, $resolveFilterId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $type = (string)($req->post['type'] ?? '');
        $name = trim((string)($req->post['name'] ?? ''));
        if ($gid <= 0 || $type === '' || $name === '') {
            return Response::text('Invalid filter data', 422);
        }

        $groupSlugs = array_filter(array_map('trim', explode(',', (string)($req->post['group_slugs'] ?? ''))));
        $groupIds = [];
        foreach ($groupSlugs as $groupSlugValue) {
            $row = $app->db->one("SELECT id FROM groups WHERE slug=? LIMIT 1", [$groupSlugValue]);
            $gidValue = (int)($row['id'] ?? 0);
            if ($gidValue > 0) {
                $groupIds[] = $gidValue;
            }
        }
        $exemptions = array_filter(array_map('trim', explode(',', (string)($req->post['exemptions'] ?? ''))));
        $leftFilterPublicId = trim((string)($req->post['left_filter_public_id'] ?? ''));
        $rightFilterPublicId = trim((string)($req->post['right_filter_public_id'] ?? ''));
        $config = [
            'corp_id' => (int)($req->post['corp_id'] ?? 0),
            'alliance_id' => (int)($req->post['alliance_id'] ?? 0),
            'group_ids' => $groupIds,
            'exemptions' => array_map('intval', $exemptions),
            'left_filter_id' => $resolveFilterId($leftFilterPublicId),
            'right_filter_id' => $resolveFilterId($rightFilterPublicId),
            'operator' => (string)($req->post['operator'] ?? 'and'),
            'negate' => (int)($req->post['negate'] ?? 0) === 1,
        ];

        $stamp = $nowUtc();
        $publicId = $generatePublicId('module_secgroups_filters');
        $app->db->run(
            "INSERT INTO module_secgroups_filters\n"
            . " (public_id, type, name, description, config_json, grace_period_days, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $publicId,
                $type,
                $name,
                trim((string)($req->post['description'] ?? '')),
                json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (int)($req->post['grace_period_days'] ?? 0),
                $stamp,
                $stamp,
            ]
        );
        $row = $app->db->one("SELECT LAST_INSERT_ID() AS id");
        $filterId = (int)($row['id'] ?? 0);

        $nextOrderRow = $app->db->one(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM module_secgroups_group_filters WHERE group_id=?",
            [$gid]
        );
        $nextOrder = (int)($nextOrderRow['next_order'] ?? 1);
        $app->db->run(
            "INSERT INTO module_secgroups_group_filters (group_id, filter_id, sort_order, enabled) VALUES (?, ?, ?, 1)",
            [$gid, $filterId, $nextOrder]
        );

        return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
    });

    $registry->route('POST', '/admin/securegroups/groups/{slug}/rules/{filterPublicId}/move', function (Request $req) use ($app, $requireLogin, $requireRight, $resolveGroupId, $resolveFilterId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $filterId = $resolveFilterId((string)($req->params['filterPublicId'] ?? ''));
        $direction = (string)($req->post['direction'] ?? '');
        if ($gid <= 0 || $filterId <= 0 || ($direction !== 'up' && $direction !== 'down')) {
            return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
        }

        $ordered = $app->db->all(
            "SELECT filter_id FROM module_secgroups_group_filters WHERE group_id=? ORDER BY sort_order ASC, filter_id ASC",
            [$gid]
        );
        if (empty($ordered)) {
            return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
        }

        $ids = array_map(fn(array $row): int => (int)($row['filter_id'] ?? 0), $ordered);
        $pos = array_search($filterId, $ids, true);
        if ($pos === false) {
            return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
        }

        if ($direction === 'up' && $pos > 0) {
            $swap = $ids[$pos - 1];
            $ids[$pos - 1] = $ids[$pos];
            $ids[$pos] = $swap;
        }
        if ($direction === 'down' && $pos < count($ids) - 1) {
            $swap = $ids[$pos + 1];
            $ids[$pos + 1] = $ids[$pos];
            $ids[$pos] = $swap;
        }

        foreach ($ids as $index => $id) {
            $app->db->run(
                "UPDATE module_secgroups_group_filters SET sort_order=? WHERE group_id=? AND filter_id=?",
                [$index + 1, $gid, $id]
            );
        }

        return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
    });

    $registry->route('POST', '/admin/securegroups/groups/{slug}/rules/{filterPublicId}/toggle', function (Request $req) use ($app, $requireLogin, $requireRight, $resolveGroupId, $resolveFilterId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $filterId = $resolveFilterId((string)($req->params['filterPublicId'] ?? ''));
        $enabled = (int)($req->post['enabled'] ?? 1) === 1 ? 1 : 0;
        if ($gid <= 0 || $filterId <= 0) {
            return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
        }

        $app->db->run(
            "UPDATE module_secgroups_group_filters SET enabled=? WHERE group_id=? AND filter_id=?",
            [$enabled, $gid, $filterId]
        );

        return Response::redirect('/admin/securegroups/groups/' . rawurlencode($groupSlug) . '/rules');
    });

    $registry->route('POST', '/admin/securegroups/groups/{slug}/rules/test', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $evaluateFilter, $resolveGroupId, $resolveFilterId, $resolveUserId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = (string)($req->params['slug'] ?? '');
        $gid = $resolveGroupId($groupSlug);
        $filterId = $resolveFilterId(trim((string)($req->post['filter_public_id'] ?? '')));
        $userId = $resolveUserId(trim((string)($req->post['user_public_id'] ?? '')));
        $group = $app->db->one("SELECT display_name FROM module_secgroups_groups WHERE id=?", [$gid]);
        $filter = $app->db->one("SELECT * FROM module_secgroups_filters WHERE id=?", [$filterId]);

        if (!$group || !$filter || $userId <= 0) {
            return Response::text('Invalid test request', 422);
        }

        $result = $evaluateFilter($userId, $filter, ['evaluate_filter' => $evaluateFilter]);
        $body = "<h1 class='mb-3'>Rule Test Result</h1>
            <p><strong>Group:</strong> " . htmlspecialchars((string)($group['display_name'] ?? '')) . "</p>
            <p><strong>Filter:</strong> " . htmlspecialchars((string)($filter['name'] ?? '')) . "</p>
            <p><strong>Result:</strong> " . (($result['pass'] ?? false) ? 'Pass' : 'Fail') . "</p>
            <pre class='bg-light p-3'>" . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "</pre>
            <a class='btn btn-outline-secondary' href='/admin/securegroups/groups/" . htmlspecialchars($groupSlug) . "/rules'>Back</a>";

        return Response::html($renderPage('Rule Test', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/requests', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $rows = $app->db->all(
            "SELECT r.*, r.public_id AS request_public_id, g.display_name, u.character_name, u.public_id AS user_public_id\n"
            . " FROM module_secgroups_requests r\n"
            . " JOIN module_secgroups_groups g ON g.id=r.group_id\n"
            . " JOIN eve_users u ON u.id=r.user_id\n"
            . " ORDER BY r.created_at DESC LIMIT 200"
        );

        $tableRows = '';
        foreach ($rows as $row) {
            $rid = (string)($row['request_public_id'] ?? '');
            $tableRows .= "<tr>
                <td>" . htmlspecialchars((string)($row['display_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['character_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['user_public_id'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['status'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['created_at'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['note'] ?? '')) . "</td>
                <td>
                  <form method='post' action='/admin/securegroups/requests/" . htmlspecialchars($rid) . "/approve' class='d-flex gap-2'>
                    <input class='form-control form-control-sm' name='admin_note' placeholder='Approval note'>
                    <button class='btn btn-sm btn-success'>Approve</button>
                  </form>
                  <form method='post' action='/admin/securegroups/requests/" . htmlspecialchars($rid) . "/deny' class='d-flex gap-2 mt-2'>
                    <input class='form-control form-control-sm' name='admin_note' placeholder='Denial note'>
                    <button class='btn btn-sm btn-outline-danger'>Deny</button>
                  </form>
                </td>
            </tr>";
        }
        if ($tableRows === '') {
            $tableRows = "<tr><td colspan='7' class='text-muted'>No requests.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Secure Group Requests</h1>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Group</th><th>User</th><th>Member ID</th><th>Status</th><th>Submitted</th><th>Note</th><th>Actions</th></tr></thead>
                  <tbody>{$tableRows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Group Requests', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/requests/{publicId}/approve', function (Request $req) use ($app, $requireLogin, $requireRight, $syncUserRightsGroup, $nowUtc, $resolveRequestId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $rid = $resolveRequestId((string)($req->params['publicId'] ?? ''));
        $adminNote = trim((string)($req->post['admin_note'] ?? 'Approved'));
        $request = $app->db->one("SELECT * FROM module_secgroups_requests WHERE id=?", [$rid]);
        if (!$request) {
            return Response::text('Request not found', 404);
        }
        $group = $app->db->one("SELECT * FROM module_secgroups_groups WHERE id=?", [(int)($request['group_id'] ?? 0)]);
        if (!$group) {
            return Response::text('Group not found', 404);
        }

        $app->db->run(
            "UPDATE module_secgroups_requests SET status='APPROVED', decided_at=?, admin_note=? WHERE id=?",
            [$nowUtc(), $adminNote, $rid]
        );
        $stamp = $nowUtc();
        $app->db->run(
            "INSERT INTO module_secgroups_memberships\n"
            . " (group_id, user_id, status, source, reason, last_evaluated_at, created_at, updated_at)\n"
            . " VALUES (?, ?, 'IN', 'REQUEST', ?, ?, ?, ?)\n"
            . " ON DUPLICATE KEY UPDATE status='IN', source='REQUEST', reason=VALUES(reason), last_evaluated_at=?, updated_at=?",
            [$group['id'], $request['user_id'], $adminNote, $stamp, $stamp, $stamp, $stamp]
        );

        $rightsGroupId = (int)($group['rights_group_id'] ?? 0);
        if ($rightsGroupId > 0) {
            $syncUserRightsGroup((int)($request['user_id'] ?? 0), $rightsGroupId, true);
        }

        $app->db->run(
            "INSERT INTO module_secgroups_logs (group_id, user_id, action, source, message, created_at) VALUES (?, ?, 'REQUEST_APPROVE', 'REQUEST', ?, ?)",
            [(int)($group['id'] ?? 0), (int)($request['user_id'] ?? 0), $adminNote, $nowUtc()]
        );

        return Response::redirect('/admin/securegroups/requests');
    });

    $registry->route('POST', '/admin/securegroups/requests/{publicId}/deny', function (Request $req) use ($app, $requireLogin, $requireRight, $nowUtc, $resolveRequestId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $rid = $resolveRequestId((string)($req->params['publicId'] ?? ''));
        $adminNote = trim((string)($req->post['admin_note'] ?? 'Denied'));
        $request = $app->db->one("SELECT * FROM module_secgroups_requests WHERE id=?", [$rid]);
        if (!$request) {
            return Response::text('Request not found', 404);
        }

        $app->db->run(
            "UPDATE module_secgroups_requests SET status='DENIED', decided_at=?, admin_note=? WHERE id=?",
            [$nowUtc(), $adminNote, $rid]
        );
        $app->db->run(
            "INSERT INTO module_secgroups_logs (group_id, user_id, action, source, message, created_at) VALUES (?, ?, 'REQUEST_DENY', 'REQUEST', ?, ?)",
            [(int)($request['group_id'] ?? 0), (int)($request['user_id'] ?? 0), $adminNote, $nowUtc()]
        );

        return Response::redirect('/admin/securegroups/requests');
    });

    $registry->route('GET', '/admin/securegroups/logs', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $rows = $app->db->all(
            "SELECT l.*, g.display_name, u.character_name, u.public_id AS user_public_id\n"
            . " FROM module_secgroups_logs l\n"
            . " JOIN module_secgroups_groups g ON g.id=l.group_id\n"
            . " JOIN eve_users u ON u.id=l.user_id\n"
            . " ORDER BY l.created_at DESC LIMIT 200"
        );
        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= "<tr>
                <td>" . htmlspecialchars((string)($row['display_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['character_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['user_public_id'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['action'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['source'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['message'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['created_at'] ?? '')) . "</td>
            </tr>";
        }
        if ($tableRows === '') {
            $tableRows = "<tr><td colspan='7' class='text-muted'>No logs yet.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Secure Group Logs</h1>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Group</th><th>User</th><th>Member ID</th><th>Action</th><th>Source</th><th>Message</th><th>When</th></tr></thead>
                  <tbody>{$tableRows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Secure Group Logs', $body), 200);
    });

    $registry->route('GET', '/admin/securegroups/overrides', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $overrides = $app->db->all(
            "SELECT o.*, g.display_name, u.character_name, u.public_id AS user_public_id\n"
            . " FROM module_secgroups_overrides o\n"
            . " JOIN module_secgroups_groups g ON g.id=o.group_id\n"
            . " JOIN eve_users u ON u.id=o.user_id\n"
            . " ORDER BY o.created_at DESC"
        );
        $rows = '';
        foreach ($overrides as $override) {
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($override['display_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($override['character_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($override['user_public_id'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($override['forced_state'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($override['expires_at'] ?? '—')) . "</td>
                <td>" . htmlspecialchars((string)($override['reason'] ?? '')) . "</td>
            </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='6' class='text-muted'>No overrides.</td></tr>";
        }

        $groupOptions = '';
        foreach ($app->db->all("SELECT key_slug, display_name FROM module_secgroups_groups ORDER BY display_name ASC") as $group) {
            $groupOptions .= "<option value='" . htmlspecialchars((string)($group['key_slug'] ?? '')) . "'>" . htmlspecialchars((string)($group['display_name'] ?? '')) . "</option>";
        }

        $body = "<h1 class='mb-3'>Manual Overrides</h1>
            <div class='card mb-4'>
              <div class='card-body'>
                <form method='post' action='/admin/securegroups/overrides/add'>
                  <div class='row g-2'>
                    <div class='col-md-3'>
                      <label class='form-label'>Group</label>
                      <select class='form-select' name='group_slug'>{$groupOptions}</select>
                    </div>
                    <div class='col-md-3'>
                      <label class='form-label'>Member ID</label>
                      <input class='form-control' name='user_public_id' required>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Forced State</label>
                      <select class='form-select' name='forced_state'>
                        <option value='IN'>IN</option>
                        <option value='OUT'>OUT</option>
                      </select>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Expires At (UTC)</label>
                      <input class='form-control' name='expires_at' placeholder='YYYY-mm-dd HH:MM:SS'>
                    </div>
                    <div class='col-md-2'>
                      <label class='form-label'>Reason</label>
                      <input class='form-control' name='reason' required>
                    </div>
                  </div>
                  <button class='btn btn-success mt-3'>Add Override</button>
                </form>
              </div>
            </div>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Group</th><th>User</th><th>Member ID</th><th>State</th><th>Expires</th><th>Reason</th></tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Manual Overrides', $body), 200);
    });

    $registry->route('POST', '/admin/securegroups/overrides/add', function (Request $req) use ($app, $requireLogin, $requireRight, $nowUtc, $resolveGroupId, $resolveUserId): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $groupSlug = trim((string)($req->post['group_slug'] ?? ''));
        $groupId = $resolveGroupId($groupSlug);
        $publicId = trim((string)($req->post['user_public_id'] ?? ''));
        $userId = $resolveUserId($publicId);
        $state = (string)($req->post['forced_state'] ?? 'IN');
        $expires = trim((string)($req->post['expires_at'] ?? ''));
        $reason = trim((string)($req->post['reason'] ?? ''));
        if ($groupId <= 0 || $userId <= 0 || $reason === '') {
            return Response::text('Invalid override data', 422);
        }

        $app->db->run(
            "INSERT INTO module_secgroups_overrides\n"
            . " (group_id, user_id, forced_state, expires_at, reason, created_by, created_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$groupId, $userId, $state, $expires !== '' ? $expires : null, $reason, (int)($_SESSION['user_id'] ?? 0), $nowUtc()]
        );

        return Response::redirect('/admin/securegroups/overrides');
    });

    $registry->route('GET', '/admin/system/cron', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        JobRegistry::sync($app->db);
        $jobs = $app->db->all(
            "SELECT job_key, name, schedule_seconds, is_enabled, last_run_at, last_status, last_message\n"
            . " FROM module_corptools_jobs ORDER BY job_key ASC"
        );
        $runs = $app->db->all(
            "SELECT job_key, status, started_at, finished_at, duration_ms, message\n"
            . " FROM module_corptools_job_runs ORDER BY started_at DESC LIMIT 50"
        );

        $jobRows = '';
        foreach ($jobs as $job) {
            $key = htmlspecialchars((string)($job['job_key'] ?? ''));
            $jobRows .= "<tr>
                <td>{$key}</td>
                <td>" . htmlspecialchars((string)($job['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['schedule_seconds'] ?? '')) . "s</td>
                <td>" . htmlspecialchars((string)($job['last_status'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['last_run_at'] ?? '—')) . "</td>
                <td>" . htmlspecialchars((string)($job['last_message'] ?? '')) . "</td>
                <td>
                  <form method='post' action='/admin/system/cron/run'>
                    <input type='hidden' name='job_key' value='{$key}'>
                    <button class='btn btn-sm btn-outline-primary'>Run Now</button>
                  </form>
                </td>
            </tr>";
        }
        if ($jobRows === '') {
            $jobRows = "<tr><td colspan='7' class='text-muted'>No cron jobs registered.</td></tr>";
        }

        $runRows = '';
        foreach ($runs as $run) {
            $runRows .= "<tr>
                <td>" . htmlspecialchars((string)($run['job_key'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($run['status'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($run['started_at'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($run['finished_at'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($run['duration_ms'] ?? 0)) . "ms</td>
                <td>" . htmlspecialchars((string)($run['message'] ?? '')) . "</td>
            </tr>";
        }
        if ($runRows === '') {
            $runRows = "<tr><td colspan='6' class='text-muted'>No job runs yet.</td></tr>";
        }

        $snippet = "* * * * * cd /var/www/ModularAlliance && /usr/bin/flock -n /tmp/modularalliance-cron.lock /usr/bin/php -d detect_unicode=0 bin/cron.php run --due --verbose >> /var/log/modularalliance/cron.log 2>&1";

        $body = "<h1 class='mb-3'>Cron Manager</h1>
            <div class='card mb-4'>
              <div class='card-body'>
                <h5 class='card-title'>Ubuntu Crontab Snippet</h5>
                <pre class='bg-light p-3'>{$snippet}</pre>
                <ul class='text-muted small'>
                  <li>Use absolute paths and ensure log permissions.</li>
                  <li>Use log rotation for /var/log/modularalliance/cron.log.</li>
                  <li>Inspect failures in this dashboard and module logs.</li>
                </ul>
              </div>
            </div>
            <div class='card mb-4'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Job Key</th><th>Name</th><th>Schedule</th><th>Last Status</th><th>Last Run</th><th>Message</th><th></th></tr></thead>
                  <tbody>{$jobRows}</tbody>
                </table>
              </div>
            </div>
            <div class='card'>
              <div class='table-responsive'>
                <table class='table table-striped mb-0'>
                  <thead><tr><th>Job</th><th>Status</th><th>Started</th><th>Finished</th><th>Duration</th><th>Message</th></tr></thead>
                  <tbody>{$runRows}</tbody>
                </table>
              </div>
            </div>";

        return Response::html($renderPage('Cron Manager', $body), 200);
    });

    $registry->route('POST', '/admin/system/cron/run', function (Request $req) use ($app, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('securegroups.admin')) return $resp;

        $jobKey = (string)($req->post['job_key'] ?? '');
        if ($jobKey !== '') {
            $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
            $runner->runJob($app, $jobKey, ['trigger' => 'ui']);
        }
        return Response::redirect('/admin/system/cron');
    });
};
