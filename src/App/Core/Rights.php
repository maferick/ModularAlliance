<?php
declare(strict_types=1);

namespace App\Core;

final class Rights
{
    public function __construct(private readonly Db $db) {}

    public function userHasRight(int $userId, string $right): bool
    {
        // Admin hard override (never lock out)
        $admin = $this->db->one(
            "SELECT 1
             FROM eve_user_groups ug
             JOIN groups g ON g.id = ug.group_id
             WHERE ug.user_id=? AND g.slug='admin'
             LIMIT 1",
            [$userId]
        );
        if ($admin) return true;

        return (bool)$this->db->one(
            "SELECT 1
             FROM eve_user_groups ug
             JOIN group_rights gr ON gr.group_id = ug.group_id
             JOIN rights r ON r.id = gr.right_id
             WHERE ug.user_id=? AND r.slug=?
             LIMIT 1",
            [$userId, $right]
        );
    }
}
