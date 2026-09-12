<?php
declare(strict_types=1);

/**
 * GET /podcast.xml — the podcast feed.
 *
 * Emitted straight to the output rather than through render(), because the audience is a
 * machine: an RSS reader, Spotify, Apple Podcasts. The site's HTML layout around it would
 * make the document invalid and every one of them would refuse it.
 *
 * Cached for fifteen minutes. Podcast apps poll on their own schedule — some of them every
 * hour — and there is nothing here they need to the second. A conditional request is honoured
 * so a client that already has this version gets a 304 and no body.
 */

$scopeUnitIds = [];
$site = setting('site_title');
if ($site === null) {
    $site = 'Sermons';
}

$feed = PodcastFeed::build($scopeUnitIds);
$xml = $feed['xml'];

$etag = '"' . substr(sha1($xml), 0, 32) . '"';
$lastModified = gmdate('D, d M Y H:i:s', time()) . ' GMT';

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('X-Feed-Items: ' . $feed['items']);

// A client that already has this exact document should not download it again.
$incoming = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($incoming !== '' && $incoming === $etag) {
    http_response_code(304);
    exit;
}

echo $xml;
exit;
