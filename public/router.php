<?php
declare(strict_types=1);

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = realpath(__DIR__);
$requestedFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($requestedPath, '/\\'));
if ($publicRoot !== false && $requestedFile !== false && is_file($requestedFile)
    && str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR)) {
    return false;
}
require __DIR__ . '/index.php';
