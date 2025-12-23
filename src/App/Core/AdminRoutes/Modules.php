<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Http\Response;

final class Modules
{
    public static function register(App $app, callable $render): void
    {
        $app->router->get('/admin/modules', function () use ($app, $render): Response {
            $mods = $app->modules->getManifests();
            usort($mods, fn(array $a, array $b) => strcmp((string)($a['name'] ?? $a['slug'] ?? ''), (string)($b['name'] ?? $b['slug'] ?? '')));

            $rows = '';
            foreach ($mods as $m) {
                $slug = htmlspecialchars((string)($m['slug'] ?? ''));
                $name = htmlspecialchars((string)($m['name'] ?? $slug));
                $desc = htmlspecialchars((string)($m['description'] ?? ''));
                $version = htmlspecialchars((string)($m['version'] ?? ''));
                $rightsRaw = is_array($m['rights'] ?? null) ? $m['rights'] : [];
                $routesRaw = is_array($m['routes'] ?? null) ? $m['routes'] : [];
                $menuRaw = is_array($m['menu'] ?? null) ? $m['menu'] : [];
                $rightsLabels = [];
                foreach ($rightsRaw as $r) {
                    $label = (string)($r['description'] ?? $r['slug'] ?? '');
                    if ($label !== '') $rightsLabels[] = $label;
                }
                $rights = $rightsLabels !== [] ? htmlspecialchars(implode(', ', $rightsLabels)) : '—';

                $routeLabels = [];
                foreach ($routesRaw as $r) {
                    $method = strtoupper((string)($r['method'] ?? ''));
                    $path = (string)($r['path'] ?? '');
                    $right = (string)($r['right'] ?? '');
                    $label = trim($method . ' ' . $path);
                    if ($right !== '') $label .= " ({$right})";
                    if ($label !== '') $routeLabels[] = $label;
                }
                $routes = $routeLabels !== [] ? htmlspecialchars(implode(', ', $routeLabels)) : '—';

                $menuLabels = [];
                foreach ($menuRaw as $r) {
                    $label = (string)($r['title'] ?? $r['slug'] ?? $r['url'] ?? '');
                    if ($label !== '') $menuLabels[] = $label;
                }
                $menu = $menuLabels !== [] ? htmlspecialchars(implode(', ', $menuLabels)) : '—';
                $searchBlob = strtolower(trim(implode(' ', array_filter([
                    (string)($m['name'] ?? ''),
                    (string)($m['slug'] ?? ''),
                    (string)($m['description'] ?? ''),
                    (string)($m['version'] ?? ''),
                    implode(' ', $rightsLabels),
                    implode(' ', $routeLabels),
                    implode(' ', $menuLabels),
                ]))));
                $searchAttr = htmlspecialchars($searchBlob);

                $rows .= "<tr data-search=\"{$searchAttr}\">
                            <td>{$name}<div class='text-muted small'><code>{$slug}</code></div></td>
                            <td>{$version}</td>
                            <td>{$desc}</td>
                            <td>{$rights}</td>
                            <td>{$routes}</td>
                            <td>{$menu}</td>
                          </tr>";
            }

            if ($rows === '') {
                $rows = "<tr><td colspan='6' class='text-muted'>No modules found.</td></tr>";
            }

            $body = "<h1>Modules</h1>
                     <p class='text-muted'>Overview of loaded modules and their declared capabilities.</p>
                     <div class='row g-2 align-items-center mb-3'>
                       <div class='col-md-6'>
                         <label class='form-label' for='module-search'>Search modules</label>
                         <input class='form-control' id='module-search' type='search' placeholder='Search by name, key, route, or right'>
                       </div>
                     </div>
                     <div class='table-responsive'>
                       <table class='table table-sm align-middle'>
                         <thead>
                           <tr>
                             <th>Module</th>
                             <th>Version</th>
                             <th>Description</th>
                             <th>Rights</th>
                             <th>Routes</th>
                             <th>Menu</th>
                           </tr>
                         </thead>
                         <tbody id='modules-table'>{$rows}</tbody>
                       </table>
                     </div>";

            $body .= "<script>
                        (function () {
                          const input = document.getElementById('module-search');
                          const rows = Array.from(document.querySelectorAll('#modules-table tr[data-search]'));
                          const noRows = document.querySelector('#modules-table tr:not([data-search])');
                          if (!input) return;
                          const update = () => {
                            const q = input.value.trim().toLowerCase();
                            let visible = 0;
                            rows.forEach((row) => {
                              const hay = row.getAttribute('data-search') || '';
                              const match = q === '' || hay.includes(q);
                              row.style.display = match ? '' : 'none';
                              if (match) visible += 1;
                            });
                            if (noRows) {
                              noRows.style.display = visible === 0 ? '' : 'none';
                            }
                          };
                          input.addEventListener('input', update);
                        })();
                      </script>";

            return $render('Modules', $body);
        }, ['right' => 'admin.modules']);
    }
}
