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
 */

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

exit($failed > 0 ? 2 : 0);
