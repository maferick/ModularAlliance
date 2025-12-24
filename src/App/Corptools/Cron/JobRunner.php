<?php
declare(strict_types=1);

namespace App\Corptools\Cron;

use App\Core\App;
use App\Core\Db;

final class JobRunner
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions;

    public function __construct(private readonly Db $db, array $definitions)
    {
        $this->definitions = $definitions;
    }

    /** @return array<int, array<string, mixed>> */
    public function runDueJobs(App $app, array $context = []): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        $jobs = $this->db->all(
            "SELECT job_key, schedule_seconds\n"
            . " FROM module_corptools_jobs\n"
            . " WHERE is_enabled=1 AND (next_run_at IS NULL OR next_run_at <= NOW())\n"
            . " ORDER BY COALESCE(next_run_at, NOW()) ASC\n"
            . " LIMIT 25"
        );

        $results = [];
        foreach ($jobs as $job) {
            $jobKey = (string)($job['job_key'] ?? '');
            if ($jobKey === '') {
                continue;
            }
            $results[] = $this->runJob($app, $jobKey, $context);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function runJob(App $app, string $jobKey, array $context = []): array
    {
        if (!$this->tablesReady()) {
            return ['job' => $jobKey, 'status' => 'skipped', 'message' => 'Missing cron tables'];
        }

        $definition = $this->definitions[$jobKey] ?? null;
        if (!$definition || !is_callable($definition['handler'] ?? null)) {
            return ['job' => $jobKey, 'status' => 'missing', 'message' => 'Job not registered'];
        }

        $schedule = (int)($definition['schedule'] ?? 60);
        if ($schedule <= 0) {
            $schedule = 60;
        }

        $lockTtl = max(300, $schedule);
        $lockOwner = $this->acquireLock($jobKey, $lockTtl);
        if ($lockOwner === null) {
            $this->logRun($jobKey, 'skipped', 'Skipped: lock active', null, null, ['context' => $context]);
            return ['job' => $jobKey, 'status' => 'skipped', 'message' => 'Lock active'];
        }

        $runId = $this->startRun($jobKey);
        $start = microtime(true);
        $status = 'success';
        $message = '';
        $trace = null;
        $meta = ['context' => $context];

        try {
            $result = ($definition['handler'])($app, $context);
            if (is_string($result)) {
                $message = $result;
            } elseif (is_array($result)) {
                $message = (string)($result['message'] ?? '');
                $status = (string)($result['status'] ?? $status);
                if (isset($result['metrics'])) {
                    $meta['metrics'] = $result['metrics'];
                }
                if (isset($result['log_lines']) && is_array($result['log_lines'])) {
                    $meta['log_lines'] = array_slice(array_map('strval', $result['log_lines']), -50);
                }
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $message = $e->getMessage();
            $trace = $e->getTraceAsString();
        }

        $durationMs = (int)round((microtime(true) - $start) * 1000);
        $this->finishRun($runId, $status, $durationMs, $message, $trace, $meta);
        $this->updateJob($jobKey, $status, $durationMs, $message, $schedule);
        $this->releaseLock($jobKey, $lockOwner);

        return ['job' => $jobKey, 'status' => $status, 'message' => $message, 'duration_ms' => $durationMs];
    }

    private function startRun(string $jobKey): int
    {
        $this->db->run(
            "INSERT INTO module_corptools_job_runs (job_key, status, started_at) VALUES (?, 'running', NOW())",
            [$jobKey]
        );
        $row = $this->db->one("SELECT LAST_INSERT_ID() AS id");
        return (int)($row['id'] ?? 0);
    }

    private function logRun(string $jobKey, string $status, string $message, ?string $trace, ?int $durationMs, array $meta): void
    {
        $this->db->run(
            "INSERT INTO module_corptools_job_runs\n"
            . " (job_key, status, started_at, finished_at, duration_ms, message, error_trace, meta_json)\n"
            . " VALUES (?, ?, NOW(), NOW(), ?, ?, ?, ?)",
            [
                $jobKey,
                $status,
                $durationMs ?? 0,
                $message,
                $trace,
                json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function finishRun(int $runId, string $status, int $durationMs, string $message, ?string $trace, array $meta): void
    {
        if ($runId <= 0) {
            return;
        }
        $this->db->run(
            "UPDATE module_corptools_job_runs\n"
            . " SET status=?, finished_at=NOW(), duration_ms=?, message=?, error_trace=?, meta_json=?\n"
            . " WHERE id=?",
            [
                $status,
                $durationMs,
                $message,
                $trace,
                json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $runId,
            ]
        );
    }

    private function updateJob(string $jobKey, string $status, int $durationMs, string $message, int $schedule): void
    {
        $this->db->run(
            "UPDATE module_corptools_jobs\n"
            . " SET last_run_at=NOW(), last_status=?, last_duration_ms=?, last_message=?,\n"
            . " next_run_at=DATE_ADD(NOW(), INTERVAL ? SECOND)\n"
            . " WHERE job_key=?",
            [$status, $durationMs, substr($message, 0, 255), $schedule, $jobKey]
        );
    }

    private function acquireLock(string $jobKey, int $ttlSeconds): ?string
    {
        $owner = bin2hex(random_bytes(8));
        $this->db->run(
            "INSERT INTO module_corptools_job_locks (job_key, owner, locked_at, expires_at)\n"
            . " VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))\n"
            . " ON DUPLICATE KEY UPDATE\n"
            . " owner=IF(expires_at < NOW(), VALUES(owner), owner),\n"
            . " locked_at=IF(expires_at < NOW(), VALUES(locked_at), locked_at),\n"
            . " expires_at=IF(expires_at < NOW(), VALUES(expires_at), expires_at)",
            [$jobKey, $owner, $ttlSeconds]
        );

        $row = $this->db->one(
            "SELECT owner, expires_at FROM module_corptools_job_locks WHERE job_key=?",
            [$jobKey]
        );
        if (!$row) {
            return null;
        }
        if ((string)($row['owner'] ?? '') !== $owner) {
            return null;
        }
        return $owner;
    }

    private function releaseLock(string $jobKey, string $owner): void
    {
        $this->db->run(
            "DELETE FROM module_corptools_job_locks WHERE job_key=? AND owner=?",
            [$jobKey, $owner]
        );
    }

    private function tablesReady(): bool
    {
        $row = $this->db->one("SHOW TABLES LIKE 'module_corptools_jobs'");
        return $row !== null;
    }
}
