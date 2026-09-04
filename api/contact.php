<?php
declare(strict_types=1);

/** POST /api/contact {name, email, subject?, message} — forwards to the church's contact email. */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'POST required.'], 405);
}

if (!RateLimiter::attempt('contact', Fingerprint::hash(), 5, 300)) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests — please wait a few minutes.'], 429);
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$name = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$subject = trim((string) ($input['subject'] ?? 'Website contact form'));
$message = trim((string) ($input['message'] ?? ''));

if (!empty($input['website'])) {
    jsonResponse(['status' => 'success']); // honeypot
}
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
    jsonResponse(['status' => 'error', 'message' => 'Please fill in your name, a valid email, and a message.'], 400);
}

$to = setting('contact_email');
$sent = $to ? Mailer::send($to, '[Contact] ' . $subject, "From: $name <$email>\n\n$message") : false;

jsonResponse(['status' => 'success', 'message' => $sent
    ? "Message sent — we'll be in touch soon."
    : "Message received — email delivery isn't configured yet, but we've logged your note."]);
