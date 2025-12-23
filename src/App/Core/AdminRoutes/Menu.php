<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\ModuleRegistry;
use App\Http\Request;
use App\Http\Response;

final class Menu
{
    public static function register(App $app, ModuleRegistry $registry, callable $render): void
    {
        $registry->route('GET', '/admin/menu', function () use ($app, $render): Response {
            $menuRows = $app->db->all(
                "SELECT r.slug,
                        r.title AS r_title,
                        r.url AS r_url,
                        r.parent_slug AS r_parent_slug,
                        r.sort_order AS r_sort_order,
                        r.area AS r_area,
                        r.right_slug AS r_right_slug,
                        r.enabled AS r_enabled,
                        o.title AS o_title,
                        o.url AS o_url,
                        o.parent_slug AS o_parent_slug,
                        o.sort_order AS o_sort_order,
                        o.area AS o_area,
                        o.right_slug AS o_right_slug,
                        o.enabled AS o_enabled
                 FROM menu_registry r
                 LEFT JOIN menu_overrides o ON o.slug = r.slug
                 ORDER BY COALESCE(o.area, r.area) ASC,
                          COALESCE(o.sort_order, r.sort_order) ASC,
                          r.slug ASC"
            );

            $rights = $app->db->all("SELECT slug FROM rights ORDER BY slug ASC");
            $rightOptions = array_map(fn($r) => (string)$r['slug'], $rights);

            $allSlugs = array_map(fn($r) => (string)$r['slug'], $menuRows);
            sort($allSlugs);

            $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
            $msgHtml = $msg !== '' ? "<div class='alert alert-info'>" . htmlspecialchars($msg) . "</div>" : "";

            $h = $msgHtml . "<h1>Menu Editor</h1>
                  <p class='text-muted'>Adjust menu overrides without changing module defaults. Leave fields blank to revert to defaults.</p>";

            $h .= "<div class='table-responsive'><table class='table table-sm table-striped align-middle'>
                    <thead>
                      <tr>
                        <th>Slug</th>
                        <th>Effective</th>
                        <th>Override</th>
                      </tr>
                    </thead><tbody>";

            foreach ($menuRows as $row) {
                $slug = (string)$row['slug'];
                $effectiveTitle = $row['o_title'] ?? $row['r_title'];
                $effectiveUrl = $row['o_url'] ?? $row['r_url'];
                $effectiveParent = $row['o_parent_slug'] ?? $row['r_parent_slug'];
                $effectiveSort = $row['o_sort_order'] ?? $row['r_sort_order'];
                $effectiveArea = $row['o_area'] ?? $row['r_area'];
                $effectiveRight = $row['o_right_slug'] ?? $row['r_right_slug'];
                $effectiveEnabled = $row['o_enabled'] ?? $row['r_enabled'];

                $hasOverride = $row['o_title'] !== null
                    || $row['o_url'] !== null
                    || $row['o_parent_slug'] !== null
                    || $row['o_sort_order'] !== null
                    || $row['o_area'] !== null
                    || $row['o_right_slug'] !== null
                    || $row['o_enabled'] !== null;

                $overrideBadge = $hasOverride ? "<span class='badge text-bg-warning ms-2'>override</span>" : "";

                $defaultBits = [
                    "Title: " . htmlspecialchars((string)$row['r_title']),
                    "URL: " . htmlspecialchars((string)$row['r_url']),
                    "Area: " . htmlspecialchars((string)$row['r_area']),
                    "Sort: " . (int)$row['r_sort_order'],
                    "Right: " . htmlspecialchars((string)($row['r_right_slug'] ?? '—')),
                    "Parent: " . htmlspecialchars((string)($row['r_parent_slug'] ?? '—')),
                    "Enabled: " . ((int)$row['r_enabled'] === 1 ? 'yes' : 'no'),
                ];

                $effectiveBits = [
                    "Title: " . htmlspecialchars((string)$effectiveTitle),
                    "URL: " . htmlspecialchars((string)$effectiveUrl),
                    "Area: " . htmlspecialchars((string)$effectiveArea),
                    "Sort: " . (int)$effectiveSort,
                    "Right: " . htmlspecialchars((string)($effectiveRight ?? '—')),
                    "Parent: " . htmlspecialchars((string)($effectiveParent ?? '—')),
                    "Enabled: " . ((int)$effectiveEnabled === 1 ? 'yes' : 'no'),
                ];

                $titleVal = htmlspecialchars((string)($row['o_title'] ?? ''));
                $urlVal = htmlspecialchars((string)($row['o_url'] ?? ''));
                $parentVal = htmlspecialchars((string)($row['o_parent_slug'] ?? ''));
                $sortVal = htmlspecialchars((string)($row['o_sort_order'] ?? ''));
                $rightVal = htmlspecialchars((string)($row['o_right_slug'] ?? ''));
                $areaVal = (string)($row['o_area'] ?? '');
                $enabledVal = ($row['o_enabled'] === null) ? '' : (string)$row['o_enabled'];

                $h .= "<tr>
                        <td><strong>" . htmlspecialchars($slug) . "</strong>{$overrideBadge}
                          <div class='small text-muted'>Default: " . implode(" • ", $defaultBits) . "</div>
                        </td>
                        <td>
                          <div class='small'>" . implode(" • ", $effectiveBits) . "</div>
                        </td>
                        <td>
                          <form method='post' action='/admin/menu/save' class='row g-2'>
                            <input type='hidden' name='slug' value='" . htmlspecialchars($slug) . "'>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>Title</label>
                              <input class='form-control form-control-sm' name='title' value='{$titleVal}' placeholder='" . htmlspecialchars((string)$row['r_title']) . "'>
                            </div>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>URL</label>
                              <input class='form-control form-control-sm' name='url' value='{$urlVal}' placeholder='" . htmlspecialchars((string)$row['r_url']) . "'>
                            </div>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>Parent slug</label>
                              <input class='form-control form-control-sm' name='parent_slug' value='{$parentVal}' list='menu-parent-slugs' placeholder='" . htmlspecialchars((string)($row['r_parent_slug'] ?? '')) . "'>
                            </div>
                            <div class='col-6 col-md-3'>
                              <label class='form-label'>Sort</label>
                              <input class='form-control form-control-sm' type='number' name='sort_order' value='{$sortVal}' placeholder='" . (int)$row['r_sort_order'] . "'>
                            </div>
                            <div class='col-6 col-md-3'>
                              <label class='form-label'>Area</label>
                              <select class='form-select form-select-sm' name='area'>
                                <option value='' " . ($areaVal === '' ? 'selected' : '') . ">Default (" . htmlspecialchars((string)$row['r_area']) . ")</option>
                                <option value='left' " . ($areaVal === 'left' ? 'selected' : '') . ">left</option>
                                <option value='admin_top' " . ($areaVal === 'admin_top' ? 'selected' : '') . ">admin_top</option>
                                <option value='user_top' " . ($areaVal === 'user_top' ? 'selected' : '') . ">user_top</option>
                              </select>
                            </div>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>Right slug</label>
                              <input class='form-control form-control-sm' name='right_slug' value='{$rightVal}' list='right-slugs' placeholder='" . htmlspecialchars((string)($row['r_right_slug'] ?? '')) . "'>
                            </div>
                            <div class='col-6 col-md-3'>
                              <label class='form-label'>Enabled</label>
                              <select class='form-select form-select-sm' name='enabled'>
                                <option value='' " . ($enabledVal === '' ? 'selected' : '') . ">Default (" . ((int)$row['r_enabled'] === 1 ? 'yes' : 'no') . ")</option>
                                <option value='1' " . ($enabledVal === '1' ? 'selected' : '') . ">Enabled</option>
                                <option value='0' " . ($enabledVal === '0' ? 'selected' : '') . ">Disabled</option>
                              </select>
                            </div>
                            <div class='col-6 col-md-3 d-flex align-items-end gap-2'>
                              <button class='btn btn-sm btn-success' type='submit'>Save</button>
                              <button class='btn btn-sm btn-outline-secondary' type='submit' name='action' value='reset'>Reset</button>
                            </div>
                          </form>
                        </td>
                      </tr>";
            }

            $h .= "</tbody></table></div>";

            if (!empty($allSlugs)) {
                $h .= "<datalist id='menu-parent-slugs'>";
                foreach ($allSlugs as $slug) {
                    $h .= "<option value='" . htmlspecialchars($slug) . "'>";
                }
                $h .= "</datalist>";
            }

            if (!empty($rightOptions)) {
                $h .= "<datalist id='right-slugs'>";
                foreach ($rightOptions as $slug) {
                    $h .= "<option value='" . htmlspecialchars($slug) . "'>";
                }
                $h .= "</datalist>";
            }

            return $render('Menu Editor', $h);
        }, ['right' => 'admin.menu']);

        $registry->route('POST', '/admin/menu/save', function (Request $req) use ($app): Response {
            $slug = trim((string)($req->post['slug'] ?? ''));
            if ($slug === '') return Response::redirect('/admin/menu?msg=' . rawurlencode('Missing menu slug.'));

            $action = trim((string)($req->post['action'] ?? ''));
            if ($action === 'reset') {
                $app->db->run("DELETE FROM menu_overrides WHERE slug=?", [$slug]);
                return Response::redirect('/admin/menu?msg=' . rawurlencode("Overrides reset for {$slug}."));
            }

            $title = trim((string)($req->post['title'] ?? ''));
            $url = trim((string)($req->post['url'] ?? ''));
            $parent = trim((string)($req->post['parent_slug'] ?? ''));
            $sortRaw = trim((string)($req->post['sort_order'] ?? ''));
            $area = trim((string)($req->post['area'] ?? ''));
            $right = trim((string)($req->post['right_slug'] ?? ''));
            $enabledRaw = trim((string)($req->post['enabled'] ?? ''));

            $title = $title === '' ? null : $title;
            $url = $url === '' ? null : $url;
            $parent = $parent === '' ? null : $parent;
            $right = $right === '' ? null : $right;

            $sort = null;
            if ($sortRaw !== '' && is_numeric($sortRaw)) {
                $sort = (int)$sortRaw;
            }

            $area = $area === '' ? null : $area;
            $allowedAreas = ['left', 'admin_top', 'user_top'];
            if ($area !== null && !in_array($area, $allowedAreas, true)) {
                $area = null;
            }

            $enabled = null;
            if ($enabledRaw === '0' || $enabledRaw === '1') {
                $enabled = (int)$enabledRaw;
            }

            if ($title === null && $url === null && $parent === null && $sort === null && $area === null && $right === null && $enabled === null) {
                $app->db->run("DELETE FROM menu_overrides WHERE slug=?", [$slug]);
                return Response::redirect('/admin/menu?msg=' . rawurlencode("Overrides cleared for {$slug}."));
            }

            $app->db->run(
                "INSERT INTO menu_overrides (slug, title, url, parent_slug, sort_order, area, right_slug, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   title=VALUES(title),
                   url=VALUES(url),
                   parent_slug=VALUES(parent_slug),
                   sort_order=VALUES(sort_order),
                   area=VALUES(area),
                   right_slug=VALUES(right_slug),
                   enabled=VALUES(enabled)",
                [$slug, $title, $url, $parent, $sort, $area, $right, $enabled]
            );

            return Response::redirect('/admin/menu?msg=' . rawurlencode("Overrides saved for {$slug}."));
        }, ['right' => 'admin.menu']);
    }
}
