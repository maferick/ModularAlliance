<?php
declare(strict_types=1);

namespace App\Core;

final class Rights
{
    // Cache policy (session-based)
    private const CACHE_TTL_SECONDS = 600; // 10 minutes

    public function __construct(private readonly Db $db) {}

    /**
     * Lockout-proof rights check.
     * - eve_users.is_superadmin => allow
     * - group slug 'admin' => allow
     * - otherwise resolve via group_rights
     *
     * Scales via:
     * - user-effective-rights cached in session (TTL)
     * - global authz_state.version invalidation bump on changes
     */
    public function userHasRight(int $userId, string $right): bool
    {
        if ($userId <= 0 || $right === '') return false;

        // Hard override: superadmin
        $su = $this->db->one("SELECT 1 FROM eve_users WHERE id=? AND is_superadmin=1 LIMIT 1", [$userId]);
        if ($su) return true;

        // Hard override: admin group (never lock out)
        $admin = $this->db->one(
            "SELECT 1
             FROM eve_user_groups ug
             JOIN groups g ON g.id = ug.group_id
             WHERE ug.user_id=? AND g.slug='admin'
             LIMIT 1",
            [$userId]
        );
        if ($admin) return true;

        // Use cached effective rights when possible
        $stateVer = $this->getGlobalVersion();
        $cacheKey = 'authz.cache';
        $now = time();

        $cache = $_SESSION[$cacheKey] ?? null;
        if (is_array($cache)
            && (int)($cache['user_id'] ?? 0) === $userId
            && (int)($cache['version'] ?? 0) === $stateVer
            && (int)($cache['ts'] ?? 0) + self::CACHE_TTL_SECONDS > $now
            && is_array($cache['rights'] ?? null)
        ) {
            return isset($cache['rights'][$right]);
        }

        // Recompute effective rights for this user
        $rows = $this->db->all(
            "SELECT DISTINCT r.slug
             FROM eve_user_groups ug
             JOIN group_rights gr ON gr.group_id = ug.group_id
             JOIN rights r ON r.id = gr.right_id
             WHERE ug.user_id=?",
            [$userId]
        );

        $set = [];
        foreach ($rows as $r) {
            $slug = (string)($r['slug'] ?? '');
            if ($slug !== '') $set[$slug] = true;
        }

        $_SESSION[$cacheKey] = [
            'user_id' => $userId,
            'version' => $stateVer,
            'ts' => $now,
            'rights' => $set,
        ];

        return isset($set[$right]);
    }

    public function requireRight(int $userId, string $right): void
    {
        if ($userId <= 0 || !$this->userHasRight($userId, $right)) {
            http_response_code(403);
            echo "403 Forbidden";
            exit;
        }
    }

    /**
     * Global invalidation version, bumped on any change to group/rights assignments.
     * Table is created by migration 014_authz_state.sql.
     */
    public function getGlobalVersion(): int
    {
        $row = $this->db->one("SELECT version FROM authz_state WHERE id=1 LIMIT 1");
        if ($row && isset($row['version'])) return (int)$row['version'];

        // self-heal if table exists but row missing
        try {
            $this->db->run("INSERT IGNORE INTO authz_state (id, version) VALUES (1, 1)");
        } catch (\Throwable $e) {
            // ignore; migrations may not have run yet
        }
        return 1;
    }

    public function bumpGlobalVersion(): void
    {
        // Best effort; never break request
        try {
            $this->db->run("INSERT IGNORE INTO authz_state (id, version) VALUES (1, 1)");
            $this->db->run("UPDATE authz_state SET version=version+1, updated_at=NOW() WHERE id=1");
        } catch (\Throwable $e) {}
        // Clear session cache for current user (optional immediate effect)
        unset($_SESSION['authz.cache']);
    }

    /**
     * Explain why a user is allowed/denied a right. Returns a structured array for admin tooling.
     */
    public function explain(int $userId, string $right): array
    {
        $out = [
            'user_id' => $userId,
            'right' => $right,
            'decision' => 'deny',
            'superadmin' => false,
            'admin_group' => false,
            'groups' => [],
            'granted_by' => [], // groups that grant the right
        ];

        if ($userId <= 0 || $right === '') return $out;

        $su = $this->db->one("SELECT 1 FROM eve_users WHERE id=? AND is_superadmin=1 LIMIT 1", [$userId]);
        if ($su) {
            $out['superadmin'] = true;
            $out['decision'] = 'allow';
            return $out;
        }

        $groups = $this->db->all(
            "SELECT g.id, g.slug, g.name, g.is_admin
             FROM eve_user_groups ug
             JOIN groups g ON g.id = ug.group_id
             WHERE ug.user_id=?
             ORDER BY g.is_admin DESC, g.name ASC",
            [$userId]
        );
        foreach ($groups as $g) {
            $out['groups'][] = $g;
            if ((string)$g['slug'] === 'admin') $out['admin_group'] = true;
        }
        if ($out['admin_group']) {
            $out['decision'] = 'allow';
            return $out;
        }

        $grant = $this->db->all(
            "SELECT DISTINCT g.id, g.slug, g.name
             FROM eve_user_groups ug
             JOIN groups g ON g.id = ug.group_id
             JOIN group_rights gr ON gr.group_id = g.id
             JOIN rights r ON r.id = gr.right_id
             WHERE ug.user_id=? AND r.slug=?
             ORDER BY g.is_admin DESC, g.name ASC",
            [$userId, $right]
        );
        $out['granted_by'] = $grant;
        $out['decision'] = $grant ? 'allow' : 'deny';
        return $out;
    }
}
