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

define('ASSET_VERSION', $isLocal ? (string) time() : '1.0.11');

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // The Content-Security-Policy is NOT sent here. It is sent further down, once APP_IS_INSTALLED and the
    // church being served have both been resolved — see the note beside it. Nothing has been emitted by
    // then, so it is still a header rather than a line of the body.
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
// reinstall. So if the DB already contains the app schema AND users, we treat
// the site as installed and heal the missing lock automatically (unless currently
// running through the /install route).
$lockExists = is_file(INSTALL_LOCK_FILE);
$isInstallRoute = (strpos($_SERVER['REQUEST_URI'] ?? '', '/install') !== false) || (PHP_SAPI !== 'cli' && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/installer/') !== false);
$hasSchema = false;
if (!$lockExists && !$isInstallRoute) {
    $hasSchema = Database::hasAppSchema();
}
$installed = $lockExists || ($hasSchema && !$isInstallRoute);

if ($lockExists && !Database::isReachable() && !$hasSchema) {
    @unlink(INSTALL_LOCK_FILE);
    $installed = false;
}
if ($installed && !$lockExists && !$isInstallRoute) {
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

// SaaS: work out which tenant is serving this request. On a single-church
// install this always resolves to the seeded default tenant, so nothing about
// the existing behaviour changes; the migration back-fills it automatically.
if (APP_IS_INSTALLED) {
    try {
        Tenant::current();
    } catch (Throwable $e) {
        error_log('Tenant resolution skipped: ' . $e->getMessage());
    }
}

/*
 * The Content-Security-Policy.
 *
 * It is sent **here** and not with the other security headers near the top, and the reason is a defect
 * this placement caused twice over: `settings()` answers from `config/site.php` alone until
 * `APP_IS_INSTALLED` is defined, and it answers from the shared row alone until the church being served
 * has been resolved. Evaluating anything setting-dependent before this point silently reads the wrong
 * values.
 *
 * It cost the advertiser's inline checkout its content security policy entry: the gateway's origin was
 * looked up in a settings array that could not yet see `payhub_enabled`, came back "not configured", and
 * the origin was never permitted. The page therefore fell back to the hosted checkout on the gateway's own
 * website every single time — which is the one behaviour this whole stage exists to remove, failing
 * silently, in production only, exactly as the plan warned it might.
 *
 * `$isLocal` still decides whether the policy is sent at all, so a developer's machine is unaffected.
 */
if (PHP_SAPI !== 'cli' && !$isLocal) {
    // The admin pages rely on their own inline <script> blocks and inline handlers throughout, and
    // `script-src 'self'` silently blocks all of them — which left the sidebar toggle, pickers and tab
    // controls dead in production. The public site keeps the strict policy; the logged-in, CSRF-protected
    // admin area is allowed inline script so its controls actually work. TODO(Phase 7): move the admin JS
    // into assets/js/*.js and switch this to a nonce so 'unsafe-inline' can be dropped again.
    $isAdminRequest = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/admin');
    $scriptSrc = $isAdminRequest ? "script-src 'self' 'unsafe-inline'" : "script-src 'self'";
    $connectSrc = "connect-src 'self'";

    /*
     * The advertiser's inline checkout is the one page on the public site that has to load a third-party
     * script — the gateway's own `inline.js`, which is what draws the card form inside its iframe. There is
     * no version of an inline checkout without it: the alternative to allowing the origin is not a stricter
     * site, it is a payment button that does nothing.
     *
     * So the origin is allowed, and the exception is kept as small as it can be:
     *
     *  - **two paths only**, the checkout and the return page, so no other page on the site can load or be
     *    made to load the gateway's script;
     *  - **only while the church has the gateway configured** with a public key, so a church that does not
     *    take card payments is not permitting a script it will never use;
     *  - and if anything goes wrong working that out — no database, not installed yet, a thrown error — the
     *    strict policy stands. The checkout page then shows its hosted-checkout fallback, which is the safe
     *    direction to fail in.
     *
     * `connect-src` is widened on the same two paths for the same reason: `inline.js` talks to its own API
     * from the page, and a policy that blocks that produces the silent failure this stage exists to avoid.
     * Nothing about the policy changes anywhere else.
     */
    if (!$isAdminRequest) {
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (in_array($requestPath, ['/advertise/checkout', '/advertise/return'], true)) {
            try {
                if (Payhub::inlineReady()) {
                    $gatewayOrigin = Payhub::scriptOrigin();
                    $scriptSrc .= ' ' . $gatewayOrigin;
                    $connectSrc .= ' ' . $gatewayOrigin;
                }
            } catch (Throwable $e) {
                error_log('CSP: could not resolve the gateway origin: ' . $e->getMessage());
            }
        }
    }

    header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data: https:; media-src \'self\' https:; style-src \'self\' \'unsafe-inline\'; ' . $scriptSrc . '; frame-src https:; ' . $connectSrc);
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
