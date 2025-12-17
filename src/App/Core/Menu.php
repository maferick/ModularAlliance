<?php
declare(strict_types=1);

namespace App\Core;

final class Menu
{
    public function __construct(private readonly Db $db) {}

    public function register(array $item): void
    {
        // slug, title, url, parent_slug?, sort_order?, area?, right_slug?, enabled?
        $slug = (string)($item['slug'] ?? '');
        if ($slug === '') throw new \RuntimeException("Menu item missing slug");

        $this->db->run(
            "INSERT INTO menu_registry (slug, title, url, parent_slug, sort_order, area, right_slug, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               title=VALUES(title),
               url=VALUES(url),
               parent_slug=VALUES(parent_slug),
               sort_order=VALUES(sort_order),
               area=VALUES(area),
               right_slug=VALUES(right_slug),
               enabled=VALUES(enabled)",
            [
                $slug,
                (string)($item['title'] ?? $slug),
                (string)($item['url'] ?? '/'),
                $item['parent_slug'] ?? null,
                (int)($item['sort_order'] ?? 10),
                (string)($item['area'] ?? 'left'),
                $item['right_slug'] ?? null,
                (int)($item['enabled'] ?? 1),
            ]
        );
    }

    public function tree(string $area, callable $hasRight): array
    {
        $rows = $this->db->all(
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
}

