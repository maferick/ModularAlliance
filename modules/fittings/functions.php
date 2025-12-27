<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Db;
use App\Core\Identifiers;
use App\Core\Layout;
use App\Core\Rights;
use App\Core\Universe;
use App\Http\Response;

function fittings_csrf_token(string $key): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['csrf_tokens'][$key] = $token;
    return $token;
}

function fittings_csrf_check(string $key, ?string $token): bool
{
    $stored = $_SESSION['csrf_tokens'][$key] ?? null;
    unset($_SESSION['csrf_tokens'][$key]);
    return is_string($token) && is_string($stored) && hash_equals($stored, $token);
}

function fittings_render_page(App $app, string $title, string $bodyHtml): string
{
    $rights = new Rights($app->db);
    $hasRight = function (string $right) use ($rights): bool {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        return $uid > 0 && $rights->userHasRight($uid, $right);
    };

    $leftTree = $app->menu->tree('left', $hasRight);
    $adminTree = $app->menu->tree('admin_top', $hasRight);
    $userTree = $app->menu->tree('user_top', fn(string $r) => true);

    $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
    if ($loggedIn) {
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
    } else {
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
    }

    return Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree);
}

function fittings_generate_slug(Db $db, string $table, string $name): string
{
    return Identifiers::generateSlug($db, $table, 'slug', $name);
}

function fittings_require_login(): ?Response
{
    $cid = (int)($_SESSION['character_id'] ?? 0);
    if ($cid <= 0) {
        return Response::redirect('/auth/login');
    }
    return null;
}

function fittings_require_right(App $app, string $right): ?Response
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $rights = new Rights($app->db);
    if ($uid <= 0 || !$rights->userHasRight($uid, $right)) {
        return Response::text('403 Forbidden', 403);
    }
    return null;
}

function fittings_log_audit(Db $db, int $userId, string $action, string $entityType, int $entityId, string $message, array $meta = []): void
{
    db_exec(
        $db,
        "INSERT INTO module_fittings_audit_log\n"
        . " (user_id, action, entity_type, entity_id, message, meta_json, created_at)\n"
        . " VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [
            $userId,
            $action,
            $entityType,
            $entityId,
            $message,
            json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]
    );
}

function fittings_get_user_groups(Db $db, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $rows = db_all(
        $db,
        "SELECT group_id FROM eve_user_groups WHERE user_id=?",
        [$userId]
    );
    return array_values(array_filter(array_map(fn($row) => (int)($row['group_id'] ?? 0), $rows)));
}

function fittings_scope_org_label(Universe $universe, string $scope, int $orgId): string
{
    if ($orgId <= 0) {
        return '—';
    }
    $type = $scope === 'alliance' ? 'alliance' : 'corporation';
    return $universe->nameOrUnknown($type, $orgId);
}

function fittings_resolve_org_id(Db $db, string $scope, string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    if (ctype_digit($value)) {
        return (int)$value;
    }
    $type = $scope === 'alliance' ? 'alliance' : 'corporation';
    $row = db_one(
        $db,
        "SELECT entity_id FROM universe_entities WHERE entity_type=? AND name LIKE ? ORDER BY fetched_at DESC LIMIT 1",
        [$type, '%' . $value . '%']
    );
    return (int)($row['entity_id'] ?? 0);
}
