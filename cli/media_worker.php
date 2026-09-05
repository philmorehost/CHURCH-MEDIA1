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

// Send Daily Ad Performance Email to Publishers (Runs once per day at 8 AM)
$todayStampFile = STORAGE_PATH . '/cache/daily_ad_stats_' . date('Y-m-d') . '.flag';
if (!is_file($todayStampFile) && (int) date('H') >= 8) {
    @file_put_contents($todayStampFile, date('c'));
    $publishersStmt = $pdo->query('SELECT p.id, p.name, p.email, p.token FROM ad_publishers p JOIN ads a ON a.publisher_id = p.id WHERE a.status = "approved" GROUP BY p.id');
    $publishers = $publishersStmt->fetchAll();

    foreach ($publishers as $pub) {
        $adsStmt = $pdo->prepare('SELECT id, title, views_count, clicks_count, status, start_at, expires_at FROM ads WHERE publisher_id = ? AND status = "approved"');
        $adsStmt->execute([(int) $pub['id']]);
        $pubAds = $adsStmt->fetchAll();

        if ($pubAds) {
            $adListHtml = "";
            $totalV = 0;
            $totalC = 0;
            foreach ($pubAds as $pa) {
                $v = (int) $pa['views_count'];
                $c = (int) $pa['clicks_count'];
                $totalV += $v;
                $totalC += $c;
                $ctr = $v > 0 ? round(($c / $v) * 100, 2) : 0.0;
                $adListHtml .= "- \"{$pa['title']}\": {$v} Views, {$c} Clicks (CTR: {$ctr}%)\n";
            }
            $overallCtr = $totalV > 0 ? round(($totalC / $totalV) * 100, 2) : 0.0;
            $managerUrl = baseUrl('ad-manager?token=' . rawurlencode($pub['token']));

            $body = "Hi {$pub['name']},\n\n" .
                "Here is your daily advertisement performance summary for " . date('F j, Y') . ":\n\n" .
                "Total Views: {$totalV}\n" .
                "Total Clicks: {$totalC}\n" .
                "Average CTR: {$overallCtr}%\n\n" .
                "Campaign Breakdown:\n" .
                $adListHtml . "\n" .
                "View detailed analytics or create new ads in your Publisher Portal:\n" .
                "{$managerUrl}\n\n" .
                "Best regards,\n" . setting('site_title');

            try {
                Mailer::send($pub['email'], 'Your Daily Advert Performance Report · ' . setting('site_title'), $body);
            } catch (Throwable $e) {}
        }
    }
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

exit($failed > 0 ? 2 : 0);
