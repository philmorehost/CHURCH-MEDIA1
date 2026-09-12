#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SMS queue worker. Run from cron every minute; see Admin → SMS → Guide for the exact
 * command. Safe to run by hand at any time.
 *
 * What one run does, in order:
 *   1. Promotes scheduled campaigns whose moment has arrived into the queue.
 *   2. Refuses to send outside the quiet-hours window, or once today's unit cap is
 *      reached — leaving campaigns queued rather than failing them, so a top-up or a
 *      morning simply resumes where it left off.
 *   3. Checks the wallet and **pauses** the campaign if the balance is short of what the
 *      remaining queue will cost. It never half-sends a campaign and leaves an admin to
 *      discover it from the bill.
 *   4. Sends in claimed batches, records an outcome per recipient, and finishes each
 *      campaign as `sent`, `partial` or `failed`.
 *
 * `partial` never means "something is broken" — it means "stopped for a reason you can
 * act on", with that reason recorded in `paused_reason`. A campaign that says `failed`
 * would invite an admin to re-send the whole thing, which is how people get texted twice.
 *
 * Idempotent and resumable: a second run, an overlapping run, or a run killed halfway
 * all pick up safely. See core/SmsCampaign.php for why.
 *
 * **On delivery receipts:** the gateway answers one code per API call, not per number, so
 * "sent" here means *the gateway accepted it for delivery* — it is not proof that a
 * handset received it. Nothing in the admin screens should claim otherwise.
 *
 * Usage:
 *   php cli/sms_worker.php                 work the queue
 *   php cli/sms_worker.php --campaign=12   only campaign 12
 *   php cli/sms_worker.php --status        print the queue and exit
 *   php cli/sms_worker.php --force         ignore quiet hours (not the daily cap)
 *   php cli/sms_worker.php --quiet         print nothing on a run with no work
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
    fwrite(STDERR, "sms_worker: the application is not installed; nothing to do.\n");
    exit(1);
}

$options = array_slice($argv, 1);
$onlyCampaign = 0;
$statusOnly = false;
$force = false;
$silent = false;
foreach ($options as $option) {
    if (str_starts_with($option, '--campaign=')) {
        $onlyCampaign = (int) substr($option, 11);
    } elseif ($option === '--status') {
        $statusOnly = true;
    } elseif ($option === '--force') {
        $force = true;
    } elseif ($option === '--quiet') {
        $silent = true;
    }
}

function say(string $line): void
{
    global $silent;
    if (!$silent) {
        fwrite(STDOUT, $line . "\n");
    }
}

/* --------------------------------------------------------------- status mode */

if ($statusOnly) {
    $rows = SmsCampaign::active(50);
    if (!$rows) {
        fwrite(STDOUT, "No campaigns are queued.\n");
    } else {
        foreach ($rows as $row) {
            $counts = SmsCampaign::statusCounts((int) $row['id']);
            fwrite(STDOUT, sprintf(
                "#%-4d %-10s %-40s pending=%d sent=%d failed=%d%s\n",
                (int) $row['id'],
                (string) $row['status'],
                mb_strimwidth((string) $row['title'], 0, 40, '…'),
                $counts['pending'],
                $counts['sent'],
                $counts['failed'],
                $row['paused_reason'] ? '  (' . $row['paused_reason'] . ')' : ''
            ));
        }
    }
    fwrite(STDOUT, sprintf(
        "\nToday: %d unit(s) sent%s. Quiet hours: %s. Now within them: %s\n",
        SmsCampaign::unitsSentToday(),
        SmsCampaign::dailyCap() > 0 ? ' of ' . SmsCampaign::dailyCap() . ' allowed' : ' (no cap)',
        SmsCampaign::quietHoursLabel(),
        SmsCampaign::withinQuietHours() ? 'yes' : 'no'
    ));
    exit(0);
}

/* ------------------------------------------------------------- single runner */

// A lock file, so a slow run and the next cron tick never overlap. PHP releases it on
// exit, including after a fatal error.
$lockPath = STORAGE_PATH . '/cache/sms_worker.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "sms_worker: could not open the lock file at {$lockPath}.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another worker run is still going; leaving this one to it.');
    exit(0);
}

if (!Sms::configured()) {
    fwrite(STDERR, "sms_worker: no SMS API token is configured. Add one under Admin → SMS → Settings.\n");
    exit(0);
}

$summary = SmsRunner::run([
    'only_campaign' => $onlyCampaign,
    'force' => $force,
]);

foreach ($summary['paused_reasons'] as $reason) {
    fwrite(STDERR, 'sms_worker: paused — ' . $reason . "\n");
}

if ($summary['stopped'] !== null) {
    say($summary['stopped']);
}

if ($summary['processed'] === 0) {
    say('Nothing to send.');
} else {
    say(sprintf(
        'Run finished in %ss: %d campaign(s), %d sent, %d failed%s.',
        $summary['elapsed'],
        $summary['processed'],
        $summary['sent'],
        $summary['failed'],
        $summary['paused'] > 0 ? ', ' . $summary['paused'] . ' paused' : ''
    ));
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
