<?php
declare(strict_types=1);

// Buffered so any page can still call redirect()/header() after echoing
// markup (e.g. a form re-render that decides midway through to redirect).
// PHP flushes this automatically at script end — no matching ob_end needed.
ob_start();

require_once __DIR__ . '/../bootstrap.php';

if (!APP_IS_INSTALLED) {
    require INSTALLER_PATH . '/index.php';
    exit;
}

$router = new Router();
require CORE_PATH . '/routes.php';
$router->dispatch();
