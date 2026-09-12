<?php
declare(strict_types=1);

/**
 * GET  /api/rsvp?event_id=5   — seats taken, seats left, and who is coming.
 * GET  /api/rsvp?token=...    — one RSVP, so a guest can amend or cancel.
 * POST /api/rsvp              — submit or amend an RSVP.
 *      {event_id, name, email?, phone?, guests?, status?, note?}
 * POST /api/rsvp {action:'cancel', token} — cancel and free the seats.
 *
 * Nothing here is account-based: the guest's own `token` is their key.
 */

$pdo = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if ($token = trim((string) ($_GET['token'] ?? ''))) {
        $rsvp = Rsvp::forToken($token);
        if (!$rsvp) {
            jsonResponse(['status' => 'error', 'message' => 'That RSVP link is not valid.'], 404);
        }
        jsonResponse(['status' => 'success', 'data' => [
            'event_id' => (int) $rsvp['event_id'],
            'event_title' => (string) $rsvp['title'],
            'event_slug' => (string) $rsvp['slug'],
            'name' => (string) $rsvp['name'],
            'email' => $rsvp['email'],
            'guests' => (int) $rsvp['guests'],
            'status' => (string) $rsvp['status'],
            'checked_in' => (bool) $rsvp['checked_in'],
            'seats_left' => Rsvp::seatsLeft($rsvp),
        ]]);
    }

    $eventId = (int) ($_GET['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'event_id is required.'], 400);
    }
    $stmt = $pdo->prepare('SELECT id, max_capacity, waitlist_enabled, allow_guests, rsvp_closes_at, start_at, end_at, rsvp_mode, rsvp_enabled, rsvp_url FROM events WHERE id = ? AND is_published = 1');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        jsonResponse(['status' => 'error', 'message' => 'Event not found.'], 404);
    }

    $counts = Rsvp::counts($eventId);
    jsonResponse(['status' => 'success', 'data' => [
        'event_id' => $eventId,
        'accepting' => Rsvp::takesRsvps($event) && !Rsvp::closed($event),
        'capacity' => Rsvp::capacity($event),
        'seats_taken' => $counts['seats_taken'],
        'seats_left' => Rsvp::seatsLeft($event),
        'going' => $counts['going'],
        'maybe' => $counts['maybe'],
        'waitlist' => $counts['waitlist'],
        'allow_guests' => (bool) $event['allow_guests'],
        'max_guests' => Rsvp::MAX_GUESTS,
    ]]);
}

if ($method !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

$fingerprint = Fingerprint::hash();
if (!RateLimiter::attemptConfigured('rsvp', $fingerprint)) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests — please try again in a moment.'], 429);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (($input['action'] ?? '') === 'cancel') {
    $result = Rsvp::cancel((string) ($input['token'] ?? ''));
    jsonResponse(
        ['status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']],
        $result['ok'] ? 200 : 400
    );
}

$eventId = (int) ($input['event_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ? AND is_published = 1');
$stmt->execute([$eventId]);
$event = $stmt->fetch();
if (!$event) {
    jsonResponse(['status' => 'error', 'message' => 'Event not found.'], 404);
}
if (!Rsvp::takesRsvps($event)) {
    jsonResponse(['status' => 'error', 'message' => 'This event does not take RSVPs here.'], 400);
}

$result = Rsvp::submit($event, $input);
if (!$result['ok']) {
    jsonResponse(['status' => 'error', 'message' => $result['message']], 422);
}

// A cancellation or a smaller party may have freed a place, so re-check the list.
Rsvp::promoteWaitlist($eventId);

Analytics::record('event_view', [
    'entity_type' => 'event',
    'entity_id' => $eventId,
    'org_unit_id' => (int) ($event['org_unit_id'] ?? 0),
    'meta' => 'rsvp:' . (string) $result['status'],
]);

jsonResponse(['status' => 'success', 'message' => $result['message'], 'data' => [
    'status' => $result['status'],
    'waitlisted' => (bool) $result['waitlisted'],
    'token' => $result['token'],
    'cancel_url' => '/api/rsvp?token=' . rawurlencode((string) $result['token']),
    'seats_left' => $result['seats_left'],
    'seats_taken' => Rsvp::seatsTaken($eventId),
]]);
