<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\ModuleRegistry;
use App\Core\RedisCache;
use App\Http\Response;

final class Cache
{
    public static function register(App $app, ModuleRegistry $registry, callable $render): void
    {
        $registry->route('GET', '/admin/cache', function () use ($app, $render): Response {
            // ESI cache uses fetched_at + ttl_seconds (canonical schema)
            $esiTotal = (int)(db_value($app->db, "SELECT COUNT(*) FROM esi_cache", []) ?: 0);
            $esiExpired = (int)(db_value(
                $app->db,
                "SELECT COUNT(*) FROM esi_cache WHERE DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND) < NOW()",
                []
            ) ?: 0);

            $uniTotal = 0;
            try {
                $uniTotal = (int)(db_value($app->db, "SELECT COUNT(*) FROM universe_entities", []) ?: 0);
            } catch (\Throwable $e) {
                $uniTotal = 0;
            }

            // Redis (optional L1)
            $redis = RedisCache::fromConfig($app->config['redis'] ?? []);
            $redisEnabled = $redis->enabled();
            $redisPrefix = $redis->prefix();
            $redisStatus = $redisEnabled ? 'Connected' : 'Disabled';
            $redisKeys = null;
            if ($redisEnabled) {
                try { $redisKeys = $redis->countByPrefix(2000); } catch (\Throwable $e) { $redisKeys = null; }
            }

            $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
            $msgHtml = $msg !== '' ? "<div class='alert alert-info mb-3'>" . htmlspecialchars($msg) . "</div>" : "";

            $h = $msgHtml . <<<HTML
<h1>ESI Cache</h1>
<p class="text-muted">Operational controls for ESI cache storage (MariaDB) and optional Redis L1 acceleration.</p>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">MariaDB cache tables</h5>
        <ul class="mb-3">
          <li><strong>esi_cache</strong>: {$esiTotal} rows ({$esiExpired} expired)</li>
          <li><strong>universe_entities</strong>: {$uniTotal} rows</li>
        </ul>

        <div class="d-flex flex-wrap gap-2">
          <form method="post" action="/admin/cache">
            <input type="hidden" name="action" value="remove_expired">
            <button class="btn btn-outline-warning btn-sm" type="submit">Remove expired (ESI)</button>
          </form>

          <form method="post" action="/admin/cache">
            <input type="hidden" name="action" value="purge_esi">
            <button class="btn btn-outline-danger btn-sm" type="submit"
              onclick="return confirm('Purge ALL esi_cache rows?')">Purge ESI cache</button>
          </form>

          <form method="post" action="/admin/cache">
            <input type="hidden" name="action" value="purge_universe">
            <button class="btn btn-outline-danger btn-sm" type="submit"
              onclick="return confirm('Purge ALL universe_entities rows?')">Purge Universe cache</button>
          </form>

          <form method="post" action="/admin/cache">
            <input type="hidden" name="action" value="purge_all">
            <button class="btn btn-danger btn-sm" type="submit"
              onclick="return confirm('Purge ALL caches (ESI + Universe)?')">Purge ALL</button>
          </form>
        </div>

        <div class="form-text mt-2">
          Expired is computed as <code>DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND) &lt; NOW()</code>.
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Redis (optional)</h5>
        <p class="mb-2"><strong>Status:</strong> {$redisStatus}</p>
        <p class="mb-2"><strong>Prefix:</strong> <code>{$redisPrefix}</code></p>
HTML;

            if ($redisEnabled) {
                $h .= "<p class='mb-3'><strong>Keys (sampled):</strong> " . htmlspecialchars((string)($redisKeys ?? 'n/a')) . "</p>
        <form method='post' action='/admin/cache'>
          <input type='hidden' name='action' value='redis_flush'>
          <button class='btn btn-outline-danger btn-sm' type='submit'
            onclick=\"return confirm('Flush Redis keys with prefix {$redisPrefix}?')\">Flush Redis namespace</button>
        </form>";
            } else {
                $h .= "<p class='text-muted'>Redis is disabled or unreachable. Configure <code>/var/www/config.php</code> (redis.*) or env vars.</p>";
            }

            $h .= <<<HTML
      </div>
    </div>
  </div>
</div>
HTML;

            return $render('Cache', $h);
        }, ['right' => 'admin.cache']);

        $registry->route('POST', '/admin/cache', function () use ($app): Response {
            $action = (string)($_POST['action'] ?? '');

            $redis = RedisCache::fromConfig($app->config['redis'] ?? []);

            $msg = 'OK';

            try {
                switch ($action) {
                    case 'remove_expired':
                        db_exec($app->db, "DELETE FROM esi_cache WHERE DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND) < NOW()");
                        $msg = "Removed expired ESI rows";
                        break;

                    case 'purge_esi':
                        db_exec($app->db, "DELETE FROM esi_cache");
                        $msg = "Purged ESI cache";
                        break;

                    case 'purge_universe':
                        db_exec($app->db, "DELETE FROM universe_entities");
                        $msg = "Purged Universe cache";
                        break;

                    case 'purge_all':
                        db_exec($app->db, "DELETE FROM esi_cache");
                        db_exec($app->db, "DELETE FROM universe_entities");
                        $msg = "Purged ALL caches";
                        break;

                    case 'redis_flush':
                        if ($redis->enabled()) {
                            $n = $redis->flushPrefix(5000);
                            $msg = "Flushed Redis namespace ({$n} keys)";
                        } else {
                            $msg = "Redis not enabled";
                        }
                        break;

                    default:
                        $msg = "Unknown action";
                        break;
                }
            } catch (\Throwable $e) {
                $msg = "Error: " . $e->getMessage();
            }

            // Best-effort keep L1/L2 consistent
            if (in_array($action, ['remove_expired','purge_esi','purge_universe','purge_all'], true) && $redis->enabled()) {
                try { $redis->flushPrefix(5000); } catch (\Throwable $e) {}
            }

            return Response::redirect('/admin/cache?msg=' . rawurlencode($msg), 302);
        }, ['right' => 'admin.cache']);
    }
}
