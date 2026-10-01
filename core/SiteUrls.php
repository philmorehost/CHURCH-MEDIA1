<?php
declare(strict_types=1);

/**
 * Every public page of this church's site, in one place.
 *
 * Two callers need this list and they must agree:
 *
 *  - `views/sitemap.php`, which tells a crawler what exists, and
 *  - the traffic report (`admin/analytics.php`), which has to name the pages that had **no** visitors as
 *    well as the ones that had. A table built only from recorded hits can never show the page nobody is
 *    finding — which is usually the page a church most needs to know about.
 *
 * Two copies of this list would drift, and the drift would be silent: the sitemap would advertise a page
 * the report never mentions, and every later change would have to be made in both places or be wrong in
 * one of them.
 *
 * Paths are **absolute site paths** (`/` for the home page) because that is the form the analytics beacon
 * records, and therefore the form the report has to match. The sitemap turns them back into absolute URLs
 * with `baseUrl()`.
 *
 * The content queries are deliberately the ones the sitemap already used, tenant scope included — news
 * goes through `core/News.php` rather than a raw query for the reason recorded there: news carries a
 * `tenant_id`, and a bare `SELECT slug FROM news_posts` would list one church's stories in another
 * church's sitemap (and now, in another church's traffic report) — advertising a page that church's
 * visitor can never reach.
 */
final class SiteUrls
{
    /** Fetched lazily so a report and a sitemap in the same request do not each re-query. */
    private static ?array $cache = null;

    /**
     * The site's pages, with a human label for each (used by the traffic report, ignored by the sitemap).
     *
     * @return array<int, array{path:string,label:string,priority:string,lastmod:?string}>
     */
    public static function pages(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $pages = [
            ['path' => '/', 'label' => 'Home', 'priority' => '1.0', 'lastmod' => null],
            ['path' => '/feed', 'label' => 'Feed & reels', 'priority' => '0.9', 'lastmod' => null],
            ['path' => '/events', 'label' => 'Events', 'priority' => '0.8', 'lastmod' => null],
            ['path' => '/sermons', 'label' => 'Sermons', 'priority' => '0.8', 'lastmod' => null],
            ['path' => '/live', 'label' => 'Watch live', 'priority' => '0.6', 'lastmod' => null],
            ['path' => '/about', 'label' => 'About', 'priority' => '0.6', 'lastmod' => null],
            ['path' => '/contact', 'label' => 'Contact', 'priority' => '0.5', 'lastmod' => null],
            ['path' => '/give', 'label' => 'Giving', 'priority' => '0.5', 'lastmod' => null],
            ['path' => '/prayer', 'label' => 'Prayer wall', 'priority' => '0.5', 'lastmod' => null],
        ];

        try {
            $pdo = Database::getInstance()->getConnection();

            foreach ($pdo->query('SELECT slug, title, created_at AS ts FROM events WHERE is_published = 1') as $row) {
                $pages[] = self::contentPage('/events/' . $row['slug'], $row['title'], '0.6', $row['ts']);
            }
            foreach ($pdo->query('SELECT slug, title, published_at AS ts FROM sermons WHERE is_published = 1') as $row) {
                $pages[] = self::contentPage('/sermons/' . $row['slug'], $row['title'], '0.6', $row['ts']);
            }
            foreach ($pdo->query('SELECT slug, title, updated_at AS ts FROM pages WHERE is_published = 1') as $row) {
                $slug = (string) $row['slug'];
                $pages[] = self::contentPage($slug === 'about' ? '/about' : '/page/' . $slug, $row['title'], '0.5', $row['ts']);
            }

            $pages[] = ['path' => '/news', 'label' => 'News', 'priority' => '0.8', 'lastmod' => null];
            foreach (News::categories(false) as $category) {
                $pages[] = self::contentPage(
                    '/news/category/' . (string) $category['slug'],
                    'News: ' . (string) $category['name'],
                    '0.4',
                    null
                );
            }
            foreach (News::published(null, 500, 0, '') as $story) {
                $pages[] = self::contentPage(
                    '/news/' . (string) $story['slug'],
                    (string) $story['title'],
                    '0.7',
                    (string) ($story['updated_at'] ?: $story['published_at'])
                );
            }
        } catch (Throwable $e) {
            // A content table that cannot be read must not take the sitemap down or blank the report: the
            // fixed pages above are still true, and a short sitemap beats a 500 for a crawler.
            error_log('SiteUrls: content list unavailable — ' . $e->getMessage());
        }

        return self::$cache = $pages;
    }

    /** One content page, with the label falling back to its own path when the row has no title. */
    private static function contentPage(string $path, $title, string $priority, $lastmod): array
    {
        $label = trim((string) $title);
        return [
            'path' => $path,
            'label' => $label !== '' ? $label : $path,
            'priority' => $priority,
            'lastmod' => $lastmod !== null && $lastmod !== '' ? (string) $lastmod : null,
        ];
    }
}
