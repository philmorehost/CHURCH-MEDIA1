<?php
declare(strict_types=1);

/**
 * 4-stage installer wizard: (1) requirements + license, (2) database
 * connect + schema import, (3) super admin + branding, (4) finish + lock.
 * Session-gated so a step can't be skipped ahead of what's actually been
 * completed; each step's own file (installer/steps/N-*.php) both renders
 * its form (GET) and processes it (POST) to keep the two in lock-step.
 */

if (APP_IS_INSTALLED) {
    redirect('/');
}

if (empty($_SESSION['install'])) {
    $_SESSION['install'] = ['max_step' => 1];
}

$requested = isset($_GET['step']) ? (int) $_GET['step'] : $_SESSION['install']['max_step'];
$step = max(1, min($requested, $_SESSION['install']['max_step'], 4));

$steps = [
    1 => ['title' => 'Requirements & License', 'file' => __DIR__ . '/steps/1-requirements.php'],
    2 => ['title' => 'Database Setup', 'file' => __DIR__ . '/steps/2-database.php'],
    3 => ['title' => 'Admin & Branding', 'file' => __DIR__ . '/steps/3-admin.php'],
    4 => ['title' => 'Finish', 'file' => __DIR__ . '/steps/4-finish.php'],
];

$errors = [];

// Buffered so a step's POST handler can still call redirect()/header() after
// the layout partial has already echoed the surrounding page markup.
ob_start();
require __DIR__ . '/partials/layout-open.php';
require $steps[$step]['file'];
require __DIR__ . '/partials/layout-close.php';
ob_end_flush();
