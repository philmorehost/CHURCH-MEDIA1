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
        $sql = 'SELECT id, title, media_type, file_path, thumbnail_path, destination_url, created_at'
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
