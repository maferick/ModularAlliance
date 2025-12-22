<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

final class App
{
    public readonly array $config;
    public readonly Db $db;
    public readonly Migrator $migrator;
    public readonly Router $router;
    public readonly ModuleManager $modules;
    public readonly Menu $menu;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Db::fromConfig($config['db'] ?? []);
        $this->migrator = new Migrator($this->db);
        $this->router = new Router();
        $this->modules = new ModuleManager();
        $this->menu = new Menu($this->db);
    }

    public static function boot(): self
    {
        $cfg = \app_config();
        $app = new self($cfg);

        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        // Global route guard: deny-by-default for routes declaring a required right
        $app->router->setGuard(function (Request $req, array $meta) use ($rights): ?Response {
            if (!empty($meta['public'])) return null;

            $need = $meta['right'] ?? null;
            if ($need) {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if ($uid <= 0) return Response::redirect('/auth/login');
                $rights->requireRight($uid, (string)$need);
            }
            return null;
        });

        // Core menu defaults (idempotent)
        $app->menu->register(['slug'=>'home','title'=>'Dashboard','url'=>'/','sort_order'=>10,'area'=>'left']);
        $app->menu->register(['slug'=>'profile','title'=>'Profile','url'=>'/me','sort_order'=>20,'area'=>'left']);

        $app->menu->register(['slug'=>'admin.root','title'=>'Admin Home','url'=>'/admin','sort_order'=>10,'area'=>'admin_top','right_slug'=>'admin.access']);
        $app->menu->register(['slug'=>'admin.settings','title'=>'Settings','url'=>'/admin/settings','sort_order'=>15,'area'=>'admin_top','right_slug'=>'admin.settings']);
        $app->menu->register(['slug'=>'admin.cache','title'=>'ESI Cache','url'=>'/admin/cache','sort_order'=>20,'area'=>'admin_top','right_slug'=>'admin.cache']);
        $app->menu->register(['slug'=>'admin.rights','title'=>'Rights','url'=>'/admin/rights','sort_order'=>25,'area'=>'admin_top','right_slug'=>'admin.rights']);
        $app->menu->register(['slug'=>'admin.users','title'=>'Users & Groups','url'=>'/admin/users','sort_order'=>30,'area'=>'admin_top','right_slug'=>'admin.users']);
        $app->menu->register(['slug'=>'admin.menu','title'=>'Menu Editor','url'=>'/admin/menu','sort_order'=>40,'area'=>'admin_top','right_slug'=>'admin.menu']);
        $app->menu->register(['slug'=>'admin.modules','title'=>'Modules','url'=>'/admin/modules','sort_order'=>45,'area'=>'admin_top','right_slug'=>'admin.modules']);

        // Routes
        $app->router->get('/health', fn() => Response::text("OK\n", 200));

        $app->router->get('/', function () use ($app, $hasRight): Response {
            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);

            $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
            if ($loggedIn) {
                $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
            } else {
                $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
            }

            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) {
                $body = "<h1>Dashboard</h1>
                         <p>You are not logged in.</p>
                         <p><a href='/auth/login'>Login with EVE SSO</a></p>";
                return Response::html(Layout::page('Dashboard', $body, $leftTree, $adminTree, $userTree), 200);
            }

            $u = new Universe($app->db);
            $p = $u->characterProfile($cid);

            $char = htmlspecialchars($p['character']['name'] ?? 'Unknown');
            $corp = htmlspecialchars($p['corporation']['name'] ?? '—');
            $corpT = htmlspecialchars($p['corporation']['ticker'] ?? '');
            $all  = htmlspecialchars($p['alliance']['name'] ?? '—');
            $allT = htmlspecialchars($p['alliance']['ticker'] ?? '');

            $body = "<h1>Dashboard</h1>
                     <p>Welcome back, <strong>{$char}</strong>.</p>
                     <p>Corporation: <strong>{$corp}</strong>" . ($corpT !== '' ? " [{$corpT}]" : "") . "</p>
                     <p>Alliance: <strong>{$all}</strong>" . ($allT !== '' ? " [{$allT}]" : "") . "</p>";

            return Response::html(Layout::page('Dashboard', $body, $leftTree, $adminTree, $userTree), 200);
        });

        // User alts placeholder
        $app->router->get('/user/alts', function () use ($app, $hasRight): Response {
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid <= 0) return Response::redirect('/auth/login');

            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

            $body = "<h1>Linked Characters</h1>
                     <p>This will allow linking multiple EVE characters (alts) to one account.</p>";

            return Response::html(Layout::page('Linked Characters', $body, $leftTree, $adminTree, $userTree), 200);
        });

        // Admin renderer (shared)
        $render = function (string $title, string $bodyHtml) use ($app, $hasRight): Response {
            $leftTree  = $app->menu->tree('left', $hasRight);
            $adminTree = $app->menu->tree('admin_top', $hasRight);
            $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
            $userTree  = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

            // Brand (settings-driven, safe fallbacks)
            $settings = new Settings($app->db);

            $brandName = $settings->get('site.brand.name', 'killsineve.online') ?? 'killsineve.online';
            $type = $settings->get('site.identity.type', 'corporation') ?? 'corporation'; // corporation|alliance
            $id = (int)($settings->get('site.identity.id', '0') ?? '0');

            // If not configured, infer from logged-in character (best-effort)
            if ($id <= 0) {
                $cid = (int)($_SESSION['character_id'] ?? 0);
                if ($cid > 0) {
                    $u = new Universe($app->db);
                    $p = $u->characterProfile($cid);
                    if ($type === 'alliance' && !empty($p['alliance']['id'])) {
                        $id = (int)$p['alliance']['id'];
                        if ($brandName === 'killsineve.online' && !empty($p['alliance']['name'])) $brandName = (string)$p['alliance']['name'];
                    } elseif (!empty($p['corporation']['id'])) {
                        $id = (int)$p['corporation']['id'];
                        if ($brandName === 'killsineve.online' && !empty($p['corporation']['name'])) $brandName = (string)$p['corporation']['name'];
                    }
                }
            }

            $brandLogoUrl = null;
            if ($id > 0) {
                $brandLogoUrl = ($type === 'alliance')
                    ? "https://images.evetech.net/alliances/{$id}/logo?size=64"
                    : "https://images.evetech.net/corporations/{$id}/logo?size=64";
            }

            return Response::html(Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree, $brandName, $brandLogoUrl), 200);
        };

        $app->router->get('/admin', function () use ($render): Response {
            $body = "<h1>Admin</h1>
                     <p class='text-muted'>Control plane for platform configuration and governance.</p>
                     <ul>
                       <li><a href='/admin/settings'>Settings</a> – site identity & branding</li>
                       <li><a href='/admin/rights'>Rights</a> – groups & permission grants</li>
                       <li><a href='/admin/users'>Users</a> – assign groups to users</li>
                       <li><a href='/admin/menu'>Menu Editor</a></li>
                       <li><a href='/admin/modules'>Modules</a> – loaded modules overview</li>
                       <li><a href='/admin/cache'>ESI Cache</a></li>
                     </ul>";
            return $render('Admin', $body);
        }, ['right' => 'admin.access']);

        $app->router->get('/admin/modules', function () use ($app, $render): Response {
            $mods = $app->modules->getManifests();
            usort($mods, fn(array $a, array $b) => strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? '')));

            $rows = '';
            foreach ($mods as $m) {
                $slug = htmlspecialchars((string)($m['slug'] ?? ''));
                $name = htmlspecialchars((string)($m['name'] ?? $slug));
                $desc = htmlspecialchars((string)($m['description'] ?? ''));
                $version = htmlspecialchars((string)($m['version'] ?? ''));
                $rights = is_array($m['rights'] ?? null) ? (string)count($m['rights']) : '0';
                $routes = is_array($m['routes'] ?? null) ? (string)count($m['routes']) : '0';
                $menu = is_array($m['menu'] ?? null) ? (string)count($m['menu']) : '0';

                $rows .= "<tr>
                            <td>{$name}</td>
                            <td><code>{$slug}</code></td>
                            <td>{$version}</td>
                            <td>{$desc}</td>
                            <td class='text-end'>{$rights}</td>
                            <td class='text-end'>{$routes}</td>
                            <td class='text-end'>{$menu}</td>
                          </tr>";
            }

            if ($rows === '') {
                $rows = "<tr><td colspan='7' class='text-muted'>No modules found.</td></tr>";
            }

            $body = "<h1>Modules</h1>
                     <p class='text-muted'>Overview of loaded modules and their declared capabilities.</p>
                     <div class='table-responsive'>
                       <table class='table table-sm align-middle'>
                         <thead>
                           <tr>
                             <th>Module</th>
                             <th>Slug</th>
                             <th>Version</th>
                             <th>Description</th>
                             <th class='text-end'>Rights</th>
                             <th class='text-end'>Routes</th>
                             <th class='text-end'>Menu</th>
                           </tr>
                         </thead>
                         <tbody>{$rows}</tbody>
                       </table>
                     </div>";

            return $render('Modules', $body);
        }, ['right' => 'admin.modules']);

        $app->router->get('/admin/cache', function () use ($app, $render): Response {
            $pdo = $app->db->pdo();

            // ESI cache uses fetched_at + ttl_seconds (canonical schema)
            $esiTotal = (int)($pdo->query("SELECT COUNT(*) FROM esi_cache")->fetchColumn() ?: 0);
            $esiExpired = (int)($pdo->query("SELECT COUNT(*) FROM esi_cache WHERE DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND) < NOW()")->fetchColumn() ?: 0);

            $uniTotal = 0;
            try {
                $uniTotal = (int)($pdo->query("SELECT COUNT(*) FROM universe_entities")->fetchColumn() ?: 0);
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

        $app->router->post('/admin/cache', function () use ($app): Response {
            $pdo = $app->db->pdo();
            $action = (string)($_POST['action'] ?? '');

            $redis = RedisCache::fromConfig($app->config['redis'] ?? []);

            $msg = 'OK';

            try {
                switch ($action) {
                    case 'remove_expired':
                        $pdo->exec("DELETE FROM esi_cache WHERE DATE_ADD(fetched_at, INTERVAL ttl_seconds SECOND) < NOW()");
                        $msg = "Removed expired ESI rows";
                        break;

                    case 'purge_esi':
                        $pdo->exec("DELETE FROM esi_cache");
                        $msg = "Purged ESI cache";
                        break;

                    case 'purge_universe':
                        $pdo->exec("DELETE FROM universe_entities");
                        $msg = "Purged Universe cache";
                        break;

                    case 'purge_all':
                        $pdo->exec("DELETE FROM esi_cache");
                        $pdo->exec("DELETE FROM universe_entities");
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

        // Settings (branding / identity)
        $app->router->get('/admin/settings', function () use ($app, $render): Response {
            $settings = new Settings($app->db);

            $brandName = $settings->get('site.brand.name', 'killsineve.online') ?? 'killsineve.online';
            $type = $settings->get('site.identity.type', 'corporation') ?? 'corporation';
            $id = (int)($settings->get('site.identity.id', '0') ?? '0');

            // Build quick-pick options from logged-in character profile
            $options = [];
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid > 0) {
                $u = new Universe($app->db);
                $p = $u->characterProfile($cid);

                if (!empty($p['corporation']['id'])) {
                    $label = (string)($p['corporation']['name'] ?? 'Corporation');
                    if (!empty($p['corporation']['ticker'])) $label .= " [" . (string)$p['corporation']['ticker'] . "]";
                    $options[] = ['type' => 'corporation', 'id' => (int)$p['corporation']['id'], 'label' => $label];
                }
                if (!empty($p['alliance']['id'])) {
                    $label = (string)($p['alliance']['name'] ?? 'Alliance');
                    if (!empty($p['alliance']['ticker'])) $label .= " [" . (string)$p['alliance']['ticker'] . "]";
                    $options[] = ['type' => 'alliance', 'id' => (int)$p['alliance']['id'], 'label' => $label];
                }
            }

            $h = "<h1>Settings</h1>
                  <p class='text-muted'>Control plane for site identity, branding and platform defaults.</p>

                  <div class='card'><div class='card-body'>
                    <form method='post' action='/admin/settings/save' class='row g-3'>

                      <div class='col-12 col-lg-6'>
                        <label class='form-label'>Site name</label>
                        <input class='form-control' name='site_brand_name' value='" . htmlspecialchars($brandName) . "'>
                        <div class='form-text'>Shown top-left and used as the platform brand label.</div>
                      </div>

                      <div class='col-12 col-lg-3'>
                        <label class='form-label'>Website type</label>
                        <select class='form-select' name='site_identity_type'>
                          <option value='corporation'" . ($type==='corporation'?' selected':'') . ">Corporation</option>
                          <option value='alliance'" . ($type==='alliance'?' selected':'') . ">Alliance</option>
                        </select>
                      </div>

                      <div class='col-12 col-lg-3'>
                        <label class='form-label'>Identity ID</label>
                        <input class='form-control' name='site_identity_id' value='" . htmlspecialchars((string)$id) . "'>
                        <div class='form-text'>Used for logo + favicon. Paste an EVE corp/alliance ID, or use quick pick.</div>
                      </div>";

            if (!empty($options)) {
                $h .= "<div class='col-12'>
                         <label class='form-label'>Quick pick (from your logged-in character)</label>
                         <div class='d-flex flex-wrap gap-2'>";
                foreach ($options as $o) {
                    $oid = (int)$o['id'];
                    $logo = ($o['type'] === 'alliance')
                        ? "https://images.evetech.net/alliances/{$oid}/logo?size=32"
                        : "https://images.evetech.net/corporations/{$oid}/logo?size=32";

                    $h .= "<button type='button' class='btn btn-outline-light btn-sm'
                                  onclick=\"document.querySelector('[name=site_identity_type]').value='" . htmlspecialchars($o['type']) . "';
                                           document.querySelector('[name=site_identity_id]').value='" . $oid . "';\">
                              <img src='" . htmlspecialchars($logo) . "' style='width:18px;height:18px;border-radius:5px;margin-right:6px;'>
                              " . htmlspecialchars((string)$o['label']) . "
                           </button>";
                }
                $h .= "   </div>
                       </div>";
            }

            $h .= "      <div class='col-12'>
                        <button class='btn btn-primary'>Save settings</button>
                      </div>

                    </form>
                  </div></div>";

            return $render('Settings', $h);
        }, ['right' => 'admin.settings']);

        $app->router->post('/admin/settings/save', function () use ($app): Response {
            $settings = new Settings($app->db);

            $name = trim((string)($_POST['site_brand_name'] ?? 'killsineve.online'));
            $type = trim((string)($_POST['site_identity_type'] ?? 'corporation'));
            $id = trim((string)($_POST['site_identity_id'] ?? '0'));

            if ($name === '') $name = 'killsineve.online';
            if ($type !== 'corporation' && $type !== 'alliance') $type = 'corporation';
            if (!ctype_digit($id)) $id = '0';

            $settings->set('site.brand.name', $name);
            $settings->set('site.identity.type', $type);
            $settings->set('site.identity.id', $id);

            return Response::redirect('/admin/settings');
        }, ['right' => 'admin.settings']);


        // Rights (Groups + Permissions) — scalable UI
        $app->router->get('/admin/rights', function () use ($app, $render): Response {
            $groups = $app->db->all("SELECT id, slug, name, is_admin FROM groups ORDER BY is_admin DESC, name ASC");

            // Selected group (default: first)
            $selSlug = (string)($_GET['group'] ?? '');
            $selGroup = null;
            foreach ($groups as $g) {
                if ($selSlug !== '' && $g['slug'] === $selSlug) { $selGroup = $g; break; }
            }
            if (!$selGroup && !empty($groups)) { $selGroup = $groups[0]; $selSlug = $selGroup['slug']; }

            // Filters
            $q = trim((string)($_GET['q'] ?? ''));
            $module = trim((string)($_GET['module'] ?? ''));
            $view = trim((string)($_GET['view'] ?? 'all')); // all|granted|unassigned

            // Load rights
            $rights = $app->db->all("SELECT id, slug, description, module_slug FROM rights ORDER BY module_slug ASC, slug ASC");

            // Grants map (for selected group only)
            $grants = [];
            if ($selGroup) {
                foreach ($app->db->all("SELECT right_id FROM group_rights WHERE group_id=?", [(int)$selGroup['id']]) as $r) {
                    $grants[(int)$r['right_id']] = true;
                }
            }

            // Filter rights list
            $filtered = [];
            foreach ($rights as $r) {
                $rid = (int)$r['id'];
                $isGranted = isset($grants[$rid]);

                if ($module !== '' && $module !== 'all' && ($r['module_slug'] ?? '') !== $module) continue;
                if ($q !== '' && stripos($r['slug'] ?? '', $q) === false && stripos($r['description'] ?? '', $q) === false) continue;
                if ($view === 'granted' && !$isGranted) continue;
                if ($view === 'unassigned' && $isGranted) continue;

                $filtered[] = $r;
            }

            // Group rights by module_slug
            $byModule = [];
            foreach ($filtered as $r) {
                $ms = $r['module_slug'] ?? 'core';
                if ($ms === '') $ms = 'core';
                $byModule[$ms][] = $r;
            }

            // Build module options
            $moduleOptions = ['all' => 'All modules'];
            foreach ($rights as $r) {
                $ms = $r['module_slug'] ?? 'core';
                if ($ms === '') $ms = 'core';
                $moduleOptions[$ms] = $ms;
            }
            ksort($moduleOptions);

            $h = "<h1>Rights</h1>
                  <p class='text-muted'>Scales for large installations: manage grants per group and filter by module/search. Administrator group always bypasses checks.</p>";

            $h .= "<div class='row g-3'>";

            // Left: groups + create
            $h .= "<div class='col-lg-4'>
                    <div class='card mb-3'><div class='card-body'>
                      <h5 class='card-title'>Create group</h5>
                      <form method='post' action='/admin/rights/group-create' class='row g-2 align-items-end'>
                        <div class='col-md-6'><label class='form-label'>Slug</label><input class='form-control' name='slug' required></div>
                        <div class='col-md-6'><label class='form-label'>Name</label><input class='form-control' name='name' required></div>
                        <div class='col-md-6'><label class='form-label'>Admin override</label>
                          <select class='form-select' name='is_admin'>
                            <option value='0'>No</option><option value='1'>Yes</option>
                          </select>
                        </div>
                        <div class='col-md-6'><button class='btn btn-primary w-100' type='submit'>Create</button></div>
                      </form>
                      <div class='small text-muted mt-2'>Naming guideline: <code>admin.*</code>, <code>esi.*</code>, <code>module.&lt;slug&gt;.*</code></div>
                    </div></div>";

            // Groups list
            $h .= "<div class='card'><div class='card-body'>
                    <h5 class='card-title'>Groups</h5>
                    <div class='list-group'>";
            foreach ($groups as $g) {
                $active = ($selGroup && (int)$g['id'] === (int)$selGroup['id']) ? " active" : "";
                $badge = ((int)$g['is_admin'] === 1) ? " <span class='badge bg-warning text-dark ms-2'>admin</span>" : "";
                $h .= "<a class='list-group-item list-group-item-action{$active}' href='/admin/rights?group=" . urlencode($g['slug']) . "'>"
                    . htmlspecialchars($g['name']) . " <span class='text-muted'>(" . htmlspecialchars($g['slug']) . ")</span>{$badge}</a>";
            }
            $h .= "</div>
                  </div></div>";

            // Danger zone: delete group (no IDs shown)
            if ($selGroup && (int)$selGroup['is_admin'] !== 1 && strtolower($selGroup['slug']) !== 'admin' && strtolower($selGroup['slug']) !== 'administrator') {
                $h .= "<div class='card mt-3'><div class='card-body'>
                        <h6 class='text-danger'>Danger zone</h6>
                        <form method='post' action='/admin/rights/group-delete' onsubmit=\"return confirm('Delete group " . htmlspecialchars($selGroup['name']) . " ? This removes all assignments.');\">
                          <input type='hidden' name='group_slug' value='" . htmlspecialchars($selGroup['slug']) . "'>
                          <button class='btn btn-sm btn-danger'>Delete group</button>
                        </form>
                       </div></div>";
            } else {
                $h .= "<div class='card mt-3'><div class='card-body'>
                        <h6 class='text-muted'>Danger zone</h6>
                        <div class='text-muted small'>Administrator group cannot be deleted.</div>
                       </div></div>";
            }

            $h .= "</div>"; // /left col

            // Right: grants + filters
            $h .= "<div class='col-lg-8'>
                    <div class='card mb-3'><div class='card-body'>
                      <h5 class='card-title'>Permission grants</h5>";

            if (!$selGroup) {
                $h .= "<div class='text-muted'>No groups found.</div>";
            } else {
                $h .= "<div class='text-muted mb-2'>Group: <strong>" . htmlspecialchars($selGroup['name']) . "</strong> <span class='text-muted'>(" . htmlspecialchars($selGroup['slug']) . ")</span></div>";

                // Filters form (GET)
                $h .= "<form method='get' action='/admin/rights' class='row g-2 align-items-end'>
                        <input type='hidden' name='group' value='" . htmlspecialchars($selGroup['slug']) . "'>
                        <div class='col-md-5'><label class='form-label'>Search</label>
                          <input class='form-control' name='q' value='" . htmlspecialchars($q) . "' placeholder='admin.users, module.killfeed...'>
                        </div>
                        <div class='col-md-4'><label class='form-label'>Module</label>
                          <select class='form-select' name='module'>";
                foreach ($moduleOptions as $k => $label) {
                    $sel = ($module === $k || ($module === '' && $k === 'all')) ? " selected" : "";
                    $h .= "<option value='" . htmlspecialchars((string)$k) . "'{$sel}>" . htmlspecialchars((string)$label) . "</option>";
                }
                $h .= "     </select>
                        </div>
                        <div class='col-md-3'><label class='form-label'>View</label>
                          <select class='form-select' name='view'>
                            <option value='all'" . ($view==='all'?' selected':'') . ">All</option>
                            <option value='granted'" . ($view==='granted'?' selected':'') . ">Granted</option>
                            <option value='unassigned'" . ($view==='unassigned'?' selected':'') . ">Unassigned</option>
                          </select>
                        </div>
                        <div class='col-12 d-flex gap-2'>
                          <button class='btn btn-outline-primary btn-sm' type='submit'>Apply filters</button>
                          <a class='btn btn-outline-secondary btn-sm' href='/admin/rights?group=" . urlencode($selGroup['slug']) . "'>Reset filters</a>
                        </div>
                      </form>";

                // Grants form (POST)
                $h .= "<form method='post' action='/admin/rights/group-save' class='mt-3'>
                        <input type='hidden' name='group_slug' value='" . htmlspecialchars($selGroup['slug']) . "'>";

                if (empty($byModule)) {
                    $h .= "<div class='text-muted mt-3'>No rights match the filters.</div>";
                } else {
                    foreach ($byModule as $ms => $list) {
                        $h .= "<details class='card mt-2' open>
                                <summary class='card-body d-flex justify-content-between align-items-center'>
                                  <div><strong>" . htmlspecialchars($ms) . "</strong> <span class='text-muted'>(" . count($list) . ")</span></div>
                                </summary>
                                <div class='card-body pt-0'>";
                        foreach ($list as $r) {
                            $rid = (int)$r['id'];
                            $checked = isset($grants[$rid]) ? " checked" : "";
                            $desc = trim((string)($r['description'] ?? ''));

                            // HTML element id only (not displayed)
                            $elId = 'r_' . preg_replace('/[^a-z0-9_]+/i', '_', (string)$r['slug']);

                            $h .= "<div class='form-check my-1'>
                                    <input class='form-check-input' type='checkbox' name='right_slugs[]' value='" . htmlspecialchars((string)$r['slug']) . "' id='{$elId}'{$checked}>
                                    <label class='form-check-label' for='{$elId}'><strong>" . htmlspecialchars((string)$r['slug']) . "</strong>"
                                    . ($desc !== '' ? "<div class='small text-muted'>" . htmlspecialchars($desc) . "</div>" : "")
                                    . "</label>
                                  </div>";
                        }
                        $h .= "    </div>
                              </details>";
                    }
                    $h .= "<div class='mt-3'><button class='btn btn-primary'>Save grants</button></div>";
                }

                $h .= "</form>";

                // Explain access (no IDs)
                $exChar = trim((string)($_GET['ex_char'] ?? ''));
                $exRight = trim((string)($_GET['ex_right'] ?? ''));
                $h .= "<div class='card mt-4'><div class='card-body'>
                        <h5 class='card-title'>Explain access</h5>
                        <p class='text-muted'>Troubleshoot why a character can/can't access a right (which group grants it, or whether an override applies). No IDs required.</p>
                        <form method='get' action='/admin/rights' class='row g-2 align-items-end'>
                          <input type='hidden' name='group' value='" . htmlspecialchars($selGroup['slug']) . "'>
                          <div class='col-md-6'><label class='form-label'>Character name</label><input class='form-control' name='ex_char' value='" . htmlspecialchars($exChar) . "' placeholder='e.g. Lellebel'></div>
                          <div class='col-md-6'><label class='form-label'>Right slug</label><input class='form-control' name='ex_right' value='" . htmlspecialchars($exRight) . "' placeholder='e.g. admin.users'></div>
                          <div class='col-12'><button class='btn btn-outline-primary btn-sm'>Explain</button></div>
                        </form>";

                if ($exChar !== '' && $exRight !== '') {
                    $user = $app->db->one("SELECT id, character_name, is_superadmin FROM eve_users WHERE character_name = ? LIMIT 1", [$exChar]);
                    if (!$user) {
                        $user = $app->db->one("SELECT id, character_name, is_superadmin FROM eve_users WHERE character_name LIKE ? ORDER BY character_name ASC LIMIT 1", ['%' . $exChar . '%']);
                    }
                    $right = $app->db->one("SELECT id, slug FROM rights WHERE slug = ? LIMIT 1", [$exRight]);

                    if (!$user) {
                        $h .= "<div class='alert alert-warning mt-3'>No user found for character name <strong>" . htmlspecialchars($exChar) . "</strong>.</div>";
                    } elseif (!$right) {
                        $h .= "<div class='alert alert-warning mt-3'>Right <strong>" . htmlspecialchars($exRight) . "</strong> not found.</div>";
                    } else {
                        $uid = (int)$user['id'];
                        $rid = (int)$right['id'];

                        $ug = $app->db->all(
                            "SELECT g.slug, g.name, g.is_admin
                             FROM eve_user_groups eug
                             JOIN groups g ON g.id = eug.group_id
                             WHERE eug.user_id = ?
                             ORDER BY g.is_admin DESC, g.name ASC",
                            [$uid]
                        );

                        $hasAdminGroup = false;
                        foreach ($ug as $g) {
                            if ((int)$g['is_admin'] === 1 || strtolower((string)$g['slug']) === 'admin' || strtolower((string)$g['slug']) === 'administrator') {
                                $hasAdminGroup = true;
                            }
                        }

                        $rows = $app->db->all(
                            "SELECT g.slug, g.name
                             FROM group_rights gr
                             JOIN groups g ON g.id = gr.group_id
                             WHERE gr.right_id = ?
                             ORDER BY g.name ASC",
                            [$rid]
                        );

                        $grantsFrom = [];
                        foreach ($rows as $g) $grantsFrom[] = $g['name'] . " (" . $g['slug'] . ")";

                        $decision = 'DENY';
                        $reason = 'No matching grant.';
                        if ((int)($user['is_superadmin'] ?? 0) === 1) { $decision = 'ALLOW'; $reason = 'Superadmin override.'; }
                        elseif ($hasAdminGroup) { $decision = 'ALLOW'; $reason = 'Administrator group override.'; }
                        else {
                            $ok = $app->db->one(
                                "SELECT 1
                                 FROM eve_user_groups eug
                                 JOIN group_rights gr ON gr.group_id = eug.group_id
                                 WHERE eug.user_id = ? AND gr.right_id = ?
                                 LIMIT 1",
                                [$uid, $rid]
                            );
                            if ($ok) { $decision = 'ALLOW'; $reason = 'Granted via group membership.'; }
                        }

                        $h .= "<div class='alert " . ($decision==='ALLOW'?'alert-success':'alert-danger') . " mt-3'>
                                <strong>Decision:</strong> {$decision} &nbsp; <span class='text-muted'>" . htmlspecialchars($reason) . "</span>
                               </div>";

                        $h .= "<div class='mt-2'><strong>Character:</strong> " . htmlspecialchars((string)$user['character_name']) . "</div>";
                        $h .= "<div class='mt-1'><strong>Right:</strong> " . htmlspecialchars((string)$right['slug']) . "</div>";

                        $h .= "<div class='mt-3'><strong>User groups</strong><ul class='mb-0'>";
                        foreach ($ug as $g) {
                            $h .= "<li>" . htmlspecialchars((string)$g['name']) . " <span class='text-muted'>(" . htmlspecialchars((string)$g['slug']) . ")</span>" . ((int)$g['is_admin']===1 ? " <span class='badge bg-warning text-dark'>admin</span>" : "") . "</li>";
                        }
                        if (empty($ug)) $h .= "<li class='text-muted'>None</li>";
                        $h .= "</ul></div>";

                        $h .= "<div class='mt-3'><strong>Groups that grant this right</strong><ul class='mb-0'>";
                        foreach ($grantsFrom as $s) $h .= "<li>" . htmlspecialchars($s) . "</li>";
                        if (empty($grantsFrom)) $h .= "<li class='text-muted'>None</li>";
                        $h .= "</ul></div>";
                    }
                }

                $h .= "</div></div>";
            }

            $h .= "</div></div></div>"; // /right col & row

            // IMPORTANT: render via Layout wrapper so styling/nav loads
            return $render('Rights', $h);
        }, ['right' => 'admin.rights']);

        $app->router->post('/admin/rights/group-create', function () use ($app): Response {
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $isAdmin = (int)($_POST['is_admin'] ?? 0) === 1 ? 1 : 0;

            if ($slug === '' || $name === '') return Response::redirect('/admin/rights');
            if (in_array($slug, ['administrator'], true)) $slug = 'admin-' . $slug;

            $app->db->run(
                "INSERT INTO groups (slug, name, is_admin) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), is_admin=VALUES(is_admin)",
                [$slug, $name, $isAdmin]
            );
            (new Rights($app->db))->bumpGlobalVersion();

            return Response::redirect('/admin/rights?group=' . urlencode($slug));
        }, ['right' => 'admin.rights']);

        // Save grants for selected group (by slugs, no IDs)
        $app->router->post('/admin/rights/group-save', function () use ($app): Response {
            $groupSlug = trim((string)($_POST['group_slug'] ?? ''));
            if ($groupSlug === '') return Response::redirect('/admin/rights');

            $group = $app->db->one("SELECT id, slug FROM groups WHERE slug=? LIMIT 1", [$groupSlug]);
            if (!$group) return Response::redirect('/admin/rights');

            $rightSlugs = $_POST['right_slugs'] ?? [];
            if (!is_array($rightSlugs)) $rightSlugs = [];

            $app->db->begin();
            try {
                $app->db->run("DELETE FROM group_rights WHERE group_id=?", [(int)$group['id']]);

                if (!empty($rightSlugs)) {
                    $placeholders = implode(',', array_fill(0, count($rightSlugs), '?'));
                    $ids = $app->db->all("SELECT id FROM rights WHERE slug IN ($placeholders)", array_values($rightSlugs));
                    foreach ($ids as $row) {
                        $app->db->run(
                            "INSERT IGNORE INTO group_rights (group_id, right_id) VALUES (?,?)",
                            [(int)$group['id'], (int)$row['id']]
                        );
                    }
                }

                $app->db->commit();
            } catch (\Throwable $e) {
                $app->db->rollback();
                throw $e;
            }
            (new Rights($app->db))->bumpGlobalVersion();

            return Response::redirect('/admin/rights?group=' . urlencode($groupSlug));
        }, ['right' => 'admin.rights']);

        // Delete group by slug (no IDs). Admin group cannot be deleted.
        $app->router->post('/admin/rights/group-delete', function () use ($app): Response {
            $slug = strtolower(trim((string)($_POST['group_slug'] ?? '')));
            if ($slug === '') return Response::redirect('/admin/rights');

            $group = $app->db->one("SELECT id, slug, name, is_admin FROM groups WHERE slug=? LIMIT 1", [$slug]);
            if (!$group) return Response::redirect('/admin/rights');

            if ((int)$group['is_admin'] === 1 || $slug === 'admin' || $slug === 'administrator') {
                return Response::redirect('/admin/rights?group=' . urlencode($slug));
            }

            $app->db->begin();
            try {
                $app->db->run("DELETE FROM group_rights WHERE group_id=?", [(int)$group['id']]);
                $app->db->run("DELETE FROM eve_user_groups WHERE group_id=?", [(int)$group['id']]);
                $app->db->run("DELETE FROM groups WHERE id=?", [(int)$group['id']]);
                $app->db->commit();
            } catch (\Throwable $e) {
                $app->db->rollback();
                throw $e;
            }
            (new Rights($app->db))->bumpGlobalVersion();

            return Response::redirect('/admin/rights');
        }, ['right' => 'admin.rights']);

        // Users: assign groups
        $app->router->get('/admin/users', function () use ($app, $render): Response {
            $users = $app->db->all("SELECT id, character_id, character_name, is_superadmin, created_at FROM eve_users ORDER BY id DESC LIMIT 200");
            $groups = $app->db->all("SELECT id, slug, name, is_admin FROM groups ORDER BY is_admin DESC, name ASC");
            $ug = [];
            foreach ($app->db->all("SELECT user_id, group_id FROM eve_user_groups") as $r) {
                $ug[(int)$r['user_id']][(int)$r['group_id']] = true;
            }

            $h = "<h1>Users</h1>
                  <p class='text-muted'>Assign groups to users. Admin group and superadmin flag always override.</p>";
            $h .= "<div class='table-responsive'><table class='table table-sm table-striped align-middle'>
                    <thead><tr>
                      <th>User</th><th>Flags</th><th>Groups</th><th>Action</th>
                    </tr></thead><tbody>";

            foreach ($users as $u) {
                $uid = (int)$u['id'];
                $flags = [];
                if ((int)$u['is_superadmin'] === 1) $flags[] = "<span class='badge text-bg-danger'>superadmin</span>";
                $flagsHtml = $flags ? implode(' ', $flags) : "<span class='badge text-bg-secondary'>standard</span>";

                $h .= "<tr><td><strong>" . htmlspecialchars($u['character_name']) . "</strong>
                           <div class='text-muted small'>user_id={$uid} • character_id=" . (int)$u['character_id'] . "</div>
                        </td>
                        <td>{$flagsHtml}</td>
                        <td>
                          <form method='post' action='/admin/users/save' class='row g-2'>
                            <input type='hidden' name='user_id' value='{$uid}'>";

                foreach ($groups as $g) {
                    $gid = (int)$g['id'];
                    $checked = !empty($ug[$uid][$gid]) ? "checked" : "";
                    $label = htmlspecialchars($g['name']);
                    $h .= "<div class='col-12 col-md-6 col-xl-4'>
                              <div class='form-check'>
                                <input class='form-check-input' type='checkbox' name='group_ids[]' value='{$gid}' id='u{$uid}g{$gid}' {$checked}>
                                <label class='form-check-label' for='u{$uid}g{$gid}'>{$label}</label>
                              </div>
                            </div>";
                }

                $h .= "</td>
                        <td><button class='btn btn-sm btn-success' type='submit'>Save</button></td>
                          </form>
                      </tr>";
            }

            $h .= "</tbody></table></div>";
            return $render('Users', $h);
        }, ['right' => 'admin.users']);

        $app->router->post('/admin/users/save', function (Request $req) use ($app): Response {
            $uid = (int)($req->post['user_id'] ?? 0);
            if ($uid <= 0) return Response::redirect('/admin/users');
            $ids = $req->post['group_ids'] ?? [];
            if (!is_array($ids)) $ids = [];

            $app->db->run("DELETE FROM eve_user_groups WHERE user_id=?", [$uid]);
            foreach ($ids as $gid) {
                $gid = (int)$gid;
                if ($gid <= 0) continue;
                $app->db->run("INSERT IGNORE INTO eve_user_groups (user_id, group_id) VALUES (?, ?)", [$uid, $gid]);
            }
            (new Rights($app->db))->bumpGlobalVersion();
            return Response::redirect('/admin/users');
        }, ['right' => 'admin.users']);

        $app->router->get('/admin/menu', function () use ($app, $render): Response {
            $menuRows = $app->db->all(
                "SELECT r.slug,
                        r.title AS r_title,
                        r.url AS r_url,
                        r.parent_slug AS r_parent_slug,
                        r.sort_order AS r_sort_order,
                        r.area AS r_area,
                        r.right_slug AS r_right_slug,
                        r.enabled AS r_enabled,
                        o.title AS o_title,
                        o.url AS o_url,
                        o.parent_slug AS o_parent_slug,
                        o.sort_order AS o_sort_order,
                        o.area AS o_area,
                        o.right_slug AS o_right_slug,
                        o.enabled AS o_enabled
                 FROM menu_registry r
                 LEFT JOIN menu_overrides o ON o.slug = r.slug
                 ORDER BY COALESCE(o.area, r.area) ASC,
                          COALESCE(o.sort_order, r.sort_order) ASC,
                          r.slug ASC"
            );

            $rights = $app->db->all("SELECT slug FROM rights ORDER BY slug ASC");
            $rightOptions = array_map(fn($r) => (string)$r['slug'], $rights);

            $allSlugs = array_map(fn($r) => (string)$r['slug'], $menuRows);
            sort($allSlugs);

            $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
            $msgHtml = $msg !== '' ? "<div class='alert alert-info'>" . htmlspecialchars($msg) . "</div>" : "";

            $h = $msgHtml . "<h1>Menu Editor</h1>
                  <p class='text-muted'>Adjust menu overrides without changing module defaults. Leave fields blank to revert to defaults.</p>";

            $h .= "<div class='table-responsive'><table class='table table-sm table-striped align-middle'>
                    <thead>
                      <tr>
                        <th>Slug</th>
                        <th>Effective</th>
                        <th>Override</th>
                      </tr>
                    </thead><tbody>";

            foreach ($menuRows as $row) {
                $slug = (string)$row['slug'];
                $effectiveTitle = $row['o_title'] ?? $row['r_title'];
                $effectiveUrl = $row['o_url'] ?? $row['r_url'];
                $effectiveParent = $row['o_parent_slug'] ?? $row['r_parent_slug'];
                $effectiveSort = $row['o_sort_order'] ?? $row['r_sort_order'];
                $effectiveArea = $row['o_area'] ?? $row['r_area'];
                $effectiveRight = $row['o_right_slug'] ?? $row['r_right_slug'];
                $effectiveEnabled = $row['o_enabled'] ?? $row['r_enabled'];

                $hasOverride = $row['o_title'] !== null
                    || $row['o_url'] !== null
                    || $row['o_parent_slug'] !== null
                    || $row['o_sort_order'] !== null
                    || $row['o_area'] !== null
                    || $row['o_right_slug'] !== null
                    || $row['o_enabled'] !== null;

                $overrideBadge = $hasOverride ? "<span class='badge text-bg-warning ms-2'>override</span>" : "";

                $defaultBits = [
                    "Title: " . htmlspecialchars((string)$row['r_title']),
                    "URL: " . htmlspecialchars((string)$row['r_url']),
                    "Area: " . htmlspecialchars((string)$row['r_area']),
                    "Sort: " . (int)$row['r_sort_order'],
                    "Right: " . htmlspecialchars((string)($row['r_right_slug'] ?? '—')),
                    "Parent: " . htmlspecialchars((string)($row['r_parent_slug'] ?? '—')),
                    "Enabled: " . ((int)$row['r_enabled'] === 1 ? 'yes' : 'no'),
                ];

                $effectiveBits = [
                    "Title: " . htmlspecialchars((string)$effectiveTitle),
                    "URL: " . htmlspecialchars((string)$effectiveUrl),
                    "Area: " . htmlspecialchars((string)$effectiveArea),
                    "Sort: " . (int)$effectiveSort,
                    "Right: " . htmlspecialchars((string)($effectiveRight ?? '—')),
                    "Parent: " . htmlspecialchars((string)($effectiveParent ?? '—')),
                    "Enabled: " . ((int)$effectiveEnabled === 1 ? 'yes' : 'no'),
                ];

                $titleVal = htmlspecialchars((string)($row['o_title'] ?? ''));
                $urlVal = htmlspecialchars((string)($row['o_url'] ?? ''));
                $parentVal = htmlspecialchars((string)($row['o_parent_slug'] ?? ''));
                $sortVal = htmlspecialchars((string)($row['o_sort_order'] ?? ''));
                $rightVal = htmlspecialchars((string)($row['o_right_slug'] ?? ''));
                $areaVal = (string)($row['o_area'] ?? '');
                $enabledVal = ($row['o_enabled'] === null) ? '' : (string)$row['o_enabled'];

                $h .= "<tr>
                        <td><strong>" . htmlspecialchars($slug) . "</strong>{$overrideBadge}
                          <div class='small text-muted'>Default: " . implode(" • ", $defaultBits) . "</div>
                        </td>
                        <td>
                          <div class='small'>" . implode(" • ", $effectiveBits) . "</div>
                        </td>
                        <td>
                          <form method='post' action='/admin/menu/save' class='row g-2'>
                            <input type='hidden' name='slug' value='" . htmlspecialchars($slug) . "'>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>Title</label>
                              <input class='form-control form-control-sm' name='title' value='{$titleVal}' placeholder='" . htmlspecialchars((string)$row['r_title']) . "'>
                            </div>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>URL</label>
                              <input class='form-control form-control-sm' name='url' value='{$urlVal}' placeholder='" . htmlspecialchars((string)$row['r_url']) . "'>
                            </div>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>Parent slug</label>
                              <input class='form-control form-control-sm' name='parent_slug' value='{$parentVal}' list='menu-parent-slugs' placeholder='" . htmlspecialchars((string)($row['r_parent_slug'] ?? '')) . "'>
                            </div>
                            <div class='col-6 col-md-3'>
                              <label class='form-label'>Sort</label>
                              <input class='form-control form-control-sm' type='number' name='sort_order' value='{$sortVal}' placeholder='" . (int)$row['r_sort_order'] . "'>
                            </div>
                            <div class='col-6 col-md-3'>
                              <label class='form-label'>Area</label>
                              <select class='form-select form-select-sm' name='area'>
                                <option value='' " . ($areaVal === '' ? 'selected' : '') . ">Default (" . htmlspecialchars((string)$row['r_area']) . ")</option>
                                <option value='left' " . ($areaVal === 'left' ? 'selected' : '') . ">left</option>
                                <option value='admin_top' " . ($areaVal === 'admin_top' ? 'selected' : '') . ">admin_top</option>
                                <option value='user_top' " . ($areaVal === 'user_top' ? 'selected' : '') . ">user_top</option>
                              </select>
                            </div>
                            <div class='col-12 col-md-6'>
                              <label class='form-label'>Right slug</label>
                              <input class='form-control form-control-sm' name='right_slug' value='{$rightVal}' list='right-slugs' placeholder='" . htmlspecialchars((string)($row['r_right_slug'] ?? '')) . "'>
                            </div>
                            <div class='col-6 col-md-3'>
                              <label class='form-label'>Enabled</label>
                              <select class='form-select form-select-sm' name='enabled'>
                                <option value='' " . ($enabledVal === '' ? 'selected' : '') . ">Default (" . ((int)$row['r_enabled'] === 1 ? 'yes' : 'no') . ")</option>
                                <option value='1' " . ($enabledVal === '1' ? 'selected' : '') . ">Enabled</option>
                                <option value='0' " . ($enabledVal === '0' ? 'selected' : '') . ">Disabled</option>
                              </select>
                            </div>
                            <div class='col-6 col-md-3 d-flex align-items-end gap-2'>
                              <button class='btn btn-sm btn-success' type='submit'>Save</button>
                              <button class='btn btn-sm btn-outline-secondary' type='submit' name='action' value='reset'>Reset</button>
                            </div>
                          </form>
                        </td>
                      </tr>";
            }

            $h .= "</tbody></table></div>";

            if (!empty($allSlugs)) {
                $h .= "<datalist id='menu-parent-slugs'>";
                foreach ($allSlugs as $slug) {
                    $h .= "<option value='" . htmlspecialchars($slug) . "'>";
                }
                $h .= "</datalist>";
            }

            if (!empty($rightOptions)) {
                $h .= "<datalist id='right-slugs'>";
                foreach ($rightOptions as $slug) {
                    $h .= "<option value='" . htmlspecialchars($slug) . "'>";
                }
                $h .= "</datalist>";
            }

            return $render('Menu Editor', $h);
        }, ['right' => 'admin.menu']);

        $app->router->post('/admin/menu/save', function (Request $req) use ($app): Response {
            $slug = trim((string)($req->post['slug'] ?? ''));
            if ($slug === '') return Response::redirect('/admin/menu?msg=' . rawurlencode('Missing menu slug.'));

            $action = trim((string)($req->post['action'] ?? ''));
            if ($action === 'reset') {
                $app->db->run("DELETE FROM menu_overrides WHERE slug=?", [$slug]);
                return Response::redirect('/admin/menu?msg=' . rawurlencode("Overrides reset for {$slug}."));
            }

            $title = trim((string)($req->post['title'] ?? ''));
            $url = trim((string)($req->post['url'] ?? ''));
            $parent = trim((string)($req->post['parent_slug'] ?? ''));
            $sortRaw = trim((string)($req->post['sort_order'] ?? ''));
            $area = trim((string)($req->post['area'] ?? ''));
            $right = trim((string)($req->post['right_slug'] ?? ''));
            $enabledRaw = trim((string)($req->post['enabled'] ?? ''));

            $title = $title === '' ? null : $title;
            $url = $url === '' ? null : $url;
            $parent = $parent === '' ? null : $parent;
            $right = $right === '' ? null : $right;

            $sort = null;
            if ($sortRaw !== '' && is_numeric($sortRaw)) {
                $sort = (int)$sortRaw;
            }

            $area = $area === '' ? null : $area;
            $allowedAreas = ['left', 'admin_top', 'user_top'];
            if ($area !== null && !in_array($area, $allowedAreas, true)) {
                $area = null;
            }

            $enabled = null;
            if ($enabledRaw === '0' || $enabledRaw === '1') {
                $enabled = (int)$enabledRaw;
            }

            if ($title === null && $url === null && $parent === null && $sort === null && $area === null && $right === null && $enabled === null) {
                $app->db->run("DELETE FROM menu_overrides WHERE slug=?", [$slug]);
                return Response::redirect('/admin/menu?msg=' . rawurlencode("Overrides cleared for {$slug}."));
            }

            $app->db->run(
                "INSERT INTO menu_overrides (slug, title, url, parent_slug, sort_order, area, right_slug, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   title=VALUES(title),
                   url=VALUES(url),
                   parent_slug=VALUES(parent_slug),
                   sort_order=VALUES(sort_order),
                   area=VALUES(area),
                   right_slug=VALUES(right_slug),
                   enabled=VALUES(enabled)",
                [$slug, $title, $url, $parent, $sort, $area, $right, $enabled]
            );

            return Response::redirect('/admin/menu?msg=' . rawurlencode("Overrides saved for {$slug}."));
        }, ['right' => 'admin.menu']);

        $app->modules->loadAll($app);
        return $app;
    }

    public function handleHttp(): Response
    {
        $req = Request::fromGlobals();
        return $this->router->dispatch($req);
    }
}
