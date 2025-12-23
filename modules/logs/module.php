<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Layout;
use App\Core\ModuleInterface;
use App\Core\ModuleManifest;
use App\Core\Rights;
use App\Http\Request;
use App\Http\Response;

final class LogsModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            slug: 'logs',
            name: 'Access Logs',
            description: 'Database-backed access logs with admin viewer.',
            version: '1.0.0',
            rights: [
                [
                    'slug' => 'admin.logs',
                    'description' => 'View access logs.',
                ],
            ],
            menu: [
                [
                    'slug' => 'admin.logs',
                    'title' => 'Access Logs',
                    'url' => '/admin/logs',
                    'sort_order' => 55,
                    'area' => 'admin_top',
                    'right_slug' => 'admin.logs',
                ],
            ],
            routes: [
                ['method' => 'GET', 'path' => '/admin/logs'],
            ]
        );
    }

    public function register(App $app): void
    {
        $app->router->get('/admin/logs', function (Request $req) use ($app): Response {
            $rights = new Rights($app->db);
            $hasRight = function (string $right) use ($rights): bool {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if ($uid <= 0) return false;
                return $rights->userHasRight($uid, $right);
            };

            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

            $page = isset($req->query['page']) ? max(1, (int)$req->query['page']) : 1;
            $perPage = 50;
            $offset = ($page - 1) * $perPage;

            $countRow = $app->db->one("SELECT COUNT(*) AS total FROM access_log");
            $total = (int)($countRow['total'] ?? 0);

            $limit = max(1, min($perPage, 200));
            $offset = max(0, $offset);

            $rows = $app->db->all(
                "SELECT id, created_at, user_id, character_id, ip, method, path, status, decision, reason, context_json
                 FROM access_log
                 ORDER BY id DESC
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
                $userId = htmlspecialchars((string)($row['user_id'] ?? ''));
                $characterId = htmlspecialchars((string)($row['character_id'] ?? ''));
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
                    . '<td>' . $userId . '</td>'
                    . '<td>' . $characterId . '</td>'
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
                . '<th>User ID</th>'
                . '<th>Character ID</th>'
                . '<th>IP</th>'
                . '<th>Context</th>'
                . '</tr></thead>'
                . '<tbody>' . $rowsHtml . '</tbody>'
                . '</table>'
                . '</div>'
                . $pager;

            return Response::html(Layout::page('Access Logs', $body, $leftTree, $adminTree, $userTree), 200);
        }, ['right' => 'admin.logs']);
    }
}

return new LogsModule();
