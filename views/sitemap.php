<?php
declare(strict_types=1);

/** Dynamically generated sitemap.xml — built fresh from the DB on every request. */

header('Content-Type: application/xml; charset=utf-8');

$pdo = Database::getInstance()->getConnection();
$urls = [
    ['loc' => baseUrl(), 'priority' => '1.0'],
    ['loc' => baseUrl('feed'), 'priority' => '0.9'],
    ['loc' => baseUrl('events'), 'priority' => '0.8'],
    ['loc' => baseUrl('sermons'), 'priority' => '0.8'],
    ['loc' => baseUrl('live'), 'priority' => '0.6'],
    ['loc' => baseUrl('about'), 'priority' => '0.6'],
    ['loc' => baseUrl('contact'), 'priority' => '0.5'],
    ['loc' => baseUrl('give'), 'priority' => '0.5'],
    ['loc' => baseUrl('prayer'), 'priority' => '0.5'],
];

foreach ($pdo->query('SELECT slug, created_at AS ts FROM events WHERE is_published = 1') as $row) {
    $urls[] = ['loc' => baseUrl('events/' . $row['slug']), 'priority' => '0.6', 'lastmod' => $row['ts']];
}
foreach ($pdo->query('SELECT slug, published_at AS ts FROM sermons WHERE is_published = 1') as $row) {
    $urls[] = ['loc' => baseUrl('sermons/' . $row['slug']), 'priority' => '0.6', 'lastmod' => $row['ts']];
}
foreach ($pdo->query('SELECT slug, updated_at AS ts FROM pages WHERE is_published = 1') as $row) {
    $href = $row['slug'] === 'about' ? 'about' : 'page/' . $row['slug'];
    $urls[] = ['loc' => baseUrl($href), 'priority' => '0.5', 'lastmod' => $row['ts']];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>';
    if (!empty($u['lastmod'])) {
        echo '<lastmod>' . date('c', strtotime($u['lastmod'])) . '</lastmod>';
    }
    echo '<priority>' . $u['priority'] . '</priority></url>' . "\n";
}
echo '</urlset>';
exit;
