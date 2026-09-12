<?php
declare(strict_types=1);

/**
 * The podcast feed.
 *
 * A podcast is just an RSS 2.0 document with some extra iTunes tags, which means the sermon
 * catalogue becomes one more way for people to hear it: subscribe in Spotify or Apple
 * Podcasts once and every new sermon arrives on its own, without anyone remembering to visit
 * the website.
 *
 * Three things here are easy to get wrong and are the reason this is a class rather than a
 * view full of `<item>` tags:
 *
 *   1. **Artwork must be JPEG or PNG.** Every image this system stores goes through
 *      `MediaProcessor::processImage()`, which writes WebP — and Apple and Spotify reject
 *      WebP artwork outright. A feed pointing at a `.webp` file fails submission with a
 *      message about the image, which reads like a problem with the picture rather than with
 *      its format. `artworkUrl()` converts once, caches the result, and returns null rather
 *      than a URL it knows will be refused.
 *
 *   2. **`<enclosure>` needs the byte length.** The validator wants it, and so do some
 *      clients for progress bars. For an uploaded file we know it exactly. For audio hosted
 *      elsewhere we cannot know it without fetching it, so it is reported as 0 — allowed, and
 *      far better than a made-up number.
 *
 *   3. **`<guid>` must never change.** Podcast clients use it to decide what is new. Change it
 *      for an episode people already have and they are sent it again. It is derived from the
 *      sermon id, not from the URL, so moving the site to a new domain does not resend the
 *      whole back catalogue.
 *
 * The feed is built as a string so it can be asserted on directly in tests, without a
 * web server in the way.
 */
final class PodcastFeed
{
    /** Directories feeds larger than this are slow to parse and some clients give up. */
    public const MAX_ITEMS = 300;

    /** Apple requires at least 1400x1400 and will not accept anything smaller. */
    public const ARTWORK_MIN = 1400;

    /* --------------------------------------------------------------- durations */

    /**
     * Reads a duration the way a person would write one: `12:34`, `1:02:03`, `45m`,
     * `90` (seconds), or anything `strtotime`-ish is deliberately NOT accepted.
     *
     * Returns null for anything unparseable, because a wrong duration is worse than none —
     * podcast apps show it to decide whether to download now or later.
     */
    public static function parseDuration(?string $input): ?int
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        // h:mm:ss or mm:ss
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2})$/', $input, $m)) {
            $hours = (int) ($m[1] ?? 0);
            $minutes = (int) $m[2];
            $seconds = (int) $m[3];
            if ($minutes > 59 || $seconds > 59) {
                return null;
            }
            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }

        // 45m, 45min, 45 minutes
        if (preg_match('/^(\d+)\s*(?:m|min|mins|minutes?)$/i', $input, $m)) {
            return ((int) $m[1]) * 60;
        }

        // 2h, 2 hours
        if (preg_match('/^(\d+)\s*(?:h|hr|hrs|hours?)$/i', $input, $m)) {
            return ((int) $m[1]) * 3600;
        }

        // Plain seconds.
        if (preg_match('/^\d+$/', $input)) {
            return (int) $input;
        }

        return null;
    }

    /** A duration as `itunes:duration` wants it: `12:34`, or `1:02:03` past the hour. */
    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainder)
            : sprintf('%d:%02d', $minutes, $remainder);
    }

    /** A duration written the way a person reads it: `12 min`, `1 h 04 min`. */
    public static function humanDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return $hours . ' h ' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ' min';
        }
        return max(1, $minutes) . ' min';
    }

    /* ---------------------------------------------------------------- artwork */

    /**
     * A JPEG copy of a stored image, suitable for `itunes:image`.
     *
     * Converts once and caches beside the uploads. Returns null when there is no usable
     * image, so the caller emits no `<itunes:image>` at all rather than a WebP URL that
     * Apple and Spotify will refuse.
     *
     * Square is required by the directories. The largest centred square is taken rather than
     * stretching, and nothing is ever upscaled — inventing pixels to reach 1400 would look
     * worse than a smaller honest image, and the admin screen says so instead.
     */
    public static function artworkUrl(?string $storedPath): ?string
    {
        $storedPath = trim((string) $storedPath);
        if ($storedPath === '' || str_starts_with($storedPath, 'http://') || str_starts_with($storedPath, 'https://')) {
            return null;
        }

        $source = UPLOADS_PATH . '/' . ltrim($storedPath, '/');
        if (!is_file($source)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            // Already something the directories accept.
            return uploadUrl($storedPath);
        }

        $directory = UPLOADS_PATH . '/podcast';
        $target = $directory . '/' . substr(sha1($storedPath), 0, 20) . '.jpg';

        if (is_file($target)) {
            return uploadUrl('podcast/' . basename($target));
        }

        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $raw = @file_get_contents($source);
        if ($raw === false) {
            return null;
        }
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $side = min($width, $height);
            $square = imagecreatetruecolor($side, $side);

            // Flatten onto white: podcast artwork has no alpha channel, and a transparent PNG
            // converted carelessly comes out with black corners.
            imagefilledrectangle($square, 0, 0, $side, $side, imagecolorallocate($square, 255, 255, 255));
            imagecopy(
                $square,
                $image,
                0,
                0,
                (int) (($width - $side) / 2),
                (int) (($height - $side) / 2),
                $side,
                $side
            );

            // Never upscale, and keep the file small enough to fetch quickly.
            $out = $side > self::ARTWORK_MIN
                ? self::resize($square, self::ARTWORK_MIN)
                : $square;

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                // No artwork rather than the WebP URL. Apple and Spotify refuse WebP, so handing
                // them one trades "this episode has no cover" for "this feed is invalid" — and a
                // feed that will not load is worse than one missing a picture. This is also what
                // happens on a host where uploads/ is not writable, which is common enough on
                // shared hosting that the degradation needs to be the safe one.
                return null;
            }

            $ok = imagejpeg($out, $target, 88);

            if ($out !== $square) {
                imagedestroy($out);
            }
            imagedestroy($square);

            return $ok ? uploadUrl('podcast/' . basename($target)) : null;
        } finally {
            imagedestroy($image);
        }
    }

    private static function resize(GdImage $image, int $side): GdImage
    {
        $out = imagecreatetruecolor($side, $side);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $side, $side, imagesx($image), imagesy($image));
        return $out;
    }

    /** Whether a stored image would be accepted by the podcast directories. */
    public static function artworkProblem(?string $storedPath): ?string
    {
        $storedPath = trim((string) $storedPath);
        if ($storedPath === '') {
            return 'No cover image. Apple Podcasts requires one — square, and at least 1400×1400.';
        }

        $source = UPLOADS_PATH . '/' . ltrim($storedPath, '/');
        if (!is_file($source)) {
            return 'The cover image file is missing from disk. Upload it again.';
        }

        $size = @getimagesize($source);
        if ($size === false) {
            return 'The cover image could not be read.';
        }

        if ($size[0] < self::ARTWORK_MIN || $size[1] < self::ARTWORK_MIN) {
            return 'The cover is only ' . $size[0] . '×' . $size[1] . '. Podcast directories want at least '
                . self::ARTWORK_MIN . '×' . self::ARTWORK_MIN . ', so it will look soft on a phone.';
        }

        return null;
    }

    /* ------------------------------------------------------------------- feed */

    /**
     * The complete feed document.
     *
     * @param  array<int, mixed> $scopeUnitIds  Empty means every church.
     * @return array{xml:string, items:int, skipped:array<int, array{title:string,reason:string}>}
     */
    public static function build(array $scopeUnitIds = [], int $limit = self::MAX_ITEMS): array
    {
        $pdo = Database::getInstance()->getConnection();

        $items = [];
        $skipped = [];

        try {
            $ids = [];
            foreach ($scopeUnitIds as $unitId) {
                $unitId = (int) $unitId;
                if ($unitId > 0) {
                    $ids[] = $unitId;
                }
            }

            $sql = "SELECT id, title, slug, speaker, description, scripture_ref, published_at,
                           audio_path, audio_url, duration_seconds, episode_guid, is_explicit,
                           cover_image, series_id, series_position
                    FROM sermons
                    WHERE is_published = 1
                      AND ((audio_path IS NOT NULL AND audio_path != '') OR (audio_url IS NOT NULL AND audio_url != ''))";
            if ($ids !== []) {
                $sql .= ' AND org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            }
            $sql .= ' ORDER BY published_at DESC LIMIT ' . max(1, min(1000, $limit));

            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('PodcastFeed build failed: ' . $e->getMessage());
            $rows = [];
        }

        foreach ($rows as $row) {
            $enclosure = self::enclosureFor($row);
            if ($enclosure === null) {
                // Published with audio that no longer resolves. Skipping it is better than
                // emitting an item the directories will reject for the whole feed.
                $skipped[] = ['title' => (string) $row['title'], 'reason' => 'Its audio file is missing or its link is unusable.'];
                continue;
            }
            $row['enclosure'] = $enclosure;
            $items[] = $row;
        }

        $siteTitle = (string) (setting('site_title') ?: 'Sermons');
        $tagline = (string) (setting('site_tagline') ?: '');
        $description = trim($tagline);
        if ($description === '') {
            $description = 'Sermons and messages from ' . $siteTitle . '.';
        }

        $email = (string) (setting('contact_email') ?: '');

        // Artwork: the newest sermon's cover, then the site logo.
        $artwork = null;
        foreach ($items as $item) {
            $artwork = self::artworkUrl($item['cover_image'] ?? null);
            if ($artwork !== null) {
                break;
            }
        }
        if ($artwork === null) {
            $artwork = self::artworkUrl(setting('logo_path') ?: null);
        }

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<rss version="2.0"'
            . ' xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"'
            . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            . ' xmlns:atom="http://www.w3.org/2005/Atom">';
        $xml[] = '<channel>';

        $xml[] = '  <title>' . self::esc($siteTitle . ' — Sermons') . '</title>';
        $xml[] = '  <link>' . self::esc(baseUrl('/sermons')) . '</link>';
        $xml[] = '  <description>' . self::esc($description) . '</description>';
        // Localisation is Phase 7; until then the site is English and the tag says so.
        $xml[] = '  <language>en</language>';
        $xml[] = '  <generator>Church Media</generator>';
        $xml[] = '  <lastBuildDate>' . self::rfc822($items[0]['published_at'] ?? null) . '</lastBuildDate>';
        $xml[] = '  <atom:link href="' . self::esc(baseUrl('/podcast.xml')) . '" rel="self" type="application/rss+xml"/>';

        $xml[] = '  <itunes:author>' . self::esc($siteTitle) . '</itunes:author>';
        $xml[] = '  <itunes:summary>' . self::esc($description) . '</itunes:summary>';
        $xml[] = '  <itunes:owner>';
        $xml[] = '    <itunes:name>' . self::esc($siteTitle) . '</itunes:name>';
        if ($email !== '') {
            $xml[] = '    <itunes:email>' . self::esc($email) . '</itunes:email>';
        }
        $xml[] = '  </itunes:owner>';
        $xml[] = '  <itunes:explicit>false</itunes:explicit>';
        $xml[] = '  <itunes:category text="Religion &amp; Spirituality">';
        $xml[] = '    <itunes:category text="Christianity"/>';
        $xml[] = '  </itunes:category>';

        if ($artwork !== null) {
            $xml[] = '  <itunes:image href="' . self::esc($artwork) . '"/>';
            $xml[] = '  <image>';
            $xml[] = '    <url>' . self::esc($artwork) . '</url>';
            $xml[] = '    <title>' . self::esc($siteTitle . ' — Sermons') . '</title>';
            $xml[] = '    <link>' . self::esc(baseUrl('/sermons')) . '</link>';
            $xml[] = '  </image>';
        }

        foreach ($items as $item) {
            $link = baseUrl('/sermons/' . rawurlencode((string) $item['slug']));
            $summary = trim((string) ($item['description'] ?? ''));
            if ($summary === '') {
                $summary = (string) $item['title'];
                if (!empty($item['scripture_ref'])) {
                    $summary .= ' — ' . (string) $item['scripture_ref'];
                }
            }

            $xml[] = '  <item>';
            $xml[] = '    <title>' . self::esc((string) $item['title']) . '</title>';
            $xml[] = '    <link>' . self::esc($link) . '</link>';
            // Stable across renames and domain moves — see the note at the top of the class.
            $xml[] = '    <guid isPermaLink="false">' . self::esc(self::guidFor($item)) . '</guid>';
            $xml[] = '    <pubDate>' . self::rfc822($item['published_at'] ?? null) . '</pubDate>';
            $xml[] = '    <description>' . self::esc($summary) . '</description>';
            $xml[] = '    <itunes:summary>' . self::esc($summary) . '</itunes:summary>';
            if (!empty($item['speaker'])) {
                $xml[] = '    <itunes:author>' . self::esc((string) $item['speaker']) . '</itunes:author>';
            }
            if (!empty($item['duration_seconds'])) {
                $xml[] = '    <itunes:duration>' . self::esc(self::formatDuration((int) $item['duration_seconds'])) . '</itunes:duration>';
            }
            $xml[] = '    <itunes:explicit>' . (!empty($item['is_explicit']) ? 'true' : 'false') . '</itunes:explicit>';
            if (!empty($item['series_position'])) {
                $xml[] = '    <itunes:episode>' . (int) $item['series_position'] . '</itunes:episode>';
            }

            $itemArtwork = self::artworkUrl($item['cover_image'] ?? null);
            if ($itemArtwork !== null && $itemArtwork !== $artwork) {
                $xml[] = '    <itunes:image href="' . self::esc($itemArtwork) . '"/>';
            }

            $xml[] = '    <enclosure url="' . self::esc($item['enclosure']['url']) . '"'
                . ' length="' . (int) $item['enclosure']['length'] . '"'
                . ' type="' . self::esc($item['enclosure']['type']) . '"/>';
            $xml[] = '  </item>';
        }

        $xml[] = '</channel>';
        $xml[] = '</rss>';

        return ['xml' => implode("\n", $xml) . "\n", 'items' => count($items), 'skipped' => $skipped];
    }

    /**
     * Where an episode's audio lives, and what the directories need to know about it.
     *
     * An external `audio_url` wins over an upload, because a church already hosting on a
     * podcast platform or a CDN should not have to move.
     *
     * @return array{url:string,length:int,type:string}|null
     */
    public static function enclosureFor(array $sermon): ?array
    {
        $external = trim((string) ($sermon['audio_url'] ?? ''));
        if ($external !== '') {
            if (!preg_match('#^https?://#i', $external)) {
                return null;
            }
            return [
                'url' => $external,
                // Cannot be known without fetching it. Present and honest beats invented.
                'length' => 0,
                'type' => self::mimeFor($external),
            ];
        }

        $path = trim((string) ($sermon['audio_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $full = UPLOADS_PATH . '/' . ltrim($path, '/');
        if (!is_file($full)) {
            return null;
        }

        return [
            'url' => (string) uploadUrl($path),
            'length' => (int) filesize($full),
            'type' => self::mimeFor($path),
        ];
    }

    /** The episode id. Derived from the row id so it can never change under a client's feet. */
    public static function guidFor(array $sermon): string
    {
        $guid = trim((string) ($sermon['episode_guid'] ?? ''));
        if ($guid !== '') {
            return $guid;
        }
        return 'sermon-' . (int) ($sermon['id'] ?? 0);
    }

    private static function mimeFor(string $path): string
    {
        // An array rather than a `match`: the codebase supports PHP before 8, where `match` is not
        // a keyword and the file will not even parse. See the polyfills at the top of helpers.php.
        $types = [
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'mp4' => 'audio/mp4',
            'aac' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'opus' => 'audio/opus',
        ];

        return $types[strtolower((string) pathinfo($path, PATHINFO_EXTENSION))] ?? 'audio/mpeg';
    }

    /** RSS dates are RFC 822, which is not what `date()` gives by default. */
    private static function rfc822(mixed $datetime): string
    {
        $timestamp = $datetime ? strtotime((string) $datetime) : false;
        if ($timestamp === false) {
            $timestamp = time();
        }
        return gmdate('D, d M Y H:i:s', $timestamp) . ' +0000';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
