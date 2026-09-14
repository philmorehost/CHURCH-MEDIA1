#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Follow-up worker. Run from cron — hourly is plenty — and it does two things:
 *
 *   1. **Sends whatever email steps have come due** for people in a running sequence.
 *   2. **Puts task steps on somebody's list** — ring them, visit them, introduce them to a cell.
 *
 * A newcomer with no email address still gets the tasks. That is the common case, not an edge case:
 * most visitors leave a phone number and nothing else, so the tasks are the part of a sequence that
 * works for everybody, and the emails are the part that works without anybody remembering.
 *
 * **No sending window**, unlike the SMS features. A text message rings a phone in somebody's bedroom;
 * an email does not, and a follow-up note is not urgent enough to be worth delaying. Hourly cron is
 * safe at any hour.
 *
 * **At most one email per person per run.** A church enrolling last month's visitors backdates the
 * enrolment, which makes every step up to today due at once; without the cap the visitor receives the
 * entire sequence in one minute. See core/FollowUpRunner.php.
 *
 * **One pass per church.** The sequences, the enrolments and the words that get sent all belong to a
 * church, and a cron has no request host to resolve one from — so `Tenant::each()` is what makes this run
 * as each church in turn instead of as whichever one resolves first (the default one). A pass that throws
 * is held so the other churches still run, then turned into a non-zero exit at the end.
 *
 * Usage:
 *   php cli/followup_worker.php               send whatever is due
 *   php cli/followup_worker.php --dry-run     say who would hear from us, write nothing
 *   php cli/followup_worker.php --status      print the state of the pipeline and the cron line
 *   php cli/followup_worker.php --quiet       print nothing on a run with no work
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
    fwrite(STDERR, "followup_worker: the application is not installed; nothing to do.\n");
    exit(1);
}

$statusOnly = false;
$dryRun = false;
$silent = false;
foreach (array_slice($argv, 1) as $option) {
    if ($option === '--status') {
        $statusOnly = true;
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

$pdo = Database::getInstance()->getConnection();

/* --------------------------------------------------------------- status mode */

if ($statusOnly) {
    // `Unit::scopeClause(null, …)` is `1 = 0` — "see nothing" — so passing a null user to these
    // helpers, as this command used to, printed a confident zero for every line that goes through it.
    // A super-admin scope is the "no unit filter" this console view wants; the church is applied
    // separately, per pass, by the two helpers that can take it.
    $consoleUser = array('is_super_admin' => 1);

    // One report per church for everything a church owns. The newcomer pipeline is reported once, below,
    // because `newcomers` carries no church column at all — only a unit — so it cannot honestly be split
    // per church from here, and printing the same install-wide numbers under every church's heading would
    // look like a per-church figure without being one.
    $status = Tenant::each(static function (int $tenantId) use ($pdo, $consoleUser): array {
        $count = static function (string $sql) use ($pdo, $tenantId): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($tenantId));
            return (int) $stmt->fetchColumn();
        };

        $dueNow = FollowUpRunner::targets($pdo);
        $dueEmails = count(array_filter($dueNow, static function (array $r): bool {
            return (string) $r['kind'] === 'email';
        }));

        return array(
            'sequences' => $count('SELECT COUNT(*) FROM follow_up_sequences WHERE is_active = 1 AND tenant_id = ?'),
            'running' => $count("SELECT COUNT(*) FROM follow_up_enrolments e JOIN follow_up_sequences s ON s.id = e.sequence_id WHERE e.status = 'active' AND s.tenant_id = ?"),
            'email' => $dueEmails,
            'tasks' => count($dueNow) - $dueEmails,
            'failures' => count(FollowUpRunner::failures($consoleUser, 500, $tenantId)),
            'unreachable' => count(FollowUp::unreachable($consoleUser, 500, $tenantId)),
        );
    });

    // Installation-wide, and not a per-church fact: `newcomers` has no `tenant_id`.
    $pipeline = FollowUp::pipeline($consoleUser);

    $several = count($status) > 1;
    foreach ($status as $tenantId => $entry) {
        if ($entry['ok'] !== true || !is_array($entry['result'])) {
            fwrite(STDERR, 'followup_worker: status failed for church ' . $tenantId
                . ' — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
            continue;
        }
        $report = $entry['result'];

        if ($several) {
            $church = Tenant::find((int) $tenantId);
            fwrite(STDOUT, "\n" . (string) ($church['name'] ?? ('Church ' . $tenantId)) . "\n");
        }

        fwrite(STDOUT, 'Sequences on   : ' . (int) $report['sequences'] . "\n");
        fwrite(STDOUT, 'People being followed up: ' . (int) $report['running'] . "\n");
        fwrite(STDOUT, 'Mail configured: ' . (Mailer::configured() ? 'yes' : 'no') . "\n");
        fwrite(STDOUT, 'Due right now  : ' . (int) $report['email'] . ' email(s), ' . (int) $report['tasks'] . " task(s)\n");
        fwrite(STDOUT, 'Sends that failed: ' . (int) $report['failures'] . "\n");
        fwrite(STDOUT, 'Enrolled but with no email address: ' . (int) $report['unreachable'] . " (their email steps cannot arrive)\n");
    }

    fwrite(STDOUT, "\nPipeline (install-wide — visitors have no church column): " . $pipeline['total'] . ' visitor(s) — '
        . 'new ' . $pipeline['new']
        . ', contacted ' . $pipeline['contacted']
        . ', followed up ' . $pipeline['followed_up']
        . ', returned ' . $pipeline['returned']
        . ', inactive ' . $pipeline['inactive']
        . ', needing attention ' . $pipeline['stalled'] . "\n");
    fwrite(STDOUT, "\nSuggested cron, every hour:\n  20 * * * * php " . ROOT_PATH . "/cli/followup_worker.php --quiet\n");
    exit(0);
}

/* ------------------------------------------------------------- single runner */

// A lock file so a slow run and the next cron tick never overlap. The per-action unique key already
// makes a double send impossible; this only stops two runs doing the same work twice.
$lockPath = STORAGE_PATH . '/cache/followup_worker.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "followup_worker: could not open the lock file at {$lockPath}.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another worker run is still going; leaving this one to it.');
    exit(0);
}

$startedAt = microtime(true);

// One pass per church, because the sequences and the enrolments belong to a church. A single run for the
// whole install used whichever church resolved first — the default one, from a cron — for the sender
// identity and the sequence lookup alike.
$runs = Tenant::each(static function (int $tenantId) use ($dryRun): array {
    return FollowUpRunner::run($dryRun);
});

$several = count($runs) > 1;
$totals = ['sent' => 0, 'tasks' => 0, 'failed' => 0];
$failedPasses = 0;

foreach ($runs as $tenantId => $entry) {
    $church = $several ? Tenant::find((int) $tenantId) : null;
    $label = $several ? ((string) ($church['name'] ?? ('Church ' . $tenantId)) . ': ') : '';

    if ($entry['ok'] !== true || !is_array($entry['result'])) {
        // The pass threw. Tenant::each held it so the other churches still ran, so this is where it has
        // to become visible — silently skipping a church is how a follow-up quietly stops.
        $failedPasses++;
        fwrite(STDERR, 'followup_worker: ' . $label . 'the run failed — '
            . (string) ($entry['error'] ?? 'unknown error') . "\n");
        continue;
    }

    $summary = $entry['result'];
    $totals['sent'] += (int) $summary['sent'];
    $totals['tasks'] += (int) $summary['tasks'];
    $totals['failed'] += (int) $summary['failed'];
}

if ($totals['sent'] === 0 && $totals['failed'] === 0 && $totals['tasks'] === 0) {
    say('Nothing due right now.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($failedPasses > 0 ? 1 : 0);
}

say(sprintf(
    '%s finished in %.1fs: %d email(s) sent, %d task(s) created, %d failed.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $totals['sent'],
    $totals['tasks'],
    $totals['failed']
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and nothing was written.');
}

if ($totals['failed'] > 0) {
    say('Some messages were refused. They will be retried, and the Follow-up page lists them.');
}

// A pass that threw is worth a non-zero exit even when the other churches sent everything they owed, so
// cron mails somebody rather than the failure living only in the log.
flock($lock, LOCK_UN);
fclose($lock);
exit(($failedPasses > 0 || $totals['failed'] > 0) ? 1 : 0);
