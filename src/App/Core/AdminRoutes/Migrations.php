<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\MigrationCatalog;
use App\Core\ModuleRegistry;
use App\Http\Response;

final class Migrations
{
    public static function register(App $app, ModuleRegistry $registry, callable $render): void
    {
        $registry->route('GET', '/admin/migrations', function () use ($app, $render): Response {
            return self::renderPage($app, $render, null, null, null);
        }, ['right' => 'admin.migrations']);

        $registry->route('POST', '/admin/migrations', function () use ($app, $render): Response {
            $action = (string)($_POST['action'] ?? '');
            $msg = null;
            $log = null;
            $doctor = null;

            if ($action === 'apply') {
                $log = self::captureOutput(function () use ($app): void {
                    foreach (MigrationCatalog::migrationDirs() as [$slug, $dir]) {
                        echo "[MIGRATE] {$slug}: {$dir}\n";
                        $app->migrator->applyDir($slug, $dir);
                    }
                });
                $msg = 'Applied latest migrations.';
            } elseif ($action === 'recreate') {
                $log = self::captureOutput(function () use ($app, &$msg): void {
                    $result = $app->migrator->recreateDatabase();
                    $msg = ($result['ok'] ?? false) ? $result['message'] : 'Error: ' . $result['message'];
                });
            } elseif ($action === 'doctor') {
                $doctor = self::doctorReport($app);
                $msg = 'Doctor report generated.';
            } elseif ($action === 'repair') {
                $module = (string)($_POST['module'] ?? '');
                $file = (string)($_POST['file'] ?? '');
                $path = MigrationCatalog::resolveMigrationFile($module, $file);
                if ($path === null) {
                    $msg = 'Error: Migration file not found.';
                } else {
                    $result = $app->migrator->repairChecksum($module, $path);
                    $msg = ($result['ok'] ?? false) ? $result['message'] : 'Error: ' . ($result['message'] ?? 'Repair failed.');
                    if (!empty($result['diffs'])) {
                        $doctor = [
                            [
                                'module' => $module,
                                'path' => $file,
                                'diffs' => $result['diffs'],
                            ],
                        ];
                    }
                }
            } else {
                $msg = 'Unknown action.';
            }

            return self::renderPage($app, $render, $msg, $log, $doctor);
        }, ['right' => 'admin.migrations']);
    }

    private static function renderPage(App $app, callable $render, ?string $msg, ?string $log, ?array $doctor): Response
    {
        $applied = $app->migrator->appliedMigrations();
        $entries = MigrationCatalog::migrationEntries();
        $counts = ['applied' => 0, 'pending' => 0, 'mismatch' => 0];
        $rows = [];
        $mismatches = [];

        foreach ($entries as $entry) {
            $key = $entry['module'] . '::' . $entry['path'];
            if (!isset($applied[$key])) {
                $counts['pending']++;
                $rows[] = ['status' => 'pending', 'entry' => $entry, 'applied' => null];
                continue;
            }

            $appliedChecksum = $applied[$key]['checksum'];
            if ($appliedChecksum === $entry['checksum']) {
                $counts['applied']++;
                $rows[] = ['status' => 'applied', 'entry' => $entry, 'applied' => $appliedChecksum];
                continue;
            }

            $counts['mismatch']++;
            $rows[] = ['status' => 'mismatch', 'entry' => $entry, 'applied' => $appliedChecksum];
            $mismatches[] = [
                'module' => $entry['module'],
                'path' => $entry['path'],
                'current' => $entry['checksum'],
                'applied' => $appliedChecksum,
            ];
        }

        $alert = '';
        if ($msg !== null) {
            $class = str_starts_with($msg, 'Error:') ? 'danger' : 'info';
            $alert = "<div class='alert alert-{$class} mb-3'>" . htmlspecialchars($msg) . "</div>";
        }

        $logHtml = '';
        if ($log !== null && trim($log) !== '') {
            $logHtml = "<div class='card mb-3'><div class='card-body'>
                <h5 class='card-title'>Output</h5>
                <pre class='mb-0'>" . htmlspecialchars($log) . "</pre>
            </div></div>";
        }

        $summary = "<div class='d-flex gap-3 flex-wrap mb-3'>
            <span class='badge bg-success'>Applied {$counts['applied']}</span>
            <span class='badge bg-warning text-dark'>Pending {$counts['pending']}</span>
            <span class='badge bg-danger'>Mismatch {$counts['mismatch']}</span>
        </div>";

        $actions = "<div class='d-flex flex-wrap gap-2 mb-3'>
            <form method='post' action='/admin/migrations'>
              <input type='hidden' name='action' value='apply'>
              <button class='btn btn-outline-primary btn-sm' type='submit'>Apply latest</button>
            </form>
            <form method='post' action='/admin/migrations'>
              <input type='hidden' name='action' value='doctor'>
              <button class='btn btn-outline-secondary btn-sm' type='submit'>Run doctor</button>
            </form>
            <form method='post' action='/admin/migrations'>
              <input type='hidden' name='action' value='recreate'>
              <button class='btn btn-danger btn-sm' type='submit'
                onclick=\"return confirm('Recreate database? This will drop ALL tables.')\">Recreate database</button>
            </form>
        </div>";

        $table = "<div class='card mb-3'><div class='card-body'>
            <h5 class='card-title'>Migration status</h5>
            <div class='table-responsive'>
            <table class='table table-sm align-middle'>
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Module</th>
                  <th>Path</th>
                  <th>Checksum</th>
                  <th>Applied</th>
                </tr>
              </thead>
              <tbody>";

        foreach ($rows as $row) {
            $status = $row['status'];
            $entry = $row['entry'];
            $badgeClass = $status === 'applied' ? 'success' : ($status === 'pending' ? 'warning text-dark' : 'danger');
            $table .= "<tr>
                <td><span class='badge bg-{$badgeClass}'>" . htmlspecialchars($status) . "</span></td>
                <td>" . htmlspecialchars($entry['module']) . "</td>
                <td>" . htmlspecialchars($entry['path']) . "</td>
                <td><code>" . htmlspecialchars(substr($entry['checksum'], 0, 10)) . "…</code></td>
                <td><code>" . htmlspecialchars($row['applied'] ? substr($row['applied'], 0, 10) . '…' : '-') . "</code></td>
              </tr>";
        }

        $table .= "</tbody></table></div></div></div>";

        $mismatchHtml = '';
        if ($mismatches !== []) {
            $mismatchHtml = "<div class='card mb-3'><div class='card-body'>
                <h5 class='card-title'>Checksum mismatches</h5>
                <div class='list-group'>";
            foreach ($mismatches as $mismatch) {
                $mismatchHtml .= "<div class='list-group-item'>
                    <div class='d-flex justify-content-between align-items-start'>
                      <div>
                        <div><strong>" . htmlspecialchars($mismatch['module']) . "</strong>: " . htmlspecialchars($mismatch['path']) . "</div>
                        <div class='small text-muted'>Applied {$mismatch['applied']} → Current {$mismatch['current']}</div>
                      </div>
                      <form method='post' action='/admin/migrations' class='ms-3'>
                        <input type='hidden' name='action' value='repair'>
                        <input type='hidden' name='module' value='" . htmlspecialchars($mismatch['module']) . "'>
                        <input type='hidden' name='file' value='" . htmlspecialchars($mismatch['path']) . "'>
                        <button class='btn btn-outline-warning btn-sm' type='submit'>Accept checksum</button>
                      </form>
                    </div>
                  </div>";
            }
            $mismatchHtml .= "</div></div></div>";
        }

        $doctorHtml = '';
        if (is_array($doctor) && $doctor !== []) {
            $doctorHtml = "<div class='card mb-3'><div class='card-body'>
                <h5 class='card-title'>Doctor output</h5>";
            foreach ($doctor as $entry) {
                $doctorHtml .= "<div class='mb-3'>
                    <div><strong>" . htmlspecialchars($entry['module']) . "</strong>: " . htmlspecialchars($entry['path']) . "</div>";
                $diffs = $entry['diffs'] ?? [];
                if ($diffs === []) {
                    $doctorHtml .= "<div class='text-muted small'>Schema already matches current migration contents.</div>";
                } else {
                    $doctorHtml .= "<pre class='mb-0'>" . htmlspecialchars(implode("\n", $diffs)) . "</pre>";
                }
                $doctorHtml .= "</div>";
            }
            $doctorHtml .= "</div></div>";
        }

        $h = "<h1>Database Migrations</h1>
              <p class='text-muted'>Apply, diagnose, and recreate database migrations.</p>
              {$alert}
              {$summary}
              {$actions}
              {$logHtml}
              {$mismatchHtml}
              {$doctorHtml}
              {$table}";

        return $render('Migrations', $h);
    }

    private static function captureOutput(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } catch (\Throwable $e) {
            echo "[ERROR] " . $e->getMessage() . "\n";
        }
        return trim((string)ob_get_clean());
    }

    private static function doctorReport(App $app): array
    {
        $applied = $app->migrator->appliedMigrations();
        $entries = MigrationCatalog::migrationEntries();
        $report = [];

        foreach ($entries as $entry) {
            $key = $entry['module'] . '::' . $entry['path'];
            if (!isset($applied[$key])) {
                continue;
            }

            $appliedChecksum = $applied[$key]['checksum'];
            if ($appliedChecksum === $entry['checksum']) {
                continue;
            }

            $diffs = $app->migrator->schemaDiffStatements($entry['full']);
            $report[] = [
                'module' => $entry['module'],
                'path' => $entry['path'],
                'diffs' => $diffs,
            ];
        }

        return $report;
    }
}
