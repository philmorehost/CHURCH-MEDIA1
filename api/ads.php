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

    $stmt = $pdo->prepare('SELECT id, status, expires_at FROM ads WHERE id = ?');
    $stmt->execute([$adId]);
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

$stmt = $pdo->prepare("SELECT id, title, media_type, file_path, thumbnail_path, destination_url, target_platform, start_at, expires_at
    FROM ads
    WHERE status = 'approved'
      AND (target_platform = 'both' OR target_platform = ?)
      AND start_at <= NOW()
      AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY RAND()
    LIMIT 10");
$stmt->execute([$platform]);
$ads = $stmt->fetchAll();

$out = array_map(function ($ad) {
    return [
        'id' => (int) $ad['id'],
        'title' => $ad['title'],
        'media_type' => $ad['media_type'],
        'file_url' => uploadUrl($ad['file_path']),
        'thumbnail_url' => uploadUrl($ad['thumbnail_path']),
        'destination_url' => $ad['destination_url'],
        'is_ad' => true,
    ];
}, $ads);

jsonResponse(['ok' => true, 'ads' => $out]);
