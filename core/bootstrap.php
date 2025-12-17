<?php
declare(strict_types=1);

define('APP_ROOT', realpath(__DIR__ . '/..') ?: __DIR__ . '/..');

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_name('ma_session');
session_start();

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = APP_ROOT . '/src/App/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$CONFIG_PATH = '/var/www/config.php';
if (!is_file($CONFIG_PATH)) {
    http_response_code(500);
    echo "Missing server config at {$CONFIG_PATH}";
    exit;
}
$GLOBALS['APP_CONFIG'] = require $CONFIG_PATH;

