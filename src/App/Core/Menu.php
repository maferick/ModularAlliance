<?php
declare(strict_types=1);

namespace App\Core;

final class Menu
{
    public function __construct(private readonly Db $db) {}

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
        $slug = (string)($item['slug'] ?? '');
        $area = self::normalizeArea((string)($item['area'] ?? self::AREA_LEFT_MEMBER), true);
        $parentSlug = $item['parent_slug'] ?? null;
        $kind = self::normalizeKind((string)($item['kind'] ?? ''), $parentSlug, $area);
        $allowedAreas = self::normalizeAllowedAreas($item['allowed_areas'] ?? null, $area, $kind);
        if ($slug === '') {
            $slug = self::generateSlug($moduleSlug, (string)($item['title'] ?? ''), (string)($item['url'] ?? ''), $kind);
        }
        if ($slug === '') throw new \RuntimeException("Menu item missing slug");

        db_exec($this->db, 
            "INSERT INTO menu_registry (slug, module_slug, kind, allowed_areas, title, url, parent_slug, sort_order, area, right_slug, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               module_slug=VALUES(module_slug),
               kind=VALUES(kind),
               allowed_areas=VALUES(allowed_areas),
               title=VALUES(title),
               url=VALUES(url),
               parent_slug=VALUES(parent_slug),
               sort_order=VALUES(sort_order),
               area=VALUES(area),
               right_slug=VALUES(right_slug),
               enabled=VALUES(enabled)",
            [
                $slug,
                $moduleSlug,
                $kind,
                json_encode($allowedAreas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (string)($item['title'] ?? $slug),
                (string)($item['url'] ?? '/'),
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
                    COALESCE(o.enabled, r.enabled) AS enabled
             FROM menu_registry r
             LEFT JOIN menu_overrides o ON o.slug = r.slug
             WHERE COALESCE(o.area, r.area) = ?
             ORDER BY COALESCE(o.sort_order, r.sort_order) ASC, r.slug ASC",
            [$area]
        );

        // filter enabled + rights
        $items = [];
        foreach ($rows as $r) {
            if ((int)$r['enabled'] !== 1) continue;
            $right = $r['right_slug'] ?? null;
            if ($right && !$hasRight((string)$right)) continue;
            $items[(string)$r['slug']] = [
                'slug' => (string)$r['slug'],
                'title' => (string)$r['title'],
                'url' => (string)$r['url'],
                'parent_slug' => $r['parent_slug'] ? (string)$r['parent_slug'] : null,
                'sort_order' => (int)$r['sort_order'],
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

        return $root;
    }

    public function layoutMenus(string $path, callable $hasRight, bool $loggedIn): array
    {
        $left = $this->tree(self::AREA_LEFT, $hasRight);
        $adminTop = $this->tree(self::AREA_ADMIN_TOP, $hasRight);
        $user = $this->tree(self::AREA_USER_TOP, fn(string $r) => true);

        if ($loggedIn) {
            $user = array_values(array_filter($user, fn($n) => $n['slug'] !== 'user.login'));
        } else {
            $user = array_values(array_filter($user, fn($n) => $n['slug'] === 'user.login'));
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
}
