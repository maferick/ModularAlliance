<?php
declare(strict_types=1);

namespace App\Core;

final class CoreRights
{
    public static function register(Db $db): void
    {
        $rights = [
            ['admin.access', 'Access admin dashboard'],
            ['admin.settings', 'Manage site settings'],
            ['admin.cache', 'Manage cache'],
            ['admin.rights', 'Manage rights & groups'],
            ['admin.users', 'Manage users & groups'],
            ['admin.menu', 'Edit menu overrides'],
        ];

        foreach ($rights as [$slug, $desc]) {
            $db->run(
                "INSERT INTO rights (slug, description, module_slug) VALUES (?, ?, 'core')
                 ON DUPLICATE KEY UPDATE description=VALUES(description), module_slug=VALUES(module_slug)",
                [$slug, $desc]
            );
        }
    }
}
