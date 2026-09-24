<?php
declare(strict_types=1);

use TripleR\Config;

define('APP_ROOT', dirname(__DIR__));
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once APP_ROOT . '/app/Config.php';
Config::load(APP_ROOT);

spl_autoload_register(static function (string $class): void {
    $prefix = 'TripleR\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Manila') ?? 'Asia/Manila');
