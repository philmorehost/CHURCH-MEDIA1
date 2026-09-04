<?php
declare(strict_types=1);

/**
 * Single init chain shared by public/index.php (web requests) and
 * cli/media_worker.php (background job runner) — keeps config loading,
 * autoloading, and error handling identical in both contexts.
 */

require_once __DIR__ . '/config/paths.php';

$siteConfig = require CONFIG_PATH . '/site.php';
$isLocal = (getenv('APP_ENV') ?: ($siteConfig['app_env'] ?? 'production')) === 'local';

error_reporting(E_ALL);
ini_set('display_errors', $isLocal ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

date_default_timezone_set($siteConfig['timezone'] ?? 'UTC');

spl_autoload_register(function (string $class): void {
    // core/SecurityGuard.php <- SecurityGuard, etc. Flat namespace, one class per file.
    $path = CORE_PATH . '/' . $class . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once CORE_PATH . '/helpers.php';

define('ASSET_VERSION', $isLocal ? (string) time() : '1.0.9');

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!$isLocal) {
        header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data: https:; media-src \'self\' https:; style-src \'self\' \'unsafe-inline\'; script-src \'self\'; frame-src https:; connect-src \'self\'');
    }
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !$isLocal,
    ]);
    session_start();
}

define('APP_IS_LOCAL', $isLocal);
// The database is the real source of truth for "installed". A lock file alone
// is not proof (config/database.php may be copied or credentials rotated), and
// a missing lock is not proof of a fresh install either: an update upload that
// replaces storage/ wipes storage/installed.lock, which used to force a full
// reinstall. So if the DB already contains the app schema we treat the site as
// installed and heal the missing lock automatically; we only drop the lock (and
// show the installer) when the DB is genuinely unreachable or empty.
$lockExists = is_file(INSTALL_LOCK_FILE);
$hasSchema = false;
if (!$lockExists) {
    $hasSchema = Database::hasAppSchema();
}
$installed = $lockExists || $hasSchema;

if ($lockExists && !Database::isReachable() && !$hasSchema) {
    @unlink(INSTALL_LOCK_FILE);
    $installed = false;
}
if ($installed && !$lockExists) {
    // Heal a lock wiped by an update upload (contents are informational only).
    @file_put_contents(INSTALL_LOCK_FILE, json_encode(['installed_at' => date('c')]));
    $lockExists = true;
}
define('APP_IS_INSTALLED', $installed);

// Bring already-installed databases up to date with the latest schema
// (feature columns/tables added after first install). Stamped, idempotent.
if (APP_IS_INSTALLED) {
    Database::migrate();
}

// Site-wide IP/country gate — runs before any route handles the request.
// Fails open (logs and continues) if the DB isn't reachable, rather than
// taking the whole site down on a transient connection issue.
if (PHP_SAPI !== 'cli' && APP_IS_INSTALLED) {
    try {
        $guard = new SecurityGuard(Database::getInstance()->getConnection());
        $guard->inspectRequest(clientIp(), SecurityGuard::resolveCountryCode());
    } catch (Throwable $e) {
        error_log('SecurityGuard inspectRequest skipped: ' . $e->getMessage());
    }
}
