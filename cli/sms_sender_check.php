#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sender-ID status poller. Run from cron every 15 minutes.
 *
 * A church submits its own sender ID, the gateway reviews it, and this is what notices
 * the result — so nobody has to keep refreshing a page to find out. When one is
 * approved, the church that submitted it is told.
 *
 * Polling backs off as a request gets older, because a sender ID that has been pending
 * for a week is not going to be approved in the next fifteen minutes:
 *
 *   younger than 6 hours  → every 15 minutes
 *   younger than 1 day    → every 2 hours
 *   younger than 7 days   → every 6 hours
 *   older than that       → once a day
 *
 * A status that is final (`approved` or `rejected`) is never checked again unless
 * `--force` is given, so gateway calls settle to zero as requests resolve.
 *
 * Manual overrides are respected: a row whose `status_source` is `manual` is left alone,
 * because the super admin set it deliberately — usually precisely because the gateway
 * was unreachable.
 *
 * Usage:
 *   php cli/sms_sender_check.php            check whatever is due
 *   php cli/sms_sender_check.php --all      check every non-final sender ID now
 *   php cli/sms_sender_check.php --list     show statuses and exit
 */

if (!defined('STDERR')) {
    $errStream = @fopen('php://stderr', 'wb');
    define('STDERR', $errStream ?: fopen('php://output', 'wb'));
}
if (!defined('STDOUT')) {
    $outStream = @fopen('php://stdout', 'wb');
    define('STDOUT', $outStream ?: fopen('php://output', 'wb'));
}

require __DIR__ . '/../bootstrap.php';

if (!defined('APP_IS_INSTALLED') || !APP_IS_INSTALLED) {
    fwrite(STDERR, "sms_sender_check: the application is not installed; nothing to do.\n");
    exit(1);
}

$options = array_slice($argv, 1);
$checkAll = in_array('--all', $options, true);
$listOnly = in_array('--list', $options, true);

$pdo = Database::getInstance()->getConnection();

/** The minimum minutes between checks for a request of this age. */
$intervalFor = static function (int $ageMinutes): int {
    if ($ageMinutes < 360) {   // under 6 hours
        return 15;
    }
    if ($ageMinutes < 1440) {  // under a day
        return 120;
    }
    if ($ageMinutes < 10080) { // under a week
        return 360;
    }
    return 1440;
};

/* ---------------------------------------------------------------- list mode */

if ($listOnly) {
    $rows = $pdo->query('SELECT * FROM sms_senders ORDER BY status ASC, id ASC')->fetchAll();
    if (!$rows) {
        fwrite(STDOUT, "No sender IDs have been submitted yet.\n");
        exit(0);
    }
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "%-12s %-9s %-8s %-14s %s\n",
            (string) $row['sender_id'],
            (string) $row['status'],
            (string) $row['status_source'],
            $row['last_checked_at'] ? (string) $row['last_checked_at'] : 'never checked',
            (string) ($row['rejection_note'] ?? $row['last_check_note'] ?? '')
        ));
    }
    exit(0);
}

if (!Sms::configured()) {
    fwrite(STDERR, "sms_sender_check: no SMS API token is configured; skipping.\n");
    exit(0);
}

/* ------------------------------------------------------------------- due set */

// Only `pending` rows can change, and a manual override is not ours to overrule.
$candidates = $pdo->query("SELECT * FROM sms_senders WHERE status = 'pending' AND status_source = 'gateway' ORDER BY id ASC")->fetchAll();

$checked = 0;
$approved = 0;
$rejected = 0;
$stillPending = 0;
$errors = 0;

foreach ($candidates as $row) {
    $id = (int) $row['id'];
    $senderId = (string) $row['sender_id'];

    if (!$checkAll && $row['last_checked_at'] !== null) {
        $ageMinutes = (int) floor((time() - strtotime((string) $row['created_at'])) / 60);
        $sinceLast = (int) floor((time() - strtotime((string) $row['last_checked_at'])) / 60);
        $due = $intervalFor(max(0, $ageMinutes));
        if ($sinceLast < $due) {
            continue;
        }
    }

    $result = Sms::senderIdStatus($senderId);
    $checked++;

    if (!$result['ok']) {
        $errors++;
        // Record the attempt so a gateway that is down does not cause a retry storm.
        $pdo->prepare('UPDATE sms_senders SET last_checked_at = NOW(), last_check_note = ? WHERE id = ?')
            ->execute([mb_substr('Check failed: ' . (string) ($result['error'] ?? 'unknown error'), 0, 255), $id]);
        fwrite(STDERR, sprintf("sms_sender_check: %s — %s\n", $senderId, (string) ($result['error'] ?? 'check failed')));
        continue;
    }

    // The gateway is inconsistent about the key and the case, so read it loosely.
    $raw = $result['raw'];
    $status = '';
    foreach (['status', 'senderIDStatus', 'sender_status', 'message', 'data'] as $key) {
        if (isset($raw[$key]) && is_scalar($raw[$key])) {
            $status = strtolower(trim((string) $raw[$key]));
            break;
        }
    }

    // Match on the words rather than on an exact string: the gateway has answered with
    // "Approved", "approved." and "Sender ID approved" at different times.
    $resolved = 'pending';
    if (str_contains($status, 'approve')) {
        $resolved = 'approved';
    } elseif (str_contains($status, 'reject') || str_contains($status, 'declin') || str_contains($status, 'denied')) {
        $resolved = 'rejected';
    }

    if ($resolved === 'pending') {
        $stillPending++;
        $pdo->prepare('UPDATE sms_senders SET last_checked_at = NOW(), last_check_note = ? WHERE id = ?')
            ->execute([mb_substr('Still pending at the gateway: ' . ($status !== '' ? $status : 'no status given'), 0, 255), $id]);
        continue;
    }

    if ($resolved === 'approved') {
        $approved++;
        $pdo->prepare("UPDATE sms_senders SET status = 'approved', status_source = 'gateway', approved_at = NOW(), last_checked_at = NOW(), last_check_note = NULL WHERE id = ?")
            ->execute([$id]);

        // If nothing else is set as default yet, make this one it — a church that has
        // just been approved should be able to send without hunting for a setting.
        if ((string) setting('sms_default_sender_id', '') === '') {
            settingSave(['sms_default_sender_id' => $senderId]);
        }

        notifyApproved($row);
        fwrite(STDOUT, sprintf("sms_sender_check: %s approved.\n", $senderId));
        continue;
    }

    $rejected++;
    $note = mb_substr('The gateway rejected this sender ID. ' . ($status !== '' ? 'Gateway said: ' . $status : ''), 0, 255);
    $pdo->prepare("UPDATE sms_senders SET status = 'rejected', status_source = 'gateway', rejection_note = ?, last_checked_at = NOW() WHERE id = ?")
        ->execute([$note, $id]);

    notifyRejected($row, $note);
    fwrite(STDOUT, sprintf("sms_sender_check: %s rejected.\n", $senderId));
}

fwrite(STDOUT, sprintf(
    "sms_sender_check: %d checked, %d approved, %d rejected, %d still pending, %d error(s).\n",
    $checked,
    $approved,
    $rejected,
    $stillPending,
    $errors
));

exit(0);

/**
 * Tells the church that submitted the sender ID that it is ready to use.
 *
 * Addressed to the submitting unit, and to the submitter's own unit when they are
 * different — the person who filled the form and the church it belongs to both want
 * to know. `media_team` is included because that is who the roadmap has submitting
 * these, and they are the ones the approval unblocks.
 */
function notifyApproved(array $row): void
{
    $units = [];
    if (!empty($row['org_unit_id'])) {
        $units[] = (int) $row['org_unit_id'];
    }
    if (!empty($row['submitted_by'])) {
        try {
            $stmt = Database::getInstance()->getConnection()->prepare('SELECT org_unit_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $row['submitted_by']]);
            $unit = $stmt->fetchColumn();
            if ($unit) {
                $units[] = (int) $unit;
            }
        } catch (Throwable $e) {
            // Fall through with whatever units we already have.
        }
    }

    if ($units === []) {
        // No church attached — tell everyone who can act on it rather than nobody.
        foreach (Unit::all('id ASC') as $unit) {
            $units[] = (int) $unit['id'];
        }
    }

    try {
        Notifier::send(
            $units,
            'Sender ID ' . (string) $row['sender_id'] . ' approved',
            'Your sender ID ' . (string) $row['sender_id'] . ' has been approved and is ready to use. '
            . 'You can now send SMS from Admin → SMS → Compose.',
            ['email' => true, 'push' => true, 'roles' => ['admin', 'editor', 'media_team']]
        );
    } catch (Throwable $e) {
        error_log('sms_sender_check notify failed: ' . $e->getMessage());
    }
}

/** Tells the submitter why it was refused, so they can fix it and try again. */
function notifyRejected(array $row, string $note): void
{
    $units = [];
    if (!empty($row['org_unit_id'])) {
        $units[] = (int) $row['org_unit_id'];
    } elseif (!empty($row['submitted_by'])) {
        try {
            $stmt = Database::getInstance()->getConnection()->prepare('SELECT org_unit_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $row['submitted_by']]);
            $unit = $stmt->fetchColumn();
            if ($unit) {
                $units[] = (int) $unit;
            }
        } catch (Throwable $e) {
            // Nothing more we can do; the row itself is updated either way.
        }
    }

    if ($units === []) {
        return;
    }

    try {
        Notifier::send(
            $units,
            'Sender ID ' . (string) $row['sender_id'] . ' was not approved',
            $note . "\n\nYou can submit a different sender ID under Admin → SMS → Sender IDs.",
            ['email' => true, 'push' => false, 'roles' => ['admin', 'editor', 'media_team']]
        );
    } catch (Throwable $e) {
        error_log('sms_sender_check notify failed: ' . $e->getMessage());
    }
}
