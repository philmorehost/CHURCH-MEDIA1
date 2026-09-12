<?php
declare(strict_types=1);

/**
 * GET  /api/prayer              — the public wall: open requests, answered prayers, totals.
 * POST /api/prayer              — submit a request.
 *                                 {name?, email?, message, is_public?, is_anonymous?}
 * POST /api/prayer?action=pray  — count one "I prayed for this". {request_id}
 *
 * Anonymous submissions keep their name for the pastoral team but are never shown
 * publicly — see PrayerWall, which owns that rule.
 */

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    $wall = PrayerWall::wall(30);
    $answered = PrayerWall::answered(12);
    $session = Fingerprint::hash();

    // Which cards this visitor has already prayed for, so the button renders
    // finished rather than letting them click into a no-op.
    jsonResponse([
        'status' => 'success',
        'data' => [
            'wall' => $wall,
            'answered' => $answered,
            'stats' => PrayerWall::stats(),
            'prayed_ids' => PrayerWall::participated(array_column($wall, 'id'), $session),
        ],
    ]);
}

if ($method !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Unsupported method.'], 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;

/**
 * Reads one scalar value from the request body.
 *
 * Non-scalars are refused rather than coerced. PHP casts the array `[15, 14]` to the
 * integer 1, so a malformed body like {"request_id": [15, 14]} would otherwise
 * silently act on request 1 instead of being rejected.
 */
$scalar = static function (string $key, string $default = '') use ($input): string {
    $value = $input[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
};

/** A checkbox that arrives as "1", "true", "on" or "yes", or not at all. */
$flag = static function (string $key) use ($scalar): bool {
    return filter_var($scalar($key), FILTER_VALIDATE_BOOLEAN);
};

/* --------------------------------------------- "I prayed for this" counter */

if ($action === 'pray') {
    $session = Fingerprint::hash();

    // A separate bucket from submissions, so thanking God a few times in a row can
    // never use up the allowance for sharing a request of one's own.
    if (!RateLimiter::attemptConfigured('prayer_increment', $session)) {
        jsonResponse(['status' => 'error', 'message' => 'Please slow down a moment.'], 429);
    }

    $result = PrayerWall::recordPrayer((int) $scalar('request_id', '0'), $session);

    if (!$result['ok']) {
        jsonResponse(['status' => 'error', 'message' => (string) ($result['message'] ?? 'Could not count that.')], 404);
    }

    jsonResponse([
        'status' => 'success',
        'counted' => (bool) $result['counted'],
        'prayer_count' => (int) $result['prayer_count'],
        'message' => $result['counted']
            ? 'Thank you for praying. 🙏'
            : 'You have already prayed for this one. 🙏',
    ]);
}

/* ------------------------------------------------------------ new request */

if (!RateLimiter::attemptConfigured('prayer', Fingerprint::hash())) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests — please wait a few minutes.'], 429);
}

// Honeypot: legitimate clients never fill this hidden field.
if ($scalar('website') !== '') {
    jsonResponse(['status' => 'success']);
}

$result = PrayerWall::submit(
    $scalar('name'),
    $scalar('email'),
    $scalar('message'),
    $flag('is_public'),
    $flag('is_anonymous')
);

if (isset($result['errors'])) {
    jsonResponse(['status' => 'error', 'message' => (string) ($result['errors'][0] ?? 'Please check the form.')], 400);
}

jsonResponse(['status' => 'success', 'message' => 'Your prayer request has been received. Our team is praying with you.']);

