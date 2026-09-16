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

// Spam check. Every reply carries a fresh challenge, so a wrong answer - or one spent by a send that
// succeeded - can be answered again without reloading the page.
$captchaError = contactCaptchaCheck((string) ($input['captcha_token'] ?? ''), (string) ($input['captcha'] ?? ''));
if ($captchaError !== '') {
    jsonResponse(['status' => 'error', 'message' => $captchaError, 'captcha' => contactCaptchaIssue()]);
}

// Who receives it: the church's public contact address, else the super admin's own account, else the
// SMTP "from" address. This was `contact_email` alone, so one blank settings box dropped the message
// on the floor while the visitor was told it had been "logged".
$to = trim((string) setting('contact_email'));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $to = '';
    try {
        $stmt = Database::getInstance()->getConnection()->query(
            "SELECT email FROM users WHERE is_super_admin = 1 AND email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 1"
        );
        $candidate = trim((string) $stmt->fetchColumn());
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $to = $candidate;
        }
    } catch (Throwable $e) {
        // No database of users to fall back on - the SMTP from-address below is the last resort.
    }
}
if ($to === '') {
    $candidate = trim((string) setting('smtp_from'));
    if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        $to = $candidate;
    }
}

// Keep a copy before trying to send: it is the only one there is if delivery fails, and it is what
// makes "we've logged your note" true rather than reassuring.
$logged = false;
try {
    if (!is_dir(STORAGE_PATH . '/logs')) {
        @mkdir(STORAGE_PATH . '/logs', 0775, true);
    }
    $line = date('c') . "\t" . $name . "\t" . $email . "\t" . $subject . "\t"
        . str_replace(["\r", "\n"], ' ', $message) . "\n";
    $logged = @file_put_contents(STORAGE_PATH . '/logs/contact.log', $line, FILE_APPEND | LOCK_EX) !== false;
} catch (Throwable $e) {
    $logged = false;
}

$sent = $to !== '' && Mailer::send($to, '[Contact] ' . $subject, "From: $name <$email>\n\n$message");

if ($sent) {
    jsonResponse([
        'status' => 'success',
        'message' => "Message sent - we'll be in touch soon.",
        'captcha' => contactCaptchaIssue(),
    ]);
}

// Say what actually went wrong. The old text blamed "email delivery isn't configured" even when it
// WAS configured and the send had failed, which sends an admin looking in the wrong place.
$why = Mailer::configured()
    ? 'the notification email could not be sent'
    : 'email delivery is not configured on this site';
error_log('Contact form: message NOT delivered - ' . $why . ' (recipient: ' . ($to === '' ? 'none set' : $to) . ')'
    . ($logged ? '; a copy is in storage/logs/contact.log' : ''));

jsonResponse([
    'status' => 'error',
    'message' => 'Sorry - your message could not be sent (' . $why . '). Please try again in a few minutes, '
        . 'or reach us on WhatsApp or by phone.',
    'captcha' => contactCaptchaIssue(),
], 503);
