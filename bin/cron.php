#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Core\Db;

require __DIR__ . '/../core/bootstrap.php';

$config = (array)($GLOBALS['APP_CONFIG'] ?? []);
$db = Db::fromConfig($config['db'] ?? []);

$now = date('Y-m-d H:i:s');

$db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS cron_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_slug VARCHAR(128) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  schedule VARCHAR(64) NOT NULL DEFAULT '*/15',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  last_status ENUM('ok','error') NULL,
  last_message VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

// Baseline runner: marks due jobs as run; module-specific execution will be wired later.
$jobs = $db->all("SELECT * FROM cron_jobs WHERE enabled=1");

foreach ($jobs as $j) {
    // Placeholder: run everything every invocation for now.
    $db->run("UPDATE cron_jobs SET last_run_at=?, last_status='ok', last_message=? WHERE id=?",
        [$now, 'baseline runner (no-op)', $j['id']]
    );
}

fwrite(STDOUT, "[OK] cron.php executed at {$now}
");
