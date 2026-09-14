#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Daily devotional notification worker. Run from cron once a day; see Admin → Devotionals for
 * the exact command. Safe to run by hand at any time.
 *
 * What one run does:
 *   1. Looks for today's published devotional that has not been announced yet. Nothing written
 *      for today means nothing happens, which is the normal case on a day nobody wrote one.
 *   2. Refuses to send outside the church's sending window, shared with SMS and WhatsApp, so a
 *      mis-set cron cannot wake the congregation at 3am.
 *   3. Sends one notification per device, giving each device the entry for its own church and
 *      skipping any member who has switched devotionals off on their own dashboard.
 *   4. Claims `push_sent_at` before the first send, so a second run — or an overlapping one —
 *      cannot announce the same day twice.
 *
 * Run it twice in a row to prove the second run does nothing: that is the property the whole
 * design is for. See core/DevotionalPush.php for why it sends per device rather than to a topic,
 * and why it claims before sending rather than recording each outcome like the SMS worker.
 *
 * Usage:
 *   php cli/devotional_worker.php                 send today's devotional
 *   php cli/devotional_worker.php --dry-run       say who would receive it, send nothing
 *   php cli/devotional_worker.php --status        print what is due and whether sending is open
 *   php cli/devotional_worker.php --force         ignore the sending window
 *   php cli/devotional_worker.php --quiet         print nothing on a run with no work
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
    fwrite(STDERR, "devotional_worker: the application is not installed; nothing to do.\n");
    exit(1);
}

$statusOnly = false;
$force = false;
$dryRun = false;
$silent = false;
foreach (array_slice($argv, 1) as $option) {
    if ($option === '--status') {
        $statusOnly = true;
    } elseif ($option === '--force') {
        $force = true;
    } elseif ($option === '--dry-run') {
        $dryRun = true;
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
    $pdo = Database::getInstance()->getConnection();

    $enabled = (int) setting('devotional_push_enabled', 1) === 1;
    fwrite(STDOUT, 'Daily notification : ' . ($enabled ? 'on' : 'off') . "\n");
    fwrite(STDOUT, 'Sending window     : ' . SmsCampaign::quietHoursLabel()
        . ' (now within it: ' . (SmsCampaign::withinQuietHours() ? 'yes' : 'no') . ")\n");
    fwrite(STDOUT, 'Push configured    : ' . (Pusher::configured() ? 'yes' : 'no')
        . (Pusher::configured() ? '' : ' — ' . (string) Pusher::getLastError()) . "\n");
    fwrite(STDOUT, 'Devices registered : ' . DevotionalPush::audienceSize() . "\n");

    $statement = $pdo->query(
        "SELECT id, org_unit_id, title, is_published, push_sent_at
         FROM devotionals WHERE publish_on = CURDATE() ORDER BY org_unit_id ASC"
    );
    $rows = $statement->fetchAll();
    if (!$rows) {
        fwrite(STDOUT, "\nToday: no devotional has been written.\n");
        exit(0);
    }

    fwrite(STDOUT, "\nToday:\n");
    foreach ($rows as $row) {
        $unit = (int) $row['org_unit_id'];
        fwrite(STDOUT, sprintf(
            "  #%-4d %-14s %-40s %s%s\n",
            (int) $row['id'],
            $unit === 0 ? 'church-wide' : 'unit ' . $unit,
            mb_strimwidth((string) $row['title'], 0, 40, '…'),
            ((int) $row['is_published'] === 1 ? 'published' : 'draft'),
            $row['push_sent_at'] !== null ? ', sent ' . (string) $row['push_sent_at'] : ''
        ));
    }
    exit(0);
}

/* ------------------------------------------------------------- single runner */

// A lock file, so a slow run and the next cron tick never overlap. The claim inside
// DevotionalPush::run() already makes a double send impossible; this just avoids two runs
// doing the same work. PHP releases it on exit, including after a fatal error.
$lockPath = STORAGE_PATH . '/cache/devotional_worker.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "devotional_worker: could not open the lock file at {$lockPath}.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another worker run is still going; leaving this one to it.');
    exit(0);
}

$startedAt = microtime(true);
$summary = DevotionalPush::run($force, $dryRun);

foreach ($summary['reasons'] as $reason) {
    say($reason);
}

if ($summary['stopped'] !== null) {
    say($summary['stopped']);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$touched = $summary['sent'] + $summary['failed'];

if ($summary['claimed'] === 0 && $touched === 0) {
    say('Nothing to send.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

say(sprintf(
    '%s finished in %.1fs: %d notification(s) sent, %d skipped, %d failed%s.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $summary['sent'],
    $summary['skipped'],
    $summary['failed'],
    $summary['pruned'] > 0 ? ', ' . $summary['pruned'] . ' dead token(s) removed' : ''
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and push_sent_at was not changed.');
}

// Failures are worth a non-zero exit so cron mails somebody. A run that had nothing to do is not.
flock($lock, LOCK_UN);
fclose($lock);
exit($summary['failed'] > 0 ? 1 : 0);
