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
 * **One pass per church.** The switch, the sending window, today's entry and the devices are all per
 * church, and a cron has no request host to resolve one from — so `Tenant::each()` is what makes this
 * run as each church in turn instead of as whichever one resolves first (the default one). A pass that
 * throws is held so the other churches still run, then turned into a non-zero exit at the end.
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
    // One report per church. The switch, the sending window and today's entry are all per church, and
    // from a shell the church being served is only ever the default one — so reporting once would print
    // one church's status and call it the install's.
    $status = Tenant::each(static function (int $tenantId): array {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT id, org_unit_id, title, is_published, push_sent_at
             FROM devotionals WHERE tenant_id = ? AND publish_on = CURDATE() ORDER BY org_unit_id ASC'
        );
        $statement->execute(array($tenantId));

        return array(
            'enabled' => (int) setting('devotional_push_enabled', 1) === 1,
            'quiet' => SmsCampaign::quietHoursLabel(),
            'within' => SmsCampaign::withinQuietHours(),
            'devices' => DevotionalPush::audienceSize(),
            'rows' => $statement->fetchAll(),
        );
    });

    // Installation-wide, not per church: `Pusher` reads one service account for the whole install, so
    // printing this inside every church's block would print the same sentence a dozen times.
    fwrite(STDOUT, 'Push configured    : ' . (Pusher::configured() ? 'yes' : 'no')
        . (Pusher::configured() ? '' : ' — ' . (string) Pusher::getLastError()) . "\n");

    $several = count($status) > 1;
    foreach ($status as $tenantId => $entry) {
        if ($entry['ok'] !== true || !is_array($entry['result'])) {
            fwrite(STDERR, 'devotional_worker: status failed for church ' . $tenantId
                . ' — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
            continue;
        }
        $report = $entry['result'];

        if ($several) {
            $church = Tenant::find((int) $tenantId);
            fwrite(STDOUT, "\n" . (string) ($church['name'] ?? ('Church ' . $tenantId)) . "\n");
        }

        fwrite(STDOUT, 'Daily notification : ' . ($report['enabled'] ? 'on' : 'off') . "\n");
        fwrite(STDOUT, 'Sending window     : ' . (string) $report['quiet']
            . ' (now within it: ' . ($report['within'] ? 'yes' : 'no') . ")\n");
        fwrite(STDOUT, 'Devices registered : ' . (int) $report['devices'] . "\n");

        if (!$report['rows']) {
            fwrite(STDOUT, "\nToday: no devotional has been written.\n");
            continue;
        }

        fwrite(STDOUT, "\nToday:\n");
        foreach ($report['rows'] as $row) {
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

// One pass per church, because the switch, the sending window, today's entry and the devices are all per
// church. A single run for the whole install read whichever church resolved first — the default one, from
// a cron — and pushed its devotional to every church's phones.
$runs = Tenant::each(static function (int $tenantId) use ($force, $dryRun): array {
    return DevotionalPush::run($force, $dryRun);
});

$several = count($runs) > 1;
$totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'claimed' => 0, 'pruned' => 0, 'touched' => 0];
$stoppedSomewhere = false;
$failedPasses = 0;

foreach ($runs as $tenantId => $entry) {
    $church = $several ? Tenant::find((int) $tenantId) : null;
    $label = $several ? ((string) ($church['name'] ?? ('Church ' . $tenantId)) . ': ') : '';

    if ($entry['ok'] !== true || !is_array($entry['result'])) {
        // The pass threw. Tenant::each held it so the other churches still ran, so this is where it has
        // to become visible — silently skipping a church is how a daily notification quietly stops.
        $failedPasses++;
        fwrite(STDERR, 'devotional_worker: ' . $label . 'the run failed — '
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

    $totals['sent'] += (int) $summary['sent'];
    $totals['skipped'] += (int) $summary['skipped'];
    $totals['failed'] += (int) $summary['failed'];
    $totals['claimed'] += (int) $summary['claimed'];
    $totals['pruned'] += (int) $summary['pruned'];
    $totals['touched'] += (int) $summary['sent'] + (int) $summary['failed'];
}

if ($totals['claimed'] === 0 && $totals['touched'] === 0) {
    // Only when no church stopped: "nothing to send" next to a reason why nothing was sent would read as
    // a contradiction.
    if (!$stoppedSomewhere) {
        say('Nothing to send.');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($failedPasses > 0 ? 1 : 0);
}

say(sprintf(
    '%s finished in %.1fs: %d notification(s) sent, %d skipped, %d failed%s.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $totals['sent'],
    $totals['skipped'],
    $totals['failed'],
    $totals['pruned'] > 0 ? ', ' . $totals['pruned'] . ' dead token(s) removed' : ''
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and push_sent_at was not changed.');
}

// Failures are worth a non-zero exit so cron mails somebody. A run that had nothing to do is not — and
// neither is a church that is switched off, which is a setting rather than a fault.
flock($lock, LOCK_UN);
fclose($lock);
exit(($failedPasses > 0 || $totals['failed'] > 0) ? 1 : 0);
