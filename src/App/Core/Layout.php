<?php
declare(strict_types=1);

namespace App\Core;

final class Layout
{
    public static function page(
        string $title,
        string $bodyHtml,
        array $leftMemberTree,
        array $leftAdminTree,
        array $siteAdminTree,
        array $userMenuTree,
        ?array $moduleMenu = null,
        ?string $brandName = null,
        ?string $brandLogoUrl = null
    ): string {
        $adminHtml = self::renderMenuBootstrap($siteAdminTree);
        $userHtml  = self::renderMenuBootstrap($userMenuTree);
        $sideHtml  = self::renderSideMenuBootstrap($leftMemberTree, $leftAdminTree);
        $moduleHtml = self::renderModuleMenuBootstrap($moduleMenu);

        // Resolve branding centrally (future-proof: callers don't need to remember passing it)
        [$brandName, $brandLogoUrl] = self::resolveBranding($brandName, $brandLogoUrl);

        $adminBlock = '';
        if (trim($adminHtml) !== '') {
            $adminBlock = '
            <div class="dropdown">
              <button class="btn btn-sm btn-warning dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static">
                <i class="bi bi-gear-fill"></i> Site Admin
              </button>
              <ul class="dropdown-menu dropdown-menu-end">' . $adminHtml . '</ul>
            </div>';
        }

        $siteLabel = $brandName ?: 'killsineve.online';

        $brandHtml = '<span class="navbar-brand fw-bold">' . htmlspecialchars($siteLabel) . '</span>';
        if (!empty($brandLogoUrl)) {
            $brandHtml = '
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2 text-decoration-none" href="/">
              <img src="' . htmlspecialchars($brandLogoUrl) . '" alt="Logo" style="width:28px;height:28px;border-radius:8px;">
              <span>' . htmlspecialchars($siteLabel) . '</span>
            </a>';
        }

        $faviconHtml = '';
        if (!empty($brandLogoUrl)) {
            $faviconHtml = '
  <link rel="icon" type="image/png" href="' . htmlspecialchars($brandLogoUrl) . '">
  <link rel="apple-touch-icon" href="' . htmlspecialchars($brandLogoUrl) . '">';
        }

        return '<!doctype html>
<html data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($title) . '</title>' . $faviconHtml . '

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/assets/app.css" rel="stylesheet">
</head>
<body class="app-bg">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary px-3">
  <div class="d-flex align-items-center gap-3">
    ' . $brandHtml . '
    ' . $moduleHtml . '
  </div>

  <div class="ms-auto d-flex gap-2">
    ' . $adminBlock . '

    <div class="dropdown">
      <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static">
        <i class="bi bi-person-circle"></i> User
      </button>
      <ul class="dropdown-menu dropdown-menu-end">' . $userHtml . '</ul>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="row">
    <aside class="col-12 col-md-3 col-lg-2 border-end min-vh-100 p-3">
      <ul class="nav nav-pills flex-column gap-1">' . $sideHtml . '</ul>
    </aside>

    <main class="col-12 col-md-9 col-lg-10 p-4">
      <div class="page-card">
        ' . $bodyHtml . '
      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    }

    /**
     * Centralized branding resolution so all pages (admin + public) render consistently.
     * Priority:
     *  1) Explicit parameters passed by caller
     *  2) Settings table values (multiple key fallbacks)
     *  3) SERVER_NAME (for name) / corp_id -> EVE Tech logo (for logo)
     */
    private static function resolveBranding(?string $brandName, ?string $brandLogoUrl): array
    {
        // Brand name
        if ($brandName === null || trim($brandName) === '') {
            $brandName =
                self::getSettingFirst([
                    'site.name',
                    'brand.name',
                    'site_label',
                    'site.title',
                ]) ?: ($_SERVER['SERVER_NAME'] ?? 'killsineve.online');
        }

        // Brand logo URL
        if ($brandLogoUrl === null || trim($brandLogoUrl) === '') {
            $brandLogoUrl = self::getSettingFirst([
                'site.logo_url',
                'brand.logo_url',
                'site.logo',
            ]);

            // If no explicit logo URL is set, derive from corp_id if present
            if ($brandLogoUrl === null || trim($brandLogoUrl) === '') {
                $corpId = self::getSettingFirst([
                    'corp_id',
                    'corporation_id',
                    'eve.corp_id',
                ]);

                if ($corpId !== null && preg_match('/^\d+$/', $corpId)) {
                    $brandLogoUrl = 'https://images.evetech.net/corporations/' . rawurlencode($corpId) . '/logo?size=64';
                }
            }
        }

        return [$brandName, $brandLogoUrl];
    }

    private static function getSettingFirst(array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = self::getSetting($k);
            if ($v !== null && trim($v) !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Attempts to read a setting via whichever helper exists in the codebase.
     * Supports several common helpers without hard-coupling Layout to DB internals.
     */
    private static function getSetting(string $key): ?string
    {
        try {
            // Namespaced helper (if you later add App\Core\settings_get)
            $nsFn = __NAMESPACE__ . '\\settings_get';
            if (function_exists($nsFn)) {
                $v = $nsFn($key);
                return is_scalar($v) ? (string)$v : null;
            }

            // Global helpers commonly used in this project style
            foreach (['settings_get', 'setting', 'settings'] as $fn) {
                if (function_exists($fn)) {
                    $v = $fn($key);
                    return is_scalar($v) ? (string)$v : null;
                }
            }

            // DB helper fallback: db_one("SELECT value FROM settings WHERE `key`=?", [$key])
            if (function_exists('db_one')) {
                $row = null;

                // try parameterized signature if supported
                try {
                    $row = \db_one("SELECT value FROM settings WHERE `key` = ?", [$key]);
                } catch (\Throwable $e) {
                    // try non-parameterized (last resort)
                    $safe = str_replace("'", "''", $key);
                    $row = \db_one("SELECT value FROM settings WHERE `key` = '{$safe}'");
                }

                if (is_array($row) && array_key_exists('value', $row)) {
                    return is_scalar($row['value']) ? (string)$row['value'] : null;
                }
                if (is_scalar($row)) {
                    return (string)$row;
                }
            }
        } catch (\Throwable $e) {
            // Never break page render due to branding resolution
        }

        return null;
    }

    private static function renderMenuBootstrap(array $tree): string
    {
        $html = '';
        foreach ($tree as $n) {
            $html .= '<li><a class="dropdown-item" href="' . htmlspecialchars($n['url']) . '">' .
                     htmlspecialchars($n['title']) . '</a></li>';
        }
        return $html;
    }

    private static function renderSideMenuBootstrap(array $memberTree, array $adminTree): string
    {
        $html = '';
        $sectionIndex = 0;

        $renderSection = function (string $label, string $icon, array $tree, string $badgeClass) use (&$html, &$sectionIndex): void {
            if (empty($tree)) {
                return;
            }

            $sectionIndex++;
            $collapseId = 'sidebar-section-' . $sectionIndex;
            $html .= '<li class="nav-item">';
            $html .= '<button class="nav-link d-flex justify-content-between align-items-center w-100" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '" aria-expanded="false">';
            $html .= '<span><i class="bi ' . htmlspecialchars($icon) . ' me-2"></i>' . htmlspecialchars($label) . '</span>';
            $html .= '<span class="badge ' . htmlspecialchars($badgeClass) . '">+</span>';
            $html .= '</button>';
            $html .= '<div class="collapse" id="' . $collapseId . '">';
            $html .= '<ul class="nav flex-column ms-3 mt-1">';
            $html .= self::renderSideMenuItems($tree);
            $html .= '</ul></div></li>';
        };

        $renderSection('Member Features', 'bi-grid-1x2', $memberTree, 'text-bg-primary');
        $renderSection('Admin / HR Tools', 'bi-shield-check', $adminTree, 'text-bg-warning');

        return $html;
    }

    private static function renderSideMenuItems(array $tree): string
    {
        $html = '';
        foreach ($tree as $n) {
            $hasChildren = !empty($n['children']);
            if ($hasChildren) {
                $nodeId = 'side-node-' . md5((string)$n['slug']);
                $html .= '<li class="nav-item">';
                $html .= '<button class="nav-link d-flex justify-content-between align-items-center w-100" type="button" data-bs-toggle="collapse" data-bs-target="#' . $nodeId . '" aria-expanded="false">';
                $html .= '<span>' . htmlspecialchars($n['title']) . '</span>';
                $html .= '<span class="badge text-bg-secondary">+</span>';
                $html .= '</button>';
                $html .= '<div class="collapse" id="' . $nodeId . '">';
                $html .= '<ul class="nav flex-column ms-3 mt-1">';
                $html .= self::renderSideMenuItems($n['children']);
                $html .= '</ul></div></li>';
            } else {
                $html .= '<li class="nav-item">';
                $html .= '<a class="nav-link" href="' . htmlspecialchars($n['url']) . '">' . htmlspecialchars($n['title']) . '</a>';
                $html .= '</li>';
            }
        }
        return $html;
    }

    private static function renderModuleMenuBootstrap(?array $moduleMenu): string
    {
        if (!$moduleMenu || empty($moduleMenu['items'])) {
            return '';
        }

        $label = htmlspecialchars((string)($moduleMenu['label'] ?? 'Module'));
        $items = $moduleMenu['items'] ?? [];
        $html = '<div class="dropdown">';
        $html .= '<button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static">';
        $html .= '<i class="bi bi-diagram-3"></i> ' . $label;
        $html .= '</button>';
        $html .= '<ul class="dropdown-menu">';
        foreach ($items as $item) {
            $html .= '<li><a class="dropdown-item" href="' . htmlspecialchars((string)$item['url']) . '">' . htmlspecialchars((string)$item['title']) . '</a></li>';
        }
        $html .= '</ul></div>';
        return $html;
    }
}
