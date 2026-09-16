#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background video-conversion worker. Converts every uploaded video that is
 * still stored under originals/ into a vertical 9:16 reel. Intended to run on
 * a cron schedule (see Admin → Settings → Video Conversion for the exact
 * cPanel command). Safe to run repeatedly: only originals are picked up, and
 * items whose source file is missing are marked 'failed' and left for the
 * admin to inspect.
 *
 * It also sends the daily advert-performance report to publishers, and that half runs **one pass per
 * church**: the report carries a church's name and links to that church's site, and it used to be sent
 * under whichever church happened to resolve — the default one, from a cron. Its once-a-day marker was a
 * single install-wide file too, so the first church to run after 8am suppressed every other church's
 * report for the rest of the day.
 *
 * The video conversion is deliberately *not* per church. It is file work with no church of its own: the
 * only setting it reads is `ffmpeg_path`, which is a platform binary path, and `media_post_items` has no
 * church column. Running it once per church would re-query and re-skip the same rows N times.
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
    fwrite(STDERR, "media_worker: application is not installed; nothing to do.\n");
    exit(1);
}

$pdo = Database::getInstance()->getConnection();
set_time_limit(600);

$stmt = $pdo->query("SELECT id FROM media_post_items WHERE type = 'video' AND source = 'upload' AND file_path LIKE 'originals/%' ORDER BY id ASC");
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$converted = 0;
$failed = 0;
$skipped = 0;
$deadline = time() + 240; // cap each run at ~4 minutes so cron runs never overlap

foreach ($ids as $id) {
    if (time() >= $deadline) {
        break;
    }
    $status = MediaProcessor::convertOriginalVideo($pdo, (int) $id);
    if ($status === 'ready') {
        $converted++;
    } elseif ($status === 'failed') {
        $failed++;
    } else {
        $skipped++;
    }
}

$stillQueued = count($ids) - $converted - $failed - $skipped;

// Send the Daily Ad Performance Email to publishers — one pass per church, once per church per day.
$reportRuns = Tenant::each(static function (int $tenantId): array {
    if (!PublisherReport::due($tenantId)) {
        return ['due' => 0, 'publishers' => 0, 'sent' => 0];
    }

    // Marked before anything is sent, the same order the install-wide stamp used: a failure part-way
    // through must not queue the whole church's report up again every time cron fires.
    PublisherReport::markSent($tenantId);

    $sent = 0;
    $reports = PublisherReport::build($tenantId);
    foreach ($reports as $report) {
        try {
            // Counted on what the mailer reports rather than on the attempt: "N sent" in the log has to
            // mean the mail went somewhere, or a dead SMTP configuration reads as a healthy run.
            if (Mailer::send($report['email'], $report['subject'], $report['body'])) {
                $sent++;
            }
        } catch (Throwable $e) {
            // One publisher's mail failing must not stop the rest of the church's.
        }
    }

    return ['due' => 1, 'publishers' => count($reports), 'sent' => $sent];
});

$reportDue = 0;
$reportSent = 0;
foreach ($reportRuns as $tenantId => $entry) {
    if ($entry['ok'] === true && is_array($entry['result'])) {
        $reportDue += (int) $entry['result']['due'];
        $reportSent += (int) $entry['result']['sent'];
        continue;
    }

    // A pass that threw is worth saying out loud: silently skipping a church is how a daily report stops
    // arriving without anybody noticing.
    fwrite(STDERR, 'media_worker: the report pass for church ' . $tenantId . ' failed — '
        . (string) ($entry['error'] ?? 'unknown error') . "\n");
}

$line = sprintf(
    "[%s] media_worker: %d converted, %d failed, %d skipped, %d still queued\n",
    date('Y-m-d H:i:s'),
    $converted,
    $failed,
    $skipped,
    $stillQueued
);

fwrite(STDOUT, $line);
$logDir = STORAGE_PATH . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
@file_put_contents($logDir . '/media_worker.log', $line, FILE_APPEND);

// Only mentioned when it happened, so the usual minute-by-minute line is unchanged.
if ($reportDue > 0) {
    $reportLine = sprintf(
        "[%s] media_worker: %d church(es) had a report due, %d sent\n",
        date('Y-m-d H:i:s'),
        $reportDue,
        $reportSent
    );
    fwrite(STDOUT, $reportLine);
    @file_put_contents($logDir . '/media_worker.log', $reportLine, FILE_APPEND);
}

exit($failed > 0 ? 2 : 0);
