<?php
declare(strict_types=1);

/**
 * GET /api/calendar?event=SLUG — downloads a single-event .ics file.
 *
 * This is what the "Add to calendar" button on an event page points at, so it
 * needs to work without JavaScript and behave like a file download rather than a
 * page: the browser is told to save it, not render it.
 */

$slug = trim((string) ($_GET['event'] ?? ''));
if ($slug === '') {
    jsonResponse(['status' => 'error', 'message' => 'Provide ?event=SLUG.'], 400);
}

$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare('SELECT * FROM events WHERE slug = ? AND is_published = 1 LIMIT 1');
$stmt->execute([$slug]);
$event = $stmt->fetch();
if (!$event) {
    jsonResponse(['status' => 'error', 'message' => 'Event not found.'], 404);
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$filename = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $event['slug'])) ?: 'event';

// Visits to the calendar file are a strong "I intend to come" signal.
Analytics::record('event_view', [
    'org_unit_id' => (int) ($event['org_unit_id'] ?? 0),
    'entity_type' => 'event',
    'entity_id' => (int) $event['id'],
    'meta' => 'calendar',
]);
Analytics::flush();

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.ics"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo Rsvp::ics($event, $host);
exit;
