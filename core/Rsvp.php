<?php
declare(strict_types=1);

/**
 * Event RSVPs.
 *
 * Three modes, so nothing changes for an event that was already set up:
 *  - `legacy`   — use the original `rsvp_enabled` / `rsvp_url` columns exactly as
 *                 before. This is the default, so every existing event is untouched.
 *  - `off`      — no RSVP at all.
 *  - `external` — show the external RSVP link.
 *  - `internal` — take RSVPs here: capacity, guest counts, waitlist and check-in.
 *
 * Capacity is counted in **seats**, not rows: a guest bringing two people uses
 * three. Waitlisting is first-come-first-served and promotion happens
 * automatically when a place frees up.
 */
final class Rsvp
{
    /** A sensible ceiling so one submission cannot reserve a whole hall. */
    public const MAX_GUESTS = 10;

    /** Resolves the effective mode, translating the legacy columns. */
    public static function modeFor(array $event): string
    {
        $mode = (string) ($event['rsvp_mode'] ?? 'legacy');
        if ($mode === 'off' || $mode === 'external' || $mode === 'internal') {
            return $mode;
        }
        // legacy
        if (empty($event['rsvp_enabled'])) {
            return 'off';
        }
        return !empty($event['rsvp_url']) ? 'external' : 'off';
    }

    public static function enabled(array $event): bool
    {
        return self::modeFor($event) !== 'off';
    }

    public static function takesRsvps(array $event): bool
    {
        return self::modeFor($event) === 'internal';
    }

    /** 0 means unlimited. */
    public static function capacity(array $event): int
    {
        return max(0, (int) ($event['max_capacity'] ?? 0));
    }

    /** True when the deadline has passed, or the event itself is over. */
    public static function closed(array $event): bool
    {
        $now = time();
        if (!empty($event['rsvp_closes_at']) && strtotime((string) $event['rsvp_closes_at']) < $now) {
            return true;
        }
        $end = !empty($event['end_at']) ? strtotime((string) $event['end_at']) : strtotime((string) $event['start_at']);
        return $end !== false && $end < $now;
    }

    /** Seats already spoken for: confirmed guests only, not the waitlist. */
    public static function seatsTaken(int $eventId): int
    {
        $stmt = self::db()->prepare("SELECT COALESCE(SUM(guests + 1), 0) FROM event_rsvps WHERE event_id = ? AND status = 'going'");
        $stmt->execute([$eventId]);
        return (int) $stmt->fetchColumn();
    }

    /** Remaining seats, or null when there is no limit. */
    public static function seatsLeft(array $event): ?int
    {
        $capacity = self::capacity($event);
        if ($capacity === 0) {
            return null;
        }
        return max(0, $capacity - self::seatsTaken((int) $event['id']));
    }

    /** A full picture for the event page and the admin attendee list. */
    public static function counts(int $eventId): array
    {
        $stmt = self::db()->prepare('SELECT status, COUNT(*) AS rows_count, COALESCE(SUM(guests + 1), 0) AS seats FROM event_rsvps WHERE event_id = ? GROUP BY status');
        $stmt->execute([$eventId]);

        $out = [
            'going' => 0,
            'maybe' => 0,
            'declined' => 0,
            'waitlist' => 0,
            'cancelled' => 0,
            'seats_taken' => 0,
            'checked_in' => 0,
            'rows' => 0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $status = (string) $row['status'];
            $out[$status] = (int) $row['rows_count'];
            $out['rows'] += (int) $row['rows_count'];
            if ($status === 'going') {
                $out['seats_taken'] = (int) $row['seats'];
            }
        }

        $in = self::db()->prepare('SELECT COUNT(*) FROM event_rsvps WHERE event_id = ? AND checked_in = 1');
        $in->execute([$eventId]);
        $out['checked_in'] = (int) $in->fetchColumn();

        return $out;
    }

    /**
     * Records or updates an RSVP.
     *
     * @return array{ok:bool, message:string, status?:string, waitlisted?:bool, token?:string, seats_left?:int|null}
     */
    public static function submit(array $event, array $input): array
    {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId <= 0) {
            return ['ok' => false, 'message' => 'That event could not be found.'];
        }
        if (self::closed($event)) {
            return ['ok' => false, 'message' => 'RSVPs for this event have closed.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        // Read the requested status, then validate it — reading the raw key after
        // defaulting the check stored an empty status when none was supplied.
        $requestedStatus = (string) ($input['status'] ?? 'going');
        $status = in_array($requestedStatus, ['going', 'maybe', 'declined'], true) ? $requestedStatus : 'going';
        $guests = max(0, min(self::MAX_GUESTS, (int) ($input['guests'] ?? 0)));
        if (empty($event['allow_guests'])) {
            $guests = 0;
        }

        if ($name === '') {
            return ['ok' => false, 'message' => 'Please tell us your name.'];
        }
        if (mb_strlen($name) > 150) {
            return ['ok' => false, 'message' => 'That name is too long.'];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'That email address does not look right.'];
        }

        $eventIdSafe = (string) $eventId;
        $existing = null;
        if ($email !== '') {
            $stmt = self::db()->prepare('SELECT * FROM event_rsvps WHERE event_id = ? AND email = ? LIMIT 1');
            $stmt->execute([$eventId, $email]);
            $existing = $stmt->fetch() ?: null;
        }

        // Work out whether this booking fits, allowing for the seats the same
        // person already holds so an amendment does not look like a new booking.
        $seatsWanted = 1 + $guests;
        $capacity = self::capacity($event);
        $waitlisted = false;

        if ($capacity > 0 && $status === 'going') {
            $taken = self::seatsTaken($eventId);
            $alreadyHeld = ($existing && $existing['status'] === 'going') ? ((int) $existing['guests'] + 1) : 0;
            $available = $capacity - ($taken - $alreadyHeld);

            if ($seatsWanted > $available) {
                if (!empty($event['waitlist_enabled'])) {
                    $status = 'waitlist';
                    $waitlisted = true;
                } else {
                    return [
                        'ok' => false,
                        'message' => $available > 0
                            ? 'Only ' . $available . ' seat(s) left — please reduce the number of guests.'
                            : 'This event is fully booked.',
                    ];
                }
            }
        }

        $token = $existing['token'] ?? self::token();
        $fields = [
            'name' => mb_substr($name, 0, 150),
            'email' => $email !== '' ? mb_substr($email, 0, 190) : null,
            'phone' => $phone !== '' ? mb_substr($phone, 0, 45) : null,
            'guests' => $guests,
            'status' => $status,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            'fingerprint_hash' => Fingerprint::hash(),
        ];

        if ($existing) {
            $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($fields)));
            self::db()->prepare('UPDATE event_rsvps SET ' . $set . ' WHERE id = ?')
                ->execute(array_merge(array_values($fields), [(int) $existing['id']]));
        } else {
            $columns = ['event_id', 'token', ...array_keys($fields)];
            $values = [$eventId, $token, ...array_values($fields)];
            $marks = implode(', ', array_fill(0, count($columns), '?'));
            self::db()->prepare('INSERT INTO event_rsvps (' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)) . ') VALUES (' . $marks . ')')
                ->execute($values);
        }

        return [
            'ok' => true,
            'status' => $status,
            'waitlisted' => $waitlisted,
            'token' => $token,
            'seats_left' => self::seatsLeft($event),
            'message' => self::messageFor($status, $existing !== null),
        ];
    }

    /** Looks an RSVP up by its own token, so a guest can amend without an account. */
    public static function forToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = self::db()->prepare('SELECT r.*, e.title, e.slug, e.start_at, e.end_at, e.location, e.max_capacity, e.allow_guests, e.waitlist_enabled, e.rsvp_closes_at, e.rsvp_mode, e.rsvp_enabled, e.rsvp_url
            FROM event_rsvps r JOIN events e ON e.id = r.event_id WHERE r.token = ? LIMIT 1');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /** Cancels an RSVP and frees its seats for the waitlist. */
    public static function cancel(string $token): array
    {
        $rsvp = self::forToken($token);
        if (!$rsvp) {
            return ['ok' => false, 'message' => 'That RSVP link is not valid.'];
        }

        self::db()->prepare("UPDATE event_rsvps SET status = 'cancelled', checked_in = 0, checked_in_at = NULL WHERE id = ?")
            ->execute([(int) $rsvp['id']]);

        $promoted = self::promoteWaitlist((int) $rsvp['event_id']);

        return [
            'ok' => true,
            'message' => 'Your RSVP has been cancelled.'
                . ($promoted > 0 ? ' ' . $promoted . ' guest(s) on the waiting list have been moved up.' : ''),
            'promoted' => $promoted,
        ];
    }

    /**
     * Moves waitlisted guests into confirmed places while room remains,
     * oldest first. Idempotent — safe to call after any change.
     */
    public static function promoteWaitlist(int $eventId): int
    {
        $event = self::event($eventId);
        if (!$event) {
            return 0;
        }
        $capacity = self::capacity($event);
        if ($capacity === 0) {
            // No limit: everyone waiting can simply be confirmed.
            $stmt = self::db()->prepare("UPDATE event_rsvps SET status = 'going' WHERE event_id = ? AND status = 'waitlist'");
            $stmt->execute([$eventId]);
            return $stmt->rowCount();
        }

        $available = $capacity - self::seatsTaken($eventId);
        if ($available <= 0) {
            return 0;
        }

        $waiting = self::db()->prepare("SELECT id, guests FROM event_rsvps WHERE event_id = ? AND status = 'waitlist' ORDER BY created_at ASC, id ASC");
        $waiting->execute([$eventId]);

        $promote = self::db()->prepare("UPDATE event_rsvps SET status = 'going' WHERE id = ?");
        $promoted = 0;
        foreach ($waiting->fetchAll() as $row) {
            $seats = (int) $row['guests'] + 1;
            if ($seats > $available) {
                // Someone with a smaller party further down may still fit.
                continue;
            }
            $promote->execute([(int) $row['id']]);
            $available -= $seats;
            $promoted++;
            if ($available <= 0) {
                break;
            }
        }
        return $promoted;
    }

    /** Everyone who said they are coming, for check-in at the door. */
    public static function attendees(int $eventId): array
    {
        $stmt = self::db()->prepare("SELECT * FROM event_rsvps WHERE event_id = ? AND status IN ('going','maybe') ORDER BY name ASC");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    /** Marks a guest as arrived (or undoes it). */
    public static function setCheckedIn(int $rsvpId, bool $checkedIn): bool
    {
        $stmt = self::db()->prepare('UPDATE event_rsvps SET checked_in = ?, checked_in_at = ? WHERE id = ?');
        $stmt->execute([$checkedIn ? 1 : 0, $checkedIn ? date('Y-m-d H:i:s') : null, $rsvpId]);
        return $stmt->rowCount() > 0;
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** "Add to Google Calendar" — no account needed, works on every platform. */
    public static function googleUrl(array $event): string
    {
        $start = self::utc((string) $event['start_at']);
        $end = !empty($event['end_at']) ? self::utc((string) $event['end_at']) : self::utc((string) $event['start_at'], 2 * 3600);

        return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . rawurlencode((string) $event['title'])
            . '&dates=' . $start . '/' . $end
            . '&details=' . rawurlencode(self::plainText((string) ($event['description'] ?? ''), 700))
            . '&location=' . rawurlencode((string) ($event['location'] ?? ''));
    }

    /** Local download link for the .ics file. */
    public static function icsUrl(array $event): string
    {
        return '/api/calendar?event=' . rawurlencode((string) $event['slug']);
    }

    /**
     * Builds a single-event iCalendar file.
     *
     * Times are converted from the site timezone to UTC, which is what the format
     * expects, so the invite lands at the right hour for whoever imports it.
     */
    public static function ics(array $event, ?string $host = null): string
    {
        $host = $host !== null && $host !== '' ? $host : 'localhost';
        $uid = 'event-' . (int) $event['id'] . '@' . $host;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Church Media//Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . self::utc((string) $event['start_at']),
            'DTEND:' . (!empty($event['end_at']) ? self::utc((string) $event['end_at']) : self::utc((string) $event['start_at'], 2 * 3600)),
            'SUMMARY:' . self::escape((string) $event['title']),
            'LOCATION:' . self::escape((string) ($event['location'] ?? '')),
            'DESCRIPTION:' . self::escape(self::plainText((string) ($event['description'] ?? ''), 800)),
            'URL:' . self::escape(self::absoluteUrl('/events/' . (string) $event['slug'], $host)),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        // The spec requires lines to be folded at 75 octets, continuing with a space.
        $folded = [];
        foreach ($lines as $line) {
            $folded[] = self::fold($line);
        }

        return implode("\r\n", $folded) . "\r\n";
    }

    private static function messageFor(string $status, bool $wasUpdate): string
    {
        if ($status === 'waitlist') {
            return "You are on the waiting list — we will be in touch as soon as a place frees up.";
        }
        if ($status === 'declined') {
            return $wasUpdate ? 'Thanks for letting us know you cannot make it.' : 'Noted — thank you for letting us know.';
        }
        if ($status === 'maybe') {
            return 'Thanks — we have noted that you might come.';
        }
        return $wasUpdate ? 'Your RSVP has been updated. See you there!' : 'You are on the list. See you there!';
    }

    private static function event(int $eventId): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM events WHERE id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        return $stmt->fetch() ?: null;
    }

    /** Local time → the UTC stamp the calendar format wants. */
    private static function utc(string $local, int $addSeconds = 0): string
    {
        $ts = strtotime($local);
        if ($ts === false) {
            return gmdate('Ymd\THis\Z');
        }
        return gmdate('Ymd\THis\Z', $ts + $addSeconds);
    }

    /** Strips HTML and truncates for the invite body. */
    private static function plainText(string $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? ''), 0, $limit);
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\,', '\\;'], $value);
    }

    /** RFC 5545 line folding, never splitting a multi-byte character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $length = 0;
        $continuation = false;
        foreach (str_split($line, 1) as $char) {
            $limit = $continuation ? 74 : 75;
            // Only break on a UTF-8 lead byte, so a folded line still decodes.
            if ($length >= $limit && (ord($char) & 0xC0) !== 0x80) {
                $out .= "\r\n ";
                $continuation = true;
                $length = 1; // the leading space counts toward the next line
            }
            $out .= $char;
            $length++;
        }
        return $out;
    }

    private static function absoluteUrl(string $path, string $host): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . $path;
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
