#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Writes a database backup (plus a media manifest) into storage/backups and prunes
 * old copies. Intended to run on a cron schedule — see Admin → Backups for the exact
 * cPanel command — but safe to run by hand at any time.
 *
 * Safe to run repeatedly: each run writes a new timestamped file, and an interrupted
 * run leaves a .part file that is ignored and eventually cleaned up.
 *
 * Usage:
 *   php cli/backup.php              write a backup, then prune
 *   php cli/backup.php --no-media   skip the media manifest (faster)
 *   php cli/backup.php --no-prune   keep every backup this run
 *   php cli/backup.php --list       list existing backups and exit
 *   php cli/backup.php --prune-only just apply the retention setting
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
    fwrite(STDERR, "backup: the application is not installed; nothing to do.\n");
    exit(1);
}

$options = array_slice($argv, 1);
$listOnly = in_array('--list', $options, true);
$pruneOnly = in_array('--prune-only', $options, true);
$includeMedia = !in_array('--no-media', $options, true);
$skipPrune = in_array('--no-prune', $options, true);

if ($listOnly) {
    $backups = Backup::all();
    if (!$backups) {
        fwrite(STDOUT, "No backups yet in " . Backup::dir() . "\n");
        exit(0);
    }
    foreach ($backups as $backup) {
        fwrite(STDOUT, sprintf(
            "%-34s %10s  %s\n",
            $backup['name'],
            $backup['readable_size'],
            date('Y-m-d H:i', $backup['modified'])
        ));
    }
    exit(0);
}

if ($pruneOnly) {
    $removed = Backup::prune();
    fwrite(STDOUT, $removed === 0
        ? "Nothing to prune.\n"
        : "Pruned {$removed} file(s).\n");
    exit(0);
}

if (!Backup::writable()) {
    fwrite(STDERR, "backup: storage/backups is not writable by the user running this script.\n");
    exit(1);
}

set_time_limit(900);

$started = microtime(true);
$result = Backup::run($includeMedia);
$elapsed = round(microtime(true) - $started, 1);

if (!$result['ok']) {
    fwrite(STDERR, 'backup: ' . ($result['error'] ?? 'failed') . "\n");
    exit(1);
}

foreach ($result['warnings'] ?? [] as $warning) {
    fwrite(STDERR, "backup: warning: {$warning}\n");
}

$pruned = 0;
if (!$skipPrune) {
    $pruned = Backup::prune();
}

fwrite(STDOUT, sprintf(
    "Backup written: %s (%s, via %s, %ss)%s\n",
    $result['name'],
    Backup::humanSize((int) $result['bytes']),
    $result['method'],
    $elapsed,
    $pruned > 0 ? ", pruned {$pruned} old file(s)" : ''
));
exit(0);
