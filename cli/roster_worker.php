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
 * **One pass per church.** The rota, the people on it and the words of the message all belong to a
 * church, and a cron has no request host to resolve one from — so `Tenant::each()` is what makes this run
 * as each church in turn instead of as whichever one resolves first (the default one). A pass that throws
 * is held so the other churches still run, then turned into a non-zero exit at the end. The switch is per
 * church too, so one church turning rota messages off no longer silences every other church's rota.
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
    // One report per church. Both the switch and the audience are per church, and from a shell the church
    // being served is only ever the default one — so reporting once would print one church's audience and
    // call it the install's.
    $status = Tenant::each(static function (int $tenantId): array {
        return array(
            'enabled' => RosterNotifier::enabled(),
            'audience' => RosterNotifier::audienceSize(),
        );
    });

    $several = count($status) > 1;
    foreach ($status as $tenantId => $entry) {
        if ($entry['ok'] !== true || !is_array($entry['result'])) {
            fwrite(STDERR, 'roster_worker: status failed for church ' . $tenantId
                . ' — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
            continue;
        }
        $report = $entry['result'];

        if ($several) {
            $church = Tenant::find((int) $tenantId);
            fwrite(STDOUT, "\n" . (string) ($church['name'] ?? ('Church ' . $tenantId)) . "\n");
        }

        fwrite(STDOUT, 'Rota messages : ' . ($report['enabled'] ? 'on' : 'off') . "\n");
        fwrite(STDOUT, 'Mail configured: ' . (Mailer::configured() ? 'yes' : 'no') . "\n");
        fwrite(STDOUT, 'Would be sent now: ' . (int) $report['audience'] . " message(s)\n");
    }

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

// One pass per church, because the plans, the people on the rota and the switch that silences it all
// belong to a church. A single run for the whole install read whichever church resolved first — the
// default one, from a cron — for the site name in the message and for the switch alike.
$runs = Tenant::each(static function (int $tenantId) use ($force, $dryRun): array {
    return RosterNotifier::run($force, $dryRun);
});

$several = count($runs) > 1;
$totals = ['sent' => 0, 'notices' => 0, 'reminders' => 0, 'failed' => 0];
$stoppedSomewhere = false;
$failedPasses = 0;

foreach ($runs as $tenantId => $entry) {
    $church = $several ? Tenant::find((int) $tenantId) : null;
    $label = $several ? ((string) ($church['name'] ?? ('Church ' . $tenantId)) . ': ') : '';

    if ($entry['ok'] !== true || !is_array($entry['result'])) {
        // The pass threw. Tenant::each held it so the other churches still ran, so this is where it has
        // to become visible — silently skipping a church is how a rota notice quietly stops arriving.
        $failedPasses++;
        fwrite(STDERR, 'roster_worker: ' . $label . 'the run failed — '
            . (string) ($entry['error'] ?? 'unknown error') . "\n");
        continue;
    }

    $summary = $entry['result'];

    // A church with the switch off is not a failure and has no totals to add: it printed its own reason
    // and that is the whole of its report. One church being switched off must not stop the others.
    if ($summary['skipped'] !== null) {
        say($label . $summary['skipped'] . ' Use --force to send anyway.');
        $stoppedSomewhere = true;
        continue;
    }

    $totals['sent'] += (int) $summary['sent'];
    $totals['notices'] += (int) $summary['notices'];
    $totals['reminders'] += (int) $summary['reminders'];
    $totals['failed'] += (int) $summary['failed'];
}

// A dry run counts notices and reminders but sends nothing, so `sent` alone cannot answer "was there
// work": using it made `--dry-run` print "Nothing due right now." on every run, whatever the rota held.
$worked = $totals['sent'] + $totals['notices'] + $totals['reminders'];

if ($worked === 0 && $totals['failed'] === 0) {
    // Only when no church was switched off: "nothing due" next to a reason why nothing was sent would read
    // as a contradiction.
    if (!$stoppedSomewhere) {
        say('Nothing due right now.');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($failedPasses > 0 ? 1 : 0);
}

say(sprintf(
    '%s finished in %.1fs: %d message(s) sent (%d notice(s), %d reminder(s)), %d failed.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $totals['sent'],
    $totals['notices'],
    $totals['reminders'],
    $totals['failed']
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and nothing was marked as notified.');
}

flock($lock, LOCK_UN);
fclose($lock);
exit(($failedPasses > 0 || $totals['failed'] > 0) ? 1 : 0);
