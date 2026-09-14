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
 * **One pass per church.** The switch, the sending window, the members and the plan the nudge quotes are
 * all per church, and a cron has no request host to resolve one from — so `Tenant::each()` is what makes
 * this run as each church in turn instead of as whichever one resolves first (the default one). A pass
 * that throws is held so the other churches still run, then turned into a non-zero exit at the end.
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
    // One report per church. The switch, the sending window and the audience are all per church, and from
    // a shell the church being served is only ever the default one — so reporting once would print one
    // church's audience and call it the install's.
    $status = Tenant::each(static function (int $tenantId): array {
        $targets = ReadingReminder::targets(Database::getInstance()->getConnection());
        $devices = 0;
        foreach ($targets as $group) {
            $devices += count($group['devices']);
        }
        return array(
            'enabled' => (int) setting('reading_reminder_enabled', 1) === 1,
            'quiet' => SmsCampaign::quietHoursLabel(),
            'within' => SmsCampaign::withinQuietHours(),
            'audience' => ReadingReminder::audienceSize(),
            'members' => count($targets),
            'devices' => $devices,
        );
    });

    $several = count($status) > 1;
    foreach ($status as $tenantId => $entry) {
        if ($entry['ok'] !== true || !is_array($entry['result'])) {
            fwrite(STDERR, 'reading_worker: status failed for church ' . $tenantId
                . ' — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
            continue;
        }
        $report = $entry['result'];

        if ($several) {
            $church = Tenant::find((int) $tenantId);
            fwrite(STDOUT, "\n" . (string) ($church['name'] ?? ('Church ' . $tenantId)) . "\n");
        }

        fwrite(STDOUT, 'Reminder      : ' . ($report['enabled'] ? 'on' : 'off') . "\n");
        fwrite(STDOUT, 'Sending window: ' . (string) $report['quiet']
            . ' (now within it: ' . ($report['within'] ? 'yes' : 'no') . ")\n");
        fwrite(STDOUT, 'Members on a published plan: ' . (int) $report['audience'] . "\n");
        fwrite(STDOUT, 'Would be reminded now: ' . (int) $report['members'] . ' member(s) on '
            . (int) $report['devices'] . " device(s)\n");
    }

    // Installation-wide: `Pusher` reads one service account for the whole install, so this is not a
    // per-church fact and must not be repeated inside every church's block as though it were.
    fwrite(STDOUT, 'Push configured: ' . (Pusher::configured() ? 'yes' : 'no') . "\n");
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

// One pass per church, because the switch, the sending window, the members and the plan each nudge quotes
// are all per church. A single run for the whole install read whichever church resolved first — the
// default one, from a cron — and nudged every church's members about another church's plan.
$runs = Tenant::each(static function (int $tenantId) use ($force, $dryRun): array {
    return ReadingReminder::run($force, $dryRun);
});

$several = count($runs) > 1;
$totals = ['claimed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'pruned' => 0];
$stoppedSomewhere = false;
$failedPasses = 0;

foreach ($runs as $tenantId => $entry) {
    $church = $several ? Tenant::find((int) $tenantId) : null;
    $label = $several ? ((string) ($church['name'] ?? ('Church ' . $tenantId)) . ': ') : '';

    if ($entry['ok'] !== true || !is_array($entry['result'])) {
        // The pass threw. Tenant::each held it so the other churches still ran, so this is where it has
        // to become visible — silently skipping a church is how a daily reminder quietly stops.
        $failedPasses++;
        fwrite(STDERR, 'reading_worker: ' . $label . 'the run failed — '
            . (string) ($entry['error'] ?? 'unknown error') . "\n");
        continue;
    }

    $summary = $entry['result'];

    foreach ($summary['reasons'] as $reason) {
        say($label . $reason);
    }

    // A stopped church is not a failure and has no totals to add: it printed its reason and that is the
    // whole of its report. One church being switched off must not stop the others.
    if ($summary['stopped'] !== null) {
        say($label . $summary['stopped']);
        $stoppedSomewhere = true;
        continue;
    }

    $totals['claimed'] += (int) $summary['claimed'];
    $totals['sent'] += (int) $summary['sent'];
    $totals['skipped'] += (int) $summary['skipped'];
    $totals['failed'] += (int) $summary['failed'];
    $totals['pruned'] += (int) $summary['pruned'];
}

if ($totals['claimed'] === 0 && $totals['sent'] === 0 && $totals['failed'] === 0) {
    // Only when no church stopped: "nobody to remind" next to a reason why nobody was reminded would read
    // as a contradiction.
    if (!$stoppedSomewhere) {
        say('Nobody to remind right now.');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($failedPasses > 0 ? 1 : 0);
}

say(sprintf(
    '%s finished in %.1fs: %d member(s) reminded, %d notification(s) sent, %d skipped, %d failed%s.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $totals['claimed'],
    $totals['sent'],
    $totals['skipped'],
    $totals['failed'],
    $totals['pruned'] > 0 ? ', ' . $totals['pruned'] . ' dead token(s) removed' : ''
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and no member was marked as reminded.');
}

flock($lock, LOCK_UN);
fclose($lock);
exit(($failedPasses > 0 || $totals['failed'] > 0) ? 1 : 0);
