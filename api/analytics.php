<?php
declare(strict_types=1);

/**
 * POST /api/analytics — the anonymous analytics beacon.
 *
 * Body (JSON or form): {event, path?, entity_type?, entity_id?, org_unit_id?, device?}
 *
 * Design: this endpoint must never be able to break a page or leak data, so it
 * accepts only the known event names, ignores crawlers and link-preview fetchers,
 * stores no IP address, is rate-limited per device, and always answers 204 —
 * even when analytics is switched off or the write fails.
 */

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

// Uptime monitors, previews and crawlers should not be counted.
if (Analytics::looksLikeBot()) {
    http_response_code(204);
    exit;
}

$fingerprint = Fingerprint::hash();
if (!RateLimiter::attemptConfigured('analytics', $fingerprint)) {
    // Silently drop: a beacon must never surface a rate-limit error to a reader.
    http_response_code(204);
    exit;
}

$raw = (string) file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$event = (string) ($input['event'] ?? '');
if (!isset(Analytics::EVENTS[$event])) {
    http_response_code(204);
    exit;
}

$entityType = isset($input['entity_type']) ? (string) $input['entity_type'] : null;
if ($entityType !== null && !in_array($entityType, ['post', 'sermon', 'event', 'testimony', 'page', 'unit'], true)) {
    $entityType = null;
}

// Keep only the host part of the referrer — never the full referring URL, which
// can carry search terms or tokens belonging to somebody else.
$referrerHost = null;
$referrer = (string) ($input['referrer'] ?? '');
if ($referrer !== '') {
    $host = parse_url($referrer, PHP_URL_HOST);
    if (is_string($host) && $host !== '' && $host !== (string) ($_SERVER['HTTP_HOST'] ?? '')) {
        $referrerHost = mb_substr($host, 0, 120);
    }
}

Analytics::record($event, [
    'path' => isset($input['path']) ? (string) $input['path'] : null,
    'org_unit_id' => (int) ($input['org_unit_id'] ?? 0),
    'entity_type' => $entityType,
    'entity_id' => (int) ($input['entity_id'] ?? 0),
    'device' => ((string) ($input['device'] ?? 'web')) === 'app' ? 'app' : 'web',
    'session_hash' => $fingerprint,
    'referrer_host' => $referrerHost,
    'country' => SecurityGuard::resolveCountryCode(),
    'meta' => isset($input['meta']) ? (string) $input['meta'] : null,
]);

// Write immediately rather than waiting for shutdown, so the row exists before
// the response completes and the client can fire-and-forget.
Analytics::flush();

http_response_code(204);
exit;
