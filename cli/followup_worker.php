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
    $sequences = $pdo->query('SELECT COUNT(*) FROM follow_up_sequences WHERE is_active = 1')->fetchColumn();
    $running = $pdo->query("SELECT COUNT(*) FROM follow_up_enrolments WHERE status = 'active'")->fetchColumn();
    $pipeline = FollowUp::pipeline(null);
    $dueNow = FollowUpRunner::targets($pdo);
    $dueEmails = count(array_filter($dueNow, static function (array $r): bool {
        return (string) $r['kind'] === 'email';
    }));
    $dueTasks = count($dueNow) - $dueEmails;
    $failures = count(FollowUpRunner::failures(null, 500));
    $unreachable = count(FollowUp::unreachable(null, 500));

    fwrite(STDOUT, 'Sequences on   : ' . (int) $sequences . "\n");
    fwrite(STDOUT, 'People being followed up: ' . (int) $running . "\n");
    fwrite(STDOUT, 'Mail configured: ' . (Mailer::configured() ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, 'Due right now  : ' . $dueEmails . ' email(s), ' . $dueTasks . " task(s)\n");
    fwrite(STDOUT, 'Sends that failed: ' . $failures . "\n");
    fwrite(STDOUT, 'Enrolled but with no email address: ' . $unreachable . " (their email steps cannot arrive)\n");
    fwrite(STDOUT, "\nPipeline: " . $pipeline['total'] . ' visitor(s) — '
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
$summary = FollowUpRunner::run($dryRun);

if ($summary['sent'] === 0 && $summary['failed'] === 0 && $summary['tasks'] === 0) {
    say('Nothing due right now.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

say(sprintf(
    '%s finished in %.1fs: %d email(s) sent, %d task(s) created, %d failed.',
    $dryRun ? 'Dry run' : 'Run',
    microtime(true) - $startedAt,
    $summary['sent'],
    $summary['tasks'],
    $summary['failed']
));

if ($dryRun) {
    say('This was a dry run: nothing was sent and nothing was written.');
}

if ($summary['failed'] > 0) {
    say('Some messages were refused. They will be retried, and the Follow-up page lists them.');
}

flock($lock, LOCK_UN);
fclose($lock);
exit($summary['failed'] > 0 ? 1 : 0);
