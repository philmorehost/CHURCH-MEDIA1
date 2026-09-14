#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sends the daily devotional notification.
 *
 * Called by cli/devotional_worker.php from cron, once a day. Kept separate from the worker so
 * the audience rules can be exercised in a test without touching FCM.
 *
 * **Who receives what.** A device gets the devotional for its own church — the church it last
 * opened, held in `device_tokens.org_unit_id` — and a device that has never followed a church
 * gets the church-wide entry. A devotional belongs to one church only when `org_unit_id` is set;
 * a church that wrote its own for today gets that one instead of the shared entry, the same rule
 * `Devotional::between()` applies on the website.
 *
 * **Why this sends per token rather than to a topic.** Every other notification in this app goes
 * out on the FCM topic `all`, which is right for "there is a new sermon" — it is one request and
 * reaches everybody. It is wrong here. A member can switch devotionals off on their own
 * dashboard, and a topic send cannot honour that: FCM delivers to every subscriber of the topic
 * whatever our database says. Members are only reachable individually once their device is bound
 * to them, which is what `device_tokens.member_id` is for. A device with no member is anonymous,
 * has stated no preference, and receives — as does a device whose member never changed anything.
 *
 * **Never twice.** `devotionals.push_sent_at` is claimed with a conditional UPDATE *before* the
 * first send, so an overlapping cron run loses the race rather than duplicating the notification.
 * This is deliberately the opposite trade-off from the SMS worker, which records an outcome per
 * recipient: for a daily devotional, a church that is texted twice because a run died halfway is
 * worse than one that is not texted at all, because the second one is visible and re-runnable
 * and the first one is not.
 *
 * This assumes the install serves one tenant: `device_tokens` has no `tenant_id` to filter on, and
 * a cron run has no request host to resolve one from. True for every deployment so far.
 */
final class DevotionalPush
{
    /**
     * Devices one run will consider. A cap rather than paging, because the run is daily and a
     * church with more devices than this has bigger problems than a truncated send.
     */
    public const BATCH_LIMIT = 2000;

    /**
     * Runs one send.
     *
     * @return array{stopped:?string,sent:int,skipped:int,failed:int,claimed:int,pruned:int,reasons:array<int,string>}
     */
    public static function run(bool $force = false, bool $dryRun = false): array
    {
        $summary = array(
            'stopped' => null,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'claimed' => 0,
            'pruned' => 0,
            'reasons' => array(),
        );

        $blocked = self::blockedReason($force);
        if ($blocked !== null) {
            $summary['stopped'] = $blocked;
            return $summary;
        }

        $pdo = Database::getInstance()->getConnection();

        if (self::dueToday($pdo) === array()) {
            $summary['reasons'][] = 'Nothing has been written for today.';
            return $summary;
        }

        if (self::devices($pdo) === array()) {
            $summary['reasons'][] = 'No devices are registered yet.';
            return $summary;
        }

        $claimed = array();

        foreach (self::targets($pdo) as $target) {
            $device = $target['device'];
            $row = $target['devotional'];

            if ($dryRun) {
                $summary['sent']++;
                continue;
            }

            $rowId = (int) $row['id'];
            if (!array_key_exists($rowId, $claimed)) {
                $claimed[$rowId] = self::claim($pdo, $rowId);
                if ($claimed[$rowId]) {
                    $summary['claimed']++;
                }
            }
            if ($claimed[$rowId] === false) {
                // Another run has this day. Leaving it alone is the whole point of the claim.
                $summary['skipped']++;
                continue;
            }

            $ok = Pusher::send(
                (string) $device['token'],
                self::titleFor($row),
                self::bodyFor($row),
                null,
                array(
                    'type' => 'devotional',
                    'date' => (string) $row['publish_on'],
                    'unit_id' => (string) (int) $row['org_unit_id'],
                )
            );

            if ($ok) {
                $summary['sent']++;
                continue;
            }

            $summary['failed']++;
            if (self::tokenIsGone(Pusher::getLastError())) {
                // FCM itself says this token is gone, so keeping it would mean a failed request
                // on every future run and a permanently misleading device count.
                $pdo->prepare('DELETE FROM device_tokens WHERE id = ?')->execute(array((int) $device['id']));
                $summary['pruned']++;
            }
        }

        // Reported separately from `failed`, because a run where every failure was a dead token
        // is a run that worked, and one where the credentials are wrong is not.
        if ($summary['pruned'] > 0) {
            $summary['reasons'][] = $summary['pruned'] . ' device token(s) had been uninstalled and were removed.';
        }

        return $summary;
    }

    /**
     * Every device that should receive something today, paired with the entry it gets.
     *
     * Public and free of side effects, so the audience rules can be asserted directly rather than
     * inferred from what FCM was asked to do. A device with nothing to receive, and a device whose
     * member switched devotionals off, are simply absent from the result.
     *
     * @return array<int, array{device:array<string,mixed>, devotional:array<string,mixed>}>
     */
    public static function targets(PDO $pdo): array
    {
        // Keyed by church, so a device can be matched to the entry that applies to it: its own
        // church's, falling back to the church-wide one.
        $byUnit = array();
        foreach (self::dueToday($pdo) as $row) {
            $byUnit[(int) $row['org_unit_id']] = $row;
        }
        if ($byUnit === array()) {
            return array();
        }

        // Church-wide is only ever the fallback: a church that wrote its own entry for today must
        // get its own, which is what the `??` ordering below decides.
        $shared = $byUnit[0] ?? null;

        $targets = array();
        $wants = array();

        foreach (self::devices($pdo) as $device) {
            $unitId = (int) ($device['org_unit_id'] ?? 0);
            $entry = $byUnit[$unitId] ?? $shared;
            if ($entry === null) {
                continue;
            }

            $memberId = !empty($device['member_id']) ? (int) $device['member_id'] : null;
            if ($memberId !== null) {
                // Cached because a member can have several devices, and reading their preferences
                // once per device would be a query per row for nothing.
                if (!array_key_exists($memberId, $wants)) {
                    $wants[$memberId] = self::wantsDevotional($pdo, $memberId);
                }
                if ($wants[$memberId] === false) {
                    continue;
                }
            }

            $targets[] = array('device' => $device, 'devotional' => $entry);
        }

        return $targets;
    }

    /**
     * Why sending cannot happen right now, or null when it can.
     *
     * Returns a sentence meant for an admin rather than a code, because this text ends up in the
     * mail cron sends.
     */
    private static function blockedReason(bool $force): ?string
    {
        if ((int) setting('devotional_push_enabled', 1) !== 1) {
            return 'The daily devotional notification is switched off. Turn it on under Admin → Devotionals.';
        }

        // The church's own sending window, shared with SMS and WhatsApp rather than a second window
        // to keep in step. A devotional notification at 03:00 is the thing this prevents.
        if (!$force && !SmsCampaign::withinQuietHours()) {
            return 'Outside the sending window (' . SmsCampaign::quietHoursLabel() . '). Nothing sent.';
        }

        if (!Pusher::configured()) {
            $detail = Pusher::getLastError();
            return 'Push is not configured'
                . ($detail !== null && $detail !== '' ? ' — ' . $detail : ' — check Admin → Firebase.');
        }

        return null;
    }

    /** Today's published entries that have not been announced yet. */
    private static function dueToday(PDO $pdo): array
    {
        $sql = 'SELECT id, org_unit_id, title, scripture_reference, body, publish_on
                FROM devotionals
                WHERE publish_on = CURDATE() AND is_published = 1 AND push_sent_at IS NULL
                ORDER BY org_unit_id ASC';
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Every device we could send to.
     *
     * Ordered by id so a truncated run is at least deterministic rather than arbitrary.
     */
    private static function devices(PDO $pdo): array
    {
        $sql = "SELECT id, token, org_unit_id, member_id FROM device_tokens
                WHERE token <> '' ORDER BY id ASC LIMIT " . self::BATCH_LIMIT;
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Takes the day, so nobody else can send it.
     *
     * The `push_sent_at IS NULL` in the WHERE is what makes this safe to run from a cron that
     * might overlap itself: only one caller can change the row, and the loser is told so by
     * rowCount() rather than by luck.
     */
    private static function claim(PDO $pdo, int $devotionalId): bool
    {
        $stmt = $pdo->prepare('UPDATE devotionals SET push_sent_at = NOW() WHERE id = ? AND push_sent_at IS NULL');
        $stmt->execute(array($devotionalId));
        return $stmt->rowCount() === 1;
    }

    /**
     * Whether the member still wants devotional notifications.
     *
     * Defaults to yes, which matches Member::preferences(): an unset key means on. A member row
     * that has disappeared returns true rather than false — the foreign key sets `member_id` to
     * NULL on delete, so this is only reachable in a race, and sending is the harmless side.
     */
    private static function wantsDevotional(PDO $pdo, int $memberId): bool
    {
        $stmt = $pdo->prepare('SELECT notification_prefs FROM members WHERE id = ? LIMIT 1');
        $stmt->execute(array($memberId));
        $row = $stmt->fetch();
        if (!$row) {
            return true;
        }
        $prefs = Member::preferences($row);
        return $prefs['devotional'] !== false;
    }

    /** The notification title: the church, then the devotional. */
    private static function titleFor(array $entry): string
    {
        $title = trim((string) ($entry['title'] ?? ''));
        if ($title === '') {
            $title = 'Daily Devotional';
        }

        $unitId = (int) ($entry['org_unit_id'] ?? 0);
        if ($unitId > 0) {
            $unit = Unit::find($unitId);
            if ($unit && !empty($unit['name'])) {
                return $unit['name'] . ' — ' . $title;
            }
        }

        return $title;
    }

    /** The notification body: the reference, then as much of the text as a lock screen shows. */
    private static function bodyFor(array $entry): string
    {
        $reference = trim((string) ($entry['scripture_reference'] ?? ''));
        $excerpt = trim((string) preg_replace('/\s+/', ' ', (string) ($entry['body'] ?? '')));
        if ($excerpt !== '') {
            $excerpt = mb_strimwidth($excerpt, 0, 110, '…');
        }

        if ($reference !== '' && $excerpt !== '') {
            return $reference . ' · ' . $excerpt;
        }
        if ($reference !== '') {
            return $reference;
        }
        if ($excerpt !== '') {
            return $excerpt;
        }
        return 'Tap to read today\'s devotional.';
    }

    /**
     * Whether an FCM error means the token itself is dead.
     *
     * Deliberately narrow. `UNREGISTERED` and `NOT_FOUND` both mean the token is gone for good.
     * `INVALID_ARGUMENT` is not included even though it can mean a bad token, because it is just
     * as often a malformed payload — and deleting a live device over a bug of ours would quietly
     * shrink the audience with no error for anyone to notice.
     */
    private static function tokenIsGone(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }
        foreach (array('UNREGISTERED', 'NOT_FOUND') as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** How many devices would receive today's notification, for the admin screen. */
    public static function audienceSize(): int
    {
        $pdo = Database::getInstance()->getConnection();
        return (int) $pdo->query("SELECT COUNT(*) FROM device_tokens WHERE token <> ''")->fetchColumn();
    }
}
