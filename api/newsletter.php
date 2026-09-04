<?php
declare(strict_types=1);

/** POST /api/newsletter {email} — subscribe (or resubscribe) to the newsletter. */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'POST required.'], 405);
}

if (!RateLimiter::attemptConfigured('newsletter', Fingerprint::hash())) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests — please wait a few minutes.'], 429);
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$email = trim((string) ($input['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
}

$pdo = Database::getInstance()->getConnection();
$pdo->prepare('INSERT INTO newsletter_subscribers (email, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1')
    ->execute([$email]);

jsonResponse(['status' => 'success', 'message' => 'You are subscribed! Watch your inbox for updates.']);
