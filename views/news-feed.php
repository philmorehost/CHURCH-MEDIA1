<?php
declare(strict_types=1);

/**
 * GET /news.xml — the news feed.
 *
 * Emitted straight to the output rather than through `render()`, for the same reason as `/podcast.xml`:
 * the reader is a machine — a feed reader, a phone's news widget, an aggregator — and wrapping the XML in
 * the site's HTML layout would make the document invalid and every one of them would refuse it.
 *
 * Cached for fifteen minutes and honours a conditional request, so a reader that polls on its own
 * schedule — some of them hourly — gets a 304 and no body when nothing has changed.
 */

$site = trim((string) setting('site_title'));
if ($site === '') {
    $site = 'News';
}

$feedUrl = baseUrl('news.xml');

/**
 * Escapes a value for XML text or an attribute.
 *
 * A closure rather than a named function at the bottom of the file, which is the shape `podcast.php` uses:
 * this file is `require`d from inside a route handler, and a named function here would be declared in the
 * global scope on every request — one redeclare away from a fatal error, and named `x` on top of that.
 *
 * `ENT_XML1` rather than HTML's entity table, because this document is not HTML, and `ENT_QUOTES` because
 * half these values land in attributes. It is what stops a story title containing `&` or `<` from
 * producing a feed that every reader on the internet rejects.
 */
$x = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

// Thirty is the limit every reader in use will display and far more than a church publishes in a
// fortnight; the whole list would be a document nobody needs to download.
$posts = News::published(null, 30, 0, '');

// `lastBuildDate` is when the *content* last changed, not when this request happened.
//
// It was `date('r')` and that made the conditional request at the bottom of this file useless: the
// document — and therefore its ETag — differed on every single request, so a reader that already had
// the feed downloaded it again every time it polled. The newest story's date is the honest answer, and
// it is stable between polls; an empty feed claims no date rather than claiming to be new right now.
$newest = 0;
foreach ($posts as $candidate) {
    $stamp = (int) strtotime((string) ($candidate['published_at'] ?: $candidate['created_at']));
    if ($stamp > $newest) {
        $newest = $stamp;
    }
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
$xml .= '  <channel>' . "\n";
$xml .= '    <title>' . $x($site . ' — News') . '</title>' . "\n";
$xml .= '    <link>' . $x(baseUrl('news')) . '</link>' . "\n";
$xml .= '    <description>' . $x((string) (setting('site_tagline') ?: $site)) . '</description>' . "\n";
$xml .= '    <language>' . $x(Lang::current()) . '</language>' . "\n";
if ($newest > 0) {
    $xml .= '    <lastBuildDate>' . $x(date('r', $newest)) . '</lastBuildDate>' . "\n";
}
$xml .= '    <generator>Church Media</generator>' . "\n";
// The self link: how an aggregator that was handed this document knows where to poll it again.
$xml .= '    <atom:link href="' . $x($feedUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";

foreach ($posts as $post) {
    $link = baseUrl('news/' . rawurlencode((string) $post['slug']));
    $published = (string) ($post['published_at'] ?: $post['created_at']);

    $xml .= '    <item>' . "\n";
    $xml .= '      <title>' . $x((string) $post['title']) . '</title>' . "\n";
    $xml .= '      <link>' . $x($link) . '</link>' . "\n";
    $xml .= '      <guid isPermaLink="true">' . $x($link) . '</guid>' . "\n";
    $xml .= '      <pubDate>' . $x(date('r', strtotime($published))) . '</pubDate>' . "\n";
    $xml .= '      <description>' . $x(trim((string) $post['excerpt'])) . '</description>' . "\n";

    if (!empty($post['category_name'])) {
        $xml .= '      <category>' . $x((string) $post['category_name']) . '</category>' . "\n";
    }

    // An enclosure has to carry the real byte count or readers refuse it, and the file is on this
    // server, so it is measured rather than guessed. Nothing is emitted when the file is missing.
    $featured = (string) ($post['featured_path'] ?? '');
    if ($featured !== '') {
        $absolute = UPLOADS_PATH . '/' . $featured;
        if (is_file($absolute)) {
            $xml .= '      <enclosure url="' . $x((string) uploadUrl($featured)) . '"'
                . ' length="' . (int) filesize($absolute) . '" type="image/webp"/>' . "\n";
        }
    }

    $xml .= '    </item>' . "\n";
}

$xml .= '  </channel>' . "\n";
$xml .= '</rss>' . "\n";

$etag = '"' . substr(sha1($xml), 0, 32) . '"';

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');

$incoming = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($incoming !== '' && $incoming === $etag) {
    http_response_code(304);
    exit;
}

echo $xml;
exit;
