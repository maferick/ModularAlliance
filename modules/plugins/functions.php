<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Settings;

function plugins_get_disabled(App $app): array
{
    $settings = new Settings($app->db);
    $raw = $settings->get('plugins.disabled', '') ?? '';
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $slug) {
        if (is_string($slug) && $slug !== '') {
            $out[] = $slug;
        }
    }

    return array_values(array_unique($out));
}

function plugins_set_disabled(App $app, array $slugs): void
{
    $settings = new Settings($app->db);
    $clean = [];
    foreach ($slugs as $slug) {
        if (is_string($slug) && $slug !== '') {
            $clean[] = $slug;
        }
    }
    $clean = array_values(array_unique($clean));
    sort($clean);
    $settings->set('plugins.disabled', json_encode($clean));
}

function plugins_parse_header(string $file): array
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        return [];
    }

    $headerBlock = null;
    if (preg_match('/\A\s*<\?php\s*\/\*([\s\S]*?)\*\//', $contents, $matches)) {
        $headerBlock = $matches[1];
    } elseif (preg_match('/\A\s*\/\*([\s\S]*?)\*\//', $contents, $matches)) {
        $headerBlock = $matches[1];
    }

    if ($headerBlock === null) {
        return [];
    }

    $fields = [
        'Module Name' => 'name',
        'Plugin Name' => 'name',
        'Description' => 'description',
        'Version' => 'version',
        'Module Slug' => 'slug',
        'Plugin Slug' => 'slug',
    ];

    $out = [];
    foreach (preg_split('/\r?\n/', trim($headerBlock)) as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        if (!str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        if ($key === '' || $value === '') {
            continue;
        }
        if (isset($fields[$key])) {
            $out[$fields[$key]] = $value;
        }
    }

    return $out;
}
