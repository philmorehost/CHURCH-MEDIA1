<?php
declare(strict_types=1);

/**
 * API Endpoint for Advertisements
 *
 * GET  /api/ads?platform=web|app  - Fetches active, unexpired vertical ads
 * POST /api/ads/event             - Registers a view (impression) or click event
 */

$pdo = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? ($_SERVER['REQUEST_URI'] ?? '');

if ($method === 'POST' || str_contains($pathInfo, '/event')) {
    $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
    $adId = (int) ($input['ad_id'] ?? 0);
    $eventType = in_array($input['event_type'] ?? '', ['view', 'click'], true) ? $input['event_type'] : 'view';
    $platform = in_array($input['platform'] ?? '', ['web', 'app'], true) ? $input['platform'] : 'web';

    if ($adId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'Invalid ad ID'], 400);
    }

    /*
     * Scoped to the church being served, like every other read of this table.
     *
     * Without the clause, an impression or a click could be credited against any church's advert by id —
     * and an advert's counters are what the advertiser's performance report is built from, so a wrong
     * number here is a number sent to a paying customer in an email.
     */
    [$tenantClause, $tenantParams] = tenantScope();
    $stmt = $pdo->prepare('SELECT id, status, expires_at FROM ads WHERE id = ? AND ' . $tenantClause);
    $stmt->execute(array_merge([$adId], $tenantParams));
    $ad = $stmt->fetch();

    if (!$ad || $ad['status'] !== 'approved' || ($ad['expires_at'] && strtotime($ad['expires_at']) <= time())) {
        jsonResponse(['ok' => false, 'error' => 'Ad not found or expired'], 404);
    }

    // Rate limit impression/click events per IP to prevent spamming
    if (!RateLimiter::attempt('ad_event_' . $eventType . '_' . $adId, clientIp(), 20, 60)) {
        jsonResponse(['ok' => true, 'rate_limited' => true]);
    }

    $stmt = $pdo->prepare('INSERT INTO ad_events (ad_id, event_type, platform, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$adId, $eventType, $platform, clientIp(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

    if ($eventType === 'view') {
        $pdo->prepare('UPDATE ads SET views_count = views_count + 1 WHERE id = ?')->execute([$adId]);
    } else {
        $pdo->prepare('UPDATE ads SET clicks_count = clicks_count + 1 WHERE id = ?')->execute([$adId]);
    }

    jsonResponse(['ok' => true]);
}

// GET request: fetch active ads for specified platform
$platform = in_array($_GET['platform'] ?? '', ['web', 'app'], true) ? $_GET['platform'] : 'web';

// One query, one church, one shape — shared with the feed and with the reviewer's preview, so the three
// cannot drift apart. This used to hold its own unscoped query and its own inline array.
jsonResponse(['ok' => true, 'ads' => array_map([AdFeed::class, 'card'], AdFeed::activeFor($platform, 10))]);
