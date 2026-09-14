<?php
declare(strict_types=1);

/**
 * POST /api/devices — register or update a push device token.
 * Body (JSON): {token, platform?, unit_slug?}
 *   token     — the FCM registration token from the app
 *   platform  — 'android' | 'ios' | 'web'
 *   unit_slug — optional slug of the church the user cares about
 *
 * When the caller is a signed-in member the device is bound to them, which is what lets a
 * notification honour their own settings. A device that has never signed in stays anonymous and
 * receives everything, which is what most devices are.
 *
 * POST /api/devices {action:'remove', token} — forget a token entirely.
 * POST /api/devices {action:'unbind', token} — signing out: keep receiving announcements, but
 *   stop applying this member's settings to the device.
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

// Signing out. Without this the device keeps the previous member's notification settings, so
// the next person to use the phone is subject to choices they never made.
if (($input['action'] ?? '') === 'unbind') {
    if ($token === '') {
        jsonResponse(['status' => 'error', 'message' => 'token is required.'], 400);
    }
    $pdo->prepare('UPDATE device_tokens SET member_id = NULL WHERE token = ?')->execute([$token]);
    jsonResponse(['status' => 'success']);
}

if ($token === '' || strlen($token) > 512) {
    jsonResponse(['status' => 'error', 'message' => 'A valid token is required.'], 400);
}

$platform = in_array(trim((string) ($input['platform'] ?? '')), ['android', 'ios', 'web'], true) ? trim((string) $input['platform']) : null;

$unitId = null;
$unitSlug = trim((string) ($input['unit_slug'] ?? ''));
if ($unitSlug !== '') {
    $found = Unit::findBySlug($unitSlug);
    if ($found !== null) {
        $unitId = (int) $found['id'];
    }
}

$ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

$stmt = $pdo->prepare('SELECT id FROM device_tokens WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$existing = $stmt->fetchColumn();

// Null unless a member is signed in on this request. COALESCE below, not a plain assignment:
// the app re-registers its token on every launch, including before anyone has signed in, and a
// plain assignment would unbind a device every time it was opened by a signed-out user.
$memberId = MemberAuth::check() ? MemberAuth::id() : null;

if ($existing) {
    $pdo->prepare('UPDATE device_tokens SET platform = ?, org_unit_id = ?, user_agent = ?, member_id = COALESCE(?, member_id) WHERE id = ?')
        ->execute([$platform, $unitId, $ua, $memberId, (int) $existing]);
} else {
    $pdo->prepare('INSERT INTO device_tokens (token, platform, org_unit_id, user_agent, member_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$token, $platform, $unitId, $ua, $memberId]);
}

jsonResponse(['status' => 'success', 'member_bound' => $memberId !== null]);
