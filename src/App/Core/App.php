<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

final class App
{
    public readonly array $config;
    public readonly Db $db;
    public readonly Migrator $migrator;
    public readonly Router $router;
    public readonly ModuleManager $modules;
    public readonly Menu $menu;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Db::fromConfig($config['db'] ?? []);
        $this->migrator = new Migrator($this->db);
        $this->router = new Router();
        $this->modules = new ModuleManager();
        $this->menu = new Menu($this->db);
    }

    public static function boot(): self
    {
        $cfg = \app_config();
        $app = new self($cfg);

        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        // Global route guard: deny-by-default for routes declaring a required right
        $app->router->setGuard(function (Request $req, array $meta) use ($rights): ?Response {
            if (!empty($meta['public'])) return null;

            $need = $meta['right'] ?? null;
            if ($need) {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if ($uid <= 0) return Response::redirect('/auth/login');
                $rights->requireRight($uid, (string)$need);
            }
            return null;
        });

        // Core menu defaults (idempotent)
        $app->menu->register(['slug'=>'home','title'=>'Dashboard','url'=>'/','sort_order'=>10,'area'=>'left']);
        $app->menu->register(['slug'=>'profile','title'=>'Profile','url'=>'/me','sort_order'=>20,'area'=>'left']);

        $app->menu->register(['slug'=>'admin.root','title'=>'Admin Home','url'=>'/admin','sort_order'=>10,'area'=>'admin_top','right_slug'=>'admin.access']);
        $app->menu->register(['slug'=>'admin.cache','title'=>'ESI Cache','url'=>'/admin/cache','sort_order'=>20,'area'=>'admin_top','right_slug'=>'admin.cache']);
        $app->menu->register(['slug'=>'admin.rights','title'=>'Rights','url'=>'/admin/rights','sort_order'=>25,'area'=>'admin_top','right_slug'=>'admin.rights']);
        $app->menu->register(['slug'=>'admin.users','title'=>'Users & Groups','url'=>'/admin/users','sort_order'=>30,'area'=>'admin_top','right_slug'=>'admin.users']);
        $app->menu->register(['slug'=>'admin.menu','title'=>'Menu Editor','url'=>'/admin/menu','sort_order'=>40,'area'=>'admin_top','right_slug'=>'admin.menu']);

        // Routes
        $app->router->get('/health', fn() => Response::text("OK\n", 200));

        $app->router->get('/', function () use ($app, $hasRight): Response {
            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);

            $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
            if ($loggedIn) {
                $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
            } else {
                $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
            }

            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) {
                $body = "<h1>Dashboard</h1>
                         <p>You are not logged in.</p>
                         <p><a href='/auth/login'>Login with EVE SSO</a></p>";
                return Response::html(Layout::page('Dashboard', $body, $leftTree, $adminTree, $userTree), 200);
            }

            $u = new Universe($app->db);
            $p = $u->characterProfile($cid);

            $char = htmlspecialchars($p['character']['name'] ?? 'Unknown');
            $corp = htmlspecialchars($p['corporation']['name'] ?? '—');
            $corpT = htmlspecialchars($p['corporation']['ticker'] ?? '');
            $all  = htmlspecialchars($p['alliance']['name'] ?? '—');
            $allT = htmlspecialchars($p['alliance']['ticker'] ?? '');

            $body = "<h1>Dashboard</h1>
                     <p>Welcome back, <strong>{$char}</strong>.</p>
                     <p>Corporation: <strong>{$corp}</strong>" . ($corpT !== '' ? " [{$corpT}]" : "") . "</p>
                     <p>Alliance: <strong>{$all}</strong>" . ($allT !== '' ? " [{$allT}]" : "") . "</p>";

            return Response::html(Layout::page('Dashboard', $body, $leftTree, $adminTree, $userTree), 200);
        });

        // User alts placeholder
        $app->router->get('/user/alts', function () use ($app, $hasRight): Response {
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) return Response::redirect('/auth/login');

            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

            $body = "<h1>Linked Characters</h1>
                     <p>This will allow linking multiple EVE characters (alts) to one account.</p>";

            return Response::html(Layout::page('Linked Characters', $body, $leftTree, $adminTree, $userTree), 200);
        });

        // Admin
        $render = function (string $title, string $bodyHtml) use ($app, $hasRight): Response {
            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
            return Response::html(Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree), 200);
        };

        $app->router->get('/admin', function () use ($render): Response {
            $body = "<h1>Admin</h1>
                     <p class='text-muted'>Control plane for platform configuration and governance.</p>
                     <ul>
                       <li><a href='/admin/rights'>Rights</a> – groups & permission grants</li>
                       <li><a href='/admin/users'>Users</a> – assign groups to users</li>
                       <li><a href='/admin/menu'>Menu Editor</a></li>
                       <li><a href='/admin/cache'>ESI Cache</a></li>
                     </ul>";
            return $render('Admin', $body);
        }, ['right' => 'admin.access']);

        $app->router->get('/admin/cache', fn() => Response::text("Cache console (next)\n", 200), ['right' => 'admin.cache']);

        // Rights (Groups + Permissions)
        $app->router->get('/admin/rights', function () use ($app, $render): Response {
            $groups = $app->db->all("SELECT id, slug, name, is_admin FROM groups ORDER BY is_admin DESC, name ASC");
            $rights = $app->db->all("SELECT id, slug, description, module_slug FROM rights ORDER BY module_slug ASC, slug ASC");

            $map = [];
            foreach ($app->db->all("SELECT group_id, right_id FROM group_rights") as $r) {
                $map[(int)$r['group_id']][(int)$r['right_id']] = true;
            }

            $h = "<h1>Rights</h1>
                  <p class='text-muted'>Manage groups and assign permission grants. Admin group always bypasses checks.</p>";

            // Create group
            $h .= "<div class='card mb-3'><div class='card-body'>
                    <h5 class='card-title'>Create group</h5>
                    <form method='post' action='/admin/rights/group-create' class='row g-2 align-items-end'>
                      <div class='col-md-3'><label class='form-label'>Slug</label><input class='form-control' name='slug' required></div>
                      <div class='col-md-4'><label class='form-label'>Name</label><input class='form-control' name='name' required></div>
                      <div class='col-md-3'><label class='form-label'>Admin override</label>
                        <select class='form-select' name='is_admin'><option value='0'>No</option><option value='1'>Yes</option></select>
                      </div>
                      <div class='col-md-2'><button class='btn btn-primary w-100' type='submit'>Create</button></div>
                    </form>
                  </div></div>";

            // Matrix
            $h .= "<div class='table-responsive'><table class='table table-sm table-striped align-middle'>";
            $h .= "<thead><tr><th style='min-width:220px'>Group</th><th style='min-width:140px'>Flags</th><th>Grants</th></tr></thead><tbody>";

            foreach ($groups as $g) {
                $gid = (int)$g['id'];
                $flags = ((int)$g['is_admin'] === 1) ? "<span class='badge text-bg-warning'>admin override</span>" : "<span class='badge text-bg-secondary'>standard</span>";

                $h .= "<tr><td><strong>" . htmlspecialchars($g['name']) . "</strong><div class='text-muted small'>" . htmlspecialchars($g['slug']) . "</div></td>";
                $delete = '';
                if ((int)$g['is_admin'] !== 1) {
                    $delete = "<form method='post' action='/admin/rights/group-delete' class='d-inline ms-2' onsubmit=\"return confirm('Delete this group? This also removes its grants and user assignments.');\">
                                 <input type='hidden' name='group_id' value='{$gid}'>
                                 <button class='btn btn-sm btn-outline-danger' type='submit'>Delete</button>
                               </form>";
                }
                $h .= "<td>{$flags}{$delete}</td>";
                $h .= "<td>
                          <form method='post' action='/admin/rights/group-save'>
                            <input type='hidden' name='group_id' value='{$gid}'>
                            <div class='row g-2'>";

                foreach ($rights as $r) {
                    $rid = (int)$r['id'];
                    $checked = !empty($map[$gid][$rid]) ? "checked" : "";
                    $label = htmlspecialchars($r['slug']);
                    $desc  = htmlspecialchars($r['description']);
                    $h .= "<div class='col-12 col-md-6 col-xl-4'>
                              <div class='form-check'>
                                <input class='form-check-input' type='checkbox' name='right_ids[]' value='{$rid}' id='g{$gid}r{$rid}' {$checked}>
                                <label class='form-check-label' for='g{$gid}r{$rid}'>
                                  <span class='fw-semibold'>{$label}</span>
                                  <div class='text-muted small'>{$desc}</div>
                                </label>
                              </div>
                            </div>";
                }

                $h .= "            </div>
                            <div class='mt-2'>
                              <button class='btn btn-sm btn-success' type='submit'>Save grants</button>
                            </div>
                          </form>
                        </td></tr>";
            }

            $h .= "</tbody></table></div>";

            return $render('Rights', $h);
        }, ['right' => 'admin.rights']);

        $app->router->post('/admin/rights/group-create', function (Request $req) use ($app): Response {
            $slug = trim((string)($req->post['slug'] ?? ''));
            $name = trim((string)($req->post['name'] ?? ''));
            $isAdmin = (int)($req->post['is_admin'] ?? 0);
            if ($slug === '' || $name === '') return Response::redirect('/admin/rights');

            $app->db->run(
                "INSERT INTO groups (slug, name, is_admin) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), is_admin=VALUES(is_admin)",
                [$slug, $name, $isAdmin ? 1 : 0]
            );
            return Response::redirect('/admin/rights');
        }, ['right' => 'admin.rights']);

        $app->router->post('/admin/rights/group-save', function (Request $req) use ($app): Response {
            $gid = (int)($req->post['group_id'] ?? 0);
            if ($gid <= 0) return Response::redirect('/admin/rights');
            $ids = $req->post['right_ids'] ?? [];
            if (!is_array($ids)) $ids = [];

            $app->db->run("DELETE FROM group_rights WHERE group_id=?", [$gid]);
            foreach ($ids as $rid) {
                $rid = (int)$rid;
                if ($rid <= 0) continue;
                $app->db->run("INSERT IGNORE INTO group_rights (group_id, right_id) VALUES (?, ?)", [$gid, $rid]);
            }
            return Response::redirect('/admin/rights');
        }, ['right' => 'admin.rights']);

        // Users: assign groups
        
        $app->router->post('/admin/rights/group-delete', function (Request $req) use ($app): Response {
            // Requires admin.rights (route guard)
            $gid = (int)($req->post['group_id'] ?? 0);
            if ($gid <= 0) return Response::redirect('/admin/rights');

            $app->db->begin();
            try {
                $g = $app->db->one("SELECT id, slug, name, is_admin FROM groups WHERE id=? LIMIT 1", [$gid]);
                if (!$g) { $app->db->rollback(); return Response::redirect('/admin/rights'); }

                $isAdmin = (int)($g['is_admin'] ?? 0) === 1;
                $slug = strtolower((string)($g['slug'] ?? ''));
                $name = strtolower((string)($g['name'] ?? ''));

                if ($isAdmin || $slug === 'admin' || $slug === 'administrator' || $name === 'administrator') {
                    $app->db->rollback();
                    return Response::redirect('/admin/rights');
                }

                // FKs already cascade, but keep explicit cleanup for clarity.
                $app->db->run("DELETE FROM group_rights WHERE group_id=?", [$gid]);
                $app->db->run("DELETE FROM eve_user_groups WHERE group_id=?", [$gid]);
                $app->db->run("DELETE FROM groups WHERE id=?", [$gid]);

                $app->db->commit();
            } catch (\Throwable $e) {
                if ($app->db->inTx()) $app->db->rollback();
                throw $e;
            }

            return Response::redirect('/admin/rights');
        }, ['right' => 'admin.rights']);

$app->router->get('/admin/users', function () use ($app, $render): Response {
            $users = $app->db->all("SELECT id, character_id, character_name, is_superadmin, created_at FROM eve_users ORDER BY id DESC LIMIT 200");
            $groups = $app->db->all("SELECT id, slug, name, is_admin FROM groups ORDER BY is_admin DESC, name ASC");
            $ug = [];
            foreach ($app->db->all("SELECT user_id, group_id FROM eve_user_groups") as $r) {
                $ug[(int)$r['user_id']][(int)$r['group_id']] = true;
            }

            $h = "<h1>Users</h1>
                  <p class='text-muted'>Assign groups to users. Admin group and superadmin flag always override.</p>";
            $h .= "<div class='table-responsive'><table class='table table-sm table-striped align-middle'>
                    <thead><tr>
                      <th>User</th><th>Flags</th><th>Groups</th><th>Action</th>
                    </tr></thead><tbody>";

            foreach ($users as $u) {
                $uid = (int)$u['id'];
                $flags = [];
                if ((int)$u['is_superadmin'] === 1) $flags[] = "<span class='badge text-bg-danger'>superadmin</span>";
                $flagsHtml = $flags ? implode(' ', $flags) : "<span class='badge text-bg-secondary'>standard</span>";

                $h .= "<tr><td><strong>" . htmlspecialchars($u['character_name']) . "</strong>
                           <div class='text-muted small'>user_id={$uid} • character_id=" . (int)$u['character_id'] . "</div>
                        </td>
                        <td>{$flagsHtml}</td>
                        <td>
                          <form method='post' action='/admin/users/save' class='row g-2'>
                            <input type='hidden' name='user_id' value='{$uid}'>";

                foreach ($groups as $g) {
                    $gid = (int)$g['id'];
                    $checked = !empty($ug[$uid][$gid]) ? "checked" : "";
                    $label = htmlspecialchars($g['name']);
                    $h .= "<div class='col-12 col-md-6 col-xl-4'>
                              <div class='form-check'>
                                <input class='form-check-input' type='checkbox' name='group_ids[]' value='{$gid}' id='u{$uid}g{$gid}' {$checked}>
                                <label class='form-check-label' for='u{$uid}g{$gid}'>{$label}</label>
                              </div>
                            </div>";
                }

                $h .= "</td>
                        <td><button class='btn btn-sm btn-success' type='submit'>Save</button></td>
                          </form>
                      </tr>";
            }

            $h .= "</tbody></table></div>";
            return $render('Users', $h);
        }, ['right' => 'admin.users']);

        $app->router->post('/admin/users/save', function (Request $req) use ($app): Response {
            $uid = (int)($req->post['user_id'] ?? 0);
            if ($uid <= 0) return Response::redirect('/admin/users');
            $ids = $req->post['group_ids'] ?? [];
            if (!is_array($ids)) $ids = [];

            $app->db->run("DELETE FROM eve_user_groups WHERE user_id=?", [$uid]);
            foreach ($ids as $gid) {
                $gid = (int)$gid;
                if ($gid <= 0) continue;
                $app->db->run("INSERT IGNORE INTO eve_user_groups (user_id, group_id) VALUES (?, ?)", [$uid, $gid]);
            }
            return Response::redirect('/admin/users');
        }, ['right' => 'admin.users']);

        $app->router->get('/admin/menu', fn() => Response::text("Menu editor (next)\n", 200), ['right' => 'admin.menu']);

        $app->modules->loadAll($app);
        return $app;
    }

    public function handleHttp(): Response
    {
        $req = Request::fromGlobals();
        return $this->router->dispatch($req);
    }
}
