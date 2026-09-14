<?php
declare(strict_types=1);

/**
 * The daily advert-performance report one church's publishers receive.
 *
 * Split out of `cli/media_worker.php` the same way `core/SmsRunner.php` is split out of
 * `cli/sms_worker.php`: the worker owns the schedule and the sending, and this owns which publishers
 * belong to the church being served and what each of them is told. Splitting it also makes it testable —
 * the selection can be driven and asserted without sending a single email, which matters because
 * `Mailer` has no seam and a test must never post real mail.
 *
 * Every method here is for **one church**: call it inside a `Tenant::each()` pass (or a `Tenant::runAs()`
 * block), because `setting('site_title')` and the report's own links resolve from the ambient church.
 */
final class PublisherReport
{
    /** The hour of the day from which the report goes out. */
    private const SEND_FROM_HOUR = 8;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    /**
     * Where one church's "already sent today" marker lives.
     *
     * Per church, and that is the point: this used to be a single install-wide file, so the first church
     * to run after 8am wrote it and every other church's publishers got no report at all.
     */
    public static function stampPath(int $tenantId, ?string $day = null): string
    {
        return STORAGE_PATH . '/cache/ad_report_' . $tenantId . '_' . ($day ?? date('Y-m-d')) . '.flag';
    }

    /** True when this church has not had today's report yet and it is late enough to send it. */
    public static function due(int $tenantId, ?string $day = null, ?int $hour = null): bool
    {
        if (is_file(self::stampPath($tenantId, $day))) {
            return false;
        }

        return ($hour ?? (int) date('H')) >= self::SEND_FROM_HOUR;
    }

    /**
     * Marks today's report as done for one church, so a second run the same day sends nothing.
     *
     * Written even when a church has no publishers, which is what the old install-wide stamp did too: a
     * church with nothing to report has nothing to report all day.
     */
    public static function markSent(int $tenantId, ?string $day = null): void
    {
        @file_put_contents(self::stampPath($tenantId, $day), date('c'));
    }

    /**
     * One church's publishers, and the report each of them should be sent.
     *
     * Sends nothing. The caller does that, so that a test can look at the choice without posting mail.
     *
     * @param  int $tenantId the church; callers inside a `Tenant::each()` pass pass the pass's church
     * @return array<int, array{email:string,subject:string,body:string}>
     */
    public static function build(int $tenantId): array
    {
        $published = self::publishers($tenantId);
        $churchName = (string) setting('site_title', '');
        $out = [];

        foreach ($published as $pub) {
            // Scoped by publisher only. The publisher is the scoping authority — an advert's church is
            // stamped from its publisher's — and adding a second filter here would hide an advert whose
            // church is somehow not the publisher's, which is the one case where hiding it would be wrong.
            $ads = self::approvedAds((int) $pub['id']);
            if ($ads === []) {
                continue;
            }

            $adListHtml = '';
            $totalV = 0;
            $totalC = 0;
            foreach ($ads as $pa) {
                $v = (int) $pa['views_count'];
                $c = (int) $pa['clicks_count'];
                $totalV += $v;
                $totalC += $c;
                $ctr = $v > 0 ? round(($c / $v) * 100, 2) : 0.0;
                $adListHtml .= "- \"{$pa['title']}\": {$v} Views, {$c} Clicks (CTR: {$ctr}%)\n";
            }
            $overallCtr = $totalV > 0 ? round(($totalC / $totalV) * 100, 2) : 0.0;
            $managerUrl = baseUrl('ad-manager?token=' . rawurlencode((string) $pub['token']));

            $body = "Hi {$pub['name']},\n\n" .
                'Here is your daily advertisement performance summary for ' . date('F j, Y') . ":\n\n" .
                "Total Views: {$totalV}\n" .
                "Total Clicks: {$totalC}\n" .
                "Average CTR: {$overallCtr}%\n\n" .
                "Campaign Breakdown:\n" .
                $adListHtml . "\n" .
                "View detailed analytics or create new ads in your Publisher Portal:\n" .
                "{$managerUrl}\n\n" .
                "Best regards,\n" . $churchName;

            $out[] = [
                'email' => (string) $pub['email'],
                'subject' => 'Your Daily Advert Performance Report · ' . $churchName,
                'body' => $body,
            ];
        }

        return $out;
    }

    /**
     * The publishers of one church who have at least one approved advert.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishers(int $tenantId): array
    {
        try {
            $stmt = self::db()->prepare(
                'SELECT p.id, p.name, p.email, p.token
                 FROM ad_publishers p
                 JOIN ads a ON a.publisher_id = p.id
                 WHERE a.status = "approved" AND p.tenant_id = ?
                 GROUP BY p.id'
            );
            $stmt->execute([$tenantId]);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('PublisherReport publishers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function approvedAds(int $publisherId): array
    {
        try {
            $stmt = self::db()->prepare('SELECT id, title, views_count, clicks_count, status, start_at, expires_at FROM ads WHERE publisher_id = ? AND status = "approved"');
            $stmt->execute([$publisherId]);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}
