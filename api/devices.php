<?php
declare(strict_types=1);

/**
 * POST /api/devices — register or update a push device token.
 * Body (JSON): {token, platform?, unit_slug?}
 *   token     — the FCM registration token from the app
 *   platform  — 'android' | 'ios' | 'web'
 *   unit_slug — optional slug of the church the user cares about
 * POST /api/devices {action:'remove', token} — forget a token.
 */

$pdo = Database::getInstance()->getConnection();
$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$token = trim((string) ($input['token'] ?? ''));

if (($input['action'] ?? '') === 'remove') {
    if ($token === '') {
        jsonResponse(['status' => 'error', 'message' => 'token is required.'], 400);
    }
    $pdo->prepare('DELETE FROM device_tokens WHERE token = ?')->execute([$token]);
    jsonResponse(['status' => 'success']);
}

if ($token === '' || strlen($token) > 512) {
    jsonResponse(['status' => 'error', 'message' => 'A valid token is required.'], 400);
}

$platform = in_array(trim((string) ($input['platform'] ?? '')), ['android', 'ios', 'web'], true) ? trim((string) $input['platform']) : null;

$unitId = null;
$unitSlug = trim((string) ($input['unit_slug'] ?? ''));
if ($unitSlug !== '') {
    $stmt = $pdo->prepare('SELECT id FROM org_units WHERE slug = ? LIMIT 1');
    $stmt->execute([$unitSlug]);
    $oid = $stmt->fetchColumn();
    if ($oid !== false) {
        $unitId = (int) $oid;
    }
}

$ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

$stmt = $pdo->prepare('SELECT id FROM device_tokens WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$existing = $stmt->fetchColumn();
if ($existing) {
    $pdo->prepare('UPDATE device_tokens SET platform = ?, org_unit_id = ?, user_agent = ? WHERE id = ?')
        ->execute([$platform, $unitId, $ua, (int) $existing]);
} else {
    $pdo->prepare('INSERT INTO device_tokens (token, platform, org_unit_id, user_agent) VALUES (?, ?, ?, ?)')
        ->execute([$token, $platform, $unitId, $ua]);
}

jsonResponse(['status' => 'success']);
