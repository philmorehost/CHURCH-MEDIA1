<?php
declare(strict_types=1);

/**
 * The web app manifest, generated per church.
 *
 * Served dynamically rather than as a static file, because everything in a manifest that a person
 * actually sees is the church's own: the name under the icon on the home screen, the description, and
 * the icon itself. A static file would put the same name on every church's home screen — the same
 * mistake a cron report made with `setting('site_title')` before 7d-ii.
 *
 * Each church is reached on its own domain (`tenants.domain`), so each origin gets its own manifest,
 * its own service worker and its own cache. That is the whole reason this can be dynamic.
 */

header('Content-Type: application/manifest+json; charset=utf-8');

$s = settings();

$name = trim((string) ($s['site_title'] ?? ''));
if ($name === '') {
    $name = 'Church';
}

// A launcher truncates what it shows under an icon, so a short label that fits beats a full one that
// is cut off. Shared with the layout's iOS meta tag, which is why it is a helper and not inline here.
$short = appShortName();

$description = trim((string) ($s['meta_description'] ?? ''));
if ($description === '') {
    $description = trim((string) ($s['site_tagline'] ?? ''));
}

/**
 * The icons, in order of preference.
 *
 * A church's own uploaded logo is used when it is genuinely usable as an app icon — square, and at
 * least 192px — because declaring an icon of the wrong size is worse than not offering one: the
 * launcher either refuses the install or scales up something too small to read. So the stored file is
 * measured rather than assumed, and the two icons shipped with the site are the fallback.
 *
 * It **replaces** the shipped icons rather than joining them. A launcher picks the closest match for
 * the size it needs, so with both listed a 512px generic icon wins over a church's 192px logo and the
 * church's own logo never appears — the feature quietly doing nothing while looking implemented. The
 * trade-off is real and accepted: a 192px logo has no 512px companion, so a launcher that wants one
 * scales the logo up, which is a slightly soft icon rather than the wrong church's logo.
 *
 * `purpose` is `any` throughout, and never `maskable`. A maskable icon has to keep its content inside a
 * safe zone so a launcher can crop it to a circle without cutting anything off, and neither a church's
 * logo nor the shipped icons can be assumed to have that padding. Claiming `maskable` when it is not is
 * how an app icon ends up with its edges sliced away.
 */
$icons = [];

$logo = (string) ($s['logo_path'] ?? '');
if ($logo !== '') {
    $file = UPLOADS_PATH . '/' . $logo;
    $size = is_file($file) ? @getimagesize($file) : false;
    if ($size !== false && (int) $size[0] === (int) $size[1] && (int) $size[0] >= 192) {
        $icons[] = [
            'src' => (string) uploadUrl($logo),
            'sizes' => (int) $size[0] . 'x' . (int) $size[1],
            'type' => (string) ($size['mime'] ?? 'image/png'),
            'purpose' => 'any',
        ];
    }
}

if (!$icons) {
    $icons[] = ['src' => '/assets/logo.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
    $icons[] = ['src' => '/assets/app_icon.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
}

$manifest = [
    'name' => $name,
    'short_name' => $short,
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',
    // Kept in step with `--bg-0` in public/assets/css/site.css and the `theme-color` meta in
    // views/partials/layout-open.php: the app opens on the same colour as the site, so neither the
    // splash screen nor the status bar flashes a different shade on the way in.
    'background_color' => '#0a0912',
    'theme_color' => '#0a0912',
    'icons' => $icons,
];

if ($description !== '') {
    $manifest['description'] = $description;
}

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
