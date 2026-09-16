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
 * One pass per church, because a sender ID is polled with the token of the church that submitted it.
 * Asking the gateway about another church's sender ID gets an answer about an ID that account does not
 * own — and the row would be marked rejected on the strength of it.
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

/**
 * The church a cron resolves to, which is the seeded default one.
 *
 * `sms_senders` treats a NULL `tenant_id` as a platform-wide row — the convention
 * `admin/partials/sms/sender-ids.php` already uses — and those rows belong to the platform rather than
 * to any one church. So they are polled during *this* church's pass and not during every church's: a
 * three-church install would otherwise poll each shared row three times, with three different tokens,
 * and could get three different answers, of which the last would win.
 */
$platformTenantId = Tenant::id();

/* ---------------------------------------------------------------- list mode */

if ($listOnly) {
    // Grouped by church. Somebody running this by hand needs to know whose sender IDs these are, and
    // one undifferentiated list of every church's rows is how a name gets changed in the wrong church.
    $listed = Tenant::each(static function (int $tenantId) use ($platformTenantId): array {
        return SmsSenderCheck::rowsFor($tenantId, $tenantId === $platformTenantId);
    });

    $severalChurches = count($listed) > 1;
    $any = false;

    foreach ($listed as $tenantId => $entry) {
        if ($entry['ok'] !== true || !is_array($entry['result'])) {
            fwrite(STDERR, 'sms_sender_check: could not list church ' . $tenantId . ' — '
                . (string) ($entry['error'] ?? 'unknown error') . "\n");
            continue;
        }
        $rows = $entry['result'];
        if ($rows) {
            $any = true;
        }
        if ($severalChurches) {
            $church = Tenant::find((int) $tenantId);
            fwrite(STDOUT, "\n" . (string) ($church['name'] ?? ('Church ' . $tenantId)) . "\n");
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
    }

    if (!$any) {
        fwrite(STDOUT, "No sender IDs have been submitted yet.\n");
    }
    exit(0);
}

/* ------------------------------------------------------------------- due set */

// One pass per church: each church's own sender IDs are polled with that church's own gateway token.
// The pass itself lives in `core/SmsSenderCheck.php` — the same split `sms_worker.php` has from
// `SmsRunner` — so it can be driven directly, rather than only through this script.
$runs = Tenant::each(static function (int $tenantId) use ($checkAll, $platformTenantId): array {
    return SmsSenderCheck::pass($tenantId, $checkAll, $tenantId === $platformTenantId);
});

$severalChurches = count($runs) > 1;
$totals = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'errors' => 0];
$configured = 0;
$failedPasses = 0;

foreach ($runs as $tenantId => $entry) {
    $church = $severalChurches ? Tenant::find((int) $tenantId) : null;
    $label = $severalChurches ? (string) ($church['name'] ?? ('Church ' . $tenantId)) . ': ' : '';

    if ($entry['ok'] !== true || !is_array($entry['result'])) {
        // A pass that threw is a bug worth a non-zero exit. Tenant::each held it so the other churches
        // still ran, which is why it has to be reported out here rather than inside the function.
        $failedPasses++;
        fwrite(STDERR, 'sms_sender_check: ' . $label . 'the pass failed — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
        continue;
    }

    $pass = $entry['result'];

    if ($pass['configured'] !== true) {
        // Reported per church rather than as a pre-flight exit: a church with no token must not stop
        // every other church's sender IDs from being polled.
        if ($severalChurches) {
            fwrite(STDERR, 'sms_sender_check: ' . $label . "no SMS API token is configured; skipped.\n");
        }
        continue;
    }

    $configured++;

    foreach ($pass['lines'] as $line) {
        fwrite($line['stream'] === 'err' ? STDERR : STDOUT, 'sms_sender_check: ' . $label . $line['text'] . "\n");
    }

    foreach (['checked', 'approved', 'rejected', 'pending', 'errors'] as $key) {
        $totals[$key] += (int) $pass[$key];
    }
}

if ($configured === 0) {
    fwrite(STDERR, "sms_sender_check: no SMS API token is configured; skipping.\n");
    exit($failedPasses > 0 ? 1 : 0);
}

fwrite(STDOUT, sprintf(
    "sms_sender_check: %d checked, %d approved, %d rejected, %d still pending, %d error(s).\n",
    $totals['checked'],
    $totals['approved'],
    $totals['rejected'],
    $totals['pending'],
    $totals['errors']
));

// A pass that threw is a bug worth surfacing to cron. A sender ID that is merely still pending at the
// gateway is not a failure.
exit($failedPasses > 0 ? 1 : 0);

