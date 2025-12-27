<?php
declare(strict_types=1);

/*
Module Name: Plugins
Description: Manage uploaded plugins (upload, download, edit).
Version: 1.0.0
*/

use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Settings;
use App\Http\Request;
use App\Http\Response;

require_once __DIR__ . '/functions.php';

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('admin.plugins', 'Manage plugins.');

    $registry->menu([
        'slug' => 'admin.plugins',
        'title' => 'Plugins',
        'url' => '/admin/plugins',
        'sort_order' => 45,
        'area' => 'site_admin_top',
        'right_slug' => 'admin.plugins',
    ]);

    $modulesRoot = APP_ROOT . '/modules';
    $protectedSlugs = ['auth', 'plugins'];

    $getDisabled = function () use ($app): array {
        return plugins_get_disabled($app);
    };

    $setDisabled = function (array $slugs) use ($app): void {
        plugins_set_disabled($app, $slugs);
    };

    $parseHeader = function (string $file): array {
        return plugins_parse_header($file);
    };

    $listPlugins = function () use ($modulesRoot, $parseHeader): array {
        $plugins = [];
        foreach (glob($modulesRoot . '/*/module.php') ?: [] as $file) {
            $slug = basename(dirname($file));
            $meta = $parseHeader($file);
            $plugins[] = [
                'slug' => $meta['slug'] ?? $slug,
                'dir' => $slug,
                'name' => $meta['name'] ?? $slug,
                'description' => $meta['description'] ?? '',
                'version' => $meta['version'] ?? '',
            ];
        }

        usort($plugins, fn(array $a, array $b) => strcmp((string)$a['name'], (string)$b['name']));
        return $plugins;
    };

    $sanitizeSlug = function (?string $slug): ?string {
        if (!is_string($slug) || $slug === '') return null;
        if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) return null;
        return $slug;
    };

    $resolvePluginDir = function (?string $slug) use ($modulesRoot, $sanitizeSlug): ?string {
        $safe = $sanitizeSlug($slug);
        if ($safe === null) return null;
        $dir = $modulesRoot . '/' . $safe;
        if (!is_dir($dir)) return null;
        $real = realpath($dir);
        $root = realpath($modulesRoot);
        if ($real === false || $root === false) return null;
        if (!str_starts_with($real, $root)) return null;
        return $real;
    };

    $renderAdmin = function (string $title, string $body) use ($app): Response {
        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
        $menus = $app->menu->layoutMenus($_SERVER['REQUEST_URI'] ?? '/', $hasRight, $loggedIn);

        return Response::html(Layout::page($title, $body, $menus['left_member'], $menus['left_admin'], $menus['site_admin'], $menus['user'], $menus['module']), 200);
    };

    $registry->route('GET', '/admin/plugins', function (Request $req) use ($listPlugins, $renderAdmin, $getDisabled, $protectedSlugs): Response {
        $notice = is_string($req->query['notice'] ?? null) ? $req->query['notice'] : '';
        $error = is_string($req->query['error'] ?? null) ? $req->query['error'] : '';
        $disabled = $getDisabled();

        $alert = '';
        if ($notice !== '') {
            $alert = '<div class="alert alert-success">' . htmlspecialchars($notice) . '</div>';
        } elseif ($error !== '') {
            $alert = '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
        }

        $rows = '';
        foreach ($listPlugins() as $plugin) {
            $slug = htmlspecialchars((string)$plugin['slug']);
            $dir = htmlspecialchars((string)$plugin['dir']);
            $name = htmlspecialchars((string)$plugin['name']);
            $desc = htmlspecialchars((string)$plugin['description']);
            $version = htmlspecialchars((string)$plugin['version']);
            $isDisabled = in_array($plugin['dir'], $disabled, true) && !in_array($plugin['dir'], $protectedSlugs, true);
            $statusBadge = $isDisabled
                ? '<span class="badge text-bg-secondary">Disabled</span>'
                : '<span class="badge text-bg-success">Enabled</span>';

            $actions = '<div class="btn-group btn-group-sm" role="group">'
                . '<a class="btn btn-outline-light" href="/admin/plugins/download?slug=' . $dir . '">Download</a>'
                . '<a class="btn btn-outline-light" href="/admin/plugins/edit?slug=' . $dir . '">Edit</a>'
                . '</div>';

            if (!in_array($plugin['dir'], $protectedSlugs, true)) {
                $toggleLabel = $isDisabled ? 'Enable' : 'Disable';
                $toggleValue = $isDisabled ? 'enable' : 'disable';
                $actions .= '<form method="post" action="/admin/plugins/toggle" class="d-inline ms-2">'
                    . '<input type="hidden" name="slug" value="' . $dir . '">'
                    . '<input type="hidden" name="action" value="' . $toggleValue . '">'
                    . '<button class="btn btn-outline-warning btn-sm" type="submit">' . $toggleLabel . '</button>'
                    . '</form>';
            } else {
                $actions .= '<span class="text-muted ms-2 small">Protected</span>';
            }

            $rows .= '<tr>'
                . '<td><div class="fw-semibold">' . $name . '</div><div class="text-muted small"><code>' . $slug . '</code></div></td>'
                . '<td>' . ($version !== '' ? $version : '—') . '</td>'
                . '<td>' . ($desc !== '' ? $desc : '<span class="text-muted">No description.</span>') . '</td>'
                . '<td>' . $statusBadge . '</td>'
                . '<td>' . $actions . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="text-muted">No plugins found.</td></tr>';
        }

        $body = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">'
            . '<div><h1 class="mb-1">Plugins</h1>'
            . '<div class="text-muted">Upload, download, and edit module plugins.</div></div>'
            . '</div>'
            . $alert
            . '<div class="card mb-4"><div class="card-body">'
            . '<h2 class="h5">Upload Plugin</h2>'
            . '<p class="text-muted mb-3">Disabling a plugin prevents it from being loaded on next app boot. Protected plugins cannot be disabled.</p>'
            . '<form class="row g-3" method="post" enctype="multipart/form-data" action="/admin/plugins/upload">'
            . '<div class="col-md-6">'
            . '<label class="form-label" for="plugin-zip">Plugin zip</label>'
            . '<input class="form-control" type="file" id="plugin-zip" name="plugin_zip" accept=".zip" required>'
            . '</div>'
            . '<div class="col-md-6 d-flex align-items-end">'
            . '<div class="form-check">'
            . '<input class="form-check-input" type="checkbox" id="plugin-overwrite" name="overwrite" value="1">'
            . '<label class="form-check-label" for="plugin-overwrite">Overwrite existing plugin</label>'
            . '</div>'
            . '</div>'
            . '<div class="col-12">'
            . '<button class="btn btn-primary" type="submit">Upload</button>'
            . '</div>'
            . '</form>'
            . '</div></div>'
            . '<div class="table-responsive">'
            . '<table class="table table-sm align-middle">'
            . '<thead><tr><th>Plugin</th><th>Version</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '</div>';

        return $renderAdmin('Plugins', $body);
    }, ['right' => 'admin.plugins']);

    $registry->route('POST', '/admin/plugins/toggle', function (Request $req) use ($sanitizeSlug, $getDisabled, $setDisabled, $protectedSlugs): Response {
        $slug = is_string($req->post['slug'] ?? null) ? $req->post['slug'] : null;
        $action = is_string($req->post['action'] ?? null) ? $req->post['action'] : '';
        $safe = $sanitizeSlug($slug);
        if ($safe === null) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Invalid plugin.'));
        }

        if (in_array($safe, $protectedSlugs, true) && $action === 'disable') {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('This plugin is protected.'));
        }

        $disabled = $getDisabled();
        $changed = false;

        if ($action === 'disable') {
            if (!in_array($safe, $disabled, true)) {
                $disabled[] = $safe;
                $changed = true;
            }
        } elseif ($action === 'enable') {
            $disabled = array_values(array_filter($disabled, fn(string $slug): bool => $slug !== $safe));
            $changed = true;
        } else {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Unknown action.'));
        }

        if ($changed) {
            $setDisabled($disabled);
        }

        $message = $action === 'disable' ? 'Plugin disabled.' : 'Plugin enabled.';
        return Response::redirect('/admin/plugins?notice=' . rawurlencode($message));
    }, ['right' => 'admin.plugins']);

    $registry->route('POST', '/admin/plugins/upload', function (Request $req) use ($modulesRoot, $sanitizeSlug): Response {
        $file = $_FILES['plugin_zip'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Upload failed.'));
        }

        if (!class_exists(ZipArchive::class)) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('ZipArchive not available.'));
        }

        $zip = new ZipArchive();
        if ($zip->open((string)($file['tmp_name'] ?? '')) !== true) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Unable to open zip.'));
        }

        $topLevels = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') continue;
            $parts = explode('/', $name);
            if ($parts[0] !== '') $topLevels[$parts[0]] = true;
        }

        $topKeys = array_keys($topLevels);
        if (count($topKeys) !== 1) {
            $zip->close();
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Zip must contain a single top-level plugin folder.'));
        }

        $folder = $topKeys[0];
        $slug = $sanitizeSlug($folder);
        if ($slug === null) {
            $zip->close();
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Invalid plugin folder name.'));
        }

        $targetDir = $modulesRoot . '/' . $slug;
        $overwrite = isset($req->post['overwrite']);

        if (is_dir($targetDir) && !$overwrite) {
            $zip->close();
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Plugin already exists. Enable overwrite to replace.'));
        }

        $removeDir = function (string $dir) use (&$removeDir): void {
            foreach (scandir($dir) ?: [] as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $removeDir($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        };

        if (is_dir($targetDir) && $overwrite) {
            $removeDir($targetDir);
        }

        mkdir($targetDir, 0775, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') continue;

            if (str_starts_with($name, $folder . '/')) {
                $relative = substr($name, strlen($folder) + 1);
            } else {
                continue;
            }

            if ($relative === '') continue;
            if (str_contains($relative, '..') || str_starts_with($relative, '/')) continue;

            $dest = $targetDir . '/' . $relative;
            if (str_ends_with($name, '/')) {
                if (!is_dir($dest)) mkdir($dest, 0775, true);
                continue;
            }

            $destDir = dirname($dest);
            if (!is_dir($destDir)) mkdir($destDir, 0775, true);

            $stream = $zip->getStream($name);
            if (!is_resource($stream)) continue;
            $contents = stream_get_contents($stream);
            fclose($stream);
            if ($contents === false) continue;

            file_put_contents($dest, $contents);
        }

        $zip->close();

        if (!is_file($targetDir . '/module.php')) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Plugin extracted but module.php was not found.'));
        }

        return Response::redirect('/admin/plugins?notice=' . rawurlencode('Plugin uploaded.'));
    }, ['right' => 'admin.plugins']);

    $registry->route('GET', '/admin/plugins/download', function (Request $req) use ($resolvePluginDir): Response {
        $slug = is_string($req->query['slug'] ?? null) ? $req->query['slug'] : null;
        $dir = $resolvePluginDir($slug);
        if ($dir === null) {
            return Response::text('Plugin not found.', 404);
        }

        if (!class_exists(ZipArchive::class)) {
            return Response::text('ZipArchive not available.', 500);
        }

        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'plugin_');
        if ($tmp === false) {
            return Response::text('Unable to create temp file.', 500);
        }

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return Response::text('Unable to create zip.', 500);
        }

        $root = realpath($dir);
        if ($root === false) {
            return Response::text('Plugin not found.', 404);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $path = $fileInfo->getPathname();
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            if ($fileInfo->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($path, $relative);
            }
        }

        $zip->close();

        $contents = file_get_contents($tmp);
        unlink($tmp);
        if ($contents === false) {
            return Response::text('Unable to read zip.', 500);
        }

        $filename = basename($dir) . '.zip';
        return new Response(200, $contents, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }, ['right' => 'admin.plugins']);

    $registry->route('GET', '/admin/plugins/edit', function (Request $req) use ($resolvePluginDir, $renderAdmin): Response {
        $slug = is_string($req->query['slug'] ?? null) ? $req->query['slug'] : null;
        $dir = $resolvePluginDir($slug);
        if ($dir === null) {
            return $renderAdmin('Plugin Editor', '<div class="alert alert-danger">Plugin not found.</div>');
        }

        $files = [];
        $root = realpath($dir);
        if ($root !== false) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $relative = ltrim(str_replace($root, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
                    $files[] = $relative;
                }
            }
        }

        sort($files);
        $selected = is_string($req->query['file'] ?? null) ? $req->query['file'] : 'module.php';
        if (!in_array($selected, $files, true)) {
            $selected = $files[0] ?? 'module.php';
        }

        $selectedPath = $root !== false ? $root . '/' . $selected : null;
        $contents = '';
        $error = '';
        if ($selectedPath && is_file($selectedPath)) {
            $data = file_get_contents($selectedPath);
            if ($data === false) {
                $error = 'Unable to read selected file.';
            } else {
                $contents = $data;
            }
        } else {
            $error = 'Selected file not found.';
        }

        $options = '';
        foreach ($files as $file) {
            $safe = htmlspecialchars($file);
            $isSelected = $file === $selected ? 'selected' : '';
            $options .= "<option value=\"{$safe}\" {$isSelected}>{$safe}</option>";
        }

        $alert = $error !== '' ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '';

        $body = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">'
            . '<div><h1 class="mb-1">Edit Plugin</h1>'
            . '<div class="text-muted">Modify plugin files directly.</div></div>'
            . '<a class="btn btn-outline-light btn-sm" href="/admin/plugins">Back to plugins</a>'
            . '</div>'
            . $alert
            . '<form method="post" action="/admin/plugins/edit" class="card">'
            . '<div class="card-body">'
            . '<input type="hidden" name="slug" value="' . htmlspecialchars((string)$slug) . '">'
            . '<div class="mb-3">'
            . '<label class="form-label" for="plugin-file">File</label>'
            . '<select class="form-select" id="plugin-file" name="file">'
            . $options
            . '</select>'
            . '</div>'
            . '<div class="mb-3">'
            . '<label class="form-label" for="plugin-content">Contents</label>'
            . '<textarea class="form-control font-monospace" id="plugin-content" name="content" rows="20">'
            . htmlspecialchars($contents)
            . '</textarea>'
            . '</div>'
            . '<button class="btn btn-primary" type="submit">Save Changes</button>'
            . '</div>'
            . '</form>'
            . '<script>'
            . 'document.getElementById("plugin-file").addEventListener("change", function(e){'
            . 'const file = encodeURIComponent(e.target.value);'
            . 'window.location.href = "/admin/plugins/edit?slug=' . rawurlencode((string)$slug) . '&file=" + file;'
            . '});'
            . '</script>';

        return $renderAdmin('Edit Plugin', $body);
    }, ['right' => 'admin.plugins']);

    $registry->route('POST', '/admin/plugins/edit', function (Request $req) use ($resolvePluginDir): Response {
        $slug = is_string($req->post['slug'] ?? null) ? $req->post['slug'] : null;
        $dir = $resolvePluginDir($slug);
        if ($dir === null) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Plugin not found.'));
        }

        $root = realpath($dir);
        $file = is_string($req->post['file'] ?? null) ? $req->post['file'] : 'module.php';
        if ($root === false || str_contains($file, '..') || str_starts_with($file, '/')) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('Invalid file selection.'));
        }

        $target = $root . '/' . $file;
        if (!is_file($target)) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('File not found.'));
        }

        $content = is_string($req->post['content'] ?? null) ? $req->post['content'] : '';
        if (strlen($content) > 1024 * 1024) {
            return Response::redirect('/admin/plugins?error=' . rawurlencode('File too large to save.'));
        }

        file_put_contents($target, $content);

        return Response::redirect('/admin/plugins/edit?slug=' . rawurlencode((string)$slug) . '&file=' . rawurlencode($file));
    }, ['right' => 'admin.plugins']);
};
