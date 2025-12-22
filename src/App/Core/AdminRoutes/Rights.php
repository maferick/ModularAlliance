<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\Rights as CoreRights;
use App\Http\Response;

if (class_exists(__NAMESPACE__ . '\\Rights', false)) {
    return;
}

final class Rights
{
    public static function register(App $app, callable $render): void
    {
        // Rights (Groups + Permissions) — scalable UI
        $app->router->get('/admin/rights', function () use ($app, $render): Response {
            $groups = $app->db->all("SELECT id, slug, name, is_admin FROM groups ORDER BY is_admin DESC, name ASC");

            // Selected group (default: first)
            $selSlug = (string)($_GET['group'] ?? '');
            $selGroup = null;
            foreach ($groups as $g) {
                if ($selSlug !== '' && $g['slug'] === $selSlug) { $selGroup = $g; break; }
            }
            if (!$selGroup && !empty($groups)) { $selGroup = $groups[0]; $selSlug = $selGroup['slug']; }

            // Filters
            $q = trim((string)($_GET['q'] ?? ''));
            $module = trim((string)($_GET['module'] ?? ''));
            $view = trim((string)($_GET['view'] ?? 'all')); // all|granted|unassigned

            // Load rights
            $rights = $app->db->all("SELECT id, slug, description, module_slug FROM rights ORDER BY module_slug ASC, slug ASC");

            // Grants map (for selected group only)
            $grants = [];
            if ($selGroup) {
                foreach ($app->db->all("SELECT right_id FROM group_rights WHERE group_id=?", [(int)$selGroup['id']]) as $r) {
                    $grants[(int)$r['right_id']] = true;
                }
            }

            // Filter rights list
            $filtered = [];
            foreach ($rights as $r) {
                $rid = (int)$r['id'];
                $isGranted = isset($grants[$rid]);

                if ($module !== '' && $module !== 'all' && ($r['module_slug'] ?? '') !== $module) continue;
                if ($q !== '' && stripos($r['slug'] ?? '', $q) === false && stripos($r['description'] ?? '', $q) === false) continue;
                if ($view === 'granted' && !$isGranted) continue;
                if ($view === 'unassigned' && $isGranted) continue;

                $filtered[] = $r;
            }

            // Group rights by module_slug
            $byModule = [];
            foreach ($filtered as $r) {
                $ms = $r['module_slug'] ?? 'core';
                if ($ms === '') $ms = 'core';
                $byModule[$ms][] = $r;
            }

            // Build module options
            $moduleOptions = ['all' => 'All modules'];
            foreach ($rights as $r) {
                $ms = $r['module_slug'] ?? 'core';
                if ($ms === '') $ms = 'core';
                $moduleOptions[$ms] = $ms;
            }
            ksort($moduleOptions);

            $h = "<h1>Rights</h1>
                  <p class='text-muted'>Scales for large installations: manage grants per group and filter by module/search. Administrator group always bypasses checks.</p>";

            $h .= "<div class='row g-3'>";

            // Left: groups + create
            $h .= "<div class='col-lg-4'>
                    <div class='card mb-3'><div class='card-body'>
                      <h5 class='card-title'>Create group</h5>
                      <form method='post' action='/admin/rights/group-create' class='row g-2 align-items-end'>
                        <div class='col-md-6'><label class='form-label'>Slug</label><input class='form-control' name='slug' required></div>
                        <div class='col-md-6'><label class='form-label'>Name</label><input class='form-control' name='name' required></div>
                        <div class='col-md-6'><label class='form-label'>Admin override</label>
                          <select class='form-select' name='is_admin'>
                            <option value='0'>No</option><option value='1'>Yes</option>
                          </select>
                        </div>
                        <div class='col-md-6'><button class='btn btn-primary w-100' type='submit'>Create</button></div>
                      </form>
                      <div class='small text-muted mt-2'>Naming guideline: <code>admin.*</code>, <code>esi.*</code>, <code>module.&lt;slug&gt;.*</code></div>
                    </div></div>";

            // Groups list
            $h .= "<div class='card'><div class='card-body'>
                    <h5 class='card-title'>Groups</h5>
                    <div class='list-group'>";
            foreach ($groups as $g) {
                $active = ($selGroup && (int)$g['id'] === (int)$selGroup['id']) ? " active" : "";
                $badge = ((int)$g['is_admin'] === 1) ? " <span class='badge bg-warning text-dark ms-2'>admin</span>" : "";
                $h .= "<a class='list-group-item list-group-item-action{$active}' href='/admin/rights?group=" . urlencode($g['slug']) . "'>"
                    . htmlspecialchars($g['name']) . " <span class='text-muted'>(" . htmlspecialchars($g['slug']) . ")</span>{$badge}</a>";
            }
            $h .= "</div>
                  </div></div>";

            // Danger zone: delete group (no IDs shown)
            if ($selGroup && (int)$selGroup['is_admin'] !== 1 && strtolower($selGroup['slug']) !== 'admin' && strtolower($selGroup['slug']) !== 'administrator') {
                $h .= "<div class='card mt-3'><div class='card-body'>
                        <h6 class='text-danger'>Danger zone</h6>
                        <form method='post' action='/admin/rights/group-delete' onsubmit=\"return confirm('Delete group " . htmlspecialchars($selGroup['name']) . " ? This removes all assignments.');\">
                          <input type='hidden' name='group_slug' value='" . htmlspecialchars($selGroup['slug']) . "'>
                          <button class='btn btn-sm btn-danger'>Delete group</button>
                        </form>
                       </div></div>";
            } else {
                $h .= "<div class='card mt-3'><div class='card-body'>
                        <h6 class='text-muted'>Danger zone</h6>
                        <div class='text-muted small'>Administrator group cannot be deleted.</div>
                       </div></div>";
            }

            $h .= "</div>"; // /left col

            // Right: grants + filters
            $h .= "<div class='col-lg-8'>
                    <div class='card mb-3'><div class='card-body'>
                      <h5 class='card-title'>Permission grants</h5>";

            if (!$selGroup) {
                $h .= "<div class='text-muted'>No groups found.</div>";
            } else {
                $h .= "<div class='text-muted mb-2'>Group: <strong>" . htmlspecialchars($selGroup['name']) . "</strong> <span class='text-muted'>(" . htmlspecialchars($selGroup['slug']) . ")</span></div>";

                // Filters form (GET)
                $h .= "<form method='get' action='/admin/rights' class='row g-2 align-items-end'>
                        <input type='hidden' name='group' value='" . htmlspecialchars($selGroup['slug']) . "'>
                        <div class='col-md-5'><label class='form-label'>Search</label>
                          <input class='form-control' name='q' value='" . htmlspecialchars($q) . "' placeholder='admin.users, module.killfeed...'>
                        </div>
                        <div class='col-md-4'><label class='form-label'>Module</label>
                          <select class='form-select' name='module'>";
                foreach ($moduleOptions as $k => $label) {
                    $sel = ($module === $k || ($module === '' && $k === 'all')) ? " selected" : "";
                    $h .= "<option value='" . htmlspecialchars((string)$k) . "'{$sel}>" . htmlspecialchars((string)$label) . "</option>";
                }
                $h .= "     </select>
                        </div>
                        <div class='col-md-3'><label class='form-label'>View</label>
                          <select class='form-select' name='view'>
                            <option value='all'" . ($view==='all'?' selected':'') . ">All</option>
                            <option value='granted'" . ($view==='granted'?' selected':'') . ">Granted</option>
                            <option value='unassigned'" . ($view==='unassigned'?' selected':'') . ">Unassigned</option>
                          </select>
                        </div>
                        <div class='col-12 d-flex gap-2'>
                          <button class='btn btn-outline-primary btn-sm' type='submit'>Apply filters</button>
                          <a class='btn btn-outline-secondary btn-sm' href='/admin/rights?group=" . urlencode($selGroup['slug']) . "'>Reset filters</a>
                        </div>
                      </form>";

                // Grants form (POST)
                $h .= "<form method='post' action='/admin/rights/group-save' class='mt-3'>
                        <input type='hidden' name='group_slug' value='" . htmlspecialchars($selGroup['slug']) . "'>";

                if (empty($byModule)) {
                    $h .= "<div class='text-muted mt-3'>No rights match the filters.</div>";
                } else {
                    foreach ($byModule as $ms => $list) {
                        $h .= "<details class='card mt-2' open>
                                <summary class='card-body d-flex justify-content-between align-items-center'>
                                  <div><strong>" . htmlspecialchars($ms) . "</strong> <span class='text-muted'>(" . count($list) . ")</span></div>
                                </summary>
                                <div class='card-body pt-0'>";
                        foreach ($list as $r) {
                            $rid = (int)$r['id'];
                            $checked = isset($grants[$rid]) ? " checked" : "";
                            $desc = trim((string)($r['description'] ?? ''));

                            // HTML element id only (not displayed)
                            $elId = 'r_' . preg_replace('/[^a-z0-9_]+/i', '_', (string)$r['slug']);

                            $h .= "<div class='form-check my-1'>
                                    <input class='form-check-input' type='checkbox' name='right_slugs[]' value='" . htmlspecialchars((string)$r['slug']) . "' id='{$elId}'{$checked}>
                                    <label class='form-check-label' for='{$elId}'><strong>" . htmlspecialchars((string)$r['slug']) . "</strong>"
                                    . ($desc !== '' ? "<div class='small text-muted'>" . htmlspecialchars($desc) . "</div>" : "")
                                    . "</label>
                                  </div>";
                        }
                        $h .= "    </div>
                              </details>";
                    }
                    $h .= "<div class='mt-3'><button class='btn btn-primary'>Save grants</button></div>";
                }

                $h .= "</form>";

                // Explain access (no IDs)
                $exChar = trim((string)($_GET['ex_char'] ?? ''));
                $exRight = trim((string)($_GET['ex_right'] ?? ''));
                $h .= "<div class='card mt-4'><div class='card-body'>
                        <h5 class='card-title'>Explain access</h5>
                        <p class='text-muted'>Troubleshoot why a character can/can't access a right (which group grants it, or whether an override applies). No IDs required.</p>
                        <form method='get' action='/admin/rights' class='row g-2 align-items-end'>
                          <input type='hidden' name='group' value='" . htmlspecialchars($selGroup['slug']) . "'>
                          <div class='col-md-6'><label class='form-label'>Character name</label><input class='form-control' name='ex_char' value='" . htmlspecialchars($exChar) . "' placeholder='e.g. Lellebel'></div>
                          <div class='col-md-6'><label class='form-label'>Right slug</label><input class='form-control' name='ex_right' value='" . htmlspecialchars($exRight) . "' placeholder='e.g. admin.users'></div>
                          <div class='col-12'><button class='btn btn-outline-primary btn-sm'>Explain</button></div>
                        </form>";

                if ($exChar !== '' && $exRight !== '') {
                    $user = $app->db->one("SELECT id, character_name, is_superadmin FROM eve_users WHERE character_name = ? LIMIT 1", [$exChar]);
                    if (!$user) {
                        $user = $app->db->one("SELECT id, character_name, is_superadmin FROM eve_users WHERE character_name LIKE ? ORDER BY character_name ASC LIMIT 1", ['%' . $exChar . '%']);
                    }
                    $right = $app->db->one("SELECT id, slug FROM rights WHERE slug = ? LIMIT 1", [$exRight]);

                    if (!$user) {
                        $h .= "<div class='alert alert-warning mt-3'>No user found for character name <strong>" . htmlspecialchars($exChar) . "</strong>.</div>";
                    } elseif (!$right) {
                        $h .= "<div class='alert alert-warning mt-3'>Right <strong>" . htmlspecialchars($exRight) . "</strong> not found.</div>";
                    } else {
                        $uid = (int)$user['id'];
                        $rid = (int)$right['id'];

                        $ug = $app->db->all(
                            "SELECT g.slug, g.name, g.is_admin
                             FROM eve_user_groups eug
                             JOIN groups g ON g.id = eug.group_id
                             WHERE eug.user_id = ?
                             ORDER BY g.is_admin DESC, g.name ASC",
                            [$uid]
                        );

                        $hasAdminGroup = false;
                        foreach ($ug as $g) {
                            if ((int)$g['is_admin'] === 1 || strtolower((string)$g['slug']) === 'admin' || strtolower((string)$g['slug']) === 'administrator') {
                                $hasAdminGroup = true;
                            }
                        }

                        $rows = $app->db->all(
                            "SELECT g.slug, g.name
                             FROM group_rights gr
                             JOIN groups g ON g.id = gr.group_id
                             WHERE gr.right_id = ?
                             ORDER BY g.name ASC",
                            [$rid]
                        );

                        $grantsFrom = [];
                        foreach ($rows as $g) $grantsFrom[] = $g['name'] . " (" . $g['slug'] . ")";

                        $decision = 'DENY';
                        $reason = 'No matching grant.';
                        if ((int)($user['is_superadmin'] ?? 0) === 1) { $decision = 'ALLOW'; $reason = 'Superadmin override.'; }
                        elseif ($hasAdminGroup) { $decision = 'ALLOW'; $reason = 'Administrator group override.'; }
                        else {
                            $ok = $app->db->one(
                                "SELECT 1
                                 FROM eve_user_groups eug
                                 JOIN group_rights gr ON gr.group_id = eug.group_id
                                 WHERE eug.user_id = ? AND gr.right_id = ?
                                 LIMIT 1",
                                [$uid, $rid]
                            );
                            if ($ok) { $decision = 'ALLOW'; $reason = 'Granted via group membership.'; }
                        }

                        $h .= "<div class='alert " . ($decision==='ALLOW'?'alert-success':'alert-danger') . " mt-3'>
                                <strong>Decision:</strong> {$decision} &nbsp; <span class='text-muted'>" . htmlspecialchars($reason) . "</span>
                               </div>";

                        $h .= "<div class='mt-2'><strong>Character:</strong> " . htmlspecialchars((string)$user['character_name']) . "</div>";
                        $h .= "<div class='mt-1'><strong>Right:</strong> " . htmlspecialchars((string)$right['slug']) . "</div>";

                        $h .= "<div class='mt-3'><strong>User groups</strong><ul class='mb-0'>";
                        foreach ($ug as $g) {
                            $h .= "<li>" . htmlspecialchars((string)$g['name']) . " <span class='text-muted'>(" . htmlspecialchars((string)$g['slug']) . ")</span>" . ((int)$g['is_admin']===1 ? " <span class='badge bg-warning text-dark'>admin</span>" : "") . "</li>";
                        }
                        if (empty($ug)) $h .= "<li class='text-muted'>None</li>";
                        $h .= "</ul></div>";

                        $h .= "<div class='mt-3'><strong>Groups that grant this right</strong><ul class='mb-0'>";
                        foreach ($grantsFrom as $s) $h .= "<li>" . htmlspecialchars($s) . "</li>";
                        if (empty($grantsFrom)) $h .= "<li class='text-muted'>None</li>";
                        $h .= "</ul></div>";
                    }
                }

                $h .= "</div></div>";
            }

            $h .= "</div></div></div>"; // /right col & row

            // IMPORTANT: render via Layout wrapper so styling/nav loads
            return $render('Rights', $h);
        }, ['right' => 'admin.rights']);

        $app->router->post('/admin/rights/group-create', function () use ($app): Response {
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $isAdmin = (int)($_POST['is_admin'] ?? 0) === 1 ? 1 : 0;

            if ($slug === '' || $name === '') return Response::redirect('/admin/rights');
            if (in_array($slug, ['administrator'], true)) $slug = 'admin-' . $slug;

            $app->db->run(
                "INSERT INTO groups (slug, name, is_admin) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), is_admin=VALUES(is_admin)",
                [$slug, $name, $isAdmin]
            );
            (new CoreRights($app->db))->bumpGlobalVersion();

            return Response::redirect('/admin/rights?group=' . urlencode($slug));
        }, ['right' => 'admin.rights']);

        // Save grants for selected group (by slugs, no IDs)
        $app->router->post('/admin/rights/group-save', function () use ($app): Response {
            $groupSlug = trim((string)($_POST['group_slug'] ?? ''));
            if ($groupSlug === '') return Response::redirect('/admin/rights');

            $group = $app->db->one("SELECT id, slug FROM groups WHERE slug=? LIMIT 1", [$groupSlug]);
            if (!$group) return Response::redirect('/admin/rights');

            $rightSlugs = $_POST['right_slugs'] ?? [];
            if (!is_array($rightSlugs)) $rightSlugs = [];

            $app->db->begin();
            try {
                $app->db->run("DELETE FROM group_rights WHERE group_id=?", [(int)$group['id']]);

                if (!empty($rightSlugs)) {
                    $placeholders = implode(',', array_fill(0, count($rightSlugs), '?'));
                    $ids = $app->db->all("SELECT id FROM rights WHERE slug IN ($placeholders)", array_values($rightSlugs));
                    foreach ($ids as $row) {
                        $app->db->run(
                            "INSERT IGNORE INTO group_rights (group_id, right_id) VALUES (?,?)",
                            [(int)$group['id'], (int)$row['id']]
                        );
                    }
                }

                $app->db->commit();
            } catch (\Throwable $e) {
                $app->db->rollback();
                throw $e;
            }
            (new CoreRights($app->db))->bumpGlobalVersion();

            return Response::redirect('/admin/rights?group=' . urlencode($groupSlug));
        }, ['right' => 'admin.rights']);

        // Delete group by slug (no IDs). Admin group cannot be deleted.
        $app->router->post('/admin/rights/group-delete', function () use ($app): Response {
            $slug = strtolower(trim((string)($_POST['group_slug'] ?? '')));
            if ($slug === '') return Response::redirect('/admin/rights');

            $group = $app->db->one("SELECT id, slug, name, is_admin FROM groups WHERE slug=? LIMIT 1", [$slug]);
            if (!$group) return Response::redirect('/admin/rights');

            if ((int)$group['is_admin'] === 1 || $slug === 'admin' || $slug === 'administrator') {
                return Response::redirect('/admin/rights?group=' . urlencode($slug));
            }

            $app->db->begin();
            try {
                $app->db->run("DELETE FROM group_rights WHERE group_id=?", [(int)$group['id']]);
                $app->db->run("DELETE FROM eve_user_groups WHERE group_id=?", [(int)$group['id']]);
                $app->db->run("DELETE FROM groups WHERE id=?", [(int)$group['id']]);
                $app->db->commit();
            } catch (\Throwable $e) {
                $app->db->rollback();
                throw $e;
            }
            (new CoreRights($app->db))->bumpGlobalVersion();

            return Response::redirect('/admin/rights');
        }, ['right' => 'admin.rights']);
    }
}
