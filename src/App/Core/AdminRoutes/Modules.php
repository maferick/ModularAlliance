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
            usort($mods, fn(array $a, array $b) => strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? '')));

            $rows = '';
            foreach ($mods as $m) {
                $slug = htmlspecialchars((string)($m['slug'] ?? ''));
                $name = htmlspecialchars((string)($m['name'] ?? $slug));
                $desc = htmlspecialchars((string)($m['description'] ?? ''));
                $version = htmlspecialchars((string)($m['version'] ?? ''));
                $rights = is_array($m['rights'] ?? null) ? (string)count($m['rights']) : '0';
                $routes = is_array($m['routes'] ?? null) ? (string)count($m['routes']) : '0';
                $menu = is_array($m['menu'] ?? null) ? (string)count($m['menu']) : '0';

                $rows .= "<tr>
                            <td>{$name}</td>
                            <td><code>{$slug}</code></td>
                            <td>{$version}</td>
                            <td>{$desc}</td>
                            <td class='text-end'>{$rights}</td>
                            <td class='text-end'>{$routes}</td>
                            <td class='text-end'>{$menu}</td>
                          </tr>";
            }

            if ($rows === '') {
                $rows = "<tr><td colspan='7' class='text-muted'>No modules found.</td></tr>";
            }

            $body = "<h1>Modules</h1>
                     <p class='text-muted'>Overview of loaded modules and their declared capabilities.</p>
                     <div class='table-responsive'>
                       <table class='table table-sm align-middle'>
                         <thead>
                           <tr>
                             <th>Module</th>
                             <th>Slug</th>
                             <th>Version</th>
                             <th>Description</th>
                             <th class='text-end'>Rights</th>
                             <th class='text-end'>Routes</th>
                             <th class='text-end'>Menu</th>
                           </tr>
                         </thead>
                         <tbody>{$rows}</tbody>
                       </table>
                     </div>";

            return $render('Modules', $body);
        }, ['right' => 'admin.modules']);
    }
}
