<?php
declare(strict_types=1);

/**
 * GET /api/prayer — list requests the admin has marked public (the prayer wall).
 * POST /api/prayer {name?, email?, message, is_public?} — anonymous submissions.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $pdo = Database::getInstance()->getConnection();
    $rows = $pdo->query("SELECT id, name, message, created_at FROM prayer_requests WHERE is_public = 1 AND status != 'archived' ORDER BY created_at DESC LIMIT 30")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);
    jsonResponse(['status' => 'success', 'data' => $rows]);
}

if (!RateLimiter::attemptConfigured('prayer', Fingerprint::hash())) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests — please wait a few minutes.'], 429);
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$name = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$message = trim((string) ($input['message'] ?? ''));
$isPublic = !empty($input['is_public']) ? 1 : 0;
// Honeypot: legitimate clients never fill this hidden field.
if (!empty($input['website'])) {
    jsonResponse(['status' => 'success']);
}

if ($message === '' || mb_strlen($message) > 2000) {
    jsonResponse(['status' => 'error', 'message' => 'Please share a prayer request (up to 2000 characters).'], 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['status' => 'error', 'message' => 'That email address looks invalid.'], 400);
}

$pdo = Database::getInstance()->getConnection();
$pdo->prepare('INSERT INTO prayer_requests (name, email, message, is_public, ip_address) VALUES (?, ?, ?, ?, ?)')
    ->execute([$name ?: null, $email ?: null, $message, $isPublic, clientIp()]);

jsonResponse(['status' => 'success', 'message' => 'Your prayer request has been received. Our team is praying with you.']);
