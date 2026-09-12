<?php
declare(strict_types=1);

/**
 * Small stateless helpers shared across public views, admin views, and API
 * endpoints. Loaded once from bootstrap.php. Kept framework-free on purpose.
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || ($needle !== '' && substr($haystack, -strlen($needle)) === $needle);
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function clientIp(): string
{
    // Trust X-Forwarded-For only if a trusted proxy config says so; kept simple
    // (direct REMOTE_ADDR) since this ships without a known reverse-proxy setup.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function baseUrl(string $path = ''): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return '/assets/' . ltrim($path, '/') . '?v=' . ASSET_VERSION;
}

/**
 * True when the pinned-reels columns exist on media_posts. The pinned feature
 * was added by a migration, so a server that hasn't run it yet (or where the
 * migration failed) must still serve the feed — the APIs fall back to plain
 * ordering instead of erroring on unknown columns. Result is cached per request.
 */
function mediaPinnedColumnsExist(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_posts' AND COLUMN_NAME IN ('is_pinned','pinned_at','pinned_expires_at')");
        $stmt->execute();
        $cached = (int) $stmt->fetchColumn() === 3;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function uploadUrl(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return baseUrl('uploads/' . ltrim($path, '/'));
}

/**
 * The markup for a hero background photo — two elements, never one.
 *
 * A hero fills the viewport, so on a phone it is very tall and very narrow (roughly
 * 375 x 709). A landscape photo cannot fill a box like that without being blown up until
 * almost all of it is off-screen: a 2:1 photo at 375px wide is only 188px tall, so
 * `object-fit: cover` would scale it to 709px tall, giving a 1,418px-wide image of which
 * just 375px — 26% — is visible. The church uploads a picture of the congregation and sees
 * a zoomed crop of the middle of it.
 *
 * So the photo is never asked to fill the box. The whole thing is shown, complete, over a
 * blurred copy of itself, which keeps the hero looking deliberate rather than like a
 * photo floating on a background. Nothing the church uploads is ever lost, at any screen
 * size, whatever shape their picture is.
 *
 * The blurred layer is decorative: it is hidden from assistive technology and carries no
 * alt text, and the real alt belongs to the sharp copy. Both are the same URL, so the
 * browser fetches it once.
 *
 * @param string $alt     Meaningful alt for the photo; empty for a purely decorative one.
 * @param string $loading 'eager' for the first hero on a page, otherwise 'lazy'.
 */
function heroPhotoMarkup(?string $src, string $alt = '', string $loading = 'eager'): string
{
    if (!$src) {
        return '';
    }

    $safeSrc = e($src);

    return '<img class="hero-img-blur" src="' . $safeSrc . '" alt="" aria-hidden="true" loading="' . e($loading) . '" decoding="async">'
        . '<img class="hero-img-contain" src="' . $safeSrc . '" alt="' . e($alt) . '" loading="' . e($loading) . '" decoding="async"'
        . ($loading === 'eager' ? ' fetchpriority="high"' : '') . '>';
}

function redirect(string $path)
{
    header('Location: ' . $path);
    exit;
}

function jsonResponse(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Streams rows as an Excel-friendly CSV download and exits. fputcsv handles
 * quoting/escaping, and the UTF-8 BOM makes it open correctly in Excel.
 */
function csvDownload(string $filename, array $headers, array $rows)
{
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

/** Directory where server-hosted CSV exports live (created on demand). */
function exportsDir(): string
{
    $dir = STORAGE_PATH . '/exports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Renders headers+rows into an Excel-friendly CSV string (UTF-8 BOM). */
function buildCsvFile(array $headers, array $rows): string
{
    $out = fopen('php://temp', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    rewind($out);
    $content = (string) stream_get_contents($out);
    fclose($out);
    return $content;
}

/** Public, shareable URL for an export token. */
function exportUrl(string $token): string
{
    return baseUrl('export/' . rawurlencode($token));
}

/**
 * Saves a CSV on the server and records it in export_files. Returns
 * ['token'=>.., 'url'=>.., 'filename'=>..] so callers can flash the link.
 */
function saveExportFile(PDO $pdo, string $kind, string $title, array $headers, array $rows, ?int $formId, ?int $userId): array
{
    $token = bin2hex(random_bytes(24));
    $base = mb_substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', $title) ?: 'export', 0, 80);
    $filename = $base . '-' . date('Y-m-d') . '-' . substr($token, 0, 8) . '.csv';
    file_put_contents(exportsDir() . '/' . $filename, buildCsvFile($headers, $rows));
    $stmt = $pdo->prepare('INSERT INTO export_files (kind, title, filename, token, path, form_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$kind, $title, $filename, $token, $filename, $formId, $userId]);
    return ['token' => $token, 'url' => exportUrl($token), 'filename' => $filename];
}

function slugify(string $text): string
{
    $text = trim($text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: bin2hex(random_bytes(4));
}

/** AES-256 key derived from the install's fingerprint salt (stable per install). */
function emailSecretKey(): string
{
    static $key = null;
    if ($key === null) {
        $sec = require CONFIG_PATH . '/security.php';
        $key = hash('sha256', (string) ($sec['fingerprint_salt'] ?? 'church-media-email-key'));
    }
    return $key;
}

/** Encrypts a secret (e.g. the registrant's password) for storage at rest. */
function encryptSecret(string $plain): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', emailSecretKey(), 0, $iv);
    return $cipher === false ? '' : base64_encode($iv . $cipher);
}

/** Decrypts a value produced by encryptSecret(); null when invalid. */
function decryptSecret(string $payload): ?string
{
    if ($payload === '') {
        return null;
    }
    $raw = base64_decode($payload);
    if ($raw === false || strlen($raw) <= 16) {
        return null;
    }
    $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', emailSecretKey(), 0, substr($raw, 0, 16));
    return $out === false ? null : $out;
}

/**
 * cPanel-style password strength, 0-100. Length tiers add 10 each (at 8, 10,
 * 12, 14, 16, 18, 20 chars) and each character class adds 15 (upper, lower,
 * digit, symbol). cPanel's own default minimum strength is 65.
 */
function cpanelPasswordScore(string $pw): int
{
    $score = 0;
    $len = strlen($pw);
    foreach ([8, 10, 12, 14, 16, 18, 20] as $threshold) {
        if ($len >= $threshold) { $score += 10; }
    }
    if (preg_match('/[A-Z]/', $pw)) { $score += 15; }
    if (preg_match('/[a-z]/', $pw)) { $score += 15; }
    if (preg_match('/[0-9]/', $pw)) { $score += 15; }
    if (preg_match('/[^A-Za-z0-9]/', $pw)) { $score += 15; }
    return min(100, $score);
}

/** True when a password meets cPanel's default minimum strength (65). */
function strongEnoughPassword(string $pw): bool
{
    return cpanelPasswordScore($pw) >= 65;
}

/**
 * Lazily loads settings for the current tenant and caches them for the request.
 *
 * Resolution order, each layer overriding the last: config/site.php defaults →
 * the shared `settings` row (tenant_id IS NULL) → this tenant's own row. A
 * single-church install keeps one shared row and behaves exactly as before; a
 * tenant only stores the handful of values that differ (site title, branding,
 * SMS credentials, …).
 */
function settings(): array
{
    $tenantId = class_exists('Tenant') ? Tenant::id() : null;

    if (isset($GLOBALS['__settings_cache']) && ($GLOBALS['__settings_cache_tenant'] ?? 'unset') === $tenantId) {
        return $GLOBALS['__settings_cache'];
    }
    $GLOBALS['__settings_cache_tenant'] = $tenantId;

    $defaults = require CONFIG_PATH . '/site.php';
    if (!defined('APP_IS_INSTALLED') || !APP_IS_INSTALLED) {
        return $GLOBALS['__settings_cache'] = $defaults;
    }

    try {
        $pdo = Database::getInstance()->getConnection();
        $merged = $defaults;

        $shared = $pdo->query('SELECT * FROM settings WHERE tenant_id IS NULL ORDER BY id ASC LIMIT 1')->fetch();
        if ($shared) {
            $merged = array_merge($merged, array_filter($shared, fn ($v) => $v !== null));
        }

        if ($tenantId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM settings WHERE tenant_id = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$tenantId]);
            $own = $stmt->fetch();
            if ($own) {
                $merged = array_merge($merged, array_filter($own, fn ($v) => $v !== null));
            }
        }

        $GLOBALS['__settings_cache'] = $merged;
    } catch (Throwable $e) {
        $GLOBALS['__settings_cache'] = $defaults;
    }
    return $GLOBALS['__settings_cache'];
}

/** Drops the cached settings so a read after a write in the same request is accurate. */
function settingsForget(): void
{
    unset($GLOBALS['__settings_cache'], $GLOBALS['__settings_cache_tenant']);
}

function setting(string $key, $default = null)
{
    return settings()[$key] ?? $default;
}

/**
 * Writes settings for the current tenant.
 *
 * Creates the tenant's own `settings` row on first write and updates it after
 * that, so a tenant only ever stores the values that differ from the shared
 * defaults row. Column names come from our own code, never from request input.
 *
 * Note: the older settings screens still write to the shared row directly —
 * they move onto this helper during the Phase 7 tenant-aware settings pass, and
 * on a single-church install both paths are equivalent.
 */
function settingSave(array $values): bool
{
    $values = array_filter($values, static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    if (!$values) {
        return false;
    }

    $pdo = Database::getInstance()->getConnection();
    $tenantId = class_exists('Tenant') ? Tenant::id() : null;

    if ($tenantId === null) {
        $row = $pdo->query('SELECT id FROM settings WHERE tenant_id IS NULL ORDER BY id ASC LIMIT 1')->fetch();
    } else {
        $lookup = $pdo->prepare('SELECT id FROM settings WHERE tenant_id = ? ORDER BY id ASC LIMIT 1');
        $lookup->execute([$tenantId]);
        $row = $lookup->fetch();
    }

    $columns = array_keys($values);
    $params = array_values($values);

    if ($row) {
        $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', $columns));
        $params[] = (int) $row['id'];
        $pdo->prepare('UPDATE settings SET ' . $set . ' WHERE id = ?')->execute($params);
    } else {
        $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $pdo->prepare('INSERT INTO settings (tenant_id, ' . $cols . ') VALUES (?, ' . $marks . ')')
            ->execute(array_merge([$tenantId], $params));
    }

    settingsForget();
    return true;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function old(string $key, string $default = ''): string
{
    $value = $_SESSION['_old'][$key] ?? $default;
    return e((string) $value);
}

function keepOld(array $input): void
{
    $_SESSION['_old'] = $input;
}

function clearOld(): void
{
    unset($_SESSION['_old']);
}

/**
 * Renders a view file with $data extracted into scope, optionally inside the
 * site layout. The view runs *before* the layout's <head> is emitted (via an
 * output buffer) so a view can set $metaTitle/$metaDescription for its own
 * page — those locals are still in scope when layout-open.php requires next.
 */
function render(string $view, array $data = [], bool $layout = true): void
{
    extract($data, EXTR_SKIP);

    if (!$layout) {
        require VIEWS_PATH . '/' . $view . '.php';
        return;
    }

    ob_start();
    require VIEWS_PATH . '/' . $view . '.php';
    $content = ob_get_clean();

    require VIEWS_PATH . '/partials/layout-open.php';
    echo $content;
    require VIEWS_PATH . '/partials/layout-close.php';
}

function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'just now';
    }
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $seconds => $label) {
        $count = intdiv($diff, $seconds);
        if ($count >= 1) {
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

/**
 * Converts YouTube, Facebook, Vimeo, Instagram, TikTok, or Rumble video/reel
 * links to cross-origin, autoplay-enabled embed URLs.
 */
function embedUrl(?string $url, bool $autoplay = true): ?string
{
    if (!$url) {
        return null;
    }
    $url = trim($url);

    // YouTube: watch, shorts, live, embed, youtu.be
    $ytId = youtubeVideoId($url);
    if ($ytId) {
        return 'https://www.youtube-nocookie.com/embed/' . $ytId . ($autoplay ? '?autoplay=1&mute=0&enablejsapi=1' : '');
    }

    // Facebook video / reel / watch / fb.watch / fb.com
    if (str_contains($url, 'facebook.com') || str_contains($url, 'fb.watch') || str_contains($url, 'fb.com')) {
        return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($url) . '&show_text=false' . ($autoplay ? '&autoplay=1&mute=0' : '');
    }

    // Vimeo
    if (preg_match('#vimeo\.com/(?:video/)?([0-9]+)#', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1] . ($autoplay ? '?autoplay=1' : '');
    }

    // Instagram post/reel
    if (str_contains($url, 'instagram.com')) {
        $clean = rtrim(explode('?', $url)[0], '/');
        return $clean . '/embed/';
    }

    // TikTok
    if (preg_match('#tiktok\.com/@[^/]+/video/([0-9]+)#', $url, $m)) {
        return 'https://www.tiktok.com/embed/v2/' . $m[1];
    }

    // Rumble
    if (str_contains($url, 'rumble.com')) {
        if (preg_match('#rumble\.com/embed/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return 'https://rumble.com/embed/' . $m[1] . '/' . ($autoplay ? '?autoplay=2' : '');
        }
        if (preg_match('#rumble\.com/(v[a-zA-Z0-9_-]+)#', $url, $m)) {
            return 'https://rumble.com/embed/' . $m[1] . '/' . ($autoplay ? '?autoplay=2' : '');
        }
    }

    return $url;
}

/** Extracts a YouTube video id from watch/shorts/embed/live/share URLs, or null. */
function youtubeVideoId(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    $patterns = [
        '#youtube\.com/watch\?[^&\s]*&?v=([a-zA-Z0-9_-]{6,})#',
        '#youtube\.com/(?:embed|shorts|live|v)/([a-zA-Z0-9_-]{6,})#',
        '#youtu\.be/([a-zA-Z0-9_-]{6,})#',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, trim($url), $m)) {
            return $m[1];
        }
    }
    return null;
}

/** Public thumbnail URL for a YouTube video id. */
function youtubeThumbnailUrl(?string $videoId): string
{
    return $videoId ? 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg' : '';
}

function formatCount(int $count): string
{
    if ($count >= 1000000) {
        return round($count / 1000000, 1) . 'M';
    }
    if ($count >= 1000) {
        return round($count / 1000, 1) . 'K';
    }
    return (string) $count;
}

/** Parses the "one option per line" textarea into a clean list (select/radio/checkbox). */
function formFieldOptions(array $field): array
{
    $options = array_filter(array_map('trim', explode("\n", (string) ($field['options'] ?? ''))));
    return array_values($options);
}

/**
 * Splits "Province > Zone > Area > Parish" style path lines into nested path
 * arrays. Used by the cascading-dropdown field type ('cascade').
 */
function formCascadeOptions(array $field): array
{
    $paths = [];
    foreach (array_filter(array_map('trim', explode("\n", (string) ($field['options'] ?? '')))) as $line) {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/', $line) ?: [$line]), fn ($p) => $p !== ''));
        if ($parts) {
            $paths[] = $parts;
        }
    }
    return $paths;
}

/** Same cascade paths joined as "A > B > C" strings — used to validate submissions. */
function formCascadePaths(array $field): array
{
    return array_map(fn (array $p): string => implode(' > ', $p), formCascadeOptions($field));
}

/**
 * Full "A > B > C" paths for every church in the org hierarchy (leaves only),
 * using the configured level names. Powers the auto church-list field ('church').
 */
function churchCascadePaths(): array
{
    $paths = [];
    $walk = function (array $nodes, array $prefix) use (&$walk, &$paths): void {
        foreach ($nodes as $node) {
            $cur = array_merge($prefix, [(string) $node['name']]);
            if (!empty($node['children'])) {
                $walk($node['children'], $cur);
            } else {
                $paths[] = implode(' > ', $cur);
            }
        }
    };
    $walk(Unit::tree(), []);
    return $paths;
}

/** True when a form has an end date that has already passed (validity window closed). */
function formsExpired(array $form): bool
{
    if (empty($form['end_at'])) {
        return false;
    }
    return strtotime((string) $form['end_at']) <= time();
}

/** True when a form is currently accepting responses (active + not past its end date). */
function formsAccepting(array $form): bool
{
    return !empty($form['is_active']) && !formsExpired($form);
}

/**
 * Whether the current visitor may see a form's contents: public forms are
 * always open, private forms need a session unlock (link + password), and
 * admins who manage the form (or any super admin) skip the gate.
 */
function formUnlocked(array $form): bool
{
    if (($form['visibility'] ?? 'public') !== 'private') {
        return true;
    }
    if (!empty($_SESSION['form_unlocked'][(int) $form['id']])) {
        return true;
    }
    $user = Auth::user();
    return $user !== null && Unit::inScope($user, (int) ($form['org_unit_id'] ?? 0));
}

/** Stashes the raw POST payload so the public form can repopulate inputs after a validation error. */
function keepFormOld(array $input): void
{
    $_SESSION['_form_old'] = $input;
}

/** Returns the previously submitted value for a form input (string for scalar fields, array for checkbox). */
function formOld(string $key, $default = '')
{
    $old = $_SESSION['_form_old'] ?? [];
    return array_key_exists($key, $old) ? $old[$key] : $default;
}

function clearFormOld(): void
{
    unset($_SESSION['_form_old']);
}

/** Normalizes PHP's $_FILES shape (single vs. multiple) into a flat per-key list of file arrays. */
function normalizeUploadedFiles(array $files): array
{
    $out = [];
    foreach ($files as $key => $file) {
        if (!is_array($file['name'] ?? null)) {
            $out[$key][] = $file;
            continue;
        }
        foreach ($file['name'] as $i => $_) {
            $out[$key][] = [
                'name' => $file['name'][$i],
                'type' => $file['type'][$i] ?? '',
                'tmp_name' => $file['tmp_name'][$i] ?? '',
                'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$i] ?? 0,
            ];
        }
    }
    return $out;
}

/**
 * Validates + compresses one uploaded image for a form field. Accepts any image
 * format (JPG/PNG/GIF/WebP/BMP/AVIF), auto-shrinks it, and returns the stored
 * relative path ('form-files/xxx') or null when the file isn't a usable image.
 * Throws RuntimeException for a recoverable violation (too large).
 */
function storeFormImageUpload(array $file): ?string
{
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $maxBytes = 8 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Image "' . $file['name'] . '" is too large — max 8MB per file.');
    }
    $name = MediaProcessor::compressImage($file['tmp_name'], UPLOADS_FORM_PATH);
    if (!$name) {
        return null;
    }
    return 'form-files/' . $name;
}

/**
 * Conversion state of one media item row (media_post_items).
 * 'converted'  — a real 9:16 crop finished (converted_at set)
 * 'pending'    — uploaded original waiting to be processed (no crop yet)
 * 'original'   — plays the original as-is (crop unavailable/failed); never converted
 * 'youtube'    — a YouTube embed, no conversion involved
 * 'image'      — a photo, no conversion involved
 */
function videoConversionStatus(array $item): string
{
    if (($item['type'] ?? '') === 'image') {
        return 'image';
    }
    if (($item['source'] ?? '') === 'youtube') {
        return 'youtube';
    }
    if (!empty($item['converted_at'])) {
        return 'converted';
    }
    if (str_starts_with((string) ($item['file_path'] ?? ''), 'originals/')) {
        return 'pending';
    }
    return 'original';
}


/**
 * Replaces {{token}} placeholders in a page content section with live site
 * settings (admin-editable at /admin/settings). Supported tokens:
 * {{site_title}}, {{site_tagline}}, {{contact_email}}, {{contact_phone}},
 * {{address}}, {{effective_date}}. Returns the section with all string
 * values resolved.
 */
function resolvePageTokens(array $section): array
{
    $s = settings();
    $tokens = [
        '{{site_title}}'    => (string) ($s['site_title'] ?? ''),
        '{{site_tagline}}'  => (string) ($s['site_tagline'] ?? ''),
        '{{contact_email}}' => (string) ($s['contact_email'] ?? ''),
        '{{contact_phone}}' => (string) ($s['contact_phone'] ?? ''),
        '{{address}}'       => (string) ($s['address'] ?? ''),
        '{{site_url}}'      => rtrim(baseUrl(), '/'),
        '{{effective_date}}' => date('F j, Y'),
    ];
    foreach ($section as $key => $value) {
        if (is_string($value)) {
            $section[$key] = strtr($value, $tokens);
        } elseif (is_array($value)) {
            $section[$key] = resolvePageTokens($value);
        }
    }
    return $section;
}

/**
 * Renders a page's content sections into the public design templates. Each
 * section is one block in the JSON stored on `pages.content`:
 * hero / text / columns / image / quote / cta.
 */
function renderPageSections(array $sections): void
{
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $section = resolvePageTokens($section);
        switch ($section['type'] ?? 'text') {
            case 'hero':
                $img = !empty($section['image']) ? uploadUrl((string) $section['image']) : null;
                echo '<section class="page-hero' . ($img ? ' has-img' : '') . '">';
                if ($img) {
                    echo heroPhotoMarkup($img, (string) ($section['alt'] ?? ''));
                    echo '<div class="page-hero-shade"></div>';
                }
                echo '<div class="page-hero-inner">';
                if (!empty($section['eyebrow'])) {
                    echo '<span class="eyebrow">' . e((string) $section['eyebrow']) . '</span>';
                }
                if (!empty($section['title'])) {
                    echo '<h1>' . e((string) $section['title']) . '</h1>';
                }
                if (!empty($section['subtitle'])) {
                    echo '<p class="page-hero-sub">' . e((string) $section['subtitle']) . '</p>';
                }
                echo '</div></section>';
                break;

            case 'text':
                $center = ($section['align'] ?? '') === 'center' ? ' center' : '';
                echo '<section class="section page-text' . $center . '"><div class="container" style="max-width:780px;">';
                if (!empty($section['heading'])) {
                    echo '<h2 class="page-heading">' . e((string) $section['heading']) . '</h2>';
                }
                foreach (preg_split('/\n{2,}/', trim((string) ($section['body'] ?? ''))) ?: [] as $para) {
                    if (trim($para) !== '') {
                        echo '<p class="page-body">' . nl2br(e(trim($para))) . '</p>';
                    }
                }
                echo '</div></section>';
                break;

            case 'columns':
                $cols = array_values(array_filter($section['columns'] ?? [], 'is_array'));
                echo '<section class="section"><div class="container">';
                if (!empty($section['heading'])) {
                    echo '<div class="section-head"><span class="eyebrow">' . e((string) ($section['eyebrow'] ?? '')) . '</span><h2>' . e((string) $section['heading']) . '</h2></div>';
                }
                $layoutCols = !empty($section['layout_columns']) ? (int) $section['layout_columns'] : count($cols);
                $n = min(4, max(1, $layoutCols));
                echo '<div class="grid grid-' . $n . '">';
                foreach ($cols as $col) {
                    $colImage = !empty($col['image']) ? uploadUrl((string) $col['image']) : '';
                    echo '<div class="glass-card cms-card">';
                    if ($colImage !== '') {
                        echo '<div class="cms-card-media"><img src="' . e($colImage) . '" alt="' . e((string) ($col['alt'] ?? '')) . '" loading="lazy" decoding="async"></div>';
                    }
                    echo '<div class="cms-card-body">';
                    if (!empty($col['heading'])) {
                        echo '<h3 class="cms-card-title">' . e((string) $col['heading']) . '</h3>';
                    }
                    if (!empty($col['body'])) {
                        echo '<p class="cms-card-text">' . nl2br(e((string) $col['body'])) . '</p>';
                    }
                    echo '</div>';
                    if (!empty($col['link'])) {
                        echo '<div class="cms-card-foot"><a href="' . e((string) $col['link']) . '" class="btn sm secondary">Learn More →</a></div>';
                    }
                    echo '</div>';
                }
                echo '</div></div></section>';
                break;

            case 'image':
                if (empty($section['image'])) {
                    break;
                }
                echo '<section class="section"><div class="container">';
                echo '<figure class="page-figure"><img src="' . e(uploadUrl((string) $section['image'])) . '" alt="' . e((string) ($section['alt'] ?? '')) . '" loading="lazy">';
                if (!empty($section['caption'])) {
                    echo '<figcaption>' . e((string) $section['caption']) . '</figcaption>';
                }
                echo '</figure></div></section>';
                break;

            case 'quote':
                if (empty($section['quote'])) {
                    break;
                }
                echo '<section class="section"><div class="container">';
                echo '<blockquote class="page-quote">';
                echo '<span class="q-mark">”</span><p>' . e((string) $section['quote']) . '</p>';
                if (!empty($section['source'])) {
                    echo '<footer>— ' . e((string) $section['source']) . '</footer>';
                }
                echo '</blockquote></div></section>';
                break;

            case 'cta':
                echo '<section class="section"><div class="container" style="text-align:center;">';
                if (!empty($section['title'])) {
                    echo '<h2 class="page-cta-title">' . e((string) $section['title']) . '</h2>';
                }
                if (!empty($section['subtitle'])) {
                    echo '<p class="page-cta-sub">' . e((string) $section['subtitle']) . '</p>';
                }
                if (!empty($section['label'])) {
                    $url = (string) ($section['url'] ?? '#');
                    if (!preg_match('#^https?://#', $url) && !str_starts_with($url, '/')) {
                        $url = '/' . $url;
                    }
                    echo '<div class="hero-actions" style="margin-top:24px;"><a class="btn btn-gold" href="' . e($url) . '">' . e((string) $section['label']) . '</a></div>';
                }
                echo '</div></section>';
                break;

            case 'team':
                try {
                    $pdo = Database::getInstance()->getConnection();
                    $heading = !empty($section['heading']) ? $section['heading'] : 'Leadership & Ministry Team';
                    $eyebrow = !empty($section['eyebrow']) ? $section['eyebrow'] : 'Our People';
                    $members = $pdo->query('SELECT * FROM team_members WHERE is_published = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
                    if ($members) {
                        echo '<section class="section page-team"><div class="container">';
                        echo '<div class="section-head"><span class="eyebrow">' . e((string) $eyebrow) . '</span><h2>' . e((string) $heading) . '</h2></div>';
                        $colCount = min(4, max(1, count($members)));
                        echo '<div class="grid grid-' . $colCount . '">';
                        foreach ($members as $m) {
                            echo '<div class="glass-card team-card" style="padding:28px 22px; text-align:center; display:flex; flex-direction:column; align-items:center;">';
                            if (!empty($m['photo'])) {
                                echo '<div style="width:110px; height:110px; margin:0 auto 16px; border-radius:50%; overflow:hidden; border:2px solid var(--gold); box-shadow:0 6px 20px rgba(0,0,0,0.3); flex-shrink:0;">';
                                echo '<img src="' . e(uploadUrl((string) $m['photo'])) . '" alt="' . e((string) $m['name']) . '" style="width:100%; height:100%; object-fit:cover;">';
                                echo '</div>';
                            } else {
                                echo '<div style="width:110px; height:110px; margin:0 auto 16px; border-radius:50%; background:var(--bg-2); display:flex; align-items:center; justify-content:center; border:2px solid var(--gold); font-size:36px; font-weight:700; color:var(--gold); flex-shrink:0;">';
                                echo e(strtoupper(substr(trim((string) $m['name']), 0, 1)));
                                echo '</div>';
                            }
                            echo '<h3 style="margin:0 0 4px; font-size:20px; line-height:1.2;">' . e((string) $m['name']) . '</h3>';
                            if (!empty($m['role_title'])) {
                                echo '<p style="color:var(--gold-soft); font-size:13.5px; font-weight:600; margin:0 0 12px; letter-spacing:0.02em;">' . e((string) $m['role_title']) . '</p>';
                            }
                            if (!empty($m['bio'])) {
                                echo '<p style="color:var(--ink-dim); font-size:14px; margin:0; line-height:1.6;">' . nl2br(e((string) $m['bio'])) . '</p>';
                            }
                            echo '</div>';
                        }
                        echo '</div></div></section>';
                    }
                } catch (Throwable $e) {
                    error_log('Error rendering team section: ' . $e->getMessage());
                }
                break;
        }
    }
}
