<?php
declare(strict_types=1);

/**
 * Analytics roll-up worker.
 *
 * Run nightly from cron, e.g.:
 *   15 1 * * * /usr/bin/php /home/USER/public_html/cli/analytics_rollup.php >> /home/USER/public_html/storage/logs/analytics.log 2>&1
 *
 * What it does, all idempotent so it is safe to re-run or to miss a night:
 *   1. Rolls yesterday (and any other recent day with no roll-up) into
 *      `analytics_daily`.
 *   2. Prunes raw events past the retention window. The roll-ups are kept.
 *
 * Usage: php cli/analytics_rollup.php [--days=7] [--keep=180] [--no-prune]
 *
 * **Deliberately NOT one pass per church.** `Analytics::rollup()` computes `analytics_daily` grouped by
 * `tenant_id` in a single statement, so one pass already keeps the churches apart and N passes would do
 * the same work N times over. This was checked when the other workers were converted (7d-ii part 7): two
 * churches with three and five events of the same kind produced two separate rows with those exact
 * counts, and nothing was filed as unattributed. Wrapping this in `Tenant::each()` would be a
 * regression, not a fix.
 *
 * The retention window is an installation-wide policy over a shared, derived table, not a per-church
 * setting — see the note on `backup.php`.
 */

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

$options = getopt('', ['days::', 'keep::', 'no-prune']);
$days = isset($options['days']) ? max(1, min(90, (int) $options['days'])) : 7;
$keep = isset($options['keep']) ? max(7, (int) $options['keep']) : null;
$noPrune = array_key_exists('no-prune', $options);

$stamp = static fn (): string => date('Y-m-d H:i:s');
echo '[' . $stamp() . "] analytics roll-up starting\n";

try {
    $rolled = Analytics::rollupMissingDays($days);
    echo '[' . $stamp() . "] rolled up {$rolled} day(s) that had no aggregate yet\n";

    // Always refresh yesterday, so a late-evening traffic spike is accounted for.
    $yesterday = date('Y-m-d', (int) strtotime('-1 day'));
    Analytics::rollup($yesterday);
    echo '[' . $stamp() . "] refreshed {$yesterday}\n";

    if ($noPrune) {
        echo '[' . $stamp() . "] pruning skipped (--no-prune)\n";
    } else {
        $removed = Analytics::prune($keep);
        echo '[' . $stamp() . "] pruned {$removed} raw event(s) past the retention window\n";
    }
} catch (Throwable $e) {
    echo '[' . $stamp() . '] FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

echo '[' . $stamp() . "] done\n";
exit(0);
