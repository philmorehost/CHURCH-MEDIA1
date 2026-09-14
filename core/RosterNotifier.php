<?php
declare(strict_types=1);

/**
 * Tells people they are on the rota, and reminds them the day before.
 *
 * Two kinds of message, one worker:
 *
 *  - **notice** — "you have been asked to serve". Sent once, to anybody with an account.
 *  - **reminder** — the day before. Sent to everyone still on the rota *except* those who already
 *    said no: reminding somebody who has declined is noise, and slightly rude.
 *
 * **Email only, deliberately.** An automatic text message spends the church's SMS wallet per message,
 * so switching that on belongs in an explicit decision with a setting and a cost attached — not in
 * the same breath as a free email. The channel seam is deliberately narrow here (`mailFor` and the
 * two `body` methods) so adding SMS later is an addition rather than a rewrite.
 *
 * Somebody typed in by name has no account and no email, so they are not in this at all — the roster
 * shows the planner their number and the planner rings them. That is the same boundary the member's
 * own answer has, and it is worth keeping the two consistent.
 *
 * `notified_at` and `reminded_at` are separate columns. One shared "last message sent" column would
 * let the day-before reminder suppress the first notice: a rota filled in on a Saturday would tell
 * everyone about Sunday and then never tell them again.
 */
final class RosterNotifier
{
    public const BATCH_LIMIT = 500;

    /** How far ahead the "still to come" notice reaches. A rota far in the future is not news. */
    public const NOTICE_HORIZON_DAYS = 60;

    private static ?PDO $pdo = null;

    /** @var callable|null */
    private static $mailer = null;

    private static function db(): PDO
    {
        return self::$pdo ??= Database::getInstance()->getConnection();
    }

    /**
     * Substitutes the mail transport.
     *
     * The same shape as Sms::setTransport(), and for the same reason: the decision rules and the
     * claim/release behaviour are the parts that can be wrong in a way nobody notices, and neither
     * should need a working SMTP server to assert. It is also the seam a future SMS or WhatsApp
     * channel would hang off, which is why it is a callable rather than a boolean switch.
     */
    public static function setMailer(?callable $mailer): void
    {
        self::$mailer = $mailer;
    }

    private static function mail(string $to, string $subject, string $body): bool
    {
        if (self::$mailer !== null) {
            return (bool) (self::$mailer)($to, $subject, $body);
        }
        return Mailer::send($to, $subject, $body);
    }

    /** The setting that switches this worker off without touching cron. */
    public static function enabled(): bool
    {
        return (bool) setting('roster_reminder_enabled', 1);
    }

    /**
     * Who should be emailed, and why. Side-effect free, so what the rules *are* can be asserted
     * directly rather than inferred from who happened to get a message.
     *
     * @return array<int, array<string, mixed>> each row carries `kind`: 'notice' or 'reminder'
     */
    public static function targets(PDO $pdo): array
    {
        $select = 'SELECT a.id AS assignment_id, a.status, a.notified_at, a.reminded_at,'
            . ' m.id AS member_id, m.name AS member_name, m.email,'
            . ' r.name AS role_name, p.title, p.service_date, p.service_time, p.location';

        // Both queries share the same joins and the same reachability rules: a real member account,
        // not suspended, with an address to send to, on a service that has not been cancelled.
        $from = ' FROM service_assignments a'
            . ' JOIN service_roles r ON r.id = a.role_id'
            . ' JOIN service_plans p ON p.id = r.plan_id'
            . ' JOIN members m ON m.id = a.member_id'
            . ' WHERE m.is_suspended = 0'
            . " AND m.email IS NOT NULL AND m.email <> ''"
            . ' AND p.is_cancelled = 0';

        $noticeStmt = $pdo->prepare(
            $select . $from
            . ' AND a.notified_at IS NULL'
            . ' AND p.service_date >= CURDATE()'
            . ' AND p.service_date <= DATE_ADD(CURDATE(), INTERVAL ' . self::NOTICE_HORIZON_DAYS . ' DAY)'
            . ' ORDER BY p.service_date ASC, a.id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $noticeStmt->execute();
        $notices = $noticeStmt->fetchAll();

        $reminderStmt = $pdo->prepare(
            $select . $from
            . ' AND a.notified_at IS NOT NULL'
            // Not the same day they were told. Somebody added to tomorrow's rota needs one message,
            // not a notice immediately followed by a "still waiting to hear from you" — the second
            // reads as a reproach for not answering something they read a minute ago, and it is how
            // people learn to ignore both.
            . ' AND DATE(a.notified_at) < CURDATE()'
            . ' AND a.reminded_at IS NULL'
            . ' AND p.service_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)'
            // A decline is an answer. Reminding somebody who said no is the kind of message that
            // makes people stop reading the useful ones.
            . " AND a.status <> 'declined'"
            . ' ORDER BY p.service_time ASC, a.id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $reminderStmt->execute();
        $reminders = $reminderStmt->fetchAll();

        $out = array();
        foreach ($notices as $row) {
            $row['kind'] = 'notice';
            $out[] = $row;
        }
        foreach ($reminders as $row) {
            $row['kind'] = 'reminder';
            $out[] = $row;
        }
        return $out;
    }

    /** How many people a run would reach right now. */
    public static function audienceSize(): int
    {
        return count(self::targets(self::db()));
    }

    /**
     * Sends everything that is due.
     *
     * @return array{sent: int, failed: int, notices: int, reminders: int, skipped: ?string}
     */
    public static function run(bool $force = false, bool $dryRun = false): array
    {
        $stats = array('sent' => 0, 'failed' => 0, 'notices' => 0, 'reminders' => 0, 'skipped' => null);

        if (!self::enabled() && !$force) {
            $stats['skipped'] = 'Rota messages are switched off in Settings.';
            return $stats;
        }

        $pdo = self::db();
        foreach (self::targets($pdo) as $row) {
            $column = $row['kind'] === 'notice' ? 'notified_at' : 'reminded_at';
            $id = (int) $row['assignment_id'];
            $stats[$row['kind'] === 'notice' ? 'notices' : 'reminders']++;

            if ($dryRun) {
                continue;
            }

            // Claimed before sending so two runs cannot both send it, and released again if the send
            // fails — a duplicate email is merely annoying, but a notice that silently never arrives
            // leaves a slot nobody knows about.
            if (!self::claim($pdo, $column, $id)) {
                continue;
            }

            $sent = false;
            try {
                $sent = self::mail(
                    (string) $row['email'],
                    self::subjectFor($row),
                    self::bodyFor($row)
                );
            } catch (Throwable $e) {
                error_log('RosterNotifier send failed for assignment ' . $id . ': ' . $e->getMessage());
                $sent = false;
            }

            if ($sent) {
                $stats['sent']++;
            } else {
                self::release($pdo, $column, $id);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private static function claim(PDO $pdo, string $column, int $id): bool
    {
        // The column name is chosen by this class from two literals, never from input.
        $stmt = $pdo->prepare('UPDATE service_assignments SET `' . $column . '` = NOW() WHERE id = ? AND `' . $column . '` IS NULL');
        $stmt->execute(array($id));
        return $stmt->rowCount() > 0;
    }

    private static function release(PDO $pdo, string $column, int $id): void
    {
        $pdo->prepare('UPDATE service_assignments SET `' . $column . '` = NULL WHERE id = ?')->execute(array($id));
    }

    public static function subjectFor(array $row): string
    {
        $church = (string) setting('site_title', 'Church');

        if ($row['kind'] === 'notice') {
            return 'You have been asked to serve · ' . $church;
        }
        return $row['status'] === 'accepted'
            ? 'You are serving tomorrow · ' . $church
            : 'Still waiting to hear from you · ' . $church;
    }

    public static function bodyFor(array $row): string
    {
        $church = (string) setting('site_title', 'Church');
        $name = trim((string) $row['member_name']);
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';

        $when = ServiceRoster::dateLabel((string) $row['service_date']);
        $time = trim((string) ($row['service_time'] ?? ''));
        if ($time !== '') {
            $when .= ' at ' . $time;
        }
        $where = trim((string) ($row['location'] ?? ''));

        $lines = array($greeting, '');
        if ($row['kind'] === 'notice') {
            $lines[] = 'You have been asked to serve on ' . $row['role_name'] . ' at ' . $row['title'] . '.';
        } elseif ($row['status'] === 'accepted') {
            $lines[] = 'A reminder that you are serving on ' . $row['role_name'] . ' tomorrow.';
            $lines[] = 'Thank you — it is appreciated.';
        } else {
            $lines[] = 'You are down to serve on ' . $row['role_name'] . ' tomorrow, and we have not heard';
            $lines[] = 'back from you yet. Somebody is planning around this, so a yes or a no both help.';
        }

        $lines[] = '';
        $lines[] = 'When: ' . $when;
        if ($where !== '') {
            $lines[] = 'Where: ' . $where;
        }

        if ($row['kind'] === 'notice' || $row['status'] !== 'accepted') {
            $lines[] = '';
            $lines[] = 'Please sign in to say yes or no: ' . baseUrl('member');
            $lines[] = 'Your account uses this email address.';
        }

        $lines[] = '';
        $lines[] = '— ' . $church;
        return implode("\n", $lines);
    }
}
