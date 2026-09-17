<?php
declare(strict_types=1);

/**
 * GET /api/og?type=sermon&id=12 — the generated social share card (1200×630 PNG).
 *
 * `&cover=1` asks for the content's OWN image instead: an event flyer as the church designed it,
 * handed over as a JPEG at its own shape rather than cropped into a card. When the item has no usable
 * cover the composed card is served instead, so the URL is always an image.
 *
 * Also accepts ?slug= instead of ?id=. Only whitelisted types are rendered, and
 * only published rows, so the query string can never be used to draw arbitrary
 * text onto an image.
 *
 * Cards are cached on disk keyed by a hash of the content, so repeat requests are
 * a file read. A conditional request is answered with 304 when nothing changed.
 */

$type = (string) ($_GET['type'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$slug = trim((string) ($_GET['slug'] ?? ''));
$wantsCover = (string) ($_GET['cover'] ?? '') === '1';

$entity = ShareCard::resolve($type, $id > 0 ? $id : null, $slug !== '' ? $slug : null);

if ($entity === null) {
    // Nothing to draw: fall back to the site logo so a share still shows an
    // image rather than nothing at all.
    $logo = (string) setting('logo_path', '');
    $logoPath = $logo !== '' ? UPLOADS_PATH . '/' . ltrim($logo, '/') : '';
    if ($logoPath !== '' && is_file($logoPath)) {
        header('Content-Type: ' . (mime_content_type($logoPath) ?: 'image/png'));
        header('Cache-Control: public, max-age=3600');
        readfile($logoPath);
        exit;
    }
    http_response_code(404);
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630"><rect width="1200" height="630" fill="#0a0912"/></svg>';
    exit;
}

if ($wantsCover) {
    // The cover's own file, already normalised to a crawler-safe JPEG by ShareCard. A missing cover
    // falls through to the composed card below rather than 404ing, because a preview with the church's
    // name and the date on it beats a preview with nothing in it.
    $coverPath = ShareCard::coverFile($entity);
    if ($coverPath !== null && $coverPath !== '' && is_file($coverPath)) {
        $coverEtag = '"' . substr(hash('sha256', basename($coverPath) . '|' . filesize($coverPath)), 0, 24) . '"';
        header('Content-Type: ' . (mime_content_type($coverPath) ?: 'image/jpeg'));
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $coverEtag);
        header('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $coverEtag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . (string) filesize($coverPath));
        readfile($coverPath);
        exit;
    }
}

$path = ShareCard::generate($entity);

if ($path === null) {
    // No GD, no FreeType-free path, or the render failed: serve the cover image
    // directly if there is one, otherwise the logo, otherwise a blank card.
    $cover = $entity['cover'] ?? null;
    $coverPath = $cover ? UPLOADS_PATH . '/' . ltrim((string) $cover, '/') : '';
    if ($coverPath !== '' && is_file($coverPath)) {
        header('Content-Type: ' . (mime_content_type($coverPath) ?: 'image/jpeg'));
        header('Cache-Control: public, max-age=3600');
        readfile($coverPath);
        exit;
    }
    http_response_code(404);
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630"><rect width="1200" height="630" fill="#0a0912"/></svg>';
    exit;
}

$etag = '"' . substr(hash('sha256', basename($path) . '|' . filesize($path)), 0, 24) . '"';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
