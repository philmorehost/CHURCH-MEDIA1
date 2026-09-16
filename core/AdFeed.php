<?php
declare(strict_types=1);

/**
 * The one place an advert is chosen for display, and the one place it is shaped into something a
 * renderer can draw.
 *
 * Three things were wrong with the code this replaces, and all three were live:
 *
 * 1. **The adverts were not confined to a church.** `api/feed.php` and `api/ads.php` both selected
 *    `status = 'approved'` adverts with no tenant clause at all, although `ads.tenant_id` exists and
 *    both writers stamp it. So once a second church existed, church A's paid adverts were served into
 *    church B's site — an advertiser paying one church for impressions delivered on another's site,
 *    and a church whose own advertisers' exclusivity quietly evaporated. The clause now comes from
 *    `tenantScope()`, the same helper every SMS screen and the publisher portal already use, so this
 *    cannot drift from the convention again.
 *
 * 2. **The feed item's id was a string.** `'ad_' . $id` was handed to clients that hard-cast the field:
 *    `Post.fromJson` in both Flutter apps reads `id: json['id'] as int`, and `fetchFeed()` does not
 *    catch. So a single approved advert made the feed **unparseable in both apps** — one un-castable
 *    element fails the whole list, and the app's feed stops loading entirely rather than losing one card.
 *    An advert's feed id is now **the negative of its advert id**: numeric, so every existing parser is
 *    happy; never colliding with a media post id, which is always positive; and if a client that does not
 *    understand adverts sends it to `/api/like` or `/api/save` anyway, the lookup finds nothing instead of
 *    silently liking an unrelated post that happens to share the number. The real, positive id travels in
 *    `real_ad_id` for clients that do understand.
 *
 * 3. **Nothing rendered an advert.** There was no branch anywhere — not in `public/assets/js/feed.js`,
 *    whose `buildSlide()` had no ad case, and not in either Flutter app. The web feed therefore drew an
 *    advert as an ordinary post: it looked sponsored, **could not be clicked** because `destination_url`
 *    was never read, and its like/save/comment buttons posted `post_id = "ad_5"`. Advertisers paid, the
 *    approval email said the advert was live, and no visitor could reach the advertiser's site. The
 *    shaping lives here so that the feed, the app API and the admin preview cannot disagree about what an
 *    advert looks like — and a preview that shapes its own advert is a preview of the preview.
 */
final class AdFeed
{
    /** How many adverts one page of the feed may carry. */
    public const PER_PAGE = 2;

    /**
     * What each package's display frequency means, in minutes.
     *
     * This is the promise made to an advertiser on the pricing table — "Every 5 Minutes", "Once Daily" —
     * and until now nothing honoured it: the setting was read on every feed request and its result used by
     * nothing, so every advert was served on every page to every visitor.
     */
    public const FREQUENCY_MINUTES = [
        '5_min' => 5,
        '10_min' => 10,
        '15_min' => 15,
        '30_min' => 30,
        'once_daily' => 1440,
    ];

    /** The longest window honoured, so a visitor's record can be pruned to something bounded. */
    private const MAX_WINDOW_MINUTES = 1440;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    /**
     * The adverts this church may show, on this platform, right now.
     *
     * @param  string $platform 'web' or 'app'; an advert targeted at 'both' is eligible for either
     * @param  int    $limit    at most this many, chosen at random so two advertisers share the space
     * @return array<int, array<string, mixed>>
     */
    public static function activeFor(string $platform = 'web', int $limit = self::PER_PAGE): array
    {
        $platform = in_array($platform, ['web', 'app'], true) ? $platform : 'web';
        $limit = max(1, min(50, $limit));

        // `tenant_id IS NULL` when no church resolves, exactly as everywhere else — never "all churches".
        [$tenantClause, $tenantParams] = tenantScope();

        // `start_at <= NOW()` is what makes an approved advert genuinely live: approval stamps start_at,
        // so a row approved without one is not shown, which is the same rule the old query applied.
        $sql = 'SELECT id, title, media_type, file_path, thumbnail_path, destination_url, created_at, display_frequency'
            . ' FROM ads'
            . ' WHERE status = "approved"'
            . ' AND (target_platform = "both" OR target_platform = ?)'
            . ' AND start_at <= NOW()'
            . ' AND (expires_at IS NULL OR expires_at > NOW())'
            . ' AND ' . $tenantClause
            . ' ORDER BY RAND() LIMIT ' . $limit;

        $stmt = self::db()->prepare($sql);
        $stmt->execute(array_merge([$platform], $tenantParams));

        return $stmt->fetchAll();
    }

    /** One advert by id, whatever its status — for the reviewer's preview, which is the point of a review. */
    public static function find(int $adId): ?array
    {
        [$tenantClause, $tenantParams] = tenantScope();
        $stmt = self::db()->prepare(
            'SELECT id, title, media_type, file_path, thumbnail_path, destination_url, created_at,'
            . ' status, target_platform, start_at, expires_at'
            . ' FROM ads WHERE id = ? AND ' . $tenantClause . ' LIMIT 1'
        );
        $stmt->execute(array_merge([$adId], $tenantParams));

        return $stmt->fetch() ?: null;
    }

    /** How many minutes must pass before this advert may appear to the same visitor again. */
    public static function frequencyMinutes(array $ad): int
    {
        $frequency = trim((string) ($ad['display_frequency'] ?? ''));
        if ($frequency === '') {
            // The advert's own package value is authoritative; the global setting is the fallback for a row
            // written before the column was populated.
            $frequency = (string) setting('ad_display_frequency', '5_min');
        }

        return self::FREQUENCY_MINUTES[$frequency] ?? 5;
    }

    /**
     * The adverts this visitor has not seen recently enough, in the order they were given.
     *
     * Deliberately not part of `activeFor()`: eligibility is a fact about the advert (is it approved, has
     * it started, has it expired, is it for this platform) and frequency is a fact about *this visitor*, and
     * the admin preview must be able to ask the first question without changing the answer to the second.
     *
     * @param  array<int, array<string, mixed>> $ads
     * @return array<int, array<string, mixed>>
     */
    public static function dueForVisitor(array $ads, string $visitorKey): array
    {
        $seen = self::visitorRecord($visitorKey);
        $now = time();
        $due = [];

        foreach ($ads as $ad) {
            $id = (int) $ad['id'];
            $last = (int) ($seen[$id] ?? 0);
            if ($last === 0 || ($now - $last) >= self::frequencyMinutes($ad) * 60) {
                $due[] = $ad;
            }
        }

        return $due;
    }

    /**
     * Records that these adverts were put in front of this visitor.
     *
     * Only the adverts actually placed on the page may be passed in. Recording every candidate would
     * suppress adverts that were never shown — an advertiser paying for a frequency would silently lose
     * impressions to adverts that beat them to the two slots.
     *
     * @param array<int, array<string, mixed>> $ads
     */
    public static function recordServed(array $ads, string $visitorKey): void
    {
        if (!$ads) {
            return;
        }

        $seen = self::visitorRecord($visitorKey);
        $now = time();

        foreach ($ads as $ad) {
            $seen[(int) $ad['id']] = $now;
        }

        // Bounded: nothing can matter for longer than the longest window, so an entry older than that is
        // dead weight in a file that is read on every feed request.
        $cutoff = $now - (self::MAX_WINDOW_MINUTES * 60) - 3600;
        foreach ($seen as $id => $stamp) {
            if ((int) $stamp < $cutoff) {
                unset($seen[$id]);
            }
        }

        self::writeVisitor($visitorKey, $seen);
    }

    private static function visitorPath(string $visitorKey): string
    {
        return STORAGE_PATH . '/cache/adserve/' . hash('sha256', $visitorKey) . '.json';
    }

    /** @return array<int|string, int> advert id => unix time it was last served to this visitor */
    private static function visitorRecord(string $visitorKey): array
    {
        $path = self::visitorPath($visitorKey);
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A failure to write is not a failure to serve.
     *
     * If the cache directory is missing or unwritable the advert is shown again rather than not shown at
     * all: the first is a broken promise to an advertiser, the second is a broken page for a visitor, and
     * only one of those is what the advertiser paid for.
     */
    private static function writeVisitor(string $visitorKey, array $seen): void
    {
        $dir = STORAGE_PATH . '/cache/adserve';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(self::visitorPath($visitorKey), (string) json_encode($seen));
    }

    /**
     * An advert as a feed item, in the shape `api/feed.php` publishes.
     *
     * @param  array<string, mixed> $ad
     * @return array<string, mixed>
     */
    public static function feedItem(array $ad): array
    {
        $adId = (int) $ad['id'];

        return [
            // Negative — see the class docblock. Numeric so `as int` parses, and impossible to confuse
            // with a media post, whose ids are all positive.
            'id' => -$adId,
            'real_ad_id' => $adId,
            'is_ad' => true,
            'caption' => (string) $ad['title'],
            'post_type' => 'ad',
            'likes_count' => 0,
            'views_count' => 0,
            'saves_count' => 0,
            'comments_count' => 0,
            'created_at' => (string) ($ad['created_at'] ?? ''),
            'author_name' => 'Sponsored',
            'author_username' => 'sponsored',
            'destination_url' => (string) ($ad['destination_url'] ?? ''),
            'media_items' => [[
                'type' => (string) $ad['media_type'],
                'source' => 'upload',
                'file_url' => uploadUrl($ad['file_path']),
                'thumbnail_url' => uploadUrl($ad['thumbnail_path']),
                'conversion_status' => 'converted',
            ]],
            'categories' => [],
            'liked_by_viewer' => false,
            'saved_by_viewer' => false,
            'unit' => [],
            'unit_label' => 'Sponsored',
        ];
    }

    /**
     * An advert in the flatter shape `api/ads.php` publishes.
     *
     * @param  array<string, mixed> $ad
     * @return array<string, mixed>
     */
    public static function card(array $ad): array
    {
        $adId = (int) $ad['id'];

        return [
            'id' => -$adId,
            'real_ad_id' => $adId,
            'title' => (string) $ad['title'],
            'media_type' => (string) $ad['media_type'],
            'file_url' => uploadUrl($ad['file_path']),
            'thumbnail_url' => uploadUrl($ad['thumbnail_path']),
            'destination_url' => (string) ($ad['destination_url'] ?? ''),
            'is_ad' => true,
        ];
    }
}
