#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Reading-plan reminder worker. Run from cron once a day, in the evening; see Admin → Reading Plans
 * for the suggested line. Safe to run by hand at any time.
 *
 * What one run does:
 *   1. Finds members on a published plan who have not ticked anything today.
 *   2. Refuses to send outside the church's sending window, shared with SMS and WhatsApp.
 *   3. Skips anyone who switched reading reminders off on their own dashboard, and anyone who has
 *      already finished their plan.
 *   4. Claims the day per member before sending, so running it hourly sends once.
 *
 * The switch on the member dashboard is what makes this worker's audience what it is — before this
 * existed, that checkbox turned nothing off. See core/ReadingReminder.php.
 *
 * Usage:
 *   php cli/reading_worker.php                 remind members who have not read today
 *   php cli/reading_worker.php --dry-run       say who would be reminded, send nothing
 *   php cli/reading_worker.php --status        print the audience and whether sending is open
 *   php cli/reading_worker.php --force         ignore the sending window
 *   php cli/reading_worker.php --quiet         print nothing on a run with no work
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
    fwrite(STDERR, "reading_worker: the application is not installed; nothing to do.\n");
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
    fwrite(STDOUT, 'Reminder      : ' . ((int) setting('reading_reminder_enabled', 1) === 1 ? 'on' : 'off') . "\n");
    fwrite(STDOUT, 'Sending window: ' . SmsCampaign::quietHoursLabel()
        . ' (now within it: ' . (SmsCampaign::withinQuietHours() ? 'yes' : 'no') . ")\n");
    fwrite(STDOUT, 'Push configured: ' . (Pusher::configured() ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, 'Members on a published plan: ' . ReadingReminder::audienceSize() . "\n");

    $targets = ReadingReminder::targets(Database::getInstance()->getConnection());
    $devices = 0;
    foreach ($targets as $group) {
        $devices += count($group['devices']);
    }
    fwrite(STDOUT, 'Would be reminded now: ' . count($targets) . ' member(s) on ' . $devices . " device(s)\n");
    fwrite(STDOUT, "\nSuggested cron, once a day in the evening:\n  30 18 * * * php " . ROOT_PATH . "/cli/reading_worker.php\n");
    exit(0);
}

/* ------------------------------------------------------------- single runner */

// A lock file, so a slow run and the next cron tick never overlap. The per-member claim already
// makes a double send impossible; this just avoids two runs doing the same work.
$lockPath = STORAGE_PATH . '/cache/reading_worker.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "reading_worker: could not open the lock file at {$lockPath}.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another worker run is still going; leaving this one to it.');
    exit(0);
}

$startedAt = microtime(true);
$summary = ReadingReminder::run($force, $dryRun);

foreach ($summary['reasons'] as $reason) {
    say($reason);
}

if ($summary['stopped'] !== null) {
    say($summary['stopped']);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

if ($summary['claimed'] === 0 && $summary['sent'] === 0 && $summary['failed'] === 0) {
    say('Nobody to remind right now.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

say(sprintf(
    '%s finished in %.1fs: %d member(s) reminded, %d notification(s) sent, %d skipped, %d failed%s.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $summary['claimed'],
    $summary['sent'],
    $summary['skipped'],
    $summary['failed'],
    $summary['pruned'] > 0 ? ', ' . $summary['pruned'] . ' dead token(s) removed' : ''
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and no member was marked as reminded.');
}

flock($lock, LOCK_UN);
fclose($lock);
exit($summary['failed'] > 0 ? 1 : 0);
