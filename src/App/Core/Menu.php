<?php
declare(strict_types=1);

namespace App\Core;

final class Menu
{
    private const NODE_CONTAINER = 'container';
    private const NODE_LINK = 'link';
    private const NODE_BOTH = 'both';

    private const AUDIENCE_ADMIN = 'admin';
    private const AUDIENCE_MEMBER = 'member';

    private const REPAIR_SETTING_KEY = 'menu.repair.v2.completed';

    private static bool $repairChecked = false;

    public function __construct(private readonly Db $db)
    {
        $this->maybeRepairSlugs();
    }

    public const AREA_LEFT = 'left';
    public const AREA_ADMIN_TOP = 'admin_top';
    public const AREA_USER_TOP = 'user_top';
    public const AREA_TOP_LEFT = 'top_left';

    public const AREA_LEFT_MEMBER = 'left_member';
    public const AREA_LEFT_ADMIN = 'left_admin';
    public const AREA_MODULE_TOP = 'module_top';
    public const AREA_SITE_ADMIN = 'site_admin_top';

    private const KIND_MODULE_ROOT = 'module_root';
    private const KIND_SUBNAV = 'subnav';
    private const KIND_ACTION = 'action';

    public function register(array $item): void
    {
        // slug, title, url, parent_slug?, sort_order?, area?, right_slug?, enabled?
        $moduleSlug = (string)($item['module_slug'] ?? 'system');
        $slug = trim((string)($item['slug'] ?? ''));
        $rawArea = (string)($item['area'] ?? self::AREA_LEFT_MEMBER);
        $area = self::normalizeArea($rawArea, true);
        $parentSlug = $item['parent_slug'] ?? null;
        $kind = self::normalizeKind((string)($item['kind'] ?? ''), $parentSlug, $area);
        $allowedAreas = self::normalizeAllowedAreas($item['allowed_areas'] ?? null, $area, $kind);
        $url = trim((string)($item['url'] ?? '/'));
        $nodeType = self::normalizeNodeType((string)($item['node_type'] ?? ''), $kind);
        if ($slug === '') {
            $slug = self::generateSlug($moduleSlug, (string)($item['title'] ?? ''), $url, $kind);
        }
        if ($slug === '') throw new \RuntimeException("Menu item missing slug");

        $audience = self::audienceForArea($rawArea, $slug, (string)($item['right_slug'] ?? ''));
        $canonicalSlug = self::normalizeSlugForAudience($slug, $audience);
        $parentSlug = self::normalizeParentSlugForAudience($parentSlug, $audience);

        db_exec($this->db, 
            "INSERT INTO menu_registry (slug, module_slug, kind, node_type, allowed_areas, title, url, parent_slug, sort_order, area, right_slug, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               module_slug=VALUES(module_slug),
               kind=VALUES(kind),
               node_type=VALUES(node_type),
               allowed_areas=VALUES(allowed_areas),
               title=VALUES(title),
               url=VALUES(url),
               parent_slug=VALUES(parent_slug),
               sort_order=VALUES(sort_order),
               area=VALUES(area),
               right_slug=VALUES(right_slug),
               enabled=VALUES(enabled)",
            [
                $canonicalSlug,
                $moduleSlug,
                $kind,
                $nodeType,
                json_encode($allowedAreas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (string)($item['title'] ?? $slug),
                $url !== '' ? $url : '/',
                $parentSlug,
                (int)($item['sort_order'] ?? 10),
                $area,
                $item['right_slug'] ?? null,
                (int)($item['enabled'] ?? 1),
            ]
        );
    }

    public function tree(string $area, callable $hasRight): array
    {
        $area = self::normalizeArea($area);
        $rows = db_all($this->db,
            "SELECT r.slug,
                    COALESCE(o.title, r.title) AS title,
                    COALESCE(o.url, r.url) AS url,
                    COALESCE(o.parent_slug, r.parent_slug) AS parent_slug,
                    COALESCE(o.sort_order, r.sort_order) AS sort_order,
                    COALESCE(o.area, r.area) AS area,
                    COALESCE(o.right_slug, r.right_slug) AS right_slug,
                    COALESCE(o.enabled, r.enabled) AS enabled,
                    COALESCE(o.node_type, r.node_type) AS node_type
             FROM menu_registry r
             LEFT JOIN menu_overrides o ON o.slug = r.slug
             WHERE COALESCE(o.area, r.area) = ?
             UNION ALL
             SELECT c.slug,
                    COALESCE(o.title, c.title) AS title,
                    COALESCE(o.url, c.url) AS url,
                    COALESCE(o.parent_slug, c.parent_slug) AS parent_slug,
                    COALESCE(o.sort_order, c.sort_order) AS sort_order,
                    COALESCE(o.area, c.area) AS area,
                    COALESCE(o.right_slug, c.right_slug) AS right_slug,
                    COALESCE(o.enabled, c.enabled) AS enabled,
                    COALESCE(o.node_type, c.node_type) AS node_type
             FROM menu_custom_items c
             LEFT JOIN menu_overrides o ON o.slug = c.slug
             WHERE COALESCE(o.area, c.area) = ?
             ORDER BY sort_order ASC, slug ASC",
            [$area, $area]
        );

        // filter enabled + rights
        $items = [];
        foreach ($rows as $r) {
            if ((int)$r['enabled'] !== 1) continue;
            $right = $r['right_slug'] ?? null;
            if ($right && !$hasRight((string)$right)) continue;
            $slug = (string)$r['slug'];
            if ($slug === '' || isset($items[$slug])) {
                continue;
            }
            $items[$slug] = [
                'slug' => (string)$r['slug'],
                'title' => (string)$r['title'],
                'url' => (string)$r['url'],
                'parent_slug' => $r['parent_slug'] ? (string)$r['parent_slug'] : null,
                'sort_order' => (int)$r['sort_order'],
                'node_type' => (string)($r['node_type'] ?? self::NODE_LINK),
                'children' => [],
            ];
        }

        // build tree
        $root = [];
        foreach ($items as $slug => &$node) {
            $parent = $node['parent_slug'];
            if ($parent && isset($items[$parent])) {
                $items[$parent]['children'][] = &$node;
            } else {
                $root[] = &$node;
            }
        }
        unset($node);

        // recursive sort children by sort_order then title
        $sortFn = function (&$arr) use (&$sortFn) {
            usort($arr, fn($a,$b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['title'], $b['title']));
            foreach ($arr as &$n) {
                if (!empty($n['children'])) $sortFn($n['children']);
            }
        };
        $sortFn($root);
        self::applyNodeTypes($root);

        return $root;
    }

    public function layoutMenus(string $path, callable $hasRight, bool $loggedIn): array
    {
        $left = $this->tree(self::AREA_LEFT, $hasRight);
        $adminTop = $this->tree(self::AREA_ADMIN_TOP, $hasRight);
        $user = $this->tree(self::AREA_USER_TOP, fn(string $r) => true);
        $loginSlug = self::normalizeSlugForAudience('user.login', self::AUDIENCE_MEMBER);

        if ($loggedIn) {
            $user = array_values(array_filter($user, fn($n) => $n['slug'] !== $loginSlug));
        } else {
            $user = array_values(array_filter($user, fn($n) => $n['slug'] === $loginSlug));
        }

        $moduleTree = $this->tree(self::AREA_TOP_LEFT, $hasRight);
        $moduleContext = self::selectModuleContext($moduleTree, $path);

        return [
            'left' => $left,
            'admin_top' => $adminTop,
            'user' => $user,
            'top_left' => $moduleContext,
        ];
    }

    private static function selectModuleContext(array $tree, string $path): ?array
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $path;
        if ($path === '') {
            $path = '/';
        }
        $path = rtrim($path, '/') ?: '/';

        $best = null;
        $bestLen = 0;
        foreach ($tree as $node) {
            $url = (string)($node['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $normalized = rtrim($url, '/') ?: '/';
            if ($normalized === '/') {
                continue;
            }
            $matches = $path === $normalized || str_starts_with($path, $normalized . '/');
            if ($matches && strlen($normalized) > $bestLen) {
                $best = $node;
                $bestLen = strlen($normalized);
            }
        }

        if (!$best) {
            return null;
        }

        $items = $best['children'] ?? [];
        if (empty($items)) {
            $items = [[
                'title' => (string)$best['title'],
                'url' => (string)$best['url'],
            ]];
        }

        return [
            'label' => (string)$best['title'],
            'items' => $items,
            'url' => (string)$best['url'],
        ];
    }

    public static function normalizeArea(string $area, bool $log = false): string
    {
        $original = trim($area);
        if ($original === '') {
            return self::AREA_LEFT;
        }

        $normalized = strtolower($original);
        $valid = [self::AREA_LEFT, self::AREA_ADMIN_TOP, self::AREA_USER_TOP, self::AREA_TOP_LEFT];
        if (in_array($normalized, $valid, true)) {
            return $normalized;
        }

        $map = [
            self::AREA_LEFT_MEMBER => self::AREA_LEFT,
            self::AREA_LEFT_ADMIN => self::AREA_LEFT,
            self::AREA_MODULE_TOP => self::AREA_TOP_LEFT,
            self::AREA_SITE_ADMIN => self::AREA_ADMIN_TOP,
            self::AREA_USER_TOP => self::AREA_USER_TOP,
            'admin' => self::AREA_ADMIN_TOP,
            'admin_left' => self::AREA_LEFT,
            'admin_hr' => self::AREA_ADMIN_TOP,
            'hr' => self::AREA_ADMIN_TOP,
            'member_left' => self::AREA_LEFT,
            'members' => self::AREA_LEFT,
            'top_left' => self::AREA_TOP_LEFT,
            'member_top' => self::AREA_USER_TOP,
            'user' => self::AREA_USER_TOP,
        ];

        $mapped = $map[$normalized] ?? self::AREA_LEFT;
        if ($log && !array_key_exists($normalized, $map)) {
            error_log("Menu area '{$original}' normalized to '{$mapped}'");
        }
        return $mapped;
    }

    private static function normalizeKind(string $kind, mixed $parentSlug, string $area): string
    {
        $normalized = strtolower(trim($kind));
        $valid = [self::KIND_MODULE_ROOT, self::KIND_SUBNAV, self::KIND_ACTION];
        if (in_array($normalized, $valid, true)) {
            return $normalized;
        }

        if ($parentSlug !== null && $parentSlug !== '') {
            return self::KIND_SUBNAV;
        }
        if ($area === self::AREA_TOP_LEFT) {
            return self::KIND_MODULE_ROOT;
        }
        return self::KIND_ACTION;
    }

    private static function normalizeAllowedAreas(mixed $raw, string $area, string $kind): array
    {
        $areas = [];
        if (is_array($raw)) {
            $areas = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $areas = $decoded;
            } else {
                $areas = array_map('trim', explode(',', $raw));
            }
        }

        $allowed = [];
        foreach ($areas as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $normalized = self::normalizeArea($entry);
            if (!in_array($normalized, $allowed, true)) {
                $allowed[] = $normalized;
            }
        }

        if (empty($allowed)) {
            if ($kind === self::KIND_MODULE_ROOT) {
                return [self::AREA_LEFT, self::AREA_ADMIN_TOP, self::AREA_USER_TOP, self::AREA_TOP_LEFT];
            }
            return [$area];
        }

        return $allowed;
    }

    public static function normalizeUrl(string $url): string
    {
        $raw = trim($url);
        if ($raw === '') {
            return '';
        }
        $parsed = parse_url($raw);
        if ($parsed !== false && is_array($parsed)) {
            $path = (string)($parsed['path'] ?? '');
            $query = (string)($parsed['query'] ?? '');
            $raw = $path !== '' ? $path : $raw;
            if ($query !== '') {
                $raw .= '?' . $query;
            }
        }
        $normalized = strtolower($raw);
        $normalized = rtrim($normalized, '/');
        return $normalized === '' ? '/' : $normalized;
    }

    public static function canonicalKey(array $item): string
    {
        $slug = trim((string)($item['slug'] ?? ''));
        return "slug:{$slug}";
    }

    private static function generateSlug(string $moduleSlug, string $title, string $url, string $kind): string
    {
        $base = $title !== '' ? $title : $url;
        $base = trim($base);
        if ($base === '') {
            $base = $kind !== '' ? $kind : 'item';
        }
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9]+/i', '-', $base);
        $base = trim((string)$base, '-');
        if ($base === '') {
            $base = 'item';
        }
        $hash = substr(sha1($moduleSlug . '|' . $base . '|' . $url), 0, 8);
        return rtrim($moduleSlug, '.') . '.' . $base . '-' . $hash;
    }

    private static function normalizeNodeType(string $nodeType, string $kind): string
    {
        $normalized = strtolower(trim($nodeType));
        $valid = [self::NODE_CONTAINER, self::NODE_LINK, self::NODE_BOTH];
        if (in_array($normalized, $valid, true)) {
            return $normalized;
        }
        if ($kind === self::KIND_MODULE_ROOT) {
            return self::NODE_BOTH;
        }
        return self::NODE_LINK;
    }

    public static function normalizeSlugForAudience(string $slug, string $audience): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return $slug;
        }

        if ($audience === self::AUDIENCE_ADMIN) {
            if (str_ends_with($slug, '.member')) {
                $slug = substr($slug, 0, -7);
            }
            if (str_starts_with($slug, 'admin.') || str_ends_with($slug, '.admin')) {
                return $slug;
            }
            if (str_starts_with($slug, 'custom.')) {
                return $slug . '.admin';
            }
            return 'admin.' . $slug;
        }

        if (str_ends_with($slug, '.admin')) {
            $slug = substr($slug, 0, -6);
        }
        if (str_starts_with($slug, 'admin.')) {
            $slug = substr($slug, 6);
        }
        if (str_ends_with($slug, '.member')) {
            return $slug;
        }
        return $slug . '.member';
    }

    private static function normalizeParentSlugForAudience(mixed $parentSlug, string $audience): ?string
    {
        if (!is_string($parentSlug)) {
            return $parentSlug === null ? null : (string)$parentSlug;
        }
        $parentSlug = trim($parentSlug);
        if ($parentSlug === '') {
            return null;
        }
        return self::normalizeSlugForAudience($parentSlug, $audience);
    }

    public static function audienceForArea(string $area, ?string $slug = null, ?string $rightSlug = null): string
    {
        $raw = strtolower(trim($area));
        if (in_array($raw, ['admin_top', 'site_admin_top', 'left_admin', 'admin', 'admin_left', 'admin_hr', 'hr'], true)) {
            return self::AUDIENCE_ADMIN;
        }
        if (is_string($slug) && ($slug !== '')) {
            if (str_starts_with($slug, 'admin.') || str_ends_with($slug, '.admin')) {
                return self::AUDIENCE_ADMIN;
            }
        }
        if (is_string($rightSlug) && $rightSlug !== '') {
            if (str_starts_with($rightSlug, 'admin.') || str_ends_with($rightSlug, '.admin')) {
                return self::AUDIENCE_ADMIN;
            }
        }
        $normalized = self::normalizeArea($area);
        if (in_array($normalized, [self::AREA_ADMIN_TOP, self::AREA_SITE_ADMIN], true)) {
            return self::AUDIENCE_ADMIN;
        }
        return self::AUDIENCE_MEMBER;
    }

    private static function applyNodeTypes(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                self::applyNodeTypes($node['children']);
            }

            $nodeType = strtolower((string)($node['node_type'] ?? self::NODE_LINK));
            $nodeType = in_array($nodeType, [self::NODE_CONTAINER, self::NODE_LINK, self::NODE_BOTH], true)
                ? $nodeType
                : self::NODE_LINK;
            $hasChildren = !empty($node['children']);
            $url = (string)($node['url'] ?? '');

            if ($hasChildren && $url !== '' && $nodeType === self::NODE_LINK) {
                $nodeType = self::NODE_BOTH;
            } elseif ($hasChildren && $url === '' && $nodeType === self::NODE_LINK) {
                $nodeType = self::NODE_CONTAINER;
            } elseif (!$hasChildren && $nodeType === self::NODE_CONTAINER) {
                $nodeType = $url === '' ? self::NODE_CONTAINER : self::NODE_LINK;
            }

            $node['node_type'] = $nodeType;
        }
        unset($node);
    }

    private function maybeRepairSlugs(): void
    {
        if (self::$repairChecked) {
            return;
        }
        self::$repairChecked = true;

        try {
            if (!self::tableExists($this->db, 'settings')) {
                return;
            }
            $settings = new Settings($this->db);
            $completed = (string)($settings->get(self::REPAIR_SETTING_KEY, '') ?? '');
            if ($completed !== '') {
                return;
            }
            if (!self::tableExists($this->db, 'menu_registry')
                || !self::tableExists($this->db, 'menu_custom_items')
                || !self::tableExists($this->db, 'menu_repair_report')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        try {
            $this->repairMenuSlugs();
            $settings = new Settings($this->db);
            $settings->set(self::REPAIR_SETTING_KEY, gmdate('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            // Do not fail menu loads if repair fails.
        }
    }

    private static function tableExists(Db $db, string $table): bool
    {
        try {
            $row = db_one($db, "SHOW TABLES LIKE ?", [$table]);
            return $row !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function repairMenuSlugs(): void
    {
        $registryRows = db_all(
            $this->db,
            "SELECT id, slug, area, right_slug
             FROM menu_registry
             ORDER BY id ASC"
        );
        $customRows = db_all(
            $this->db,
            "SELECT id, slug, area, right_slug, public_id
             FROM menu_custom_items
             ORDER BY id ASC"
        );

        $used = [];
        foreach ($registryRows as $row) {
            $used[(string)$row['slug']] = (int)$row['id'];
        }
        foreach ($customRows as $row) {
            $used[(string)$row['slug']] = (int)$row['id'];
        }

        $slugMap = [];
        foreach ($registryRows as $row) {
            $slug = (string)$row['slug'];
            $audience = self::audienceForArea((string)$row['area'], $slug, (string)($row['right_slug'] ?? ''));
            $normalized = self::normalizeSlugForAudience($slug, $audience);
            $normalized = $this->ensureUniqueSlug($normalized, $used, (int)$row['id']);
            if ($normalized !== $slug) {
                $slugMap[$slug] = $normalized;
                $used[$normalized] = (int)$row['id'];
            }
        }

        $customPublicIds = [];
        foreach ($customRows as $row) {
            $publicId = (string)($row['public_id'] ?? '');
            if ($publicId === '') {
                $publicId = Identifiers::generatePublicId($this->db, 'menu_custom_items');
                $customPublicIds[(int)$row['id']] = $publicId;
            }
        }

        foreach ($customRows as $row) {
            $slug = (string)$row['slug'];
            $publicId = $customPublicIds[(int)$row['id']] ?? (string)($row['public_id'] ?? '');
            if ($publicId === '') {
                continue;
            }
            $audience = self::audienceForArea((string)$row['area'], $slug, (string)($row['right_slug'] ?? ''));
            $baseSlug = 'custom.' . $publicId;
            $normalized = self::normalizeSlugForAudience($baseSlug, $audience);
            $normalized = $this->ensureUniqueSlug($normalized, $used, (int)$row['id']);
            if ($normalized !== $slug) {
                $slugMap[$slug] = $normalized;
                $used[$normalized] = (int)$row['id'];
            }
        }

        if ($slugMap === [] && $customPublicIds === []) {
            return;
        }

        db_tx($this->db, function () use ($slugMap, $customPublicIds): void {
            foreach ($customPublicIds as $id => $publicId) {
                db_exec($this->db, "UPDATE menu_custom_items SET public_id=? WHERE id=?", [$publicId, $id]);
                $this->logRepair('custom_public_id', "Assigned public ID {$publicId} to custom item {$id}.", [
                    'custom_id' => $id,
                    'public_id' => $publicId,
                ]);
            }

            foreach ($slugMap as $old => $new) {
                $updatedRegistry = db_exec($this->db, "UPDATE menu_registry SET slug=? WHERE slug=?", [$new, $old]);
                $updatedCustom = db_exec($this->db, "UPDATE menu_custom_items SET slug=? WHERE slug=?", [$new, $old]);
                $updatedOverrides = db_exec($this->db, "UPDATE menu_overrides SET slug=? WHERE slug=?", [$new, $old]);

                $this->logRepair('slug_rename', "Renamed menu slug {$old} to {$new}.", [
                    'old_slug' => $old,
                    'new_slug' => $new,
                    'registry' => $updatedRegistry,
                    'custom' => $updatedCustom,
                    'overrides' => $updatedOverrides,
                ]);
            }

            foreach ($slugMap as $old => $new) {
                $registryParents = db_exec($this->db, "UPDATE menu_registry SET parent_slug=? WHERE parent_slug=?", [$new, $old]);
                $customParents = db_exec($this->db, "UPDATE menu_custom_items SET parent_slug=? WHERE parent_slug=?", [$new, $old]);
                $overrideParents = db_exec($this->db, "UPDATE menu_overrides SET parent_slug=? WHERE parent_slug=?", [$new, $old]);

                if ($registryParents + $customParents + $overrideParents > 0) {
                    $this->logRepair('parent_update', "Re-parented menu items from {$old} to {$new}.", [
                        'old_slug' => $old,
                        'new_slug' => $new,
                        'registry' => $registryParents,
                        'custom' => $customParents,
                        'overrides' => $overrideParents,
                    ]);
                }
            }
        });
    }

    private function ensureUniqueSlug(string $slug, array $used, int $id): string
    {
        if (!isset($used[$slug]) || $used[$slug] === $id) {
            return $slug;
        }
        return $slug . '-' . $id;
    }

    private function logRepair(string $type, string $message, array $details): void
    {
        db_exec(
            $this->db,
            "INSERT INTO menu_repair_report (change_type, message, details_json)
             VALUES (?, ?, ?)",
            [
                $type,
                $message,
                json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
