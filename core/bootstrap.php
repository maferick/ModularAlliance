<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_name('ma_session');
session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $rel = substr($class, strlen($prefix));
        $path = APP_ROOT . '/src/App/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($path)) require $path;
    }
});

function app_config(): array {
    $cfgFile = '/var/www/config.php';
    if (!is_file($cfgFile)) {
        http_response_code(500);
        echo "Missing server config: /var/www/config.php\n";
        exit;
    }
    $cfg = require $cfgFile;
    if (!is_array($cfg)) {
        http_response_code(500);
        echo "Invalid config format in /var/www/config.php\n";
        exit;
    }
    return $cfg;
}
