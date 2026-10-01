<?php
declare(strict_types=1);

/** Dynamically generated sitemap.xml — built fresh from the DB on every request. */

header('Content-Type: application/xml; charset=utf-8');

// The page list itself now lives in core/SiteUrls.php. The traffic report needs the same list to be able
// to name the pages that had no visitors at all, and two copies of it would drift apart silently — the
// sitemap would advertise a page the report never mentions.
$urls = SiteUrls::pages();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $page) {
    echo '  <url><loc>' . htmlspecialchars(baseUrl(ltrim($page['path'], '/')), ENT_XML1) . '</loc>';
    if (!empty($page['lastmod'])) {
        echo '<lastmod>' . date('c', (int) strtotime((string) $page['lastmod'])) . '</lastmod>';
    }
    echo '<priority>' . $page['priority'] . '</priority></url>' . "\n";
}
echo '</urlset>';
exit;
