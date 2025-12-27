<?php
declare(strict_types=1);

/*
Module Name: Access Logs
Description: Database-backed access logs with admin viewer.
Version: 1.0.0
*/

use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Http\Request;
use App\Http\Response;

require_once __DIR__ . '/functions.php';

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('admin.logs', 'View access logs.');

    $registry->menu([
        'slug' => 'admin.logs',
        'title' => 'Access Logs',
        'url' => '/admin/logs',
        'sort_order' => 55,
        'area' => 'site_admin_top',
        'right_slug' => 'admin.logs',
    ]);

    $registry->route('GET', '/admin/logs', function (Request $req) use ($app): Response {
        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
        $menus = $app->menu->layoutMenus($_SERVER['REQUEST_URI'] ?? '/', $hasRight, $loggedIn);

        $page = isset($req->query['page']) ? max(1, (int)$req->query['page']) : 1;
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $countRow = db_one($app->db, "SELECT COUNT(*) AS total FROM access_log");
        $total = (int)($countRow['total'] ?? 0);

        $limit = max(1, min($perPage, 200));
        $offset = max(0, $offset);

        $rows = db_all($app->db, 
            "SELECT l.id, l.created_at, l.user_id, l.character_id, l.ip, l.method, l.path, l.status, l.decision, l.reason, l.context_json,
                    u.public_id AS user_public_id, u.character_name AS user_character_name
             FROM access_log l
             LEFT JOIN eve_users u ON u.id = l.user_id
             ORDER BY l.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $created = htmlspecialchars((string)($row['created_at'] ?? ''));
            $method = htmlspecialchars((string)($row['method'] ?? ''));
            $path = htmlspecialchars((string)($row['path'] ?? ''));
            $status = htmlspecialchars((string)($row['status'] ?? ''));
            $decision = htmlspecialchars((string)($row['decision'] ?? ''));
            $reason = htmlspecialchars((string)($row['reason'] ?? ''));
            $labels = logs_format_user_labels($row);
            $userLabel = htmlspecialchars($labels['user']);
            $characterLabel = htmlspecialchars($labels['character']);
            $ip = htmlspecialchars((string)($row['ip'] ?? ''));
            $context = '';

            if (!empty($row['context_json'])) {
                $context = '<details><summary>Details</summary><pre class="mb-0"><code>'
                    . htmlspecialchars((string)$row['context_json'])
                    . '</code></pre></details>';
            }

            $rowsHtml .= '<tr>'
                . '<td class="text-muted">' . $created . '</td>'
                . '<td><code>' . $method . '</code> <span class="text-info">' . $path . '</span></td>'
                . '<td>' . $status . '</td>'
                . '<td>' . $decision . '</td>'
                . '<td>' . $reason . '</td>'
                . '<td>' . $userLabel . '</td>'
                . '<td>' . $characterLabel . '</td>'
                . '<td>' . $ip . '</td>'
                . '<td>' . $context . '</td>'
                . '</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='9' class='text-muted'>No access logs recorded yet.</td></tr>";
        }

        $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;
        $prevPage = $page > 1 ? $page - 1 : null;
        $nextPage = $page < $totalPages ? $page + 1 : null;

        $pager = '<div class="d-flex justify-content-between align-items-center mt-3">';
        $pager .= '<div class="text-muted">Total: ' . number_format($total) . '</div>';
        $pager .= '<div class="btn-group" role="group">';
        if ($prevPage) {
            $pager .= '<a class="btn btn-sm btn-outline-light" href="/admin/logs?page=' . $prevPage . '">Previous</a>';
        } else {
            $pager .= '<button class="btn btn-sm btn-outline-light" disabled>Previous</button>';
        }
        if ($nextPage) {
            $pager .= '<a class="btn btn-sm btn-outline-light" href="/admin/logs?page=' . $nextPage . '">Next</a>';
        } else {
            $pager .= '<button class="btn btn-sm btn-outline-light" disabled>Next</button>';
        }
        $pager .= '</div></div>';

        $body = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">'
            . '<div><h1 class="mb-1">Access Logs</h1>'
            . '<div class="text-muted">Latest access decisions captured by the router guard.</div></div>'
            . '</div>'
            . '<div class="table-responsive">'
            . '<table class="table table-sm table-striped align-middle">'
            . '<thead><tr>'
            . '<th>Time</th>'
            . '<th>Route</th>'
            . '<th>Status</th>'
            . '<th>Decision</th>'
            . '<th>Reason</th>'
            . '<th>Member</th>'
            . '<th>Character</th>'
            . '<th>IP</th>'
            . '<th>Context</th>'
            . '</tr></thead>'
            . '<tbody>' . $rowsHtml . '</tbody>'
            . '</table>'
            . '</div>'
            . $pager;

        return Response::html(Layout::page('Access Logs', $body, $menus['left_member'], $menus['left_admin'], $menus['site_admin'], $menus['user'], $menus['module']), 200);
    }, ['right' => 'admin.logs']);
};
