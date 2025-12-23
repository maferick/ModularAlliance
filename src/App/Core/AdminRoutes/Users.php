<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Http\Request;
use App\Http\Response;

final class Users
{
    public static function register(App $app, ModuleRegistry $registry, callable $render): void
    {
        // Users: assign groups
        $registry->route('GET', '/admin/users', function () use ($app, $render): Response {
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

        $registry->route('POST', '/admin/users/save', function (Request $req) use ($app): Response {
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
            (new Rights($app->db))->bumpGlobalVersion();
            return Response::redirect('/admin/users');
        }, ['right' => 'admin.users']);
    }
}
