<?php
declare(strict_types=1);

namespace App\Core;

final class Layout
{
    public static function page(
        string $title,
        string $bodyHtml,
        array $leftMenuTree,
        array $adminMenuTree,
        array $userMenuTree
    ): string {
        $adminHtml = self::renderMenuBootstrap($adminMenuTree);
        $userHtml  = self::renderMenuBootstrap($userMenuTree);
        $sideHtml  = self::renderSideMenuBootstrap($leftMenuTree);

        $adminBlock = '';
        if (trim($adminHtml) !== '') {
            $adminBlock = '
            <div class="dropdown">
              <button class="btn btn-sm btn-warning dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-shield-lock"></i> Admin
              </button>
              <ul class="dropdown-menu dropdown-menu-end">' . $adminHtml . '</ul>
            </div>';
        }

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($title) . '</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/assets/app.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary px-3">
  <span class="navbar-brand fw-bold">killsineve.online</span>

  <div class="ms-auto d-flex gap-2">
    ' . $adminBlock . '

    <div class="dropdown">
      <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-person-circle"></i> User
      </button>
      <ul class="dropdown-menu dropdown-menu-end">' . $userHtml . '</ul>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="row">
    <aside class="col-12 col-md-3 col-lg-2 bg-white border-end min-vh-100 p-3">
      <ul class="nav nav-pills flex-column gap-1">' . $sideHtml . '</ul>
    </aside>

    <main class="col-12 col-md-9 col-lg-10 p-4">
      ' . $bodyHtml . '
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
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

    private static function renderSideMenuBootstrap(array $tree): string
    {
        $html = '';
        foreach ($tree as $n) {
            $html .= '<li class="nav-item">';
            $html .= '<a class="nav-link" href="' . htmlspecialchars($n['url']) . '">' .
                     htmlspecialchars($n['title']) . '</a>';

            if (!empty($n['children'])) {
                $html .= '<ul class="nav flex-column ms-3">';
                $html .= self::renderSideMenuBootstrap($n['children']);
                $html .= '</ul>';
            }

            $html .= '</li>';
        }
        return $html;
    }
}
