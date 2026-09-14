#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rota worker. Run from cron a few times a day — hourly is fine — and it does two things:
 *
 *   1. **Tells people they have been put on a service.** A member added to a rota gets one email,
 *      as soon as the next run picks it up.
 *   2. **Reminds them the day before.** Everyone still on the rota gets one message the day before
 *      the service — worded differently depending on whether they have answered yet.
 *
 * Anybody typed in by name has no account and no address, so they are not in this at all: the roster
 * shows the planner their number and the planner rings them. See core/RosterNotifier.php.
 *
 * **No sending window.** The SMS features refuse to send between 20:00 and 07:00 because a text
 * message rings a phone in somebody's bedroom. An email does not, and a rota notice is not urgent
 * enough to be worth delaying — so here the cron time *is* the control, and the worker runs whenever
 * it is called.
 *
 * Usage:
 *   php cli/roster_worker.php                 send whatever is due
 *   php cli/roster_worker.php --dry-run       say who would be emailed, send nothing
 *   php cli/roster_worker.php --status        print the switch, the audience and the cron line
 *   php cli/roster_worker.php --force         run even though the setting is off
 *   php cli/roster_worker.php --quiet         print nothing on a run with no work
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
    fwrite(STDERR, "roster_worker: the application is not installed; nothing to do.\n");
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
    fwrite(STDOUT, 'Rota messages : ' . (RosterNotifier::enabled() ? 'on' : 'off') . "\n");
    fwrite(STDOUT, 'Mail configured: ' . (Mailer::configured() ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, 'Would be sent now: ' . RosterNotifier::audienceSize() . " message(s)\n");
    fwrite(STDOUT, "\nSuggested cron, every hour:\n  5 * * * * php " . ROOT_PATH . "/cli/roster_worker.php --quiet\n");
    exit(0);
}

/* ------------------------------------------------------------- single runner */

// A lock file, so a slow run and the next cron tick never overlap. The per-assignment claim already
// makes a double send impossible; this only stops two runs doing the same work.
$lockPath = STORAGE_PATH . '/cache/roster_worker.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "roster_worker: could not open the lock file at {$lockPath}.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another worker run is still going; leaving this one to it.');
    exit(0);
}

$startedAt = microtime(true);
$summary = RosterNotifier::run($force, $dryRun);

if ($summary['skipped'] !== null) {
    say($summary['skipped'] . ' Use --force to send anyway.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

if ($summary['sent'] === 0 && $summary['failed'] === 0) {
    say('Nothing due right now.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

say(sprintf(
    '%s finished in %.1fs: %d message(s) sent (%d notice(s), %d reminder(s)), %d failed.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $summary['sent'],
    $summary['notices'],
    $summary['reminders'],
    $summary['failed']
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and nothing was marked as notified.');
}

flock($lock, LOCK_UN);
fclose($lock);
exit($summary['failed'] > 0 ? 1 : 0);
