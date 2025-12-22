<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\App;
use App\Core\Settings;

$app = App::boot();
$settings = new Settings($app->db);
$now = time();

$manifests = $app->modules->getManifests();
foreach ($manifests as $manifest) {
    if (empty($manifest['cron']) || !is_array($manifest['cron'])) {
        continue;
    }

    $slug = (string)($manifest['slug'] ?? 'module');

    foreach ($manifest['cron'] as $job) {
        if (!is_array($job)) continue;
        $name = (string)($job['name'] ?? '');
        $every = (int)($job['every'] ?? 0);
        $handler = $job['handler'] ?? null;
        if ($name === '' || $every <= 0 || !is_callable($handler)) continue;

        $key = 'cron.' . $slug . '.' . $name . '.last_run';
        $lastRun = (int)($settings->get($key, '0') ?? '0');

        if (($now - $lastRun) < $every) continue;

        try {
            $handler($app);
            $settings->set($key, (string)$now);
            echo "[cron] {$slug}:{$name} ok\n";
        } catch (Throwable $e) {
            $settings->set($key, (string)$now);
            echo "[cron] {$slug}:{$name} failed: " . $e->getMessage() . "\n";
        }
    }
}
