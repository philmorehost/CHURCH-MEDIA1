<?php
declare(strict_types=1);

/**
 * Dev-only router for `php -S` (the built-in server ignores .htaccess).
 * Mirrors public/.htaccess: real files are served as-is, everything else
 * goes through the front controller so clean URLs work in local dev too.
 */

$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
