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
 * The icons, generated per church by the `/app-icon.png` route from the logo and favicon saved in
 * Settings.
 *
 * There is deliberately **no** fallback to the artwork shipped with the code. That fallback is exactly
 * what put the same default logo on every church's home screen: a church whose upload was not precisely
 * square and 192px got the shipped icon instead of its own, which looks implemented and does nothing.
 * The route now draws a correctly-sized square PNG from whatever was uploaded — the logo first, the
 * favicon second, and the church's own initial only when nothing has been uploaded at all — so the icon
 * is always this church's own mark.
 *
 * Both sizes are declared because a launcher picks the closest match for the density it needs. The
 * `sizes` are true rather than assumed, because the route draws to them exactly, which is what keeps an
 * install from being refused for declaring the wrong size.
 *
 * `purpose` is `any` throughout, and never `maskable`. A maskable icon has to keep its content inside a
 * safe zone so a launcher can crop it to a circle without cutting anything off, and a church's logo
 * cannot be assumed to have that padding. Claiming `maskable` when it is not is how an app icon ends up
 * with its edges sliced away.
 */
$icons = [
    ['src' => appIconUrl(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => appIconUrl(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
];

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
