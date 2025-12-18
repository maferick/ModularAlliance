<?php
declare(strict_types=1);

namespace App\Core;

final class Rights
{
    public function __construct(private readonly Db $db) {}

    /**
     * Lockout-proof rights check.
     * - eve_users.is_superadmin => allow
     * - group slug 'admin' => allow
     * - otherwise resolve via group_rights
     */

    public function userHasRight(int $userId, string $right): bool
    {
        // Superadmin hard override
        $su = $this->db->one("SELECT 1 FROM eve_users WHERE id=? AND is_superadmin=1 LIMIT 1", [$userId]);
        if ($su) return true;

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

    public function requireRight(int $userId, string $right): void
    {
        if ($userId <= 0 || !$this->userHasRight($userId, $right)) {
            http_response_code(403);
            echo "403 Forbidden";
            exit;
        }
    }
}
