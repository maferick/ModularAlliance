<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Http\Response;

final class Home
{
    public static function register(App $app, callable $render): void
    {
        $app->router->get('/admin', function () use ($render): Response {
            $body = "<h1>Admin</h1>
                     <p class='text-muted'>Control plane for platform configuration and governance.</p>
                     <ul>
                       <li><a href='/admin/settings'>Settings</a> – site identity & branding</li>
                       <li><a href='/admin/rights'>Rights</a> – groups & permission grants</li>
                       <li><a href='/admin/users'>Users</a> – assign groups to users</li>
                       <li><a href='/admin/menu'>Menu Editor</a></li>
                       <li><a href='/admin/plugins'>Plugins</a> – manage uploaded plugins</li>
                       <li><a href='/admin/cache'>ESI Cache</a></li>
                     </ul>";
            return $render('Admin', $body);
        }, ['right' => 'admin.access']);
    }
}
