<?php
declare(strict_types=1);

/*
Module Name: Fittings
Description: Fittings and doctrines management with EFT import/export.
Version: 1.0.0
*/

use App\Core\App;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Identifiers;
use App\Core\Universe;
use App\Corptools\Cron\JobRegistry;
use App\Fittings\EftParser;
use App\Fittings\TypeResolver;
use App\Http\Request;
use App\Http\Response;

require_once __DIR__ . '/functions.php';

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('fittings.access_fittings', 'Access the fittings module.');
    $registry->right('fittings.manage', 'Manage fittings, doctrines, and categories.');

    $registry->menu([
        'slug' => 'fittings',
        'title' => 'Fittings',
        'url' => '/fittings',
        'sort_order' => 40,
        'area' => 'left_member',
        'right_slug' => 'fittings.access_fittings',
    ]);

    $registry->menu([
        'slug' => 'admin.fittings',
        'title' => 'Fittings',
        'url' => '/admin/fittings',
        'sort_order' => 60,
        'area' => 'site_admin_top',
        'right_slug' => 'fittings.manage',
    ]);

    $registry->menu([
        'slug' => 'module.fittings',
        'title' => 'Fittings',
        'url' => '/fittings',
        'sort_order' => 10,
        'area' => 'module_top',
        'allowed_areas' => ['top_left', 'left'],
        'right_slug' => 'fittings.access_fittings',
    ]);
    $registry->menu([
        'slug' => 'module.fittings.browse',
        'title' => 'Browse',
        'url' => '/fittings',
        'sort_order' => 11,
        'area' => 'module_top',
        'parent_slug' => 'module.fittings',
        'allowed_areas' => ['top_left', 'left'],
        'right_slug' => 'fittings.access_fittings',
    ]);
    $registry->menu([
        'slug' => 'module.fittings.doctrines',
        'title' => 'Doctrines',
        'url' => '/fittings/doctrines',
        'sort_order' => 12,
        'area' => 'module_top',
        'parent_slug' => 'module.fittings',
        'allowed_areas' => ['top_left', 'left'],
        'right_slug' => 'fittings.access_fittings',
    ]);
    $registry->menu([
        'slug' => 'module.fittings.categories',
        'title' => 'Categories',
        'url' => '/fittings/categories',
        'sort_order' => 13,
        'area' => 'module_top',
        'parent_slug' => 'module.fittings',
        'allowed_areas' => ['top_left', 'left'],
        'right_slug' => 'fittings.access_fittings',
    ]);
    $registry->menu([
        'slug' => 'module.fittings.skills_check',
        'title' => 'Skills Check',
        'url' => '/fittings/skills-check',
        'sort_order' => 14,
        'area' => 'module_top',
        'parent_slug' => 'module.fittings',
        'allowed_areas' => ['top_left', 'left'],
        'right_slug' => 'fittings.access_fittings',
    ]);

    $csrfToken = fn(string $key): string => fittings_csrf_token($key);
    $csrfCheck = fn(string $key, ?string $token): bool => fittings_csrf_check($key, $token);

    $renderPage = fn(string $title, string $bodyHtml): string => fittings_render_page($app, $title, $bodyHtml);

    $generateSlug = fn(string $table, string $name): string => fittings_generate_slug($app->db, $table, $name);

    $requireLogin = fn(): ?Response => fittings_require_login();

    $requireRight = fn(string $right): ?Response => fittings_require_right($app, $right);

    $logAudit = function (int $userId, string $action, string $entityType, int $entityId, string $message, array $meta = []) use ($app): void {
        fittings_log_audit($app->db, $userId, $action, $entityType, $entityId, $message, $meta);
    };

    $getUserGroups = fn(int $userId): array => fittings_get_user_groups($app->db, $userId);

    $getVisibleCategories = function (int $userId, int $characterId) use ($app, $getUserGroups): array {
        $cats = db_all($app->db, 
            "SELECT id, slug, name, description, visibility_scope, visibility_org_id\n"
            . " FROM module_fittings_categories WHERE is_active=1 ORDER BY name ASC"
        );
        if (empty($cats)) return [];

        $groupIds = $getUserGroups($userId);
        $groupMap = [];
        if (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $rows = db_all($app->db, 
                "SELECT category_id, group_id FROM module_fittings_category_groups\n"
                . " WHERE group_id IN ({$placeholders})",
                $groupIds
            );
            foreach ($rows as $row) {
                $groupMap[(int)$row['category_id']][] = (int)$row['group_id'];
            }
        }

        $profile = [];
        if ($characterId > 0) {
            $u = new Universe($app->db);
            $profile = $u->characterProfile($characterId);
        }
        $corpId = (int)($profile['corporation']['id'] ?? 0);
        $allianceId = (int)($profile['alliance']['id'] ?? 0);

        $visible = [];
        foreach ($cats as $cat) {
            $catId = (int)($cat['id'] ?? 0);
            $scope = (string)($cat['visibility_scope'] ?? 'all');
            $scopeId = (int)($cat['visibility_org_id'] ?? 0);

            if ($scope === 'corp') {
                if ($corpId <= 0) continue;
                if ($scopeId > 0 && $corpId !== $scopeId) continue;
            } elseif ($scope === 'alliance') {
                if ($allianceId <= 0) continue;
                if ($scopeId > 0 && $allianceId !== $scopeId) continue;
            }

            $allowedGroups = $groupMap[$catId] ?? [];
            if (!empty($allowedGroups) && empty(array_intersect($allowedGroups, $groupIds))) {
                continue;
            }

            $visible[] = $cat;
        }

        return $visible;
    };

    $parseEft = function (string $eftText): array {
        $parser = new EftParser();
        return $parser->parse($eftText);
    };

    $buildBuyAll = function (array $items): array {
        $flat = [];
        foreach ($items as $item) {
            $name = (string)($item['name'] ?? '');
            if ($name === '') continue;
            $qty = (int)($item['quantity'] ?? 1);
            $flat[$name] = ($flat[$name] ?? 0) + max(1, $qty);
        }
        $lines = [];
        foreach ($flat as $name => $qty) {
            $lines[] = $name . " x" . $qty;
        }
        sort($lines);
        return $lines;
    };

    $resolveFitTypeIds = function (array $parsed) use ($app): array {
        $resolver = new TypeResolver($app->db);
        $typeIds = [];
        foreach ($parsed['items'] ?? [] as $item) {
            $name = (string)($item['name'] ?? '');
            if ($name === '') continue;
            $typeIds[$name] = $resolver->resolveTypeId($name);
        }
        $shipName = (string)($parsed['ship'] ?? '');
        $shipTypeId = $shipName !== '' ? $resolver->resolveTypeId($shipName) : null;
        return ['ship_type_id' => $shipTypeId, 'item_type_ids' => $typeIds];
    };

    $buildEsiItems = function (array $parsed, array $typeIds): array {
        $slotCounters = [
            'low' => 0,
            'mid' => 0,
            'high' => 0,
            'rig' => 0,
            'subsystem' => 0,
        ];
        $items = [];
        foreach ($parsed['items'] ?? [] as $item) {
            $name = (string)($item['name'] ?? '');
            $qty = (int)($item['quantity'] ?? 1);
            $section = (string)($item['section'] ?? 'cargo');
            $typeId = $typeIds['item_type_ids'][$name] ?? null;
            if (!$typeId) {
                continue;
            }
            $flag = 'Cargo';
            if (in_array($section, ['low', 'mid', 'high', 'rig', 'subsystem'], true)) {
                $index = $slotCounters[$section]++;
                $flag = match ($section) {
                    'low' => 'LoSlot' . $index,
                    'mid' => 'MedSlot' . $index,
                    'high' => 'HiSlot' . $index,
                    'rig' => 'RigSlot' . $index,
                    'subsystem' => 'SubSystemSlot' . $index,
                    default => 'Cargo',
                };
            } elseif ($section === 'drone') {
                $flag = 'DroneBay';
            } elseif ($section === 'cargo') {
                $flag = 'Cargo';
            }

            $items[] = [
                'type_id' => (int)$typeId,
                'quantity' => max(1, $qty),
                'flag' => $flag,
            ];
        }
        return $items;
    };

    $registry->route('GET', '/fittings', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $getVisibleCategories): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $categories = $getVisibleCategories($uid, $cid);
        $catIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $categories)));
        $categoryBySlug = [];
        foreach ($categories as $cat) {
            $slug = (string)($cat['slug'] ?? '');
            if ($slug !== '') {
                $categoryBySlug[$slug] = (int)($cat['id'] ?? 0);
            }
        }

        $search = trim((string)($req->query['search'] ?? ''));
        $categoryFilter = trim((string)($req->query['category'] ?? ''));
        $doctrineFilter = trim((string)($req->query['doctrine'] ?? ''));
        $shipFilter = trim((string)($req->query['ship'] ?? ''));
        $scopeFilter = (string)($req->query['scope'] ?? '');
        $favoritesOnly = !empty($req->query['favorites']);
        $page = max(1, (int)($req->query['page'] ?? 1));
        $perPage = 12;
        $offset = ($page - 1) * $perPage;
        $doctrines = db_all($app->db, "SELECT id, slug, name FROM module_fittings_doctrines WHERE is_active=1 ORDER BY name ASC");
        $doctrineBySlug = [];
        foreach ($doctrines as $doc) {
            $slug = (string)($doc['slug'] ?? '');
            if ($slug !== '') {
                $doctrineBySlug[$slug] = (int)($doc['id'] ?? 0);
            }
        }

        $where = [];
        $params = [];
        $categoryFilterId = $categoryFilter !== '' ? ($categoryBySlug[$categoryFilter] ?? 0) : 0;
        $doctrineFilterId = $doctrineFilter !== '' ? ($doctrineBySlug[$doctrineFilter] ?? 0) : 0;
        if ($scopeFilter === 'corp' || $scopeFilter === 'alliance') {
            $catIds = array_values(array_filter($catIds, function (int $catId) use ($categories, $scopeFilter): bool {
                foreach ($categories as $cat) {
                    if ((int)($cat['id'] ?? 0) === $catId) {
                        return (string)($cat['visibility_scope'] ?? 'all') === $scopeFilter;
                    }
                }
                return false;
            }));
        }

        if (!empty($catIds)) {
            $where[] = "f.category_id IN (" . implode(',', array_fill(0, count($catIds), '?')) . ")";
            $params = array_merge($params, $catIds);
        } else {
            $where[] = "1=0";
        }
        if ($search !== '') {
            $where[] = "(f.name LIKE ? OR f.ship_name LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($categoryFilterId > 0) {
            $where[] = "f.category_id = ?";
            $params[] = $categoryFilterId;
        }
        if ($doctrineFilterId > 0) {
            $where[] = "f.doctrine_id = ?";
            $params[] = $doctrineFilterId;
        }
        if ($shipFilter !== '') {
            $where[] = "f.ship_name LIKE ?";
            $params[] = '%' . $shipFilter . '%';
        }
        if ($favoritesOnly) {
            $where[] = "fav.user_id = ?";
            $params[] = $uid;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $totalRow = db_one($app->db, 
            "SELECT COUNT(*) AS total\n"
            . " FROM module_fittings_fits f\n"
            . " LEFT JOIN module_fittings_favorites fav ON fav.fit_id = f.id\n"
            . " {$whereSql}",
            $params
        );
        $total = (int)($totalRow['total'] ?? 0);

        $rows = db_all($app->db, 
            "SELECT f.id, f.slug, f.name, f.ship_name, f.category_id, f.doctrine_id, f.has_renamed_items,\n"
            . " c.name AS category_name, d.name AS doctrine_name\n"
            . " FROM module_fittings_fits f\n"
            . " LEFT JOIN module_fittings_categories c ON c.id = f.category_id\n"
            . " LEFT JOIN module_fittings_doctrines d ON d.id = f.doctrine_id\n"
            . " LEFT JOIN module_fittings_favorites fav ON fav.fit_id = f.id AND fav.user_id = ?\n"
            . " {$whereSql}\n"
            . " ORDER BY f.updated_at DESC, f.id DESC\n"
            . " LIMIT {$perPage} OFFSET {$offset}",
            array_merge([$uid], $params)
        );

        $cards = '';
        foreach ($rows as $fit) {
            $fitSlug = (string)($fit['slug'] ?? '');
            $fitName = htmlspecialchars((string)($fit['name'] ?? ''));
            $shipName = htmlspecialchars((string)($fit['ship_name'] ?? ''));
            $categoryName = htmlspecialchars((string)($fit['category_name'] ?? ''));
            $doctrineName = htmlspecialchars((string)($fit['doctrine_name'] ?? ''));
            $renamedBadge = ((int)($fit['has_renamed_items'] ?? 0) === 1)
                ? "<span class='badge bg-warning text-dark ms-2'>Renamed items</span>"
                : '';

            $cards .= "<div class='col-md-4'>
                <div class='card h-100'>
                  <div class='card-body d-flex flex-column'>
                    <div class='d-flex justify-content-between align-items-start'>
                      <div>
                        <div class='fw-semibold'>{$fitName}{$renamedBadge}</div>
                        <div class='text-muted small'>{$shipName}</div>
                      </div>
                    </div>
                    <div class='mt-2 small text-muted'>Category: {$categoryName}</div>
                    <div class='small text-muted'>Doctrine: {$doctrineName}</div>
                    <div class='mt-3'>
                      <a class='btn btn-sm btn-outline-primary' href='/fittings/fit/" . htmlspecialchars($fitSlug) . "'>View Fit</a>
                    </div>
                  </div>
                </div>
              </div>";
        }
        if ($cards === '') {
            $cards = "<div class='col-12 text-muted'>No fittings found.</div>";
        }

        $categoryOptions = "<option value=''>All Categories</option>";
        foreach ($categories as $cat) {
            $catSlug = (string)($cat['slug'] ?? '');
            $name = htmlspecialchars((string)($cat['name'] ?? ''));
            $selected = $catSlug !== '' && $catSlug === $categoryFilter ? 'selected' : '';
            $categoryOptions .= "<option value='" . htmlspecialchars($catSlug) . "' {$selected}>{$name}</option>";
        }

        $scopeOptions = "<option value=''>All Visibility</option>";
        foreach (['corp' => 'My corporation', 'alliance' => 'My alliance'] as $key => $label) {
            $selected = $scopeFilter === $key ? 'selected' : '';
            $scopeOptions .= "<option value='{$key}' {$selected}>{$label}</option>";
        }

        $doctrineOptions = "<option value=''>All Doctrines</option>";
        foreach ($doctrines as $doc) {
            $docSlug = (string)($doc['slug'] ?? '');
            $name = htmlspecialchars((string)($doc['name'] ?? ''));
            $selected = $docSlug !== '' && $docSlug === $doctrineFilter ? 'selected' : '';
            $doctrineOptions .= "<option value='" . htmlspecialchars($docSlug) . "' {$selected}>{$name}</option>";
        }

        $pager = '';
        $pages = (int)ceil($total / $perPage);
        if ($pages > 1) {
            $pager .= "<nav><ul class='pagination pagination-sm'>";
            for ($i = 1; $i <= $pages; $i++) {
                $active = $i === $page ? 'active' : '';
                $query = http_build_query(array_merge($req->query, ['page' => $i]));
                $pager .= "<li class='page-item {$active}'><a class='page-link' href='/fittings?{$query}'>{$i}</a></li>";
            }
            $pager .= "</ul></nav>";
        }

        $favoriteChecked = $favoritesOnly ? 'checked' : '';
        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Fittings</h1>
              <div class='text-muted'>Browse alliance fittings and doctrines.</div>
            </div>
          </div>
          <form class='card card-body mb-3' method='get' action='/fittings'>
            <div class='row g-2 align-items-end'>
              <div class='col-md-3'>
                <label class='form-label'>Search</label>
                <input class='form-control' name='search' value='" . htmlspecialchars($search) . "' placeholder='Fit name or ship'>
              </div>
              <div class='col-md-2'>
                <label class='form-label'>Category</label>
                <select class='form-select' name='category'>{$categoryOptions}</select>
              </div>
              <div class='col-md-2'>
                <label class='form-label'>Doctrine</label>
                <select class='form-select' name='doctrine'>{$doctrineOptions}</select>
              </div>
              <div class='col-md-2'>
                <label class='form-label'>Visibility</label>
                <select class='form-select' name='scope'>{$scopeOptions}</select>
              </div>
              <div class='col-md-2'>
                <label class='form-label'>Ship hull</label>
                <input class='form-control' name='ship' value='" . htmlspecialchars($shipFilter) . "' placeholder='Ship'>
              </div>
              <div class='col-md-1'>
                <div class='form-check mt-4'>
                  <input class='form-check-input' type='checkbox' name='favorites' value='1' id='filter-favorites' {$favoriteChecked}>
                  <label class='form-check-label' for='filter-favorites'>Favorites</label>
                </div>
              </div>
              <div class='col-md-1'>
                <button class='btn btn-primary w-100'>Filter</button>
              </div>
            </div>
          </form>
          <div class='row g-3'>{$cards}</div>
          <div class='mt-3'>{$pager}</div>";

        return Response::html($renderPage('Fittings', $body), 200);
    });

    $registry->route('GET', '/fittings/doctrines', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $rows = db_all($app->db, "SELECT name, description FROM module_fittings_doctrines WHERE is_active=1 ORDER BY name ASC");
        $items = '';
        foreach ($rows as $row) {
            $name = htmlspecialchars((string)($row['name'] ?? ''));
            $desc = htmlspecialchars((string)($row['description'] ?? ''));
            $descHtml = $desc !== '' ? "<div class='text-muted small'>{$desc}</div>" : "<div class='text-muted small'>No description.</div>";
            $items .= "<div class='card card-body mb-2'><div class='fw-semibold'>{$name}</div>{$descHtml}</div>";
        }
        if ($items === '') {
            $items = "<div class='text-muted'>No doctrines are available yet.</div>";
        }

        $body = "<h1 class='mb-3'>Doctrines</h1>{$items}";

        return Response::html($renderPage('Doctrines', $body), 200);
    });

    $registry->route('GET', '/fittings/skills-check', function () use ($renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $body = "<h1 class='mb-3'>Skills Check</h1>
                 <div class='card card-body'>
                   <div class='fw-semibold mb-2'>Coming soon</div>
                   <div class='text-muted'>This area will compare your skills against doctrine requirements once configured by admins.</div>
                 </div>";

        return Response::html($renderPage('Skills Check', $body), 200);
    });

    $registry->route('GET', '/fittings/fit/{slug}', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $getVisibleCategories, $buildBuyAll): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $fitSlug = (string)($req->params['slug'] ?? '');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $categories = $getVisibleCategories($uid, $cid);
        $catIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $categories)));

        if ($fitSlug === '' || empty($catIds)) {
            return Response::html($renderPage('Fit', "<div class='alert alert-warning'>Fit not found.</div>"), 404);
        }

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $fit = db_one($app->db, 
            "SELECT f.*, c.name AS category_name, d.name AS doctrine_name\n"
            . " FROM module_fittings_fits f\n"
            . " LEFT JOIN module_fittings_categories c ON c.id = f.category_id\n"
            . " LEFT JOIN module_fittings_doctrines d ON d.id = f.doctrine_id\n"
            . " WHERE f.slug=? AND f.category_id IN ({$placeholders})\n"
            . " LIMIT 1",
            array_merge([$fitSlug], $catIds)
        );

        if (!$fit) {
            return Response::html($renderPage('Fit', "<div class='alert alert-warning'>Fit not found.</div>"), 404);
        }
        $fitId = (int)($fit['id'] ?? 0);

        $parsed = json_decode((string)($fit['parsed_json'] ?? '[]'), true);
        if (!is_array($parsed)) $parsed = [];
        $items = $parsed['items'] ?? [];
        $buyLines = $buildBuyAll($items);
        $buyBlock = htmlspecialchars(implode("\n", $buyLines));
        $eftText = (string)($fit['eft_text'] ?? '');
        $eftHtml = htmlspecialchars($eftText);

        $favRow = db_one($app->db, 
            "SELECT 1 FROM module_fittings_favorites WHERE user_id=? AND fit_id=? LIMIT 1",
            [$uid, $fitId]
        );
        $isFavorite = $favRow !== null;

        $saveStatus = '';
        if (isset($_SESSION['fittings_save_flash'])) {
            $flash = $_SESSION['fittings_save_flash'];
            unset($_SESSION['fittings_save_flash']);
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            $saveStatus = "<div class='alert alert-{$type}'>{$message}</div>";
        }

        $favoriteLabel = $isFavorite ? 'Remove Favorite' : 'Add Favorite';
        $saveButton = "<form method='post' action='/fittings/fit/" . htmlspecialchars($fitSlug) . "/save'>
            <button class='btn btn-outline-success'>Save to EVE via ESI</button>
          </form>
          <form method='post' action='/fittings/fit/" . htmlspecialchars($fitSlug) . "/favorite'>
            <button class='btn btn-outline-secondary'>{$favoriteLabel}</button>
          </form>";

        $exportUrl = "/fittings/fit/" . rawurlencode($fitSlug) . "/export";
        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>" . htmlspecialchars((string)($fit['name'] ?? '')) . "</h1>
              <div class='text-muted'>" . htmlspecialchars((string)($fit['ship_name'] ?? '')) . "</div>
              <div class='text-muted small'>Category: " . htmlspecialchars((string)($fit['category_name'] ?? '')) . " • Doctrine: " . htmlspecialchars((string)($fit['doctrine_name'] ?? '')) . "</div>
            </div>
            <div class='d-flex gap-2'>
              <a class='btn btn-outline-primary' href='{$exportUrl}'>Export EFT</a>
              {$saveButton}
            </div>
          </div>
          {$saveStatus}
          <div class='row g-3'>
            <div class='col-lg-6'>
              <div class='card h-100'>
                <div class='card-header'>EFT Text</div>
                <div class='card-body'>
                  <textarea class='form-control' rows='12' readonly>{$eftHtml}</textarea>
                  <button class='btn btn-sm btn-outline-secondary mt-2' onclick='navigator.clipboard.writeText(this.previousElementSibling.value)'>Copy EFT</button>
                </div>
              </div>
            </div>
            <div class='col-lg-6'>
              <div class='card h-100'>
                <div class='card-header'>Copy Buy All</div>
                <div class='card-body'>
                  <textarea class='form-control' rows='12' readonly>{$buyBlock}</textarea>
                  <button class='btn btn-sm btn-outline-secondary mt-2' onclick='navigator.clipboard.writeText(this.previousElementSibling.value)'>Copy Buy All</button>
                </div>
              </div>
            </div>
          </div>";

        return Response::html($renderPage('Fit', $body), 200);
    });

    $registry->route('GET', '/fittings/fit/{slug}/export', function (Request $req) use ($app, $requireLogin, $requireRight, $getVisibleCategories): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $fitSlug = (string)($req->params['slug'] ?? '');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $categories = $getVisibleCategories($uid, $cid);
        $catIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $categories)));
        if ($fitSlug === '' || empty($catIds)) {
            return Response::text('Not Found', 404);
        }

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $fit = db_one($app->db, 
            "SELECT eft_text, name FROM module_fittings_fits WHERE slug=? AND category_id IN ({$placeholders}) LIMIT 1",
            array_merge([$fitSlug], $catIds)
        );
        if (!$fit) return Response::text('Not Found', 404);

        $text = (string)($fit['eft_text'] ?? '');
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($fit['name'] ?? 'fit'));
        $filename = $name . '.eft.txt';
        return Response::text($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    });

    $registry->route('POST', '/fittings/fit/{slug}/save', function (Request $req) use ($app, $requireLogin, $requireRight, $getVisibleCategories, $resolveFitTypeIds, $buildEsiItems, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $fitSlug = (string)($req->params['slug'] ?? '');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);

        $categories = $getVisibleCategories($uid, $cid);
        $catIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $categories)));
        if ($fitSlug === '' || empty($catIds)) {
            return Response::redirect('/fittings');
        }

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $fit = db_one($app->db, 
            "SELECT * FROM module_fittings_fits WHERE slug=? AND category_id IN ({$placeholders}) LIMIT 1",
            array_merge([$fitSlug], $catIds)
        );
        if (!$fit) {
            return Response::redirect('/fittings');
        }
        $fitId = (int)($fit['id'] ?? 0);

        $parsed = json_decode((string)($fit['parsed_json'] ?? '[]'), true);
        if (!is_array($parsed)) $parsed = [];

        $typeIds = $resolveFitTypeIds($parsed);
        $shipTypeId = (int)($typeIds['ship_type_id'] ?? 0);
        if ($shipTypeId <= 0) {
            $_SESSION['fittings_save_flash'] = ['type' => 'warning', 'message' => 'Unable to resolve ship type for ESI save.'];
            return Response::redirect('/fittings/fit/' . rawurlencode($fitSlug));
        }

        $esiItems = $buildEsiItems($parsed, $typeIds);
        if (empty($esiItems)) {
            $_SESSION['fittings_save_flash'] = ['type' => 'warning', 'message' => 'Unable to resolve fitting items for ESI save.'];
            return Response::redirect('/fittings/fit/' . rawurlencode($fitSlug));
        }

        $sso = new \App\Core\EveSso($app->db);
        $token = $sso->getAccessTokenForCharacter($cid, 'default');
        if (!empty($token['expired']) || empty($token['access_token'])) {
            $_SESSION['fittings_save_flash'] = ['type' => 'warning', 'message' => 'Token expired or missing. Re-authorize your character in Character Link Hub.'];
            return Response::redirect('/fittings/fit/' . rawurlencode($fitSlug));
        }
        $scopes = $token['scopes'] ?? [];
        if (!is_array($scopes) || !in_array('esi-fittings.write_fittings.v1', $scopes, true)) {
            $_SESSION['fittings_save_flash'] = ['type' => 'warning', 'message' => 'Missing ESI scope: esi-fittings.write_fittings.v1. Re-authorize your character.'];
            return Response::redirect('/fittings/fit/' . rawurlencode($fitSlug));
        }

        $payload = [
            'name' => (string)($fit['name'] ?? 'Fit'),
            'description' => 'Saved from ModularAlliance fittings module.',
            'ship_type_id' => $shipTypeId,
            'items' => $esiItems,
        ];

        try {
            [$status, $body] = \App\Core\HttpClient::postJson(
                'https://esi.evetech.net/latest/characters/' . $cid . '/fittings/',
                $payload,
                ['Authorization: Bearer ' . $token['access_token']]
            );
        } catch (\Throwable $e) {
            $status = 0;
            $body = $e->getMessage();
        }

        $resultStatus = $status >= 200 && $status < 300 ? 'success' : 'failed';
        $message = $resultStatus === 'success' ? 'Fit saved to EVE.' : "Failed to save fit (HTTP {$status}).";

        db_exec($app->db, 
            "INSERT INTO module_fittings_saved_events\n"
            . " (fit_id, user_id, character_id, status, message, created_at)\n"
            . " VALUES (?, ?, ?, ?, ?, NOW())",
            [$fitId, $uid, $cid, $resultStatus, $message]
        );

        $logAudit($uid, 'fit.save_esi', 'fit', $fitId, $message, [
            'status' => $status,
            'response' => substr((string)$body, 0, 500),
        ]);

        $_SESSION['fittings_save_flash'] = [
            'type' => $resultStatus === 'success' ? 'success' : 'warning',
            'message' => $message,
        ];
        return Response::redirect('/fittings/fit/' . rawurlencode($fitSlug));
    });

    $registry->route('POST', '/fittings/fit/{slug}/favorite', function (Request $req) use ($app, $requireLogin, $requireRight, $getVisibleCategories): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $fitSlug = (string)($req->params['slug'] ?? '');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);
        if ($fitSlug === '' || $uid <= 0) {
            return Response::redirect('/fittings');
        }

        $categories = $getVisibleCategories($uid, $cid);
        $catIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $categories)));
        if (empty($catIds)) {
            return Response::redirect('/fittings');
        }

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $fit = db_one($app->db, 
            "SELECT id FROM module_fittings_fits WHERE slug=? AND category_id IN ({$placeholders}) LIMIT 1",
            array_merge([$fitSlug], $catIds)
        );
        if (!$fit) {
            return Response::redirect('/fittings');
        }
        $fitId = (int)($fit['id'] ?? 0);

        $exists = db_one($app->db, 
            "SELECT 1 FROM module_fittings_favorites WHERE user_id=? AND fit_id=? LIMIT 1",
            [$uid, $fitId]
        );
        if ($exists) {
            db_exec($app->db, "DELETE FROM module_fittings_favorites WHERE user_id=? AND fit_id=?", [$uid, $fitId]);
        } else {
            db_exec($app->db, 
                "INSERT IGNORE INTO module_fittings_favorites (user_id, fit_id, created_at) VALUES (?, ?, NOW())",
                [$uid, $fitId]
            );
        }

        return Response::redirect('/fittings/fit/' . rawurlencode($fitSlug));
    });

    $registry->route('GET', '/fittings/doctrine/{slug}', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $getVisibleCategories): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $doctrineSlug = (string)($req->params['slug'] ?? '');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $categories = $getVisibleCategories($uid, $cid);
        $catIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $categories)));
        if ($doctrineSlug === '' || empty($catIds)) {
            return Response::html($renderPage('Doctrine', "<div class='alert alert-warning'>Doctrine not found.</div>"), 404);
        }

        $doctrine = db_one($app->db, 
            "SELECT * FROM module_fittings_doctrines WHERE slug=? LIMIT 1",
            [$doctrineSlug]
        );
        if (!$doctrine) {
            return Response::html($renderPage('Doctrine', "<div class='alert alert-warning'>Doctrine not found.</div>"), 404);
        }
        $doctrineId = (int)($doctrine['id'] ?? 0);

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $fits = db_all($app->db, 
            "SELECT id, slug, name, ship_name FROM module_fittings_fits\n"
            . " WHERE doctrine_id=? AND category_id IN ({$placeholders})\n"
            . " ORDER BY name ASC",
            array_merge([$doctrineId], $catIds)
        );
        $rows = '';
        foreach ($fits as $fit) {
            $fitSlug = (string)($fit['slug'] ?? '');
            $rows .= "<li class='list-group-item d-flex justify-content-between align-items-center'>
                <div>
                  <div class='fw-semibold'>" . htmlspecialchars((string)($fit['name'] ?? '')) . "</div>
                  <div class='text-muted small'>" . htmlspecialchars((string)($fit['ship_name'] ?? '')) . "</div>
                </div>
                <a class='btn btn-sm btn-outline-primary' href='/fittings/fit/" . htmlspecialchars($fitSlug) . "'>View</a>
              </li>";
        }
        if ($rows === '') {
            $rows = "<li class='list-group-item text-muted'>No fits assigned.</li>";
        }

        $body = "<div class='card'>
            <div class='card-body'>
              <h1 class='mb-2'>" . htmlspecialchars((string)($doctrine['name'] ?? 'Doctrine')) . "</h1>
              <div class='text-muted'>" . nl2br(htmlspecialchars((string)($doctrine['description'] ?? ''))) . "</div>
            </div>
          </div>
          <div class='card mt-3'>
            <div class='card-header'>Fits in Doctrine</div>
            <ul class='list-group list-group-flush'>{$rows}</ul>
          </div>";

        return Response::html($renderPage('Doctrine', $body), 200);
    });

    $registry->route('GET', '/fittings/categories', function () use ($app, $renderPage, $requireLogin, $requireRight, $getVisibleCategories): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.access_fittings')) return $resp;

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $categories = $getVisibleCategories($uid, $cid);

        $cards = '';
        foreach ($categories as $cat) {
            $cards .= "<div class='col-md-4'>
                <div class='card h-100'>
                  <div class='card-body'>
                    <div class='fw-semibold'>" . htmlspecialchars((string)($cat['name'] ?? '')) . "</div>
                    <div class='text-muted small'>" . nl2br(htmlspecialchars((string)($cat['description'] ?? ''))) . "</div>
                  </div>
                </div>
              </div>";
        }
        if ($cards === '') {
            $cards = "<div class='col-12 text-muted'>No categories available.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Categories</h1>
              <div class='text-muted'>Only categories you can access are listed.</div>
            </div>
          </div>
          <div class='row g-3'>{$cards}</div>";

        return Response::html($renderPage('Fitting Categories', $body), 200);
    });

    $registry->route('GET', '/admin/fittings', function () use ($renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Fittings Admin</h1>
              <div class='text-muted'>Manage categories, doctrines, and fittings.</div>
            </div>
          </div>
          <div class='row g-3'>
            <div class='col-md-4'>
              <div class='card h-100'><div class='card-body'>
                <div class='fw-semibold mb-2'>Categories</div>
                <div class='text-muted small mb-3'>Control visibility rules and group access.</div>
                <a class='btn btn-outline-primary' href='/admin/fittings/categories'>Manage Categories</a>
              </div></div>
            </div>
            <div class='col-md-4'>
              <div class='card h-100'><div class='card-body'>
                <div class='fw-semibold mb-2'>Doctrines</div>
                <div class='text-muted small mb-3'>Define doctrines and group fits.</div>
                <a class='btn btn-outline-primary' href='/admin/fittings/doctrines'>Manage Doctrines</a>
              </div></div>
            </div>
            <div class='col-md-4'>
              <div class='card h-100'><div class='card-body'>
                <div class='fw-semibold mb-2'>Fits</div>
                <div class='text-muted small mb-3'>Import and maintain fit library.</div>
                <a class='btn btn-outline-primary' href='/admin/fittings/fits'>Manage Fits</a>
              </div></div>
            </div>
            <div class='col-md-4'>
              <div class='card h-100'><div class='card-body'>
                <div class='fw-semibold mb-2'>Audit Trail</div>
                <div class='text-muted small mb-3'>Review recent changes.</div>
                <a class='btn btn-outline-primary' href='/admin/fittings/audit'>View Audit Log</a>
              </div></div>
            </div>
            <div class='col-md-4'>
              <div class='card h-100'><div class='card-body'>
                <div class='fw-semibold mb-2'>Admin Tools</div>
                <div class='text-muted small mb-3'>Run type cache jobs and health checks.</div>
                <a class='btn btn-outline-primary' href='/admin/fittings/tools'>Open Tools</a>
              </div></div>
            </div>
            <div class='col-md-4'>
              <div class='card h-100'><div class='card-body'>
                <div class='fw-semibold mb-2'>Cron Job Manager</div>
                <div class='text-muted small mb-3'>Review scheduled tasks and runs.</div>
                <a class='btn btn-outline-primary' href='/admin/corptools/cron'>Open Cron Manager</a>
              </div></div>
            </div>
          </div>";

        return Response::html($renderPage('Fittings Admin', $body), 200);
    });

    $registry->route('GET', '/admin/fittings/categories', function () use ($app, $renderPage, $requireLogin, $requireRight, $csrfToken): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $universe = new Universe($app->db);
        $categories = db_all($app->db, 
            "SELECT c.*, GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ') AS group_names\n"
            . " FROM module_fittings_categories c\n"
            . " LEFT JOIN module_fittings_category_groups cg ON cg.category_id = c.id\n"
            . " LEFT JOIN groups g ON g.id = cg.group_id\n"
            . " GROUP BY c.id\n"
            . " ORDER BY c.name ASC"
        );
        $groupRows = db_all($app->db, "SELECT id, slug, name FROM groups ORDER BY name ASC");

        $categoryRows = '';
        foreach ($categories as $cat) {
            $catSlug = (string)($cat['slug'] ?? '');
            $name = htmlspecialchars((string)($cat['name'] ?? ''));
            $scopeKey = (string)($cat['visibility_scope'] ?? 'all');
            $scope = htmlspecialchars(match ($scopeKey) {
                'corp' => 'Corporation',
                'alliance' => 'Alliance',
                default => 'All members',
            });
            $scopeOrg = $scopeKey === 'all'
                ? '—'
                : fittings_scope_org_label($universe, $scopeKey === 'alliance' ? 'alliance' : 'corporation', (int)($cat['visibility_org_id'] ?? 0));
            $scopeLabel = htmlspecialchars($scopeOrg);
            $csrfKey = 'fittings_cat_delete_' . $catSlug;
            $groupNames = htmlspecialchars((string)($cat['group_names'] ?? 'All members in scope'));
            if ($groupNames === '') {
                $groupNames = 'All members in scope';
            }
            $categoryRows .= "<tr>
                <td>{$name}</td>
                <td>{$scope}</td>
                <td>{$scopeLabel}</td>
                <td>{$groupNames}</td>
                <td>
                  <form method='post' action='/admin/fittings/categories/delete' class='d-inline' onsubmit=\"return confirm('Delete category {$name}?');\">
                    <input type='hidden' name='category_slug' value='" . htmlspecialchars($catSlug) . "'>
                    <input type='hidden' name='csrf_key' value='{$csrfKey}'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken($csrfKey)) . "'>
                    <button class='btn btn-sm btn-outline-danger'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($categoryRows === '') {
            $categoryRows = "<tr><td colspan='5' class='text-muted'>No categories yet.</td></tr>";
        }

        $groupOptions = '';
        foreach ($groupRows as $group) {
            $groupOptions .= "<option value='" . htmlspecialchars((string)($group['slug'] ?? '')) . "'>" . htmlspecialchars((string)($group['name'] ?? '')) . "</option>";
        }

        $csrf = $csrfToken('fittings_cat_save');
        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Manage Categories</h1>
              <div class='text-muted'>Control visibility and access rules.</div>
            </div>
          </div>
          <div class='card card-body mb-3'>
            <form method='post' action='/admin/fittings/categories/save' class='row g-3'>
              <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
              <div class='col-md-4'>
                <label class='form-label'>Name</label>
                <input class='form-control' name='name' required>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Visibility scope</label>
                <select class='form-select' name='visibility_scope'>
                  <option value='all'>All members</option>
                  <option value='corp'>Specific corporation</option>
                  <option value='alliance'>Specific alliance</option>
                </select>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Scope org (name or ID, optional)</label>
                <input class='form-control' name='visibility_org_id' placeholder='Corp or alliance'>
              </div>
              <div class='col-md-6'>
                <label class='form-label'>Allowed Groups</label>
                <select class='form-select' name='group_slugs[]' multiple>{$groupOptions}</select>
                <div class='form-text'>Leave empty to allow all members in scope.</div>
              </div>
              <div class='col-12'>
                <label class='form-label'>Description</label>
                <textarea class='form-control' name='description' rows='2'></textarea>
              </div>
              <div class='col-12'>
                <button class='btn btn-primary'>Create Category</button>
              </div>
            </form>
          </div>
          <div class='card'>
            <div class='card-header'>Existing Categories</div>
            <div class='table-responsive'>
              <table class='table table-sm table-striped mb-0'>
                <thead><tr><th>Name</th><th>Scope</th><th>Scope org</th><th>Groups</th><th></th></tr></thead>
                <tbody>{$categoryRows}</tbody>
              </table>
            </div>
          </div>";

        return Response::html($renderPage('Manage Categories', $body), 200);
    });

    $registry->route('POST', '/admin/fittings/categories/save', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_cat_save', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $name = trim((string)($req->post['name'] ?? ''));
        $description = trim((string)($req->post['description'] ?? ''));
        $visibilityScope = (string)($req->post['visibility_scope'] ?? 'all');
        $visibilityOrgRaw = (string)($req->post['visibility_org_id'] ?? '');
        $visibilityOrgId = $visibilityScope === 'all'
            ? 0
            : fittings_resolve_org_id($app->db, $visibilityScope === 'alliance' ? 'alliance' : 'corp', $visibilityOrgRaw);
        $groupSlugs = $req->post['group_slugs'] ?? [];
        $groupSlugs = is_array($groupSlugs) ? array_values(array_filter(array_map('strval', $groupSlugs))) : [];

        if ($name === '') {
            return Response::redirect('/admin/fittings/categories');
        }

        $slug = $generateSlug('module_fittings_categories', $name);
        db_exec($app->db, 
            "INSERT INTO module_fittings_categories\n"
            . " (slug, name, description, visibility_scope, visibility_org_id, is_active, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())",
            [$slug, $name, $description, $visibilityScope, $visibilityOrgId > 0 ? $visibilityOrgId : null]
        );
        $row = db_one($app->db, "SELECT LAST_INSERT_ID() AS id");
        $catId = (int)($row['id'] ?? 0);

        foreach ($groupSlugs as $gslug) {
            $groupRow = db_one($app->db, "SELECT id FROM groups WHERE slug=? LIMIT 1", [$gslug]);
            $gid = (int)($groupRow['id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            db_exec($app->db, 
                "INSERT IGNORE INTO module_fittings_category_groups (category_id, group_id) VALUES (?, ?)",
                [$catId, $gid]
            );
        }

        $logAudit((int)($_SESSION['user_id'] ?? 0), 'category.create', 'category', $catId, "Created category {$name}");

        return Response::redirect('/admin/fittings/categories');
    });

    $registry->route('POST', '/admin/fittings/categories/delete', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        $csrfKey = (string)($req->post['csrf_key'] ?? '');
        if ($csrfKey === '' || !$csrfCheck($csrfKey, (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $catSlug = trim((string)($req->post['category_slug'] ?? ''));
        $row = $catSlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_categories WHERE slug=? LIMIT 1", [$catSlug]) : null;
        $catId = (int)($row['id'] ?? 0);
        if ($catId > 0) {
            db_exec($app->db, "DELETE FROM module_fittings_category_groups WHERE category_id=?", [$catId]);
            db_exec($app->db, "DELETE FROM module_fittings_categories WHERE id=?", [$catId]);
            $logAudit((int)($_SESSION['user_id'] ?? 0), 'category.delete', 'category', $catId, 'Deleted category');
        }

        return Response::redirect('/admin/fittings/categories');
    });

    $registry->route('GET', '/admin/fittings/doctrines', function () use ($app, $renderPage, $requireLogin, $requireRight, $csrfToken): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $doctrines = db_all($app->db, "SELECT * FROM module_fittings_doctrines ORDER BY name ASC");
        $rows = '';
        foreach ($doctrines as $doc) {
            $docSlug = (string)($doc['slug'] ?? '');
            $name = htmlspecialchars((string)($doc['name'] ?? ''));
            $csrfKey = 'fittings_doc_delete_' . $docSlug;
            $rows .= "<tr>
                <td>{$name}</td>
                <td>
                  <form method='post' action='/admin/fittings/doctrines/delete' class='d-inline' onsubmit=\"return confirm('Delete doctrine {$name}?');\">
                    <input type='hidden' name='doctrine_slug' value='" . htmlspecialchars($docSlug) . "'>
                    <input type='hidden' name='csrf_key' value='{$csrfKey}'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken($csrfKey)) . "'>
                    <button class='btn btn-sm btn-outline-danger'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='2' class='text-muted'>No doctrines yet.</td></tr>";
        }

        $csrf = $csrfToken('fittings_doc_save');
        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Manage Doctrines</h1>
              <div class='text-muted'>Group fits into doctrine collections.</div>
            </div>
          </div>
          <div class='card card-body mb-3'>
            <form method='post' action='/admin/fittings/doctrines/save' class='row g-3'>
              <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
              <div class='col-md-4'>
                <label class='form-label'>Name</label>
                <input class='form-control' name='name' required>
              </div>
              <div class='col-md-8'>
                <label class='form-label'>Description</label>
                <textarea class='form-control' name='description' rows='2'></textarea>
              </div>
              <div class='col-12'>
                <button class='btn btn-primary'>Create Doctrine</button>
              </div>
            </form>
          </div>
          <div class='card'>
            <div class='card-header'>Existing Doctrines</div>
            <div class='table-responsive'>
              <table class='table table-sm table-striped mb-0'>
                <thead><tr><th>Name</th><th></th></tr></thead>
                <tbody>{$rows}</tbody>
              </table>
            </div>
          </div>";

        return Response::html($renderPage('Manage Doctrines', $body), 200);
    });

    $registry->route('POST', '/admin/fittings/doctrines/save', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_doc_save', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $name = trim((string)($req->post['name'] ?? ''));
        $description = trim((string)($req->post['description'] ?? ''));
        if ($name === '') {
            return Response::redirect('/admin/fittings/doctrines');
        }

        $slug = $generateSlug('module_fittings_doctrines', $name);
        db_exec($app->db, 
            "INSERT INTO module_fittings_doctrines (slug, name, description, is_active, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, 1, NOW(), NOW())",
            [$slug, $name, $description]
        );
        $row = db_one($app->db, "SELECT LAST_INSERT_ID() AS id");
        $docId = (int)($row['id'] ?? 0);
        $logAudit((int)($_SESSION['user_id'] ?? 0), 'doctrine.create', 'doctrine', $docId, "Created doctrine {$name}");

        return Response::redirect('/admin/fittings/doctrines');
    });

    $registry->route('POST', '/admin/fittings/doctrines/delete', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        $csrfKey = (string)($req->post['csrf_key'] ?? '');
        if ($csrfKey === '' || !$csrfCheck($csrfKey, (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $docSlug = trim((string)($req->post['doctrine_slug'] ?? ''));
        $row = $docSlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_doctrines WHERE slug=? LIMIT 1", [$docSlug]) : null;
        $docId = (int)($row['id'] ?? 0);
        if ($docId > 0) {
            db_exec($app->db, "UPDATE module_fittings_fits SET doctrine_id=NULL WHERE doctrine_id=?", [$docId]);
            db_exec($app->db, "DELETE FROM module_fittings_doctrines WHERE id=?", [$docId]);
            $logAudit((int)($_SESSION['user_id'] ?? 0), 'doctrine.delete', 'doctrine', $docId, 'Deleted doctrine');
        }

        return Response::redirect('/admin/fittings/doctrines');
    });

    $registry->route('GET', '/admin/fittings/fits', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $fits = db_all($app->db, 
            "SELECT f.id, f.slug, f.name, f.ship_name, c.name AS category_name, d.name AS doctrine_name\n"
            . " FROM module_fittings_fits f\n"
            . " LEFT JOIN module_fittings_categories c ON c.id = f.category_id\n"
            . " LEFT JOIN module_fittings_doctrines d ON d.id = f.doctrine_id\n"
            . " ORDER BY f.updated_at DESC"
        );
        $rows = '';
        foreach ($fits as $fit) {
            $fitSlug = (string)($fit['slug'] ?? '');
            $csrfKey = 'fittings_fit_delete_' . $fitSlug;
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($fit['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($fit['ship_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($fit['category_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($fit['doctrine_name'] ?? '')) . "</td>
                <td>
                  <a class='btn btn-sm btn-outline-primary' href='/admin/fittings/fits/edit?fit=" . htmlspecialchars($fitSlug) . "'>Edit</a>
                  <form method='post' action='/admin/fittings/fits/delete' class='d-inline' onsubmit=\"return confirm('Delete this fit?');\">
                    <input type='hidden' name='fit_slug' value='" . htmlspecialchars($fitSlug) . "'>
                    <input type='hidden' name='csrf_key' value='{$csrfKey}'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken($csrfKey)) . "'>
                    <button class='btn btn-sm btn-outline-danger'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='5' class='text-muted'>No fits yet.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Manage Fits</h1>
              <div class='text-muted'>Import EFT, validate, and assign to doctrines.</div>
            </div>
            <div>
              <a class='btn btn-primary' href='/admin/fittings/fits/new'>Import EFT</a>
            </div>
          </div>
          <div class='card'>
            <div class='table-responsive'>
              <table class='table table-sm table-striped mb-0'>
                <thead><tr><th>Name</th><th>Ship</th><th>Category</th><th>Doctrine</th><th></th></tr></thead>
                <tbody>{$rows}</tbody>
              </table>
            </div>
          </div>";

        return Response::html($renderPage('Manage Fits', $body), 200);
    });

    $registry->route('GET', '/admin/fittings/fits/new', function () use ($app, $renderPage, $requireLogin, $requireRight, $csrfToken): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $categories = db_all($app->db, "SELECT id, slug, name FROM module_fittings_categories ORDER BY name ASC");
        $doctrines = db_all($app->db, "SELECT id, slug, name FROM module_fittings_doctrines ORDER BY name ASC");

        $categoryOptions = '';
        foreach ($categories as $cat) {
            $categoryOptions .= "<option value='" . htmlspecialchars((string)($cat['slug'] ?? '')) . "'>" . htmlspecialchars((string)($cat['name'] ?? '')) . "</option>";
        }
        $doctrineOptions = "<option value=''>None</option>";
        foreach ($doctrines as $doc) {
            $doctrineOptions .= "<option value='" . htmlspecialchars((string)($doc['slug'] ?? '')) . "'>" . htmlspecialchars((string)($doc['name'] ?? '')) . "</option>";
        }

        $csrf = $csrfToken('fittings_fit_save');
        $body = "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3'>
            <div>
              <h1 class='mb-1'>Import Fit</h1>
              <div class='text-muted'>Paste EFT and validate before saving.</div>
            </div>
          </div>
          <form method='post' action='/admin/fittings/fits/preview' class='card card-body'>
            <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
            <div class='row g-3'>
              <div class='col-md-4'>
                <label class='form-label'>Category</label>
                <select class='form-select' name='category_slug' required>{$categoryOptions}</select>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Doctrine</label>
                <select class='form-select' name='doctrine_slug'>{$doctrineOptions}</select>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Tags (comma separated)</label>
                <input class='form-control' name='tags'>
              </div>
              <div class='col-12'>
                <label class='form-label'>EFT Text</label>
                <textarea class='form-control' name='eft_text' rows='10' required></textarea>
              </div>
            </div>
            <button class='btn btn-primary mt-3'>Validate EFT</button>
          </form>";

        return Response::html($renderPage('Import Fit', $body), 200);
    });

    $registry->route('POST', '/admin/fittings/fits/preview', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $csrfCheck, $csrfToken, $parseEft, $buildBuyAll): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_fit_save', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $eftText = trim((string)($req->post['eft_text'] ?? ''));
        $categorySlug = trim((string)($req->post['category_slug'] ?? ''));
        $doctrineSlug = trim((string)($req->post['doctrine_slug'] ?? ''));
        $categoryRow = $categorySlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_categories WHERE slug=? LIMIT 1", [$categorySlug]) : null;
        $doctrineRow = $doctrineSlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_doctrines WHERE slug=? LIMIT 1", [$doctrineSlug]) : null;
        $categoryId = (int)($categoryRow['id'] ?? 0);
        $doctrineId = (int)($doctrineRow['id'] ?? 0);
        $tagsRaw = trim((string)($req->post['tags'] ?? ''));

        $parsed = $parseEft($eftText);
        $errors = $parsed['errors'] ?? [];
        $buyLines = $buildBuyAll($parsed['items'] ?? []);
        $buyBlock = htmlspecialchars(implode("\n", $buyLines));

        $errorHtml = '';
        if (!empty($errors)) {
            $list = '';
            foreach ($errors as $err) {
                $list .= "<li>" . htmlspecialchars((string)$err) . "</li>";
            }
            $errorHtml = "<div class='alert alert-warning'><ul class='mb-0'>{$list}</ul></div>";
        }

        $csrf = $csrfToken('fittings_fit_commit');
        $body = "<h1 class='mb-3'>Validate EFT</h1>
          {$errorHtml}
          <div class='card card-body mb-3'>
            <div class='fw-semibold'>Parsed Summary</div>
            <div class='text-muted'>Ship: " . htmlspecialchars((string)($parsed['ship'] ?? '')) . "</div>
            <div class='text-muted'>Fit name: " . htmlspecialchars((string)($parsed['name'] ?? '')) . "</div>
          </div>
          <div class='row g-3'>
            <div class='col-lg-6'>
              <div class='card h-100'>
                <div class='card-header'>EFT Text</div>
                <div class='card-body'>
                  <textarea class='form-control' rows='12' readonly>" . htmlspecialchars($eftText) . "</textarea>
                </div>
              </div>
            </div>
            <div class='col-lg-6'>
              <div class='card h-100'>
                <div class='card-header'>Buy All Preview</div>
                <div class='card-body'>
                  <textarea class='form-control' rows='12' readonly>{$buyBlock}</textarea>
                </div>
              </div>
            </div>
          </div>
          <form method='post' action='/admin/fittings/fits/save' class='mt-3'>
            <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
            <input type='hidden' name='eft_text' value='" . htmlspecialchars($eftText) . "'>
            <input type='hidden' name='category_slug' value='" . htmlspecialchars($categorySlug) . "'>
            <input type='hidden' name='doctrine_slug' value='" . htmlspecialchars($doctrineSlug) . "'>
            <input type='hidden' name='tags' value='" . htmlspecialchars($tagsRaw) . "'>
            <button class='btn btn-primary'" . (!empty($errors) ? ' disabled' : '') . ">Save Fit</button>
            <a class='btn btn-outline-secondary' href='/admin/fittings/fits/new'>Back</a>
          </form>";

        return Response::html($renderPage('Validate EFT', $body), 200);
    });

    $registry->route('POST', '/admin/fittings/fits/save', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $parseEft, $logAudit, $resolveFitTypeIds): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_fit_commit', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $eftText = trim((string)($req->post['eft_text'] ?? ''));
        $categorySlug = trim((string)($req->post['category_slug'] ?? ''));
        $doctrineSlug = trim((string)($req->post['doctrine_slug'] ?? ''));
        $categoryRow = $categorySlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_categories WHERE slug=? LIMIT 1", [$categorySlug]) : null;
        $doctrineRow = $doctrineSlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_doctrines WHERE slug=? LIMIT 1", [$doctrineSlug]) : null;
        $categoryId = (int)($categoryRow['id'] ?? 0);
        $doctrineId = (int)($doctrineRow['id'] ?? 0);
        $tagsRaw = trim((string)($req->post['tags'] ?? ''));
        $parsed = $parseEft($eftText);
        if (!empty($parsed['errors'])) {
            return Response::redirect('/admin/fittings/fits/new');
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
        $uid = (int)($_SESSION['user_id'] ?? 0);

        $fitName = (string)($parsed['name'] ?? 'Fit');
        $fitSlug = $generateSlug('module_fittings_fits', $fitName);
        db_exec($app->db, 
            "INSERT INTO module_fittings_fits\n"
            . " (slug, category_id, doctrine_id, name, ship_name, eft_text, parsed_json, tags_json, created_by, updated_by, created_at, updated_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $fitSlug,
                $categoryId,
                $doctrineId > 0 ? $doctrineId : null,
                $fitName,
                (string)($parsed['ship'] ?? 'Unknown'),
                $eftText,
                json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $uid,
                $uid,
            ]
        );
        $row = db_one($app->db, "SELECT LAST_INSERT_ID() AS id");
        $fitId = (int)($row['id'] ?? 0);

        db_exec($app->db, 
            "INSERT INTO module_fittings_fit_revisions\n"
            . " (fit_id, revision, eft_text, parsed_json, created_by, created_at, change_summary)\n"
            . " VALUES (?, 1, ?, ?, ?, NOW(), 'Initial import')",
            [
                $fitId,
                $eftText,
                json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $uid,
            ]
        );

        $resolveFitTypeIds($parsed);
        $logAudit($uid, 'fit.create', 'fit', $fitId, 'Created fit from EFT');

        return Response::redirect('/admin/fittings/fits');
    });

    $registry->route('GET', '/admin/fittings/fits/edit', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight, $csrfToken): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $fitSlug = trim((string)($req->query['fit'] ?? ''));
        $fit = $fitSlug !== ''
            ? db_one($app->db, "SELECT * FROM module_fittings_fits WHERE slug=? LIMIT 1", [$fitSlug])
            : null;
        if (!$fit) {
            return Response::redirect('/admin/fittings/fits');
        }
        $fitId = (int)($fit['id'] ?? 0);

        $categories = db_all($app->db, "SELECT id, slug, name FROM module_fittings_categories ORDER BY name ASC");
        $doctrines = db_all($app->db, "SELECT id, slug, name FROM module_fittings_doctrines ORDER BY name ASC");
        $tags = json_decode((string)($fit['tags_json'] ?? '[]'), true);
        $tags = is_array($tags) ? implode(', ', $tags) : '';

        $categoryOptions = '';
        foreach ($categories as $cat) {
            $catId = (int)($cat['id'] ?? 0);
            $catSlug = (string)($cat['slug'] ?? '');
            $selected = $catId === (int)($fit['category_id'] ?? 0) ? 'selected' : '';
            $categoryOptions .= "<option value='" . htmlspecialchars($catSlug) . "' {$selected}>" . htmlspecialchars((string)($cat['name'] ?? '')) . "</option>";
        }
        $doctrineOptions = "<option value=''>None</option>";
        foreach ($doctrines as $doc) {
            $docId = (int)($doc['id'] ?? 0);
            $docSlug = (string)($doc['slug'] ?? '');
            $selected = $docId === (int)($fit['doctrine_id'] ?? 0) ? 'selected' : '';
            $doctrineOptions .= "<option value='" . htmlspecialchars($docSlug) . "' {$selected}>" . htmlspecialchars((string)($doc['name'] ?? '')) . "</option>";
        }

        $csrf = $csrfToken('fittings_fit_update');
        $body = "<h1 class='mb-3'>Edit Fit</h1>
          <form method='post' action='/admin/fittings/fits/update' class='card card-body'>
            <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
            <input type='hidden' name='fit_slug' value='" . htmlspecialchars((string)($fit['slug'] ?? '')) . "'>
            <div class='row g-3'>
              <div class='col-md-4'>
                <label class='form-label'>Category</label>
                <select class='form-select' name='category_slug'>{$categoryOptions}</select>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Doctrine</label>
                <select class='form-select' name='doctrine_slug'>{$doctrineOptions}</select>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Tags</label>
                <input class='form-control' name='tags' value='" . htmlspecialchars((string)$tags) . "'>
              </div>
              <div class='col-12'>
                <label class='form-label'>EFT Text</label>
                <textarea class='form-control' name='eft_text' rows='10'>" . htmlspecialchars((string)($fit['eft_text'] ?? '')) . "</textarea>
              </div>
            </div>
            <button class='btn btn-primary mt-3'>Save Changes</button>
          </form>";

        return Response::html($renderPage('Edit Fit', $body), 200);
    });

    $registry->route('POST', '/admin/fittings/fits/update', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $parseEft, $logAudit, $resolveFitTypeIds): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_fit_update', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $fitSlug = trim((string)($req->post['fit_slug'] ?? ''));
        $fit = $fitSlug !== ''
            ? db_one($app->db, "SELECT * FROM module_fittings_fits WHERE slug=? LIMIT 1", [$fitSlug])
            : null;
        if (!$fit) {
            return Response::redirect('/admin/fittings/fits');
        }
        $fitId = (int)($fit['id'] ?? 0);

        $eftText = trim((string)($req->post['eft_text'] ?? ''));
        $categorySlug = trim((string)($req->post['category_slug'] ?? ''));
        $doctrineSlug = trim((string)($req->post['doctrine_slug'] ?? ''));
        $categoryRow = $categorySlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_categories WHERE slug=? LIMIT 1", [$categorySlug]) : null;
        $doctrineRow = $doctrineSlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_doctrines WHERE slug=? LIMIT 1", [$doctrineSlug]) : null;
        $categoryId = (int)($categoryRow['id'] ?? 0);
        $doctrineId = (int)($doctrineRow['id'] ?? 0);
        $tagsRaw = trim((string)($req->post['tags'] ?? ''));
        $parsed = $parseEft($eftText);
        if (!empty($parsed['errors'])) {
            return Response::redirect('/admin/fittings/fits/edit?fit=' . rawurlencode($fitSlug));
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
        $uid = (int)($_SESSION['user_id'] ?? 0);

        $revRow = db_one($app->db, "SELECT MAX(revision) AS rev FROM module_fittings_fit_revisions WHERE fit_id=?", [$fitId]);
        $revision = (int)($revRow['rev'] ?? 0) + 1;

        db_exec($app->db, 
            "UPDATE module_fittings_fits\n"
            . " SET category_id=?, doctrine_id=?, name=?, ship_name=?, eft_text=?, parsed_json=?, tags_json=?,\n"
            . " updated_by=?, updated_at=NOW(), has_renamed_items=0\n"
            . " WHERE id=?",
            [
                $categoryId,
                $doctrineId > 0 ? $doctrineId : null,
                (string)($parsed['name'] ?? 'Fit'),
                (string)($parsed['ship'] ?? 'Unknown'),
                $eftText,
                json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $uid,
                $fitId,
            ]
        );

        db_exec($app->db, 
            "INSERT INTO module_fittings_fit_revisions\n"
            . " (fit_id, revision, eft_text, parsed_json, created_by, created_at, change_summary)\n"
            . " VALUES (?, ?, ?, ?, ?, NOW(), 'Updated fit')",
            [
                $fitId,
                $revision,
                $eftText,
                json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $uid,
            ]
        );

        $resolveFitTypeIds($parsed);
        $logAudit($uid, 'fit.update', 'fit', $fitId, 'Updated fit');

        return Response::redirect('/admin/fittings/fits');
    });

    $registry->route('POST', '/admin/fittings/fits/delete', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        $csrfKey = (string)($req->post['csrf_key'] ?? '');
        if ($csrfKey === '' || !$csrfCheck($csrfKey, (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $fitSlug = trim((string)($req->post['fit_slug'] ?? ''));
        $fitRow = $fitSlug !== '' ? db_one($app->db, "SELECT id FROM module_fittings_fits WHERE slug=? LIMIT 1", [$fitSlug]) : null;
        $fitId = (int)($fitRow['id'] ?? 0);
        if ($fitId > 0) {
            db_exec($app->db, "DELETE FROM module_fittings_fit_revisions WHERE fit_id=?", [$fitId]);
            db_exec($app->db, "DELETE FROM module_fittings_favorites WHERE fit_id=?", [$fitId]);
            db_exec($app->db, "DELETE FROM module_fittings_saved_events WHERE fit_id=?", [$fitId]);
            db_exec($app->db, "DELETE FROM module_fittings_fits WHERE id=?", [$fitId]);
            $logAudit((int)($_SESSION['user_id'] ?? 0), 'fit.delete', 'fit', $fitId, 'Deleted fit');
        }

        return Response::redirect('/admin/fittings/fits');
    });

    $registry->route('GET', '/admin/fittings/audit', function (Request $req) use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $rows = db_all($app->db, 
            "SELECT a.created_at, a.action, a.entity_type, a.message, u.character_name,\n"
            . " f.slug AS fit_slug, d.slug AS doctrine_slug, c.slug AS category_slug\n"
            . " FROM module_fittings_audit_log a\n"
            . " LEFT JOIN eve_users u ON u.id = a.user_id\n"
            . " LEFT JOIN module_fittings_fits f ON f.id = a.entity_id AND a.entity_type='fit'\n"
            . " LEFT JOIN module_fittings_doctrines d ON d.id = a.entity_id AND a.entity_type='doctrine'\n"
            . " LEFT JOIN module_fittings_categories c ON c.id = a.entity_id AND a.entity_type='category'\n"
            . " ORDER BY a.created_at DESC LIMIT 100"
        );
        $rowsHtml = '';
        foreach ($rows as $row) {
            $entityRef = (string)($row['fit_slug'] ?? $row['doctrine_slug'] ?? $row['category_slug'] ?? '');
            $entityLabel = htmlspecialchars((string)($row['entity_type'] ?? ''));
            if ($entityRef !== '') {
                $entityLabel .= " • " . htmlspecialchars($entityRef);
            }
            $rowsHtml .= "<tr>
                <td>" . htmlspecialchars((string)($row['created_at'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['character_name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($row['action'] ?? '')) . "</td>
                <td>{$entityLabel}</td>
                <td>" . htmlspecialchars((string)($row['message'] ?? '')) . "</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='5' class='text-muted'>No audit entries yet.</td></tr>";
        }

        $body = "<h1 class='mb-3'>Fittings Audit Log</h1>
          <div class='card'>
            <div class='table-responsive'>
              <table class='table table-sm table-striped mb-0'>
                <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Message</th></tr></thead>
                <tbody>{$rowsHtml}</tbody>
              </table>
            </div>
          </div>";

        return Response::html($renderPage('Fittings Audit', $body), 200);
    });

    $registry->route('GET', '/admin/fittings/tools', function () use ($app, $renderPage, $requireLogin, $requireRight, $csrfToken): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        $csrfRefresh = $csrfToken('fittings_types_refresh');
        $csrfScan = $csrfToken('fittings_rename_scan');
        $body = "<h1 class='mb-3'>Fittings Admin Tools</h1>
          <div class='card card-body mb-3'>
            <div class='fw-semibold'>Type Name Cache</div>
            <div class='text-muted'>Refresh cached type names and update rename markers.</div>
            <form method='post' action='/admin/fittings/tools/refresh-types' class='mt-3'>
              <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfRefresh) . "'>
              <button class='btn btn-outline-primary'>Rebuild type-name cache</button>
            </form>
          </div>
          <div class='card card-body'>
            <div class='fw-semibold'>Rename Scanner</div>
            <div class='text-muted'>Scan fits for renamed types and flag impacted fits.</div>
            <form method='post' action='/admin/fittings/tools/scan-renames' class='mt-3'>
              <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfScan) . "'>
              <button class='btn btn-outline-primary'>Scan for renamed types</button>
            </form>
          </div>";

        return Response::html($renderPage('Fittings Tools', $body), 200);
    });

    $registry->route('POST', '/admin/fittings/tools/refresh-types', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_types_refresh', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $resolver = new TypeResolver($app->db);
        $rows = db_all($app->db, "SELECT DISTINCT type_id FROM module_fittings_type_names WHERE type_id IS NOT NULL");
        $count = 0;
        foreach ($rows as $row) {
            $typeId = (int)($row['type_id'] ?? 0);
            if ($typeId <= 0) continue;
            $resolver->refreshTypeName($typeId);
            $count++;
        }
        $logAudit((int)($_SESSION['user_id'] ?? 0), 'types.refresh', 'system', 0, "Refreshed {$count} type names");

        return Response::redirect('/admin/fittings/tools');
    });

    $registry->route('POST', '/admin/fittings/tools/scan-renames', function (Request $req) use ($app, $requireLogin, $requireRight, $csrfCheck, $logAudit): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;
        if (!$csrfCheck('fittings_rename_scan', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('Invalid CSRF token', 400);
        }

        $fits = db_all($app->db, "SELECT id, parsed_json FROM module_fittings_fits");
        $impacted = 0;
        foreach ($fits as $fit) {
            $fitId = (int)($fit['id'] ?? 0);
            $parsed = json_decode((string)($fit['parsed_json'] ?? '[]'), true);
            if (!is_array($parsed)) $parsed = [];
            $items = $parsed['items'] ?? [];
            $hasRename = false;
            foreach ($items as $item) {
                $name = (string)($item['name'] ?? '');
                $row = db_one($app->db, "SELECT type_id, current_name, original_name FROM module_fittings_type_names WHERE original_name=? LIMIT 1", [$name]);
                if ($row && (string)($row['current_name'] ?? '') !== (string)($row['original_name'] ?? '')) {
                    $hasRename = true;
                    break;
                }
            }
            if ($hasRename) {
                db_exec($app->db, "UPDATE module_fittings_fits SET has_renamed_items=1 WHERE id=?", [$fitId]);
                $impacted++;
            }
        }
        $logAudit((int)($_SESSION['user_id'] ?? 0), 'types.scan', 'system', 0, "Marked {$impacted} fits with renamed items");

        return Response::redirect('/admin/fittings/tools');
    });

    $jobDefinitions = [
        [
            'key' => 'fittings.types_refresh',
            'name' => 'Fittings: Refresh type names',
            'description' => 'Refresh cached type names and record renames.',
            'schedule' => 21600,
            'enabled' => 1,
            'handler' => function (App $app, array $context = []): array {
                $resolver = new TypeResolver($app->db);
                $rows = db_all($app->db, "SELECT DISTINCT type_id FROM module_fittings_type_names WHERE type_id IS NOT NULL");
                $count = 0;
                foreach ($rows as $row) {
                    $typeId = (int)($row['type_id'] ?? 0);
                    if ($typeId <= 0) continue;
                    $resolver->refreshTypeName($typeId);
                    $count++;
                }
                return ['status' => 'success', 'message' => "Refreshed {$count} type names", 'metrics' => ['types' => $count]];
            },
        ],
        [
            'key' => 'fittings.rename_scan',
            'name' => 'Fittings: Scan for renamed types',
            'description' => 'Flag fits impacted by renamed item names.',
            'schedule' => 43200,
            'enabled' => 1,
            'handler' => function (App $app, array $context = []): array {
                $fits = db_all($app->db, "SELECT id, parsed_json FROM module_fittings_fits");
                $impacted = 0;
                foreach ($fits as $fit) {
                    $fitId = (int)($fit['id'] ?? 0);
                    $parsed = json_decode((string)($fit['parsed_json'] ?? '[]'), true);
                    if (!is_array($parsed)) $parsed = [];
                    $items = $parsed['items'] ?? [];
                    $hasRename = false;
                    foreach ($items as $item) {
                        $name = (string)($item['name'] ?? '');
                        $row = db_one($app->db, "SELECT current_name, original_name FROM module_fittings_type_names WHERE original_name=? LIMIT 1", [$name]);
                        if ($row && (string)($row['current_name'] ?? '') !== (string)($row['original_name'] ?? '')) {
                            $hasRename = true;
                            break;
                        }
                    }
                    db_exec($app->db, "UPDATE module_fittings_fits SET has_renamed_items=? WHERE id=?", [$hasRename ? 1 : 0, $fitId]);
                    if ($hasRename) $impacted++;
                }
                return ['status' => 'success', 'message' => "Scanned fits, impacted {$impacted}", 'metrics' => ['impacted' => $impacted]];
            },
        ],
        [
            'key' => 'fittings.cleanup',
            'name' => 'Fittings: Cleanup logs',
            'description' => 'Remove old audit and saved-to-EVE logs.',
            'schedule' => 86400,
            'enabled' => 1,
            'handler' => function (App $app, array $context = []): array {
                $retentionDays = 90;
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
                db_exec($app->db, "DELETE FROM module_fittings_audit_log WHERE created_at < ?", [$cutoff]);
                db_exec($app->db, "DELETE FROM module_fittings_saved_events WHERE created_at < ?", [$cutoff]);
                return ['status' => 'success', 'message' => "Cleaned logs older than {$retentionDays} days"];
            },
        ],
    ];

    foreach ($jobDefinitions as $definition) {
        JobRegistry::register($definition);
    }
    JobRegistry::sync($app->db);

    $registry->route('GET', '/admin/fittings/cron', function () use ($app, $renderPage, $requireLogin, $requireRight): Response {
        if ($resp = $requireLogin()) return $resp;
        if ($resp = $requireRight('fittings.manage')) return $resp;

        JobRegistry::sync($app->db);
        $jobs = db_all($app->db, 
            "SELECT job_key, name, schedule_seconds, is_enabled, last_run_at, last_status, last_message\n"
            . " FROM module_corptools_jobs WHERE job_key LIKE 'fittings.%' ORDER BY job_key ASC"
        );
        $rows = '';
        foreach ($jobs as $job) {
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($job['job_key'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['name'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['schedule_seconds'] ?? '')) . "s</td>
                <td>" . htmlspecialchars((string)($job['last_status'] ?? '')) . "</td>
                <td>" . htmlspecialchars((string)($job['last_run_at'] ?? '—')) . "</td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='5' class='text-muted'>No fittings jobs registered.</td></tr>";
        }
        $body = "<h1 class='mb-3'>Fittings Cron Jobs</h1>
          <div class='card'>
            <div class='table-responsive'>
              <table class='table table-sm table-striped mb-0'>
                <thead><tr><th>Job</th><th>Name</th><th>Schedule</th><th>Status</th><th>Last Run</th></tr></thead>
                <tbody>{$rows}</tbody>
              </table>
            </div>
          </div>
          <div class='mt-3 text-muted'>Use the main Cron Job Manager for run history and manual runs.</div>";

        return Response::html($renderPage('Fittings Cron', $body), 200);
    });
};
