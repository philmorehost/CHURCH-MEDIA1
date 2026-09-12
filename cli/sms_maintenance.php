#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SMS housekeeping. Run from cron once a day; see Admin → SMS → Settings for the exact
 * command. Safe to run by hand at any time.
 *
 * Why this exists: the Settings tab has always offered "Keep gateway logs for N days",
 * and until now nothing enforced it. A retention control that does nothing is worse than
 * no control at all, because an admin sets it and stops worrying while the table grows
 * forever. This is the part that keeps that promise.
 *
 * What one run does:
 *   1. Deletes `sms_messages_log` and `sms_wallet_log` rows older than the retention
 *      window. Both grow by one row per API call — every send, and every wallet read.
 *   2. Releases recipient claims abandoned by a worker that died mid-batch, so the next
 *      run picks them up instead of waiting out the ten-minute claim timeout. The worker
 *      already does this for the campaigns it is working on; this is the safety net for
 *      the ones it is not.
 *   3. Reports campaigns whose stored tallies disagree with their recipient rows, and
 *      `--fix-counters` repairs them.
 *
 * What it deliberately does NOT do:
 *   - It never deletes a campaign or a recipient row. Those are the record of who was
 *     texted and what it cost, and "did we tell Ada about the March meeting?" is a
 *     question the church may need answered years later. Recipient rows disappear only
 *     when an admin deletes the campaign itself, which cascades.
 *   - It does not repair a tally mismatch unless asked. A wrong count is a symptom of
 *     something else; silently rewriting it would hide the cause. The report comes first,
 *     the repair second, and only for campaigns that have finished — never one in flight.
 *
 * Usage:
 *   php cli/sms_maintenance.php                 prune, release claims, report
 *   php cli/sms_maintenance.php --dry-run       say what it would do, change nothing
 *   php cli/sms_maintenance.php --fix-counters  also repair mismatched tallies
 *   php cli/sms_maintenance.php --retention=90  override the configured window, in days
 *   php cli/sms_maintenance.php --quiet         print nothing when there is no work
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
    fwrite(STDERR, "sms_maintenance: the application is not installed; nothing to do.\n");
    exit(1);
}

$before = microtime(true);

$dryRun = false;
$fixCounters = false;
$silent = false;
$retentionOverride = null;

foreach (array_slice($argv, 1) as $option) {
    if ($option === '--dry-run') {
        $dryRun = true;
    } elseif ($option === '--fix-counters') {
        $fixCounters = true;
    } elseif ($option === '--quiet') {
        $silent = true;
    } elseif (str_starts_with($option, '--retention=')) {
        $retentionOverride = (int) substr($option, 12);
    }
}

function say(string $line): void
{
    global $silent;
    if (!$silent) {
        fwrite(STDOUT, $line . "\n");
    }
}

// A lock file, matching the worker. PHP releases it on exit, including after a fatal
// error, so a crashed run cannot wedge the next one.
$lockPath = STORAGE_PATH . '/cache/sms_maintenance.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "sms_maintenance: could not open the lock file at {$lockPath}.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another maintenance run is still going; leaving this one to it.');
    exit(0);
}

$pdo = Database::getInstance()->getConnection();

/**
 * The retention window. A configured value of 0 or less would mean "delete everything",
 * which is never what an admin meant, so it falls back to the default instead.
 */
$retentionDays = $retentionOverride ?? (int) setting('sms_log_retention_days', 30);
if ($retentionDays < 1) {
    $retentionDays = 30;
    fwrite(STDERR, "sms_maintenance: the retention setting was not a positive number of days; using {$retentionDays}.\n");
}

$did = [];
$problems = [];

say('SMS housekeeping' . ($dryRun ? ' (dry run — nothing will be changed)' : ''));
say('');
say('  Keeping gateway logs for ' . $retentionDays . ' day(s).');

/* ============================================================== log retention ==
 * The DELETE is bounded by a date, not by a row count, and `created_at` is indexed on
 * both tables, so this stays cheap however large the tables get.
 */
foreach ([
    'sms_messages_log' => 'gateway log',
    'sms_wallet_log' => 'wallet log',
] as $table => $label) {
    try {
        if ($dryRun) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE created_at < (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$retentionDays]);
            $count = (int) $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE created_at < (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$retentionDays]);
            $count = $stmt->rowCount();
        }
    } catch (Throwable $e) {
        $problems[] = 'Could not prune the ' . $label . ': ' . $e->getMessage();
        continue;
    }

    if ($count > 0) {
        $did[] = sprintf('%s %d old row(s) from the %s', $dryRun ? 'Would remove' : 'Removed', $count, $label);
    }
}

/* ============================================================ abandoned claims ==
 * SmsCampaign::releaseStale() returns rows to `pending` when their claim is older than
 * CLAIM_TIMEOUT_MINUTES. Run across every campaign still in flight, this covers the case
 * where the worker that took the claim never came back.
 */
$released = 0;
try {
    foreach (SmsCampaign::active(200) as $campaign) {
        $campaignId = (int) $campaign['id'];
        if ($dryRun) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sms_campaign_recipients
                 WHERE campaign_id = ? AND status = 'pending'
                   AND lock_token IS NOT NULL
                   AND claimed_at < (NOW() - INTERVAL ? MINUTE)"
            );
            $stmt->execute([$campaignId, SmsCampaign::CLAIM_TIMEOUT_MINUTES]);
            $released += (int) $stmt->fetchColumn();
        } else {
            $released += SmsCampaign::releaseStale($campaignId);
        }
    }
} catch (Throwable $e) {
    $problems[] = 'Could not release abandoned claims: ' . $e->getMessage();
}

if ($released > 0) {
    $did[] = sprintf(
        '%s %d abandoned claim(s) back to pending',
        $dryRun ? 'Would release' : 'Released',
        $released
    );
}

/* ============================================================= tally integrity ==
 * A campaign whose stored counters disagree with its recipient rows is a reporting bug,
 * and the report is the point — an admin reading "412 sent" when 500 went out needs to
 * know the number is not to be trusted.
 */
$mismatched = [];
try {
    $campaigns = $pdo->query(
        'SELECT id, title, status, total_recipients, sent_count, failed_count, skipped_count, units_charged
         FROM sms_campaigns ORDER BY id ASC'
    )->fetchAll();

    foreach ($campaigns as $campaign) {
        $counts = SmsCampaign::statusCounts((int) $campaign['id']);
        $actualTotal = array_sum($counts);
        $actualSent = (int) ($counts['sent'] ?? 0);
        $actualFailed = (int) ($counts['failed'] ?? 0);
        $actualSkipped = (int) ($counts['skipped'] ?? 0);
        $actualUnits = (int) $pdo->query(
            'SELECT COALESCE(SUM(units), 0) FROM sms_campaign_recipients WHERE campaign_id = '
            . (int) $campaign['id'] . " AND status = 'sent'"
        )->fetchColumn();

        if ($actualTotal !== (int) $campaign['total_recipients']
            || $actualSent !== (int) $campaign['sent_count']
            || $actualFailed !== (int) $campaign['failed_count']
            || $actualSkipped !== (int) $campaign['skipped_count']
            || $actualUnits !== (int) $campaign['units_charged']
        ) {
            $mismatched[] = [
                'id' => (int) $campaign['id'],
                'title' => (string) $campaign['title'],
                'status' => (string) $campaign['status'],
                'stored' => sprintf(
                    'total=%d sent=%d failed=%d skipped=%d units=%d',
                    $campaign['total_recipients'],
                    $campaign['sent_count'],
                    $campaign['failed_count'],
                    $campaign['skipped_count'],
                    $campaign['units_charged']
                ),
                'actual' => sprintf(
                    'total=%d sent=%d failed=%d skipped=%d units=%d',
                    $actualTotal,
                    $actualSent,
                    $actualFailed,
                    $actualSkipped,
                    $actualUnits
                ),
            ];
        }
    }
} catch (Throwable $e) {
    $problems[] = 'Could not check campaign tallies: ' . $e->getMessage();
}

if ($mismatched !== []) {
    say('');
    say('  Campaigns whose counters disagree with their recipient rows:');
    foreach ($mismatched as $row) {
        say(sprintf('    #%d %s [%s]', $row['id'], $row['title'], $row['status']));
        say('      stored: ' . $row['stored']);
        say('      actual: ' . $row['actual']);
    }

    $fixable = array_filter($mismatched, static fn(array $row): bool => !in_array($row['status'], SmsCampaign::ACTIVE_STATUSES, true));

    if ($fixCounters && !$dryRun) {
        $fixed = 0;
        foreach ($fixable as $row) {
            SmsCampaign::refreshCounters($row['id']);
            $fixed++;
        }
        $did[] = 'Recalculated the counters on ' . $fixed . ' finished campaign(s)';
        if (count($mismatched) > $fixed) {
            $problems[] = (count($mismatched) - $fixed) . ' campaign(s) still in flight were left alone — their counters move as they send.';
        }
    } else {
        say('');
        say('  Nothing was rewritten. If these are wrong, run again with --fix-counters;');
        say('  that only touches campaigns that have finished, never one still sending.');
    }
}

/* ==================================================================== report == */

if ($did === []) {
    say('');
    say('  Nothing needed doing.');
} else {
    say('');
    foreach ($did as $line) {
        say('  • ' . $line);
    }
}

if ($problems !== []) {
    say('');
    foreach ($problems as $line) {
        fwrite(STDERR, 'sms_maintenance: ' . $line . "\n");
    }
}

say('');
say(sprintf('  Finished in %.1fs.', microtime(true) - $before));

flock($lock, LOCK_UN);
fclose($lock);

// A problem is worth surfacing to cron's mail, so it is a non-zero exit — but only after
// the work above has been attempted, never before it.
exit($problems === [] ? 0 : 1);
