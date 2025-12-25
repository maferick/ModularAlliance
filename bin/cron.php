<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;
use App\Corptools\Cron\JobRegistry;
use App\Corptools\Cron\JobRunner;

$app = App::boot();

$command = $argv[1] ?? '';
$opts = $argv;

if ($command === 'run') {
    $jobKey = null;
    $dueOnly = in_array('--due', $opts, true);
    $dryRun = in_array('--dry-run', $opts, true);
    $verbose = in_array('--verbose', $opts, true);

    foreach ($opts as $opt) {
        if (str_starts_with($opt, '--job=')) {
            $jobKey = substr($opt, strlen('--job='));
        }
    }

    JobRegistry::sync($app->db);
    $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());

    $exitCode = 0;
    if (is_string($jobKey) && $jobKey !== '') {
        $result = $runner->runJob($app, $jobKey, [
            'trigger' => 'cli',
            'dry_run' => $dryRun,
            'verbose' => $verbose,
        ]);
        $status = (string)($result['status'] ?? 'unknown');
        $message = (string)($result['message'] ?? '');
        echo "[cron] {$jobKey}: {$status}" . ($message !== '' ? " - {$message}" : '') . "\n";
        if ($status !== 'success' && $status !== 'skipped') {
            $exitCode = 1;
        }
        exit($exitCode);
    }

    if ($dueOnly || $jobKey === null) {
        $results = $runner->runDueJobs($app, [
            'trigger' => 'cli',
            'dry_run' => $dryRun,
            'verbose' => $verbose,
        ]);
        foreach ($results as $result) {
            $job = (string)($result['job'] ?? 'job');
            $status = (string)($result['status'] ?? 'unknown');
            $message = (string)($result['message'] ?? '');
            echo "[cron] {$job}: {$status}" . ($message !== '' ? " - {$message}" : '') . "\n";
            if ($status !== 'success' && $status !== 'skipped') {
                $exitCode = 1;
            }
        }
        exit($exitCode);
    }
}

echo "Usage:\n";
echo "  php bin/cron.php run --due [--dry-run] [--verbose]\n";
echo "  php bin/cron.php run --job=corptools.audit_refresh [--dry-run] [--verbose]\n";
exit(1);
