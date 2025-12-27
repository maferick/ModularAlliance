<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;

$app = App::boot(false);

$tables = [
    'eve_token_buckets',
    'module_corptools_settings',
    'corp_scope_policies',
    'corp_scope_policy_overrides',
    'module_corptools_character_scope_status',
    'module_corptools_audit_events',
    'module_corptools_character_audit',
    'module_corptools_character_audit_snapshots',
    'module_corptools_corp_audit',
    'module_corptools_corp_audit_snapshots',
    'module_corptools_jobs',
    'module_corptools_job_runs',
];

try {
    foreach ($tables as $table) {
        $tableQuoted = db_quote($app->db, $table);
        db_one($app->db, "SHOW TABLES LIKE {$tableQuoted}");
    }

    db_all(
        $app->db,
        "SELECT job_key, last_run_at, last_status, last_duration_ms
         FROM module_corptools_jobs
         ORDER BY job_key ASC"
    );
} catch (Throwable $e) {
    fwrite(STDERR, "CorpTools status check failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "CorpTools status check OK.\n";
