<?php
declare(strict_types=1);

/**
 * The parts of a member account that live in *other* tables.
 *
 * Bookmarks and donations both predate accounts: `post_saves` was keyed only by an
 * anonymous browser fingerprint and `donations` only by whatever email the giver
 * typed, so neither could answer "show me mine". Both grew a nullable `member_id`.
 *
 * Two rules keep this honest:
 *
 *  1. **Reads never depend on `member_id` alone.** They also accept rows that are still
 *     anonymous *and* match this member's fingerprint or email, so a bookmark saved two
 *     minutes ago appears immediately rather than at the next sign-in.
 *  2. **Claiming only ever fills a NULL.** A row that already has an owner is never
 *     reassigned, so two people sharing a browser cannot take each other's history.
 */
class MemberActivity
{
    /**
     * Adopts this browser's anonymous bookmarks, and any donation made with this email.
     * Called at sign-in. Safe to run repeatedly.
     *
     * @return array{saves:int,donations:int} How many rows were adopted.
     */
    public static function claimFor(int $memberId, string $fingerprint, string $email): array
    {
        $pdo = Database::getInstance()->getConnection();
        $claimed = array('saves' => 0, 'donations' => 0);

        $saves = $pdo->prepare('UPDATE post_saves SET member_id = ? WHERE member_id IS NULL AND fingerprint_hash = ?');
        $saves->execute(array($memberId, $fingerprint));
        $claimed['saves'] = $saves->rowCount();

        $email = Member::normaliseEmail($email);
        if ($email !== '') {
            $gifts = $pdo->prepare('UPDATE donations SET member_id = ? WHERE member_id IS NULL AND LOWER(donor_email) = ?');
            $gifts->execute(array($memberId, $email));
            $claimed['donations'] = $gifts->rowCount();
        }

        return $claimed;
    }

    /** Bookmarked posts, newest first — one row per post even if saved from two devices. */
    public static function savedPosts(int $memberId, string $fingerprint, int $limit = 60): array
    {
        $pdo = Database::getInstance()->getConnection();
        $limit = max(1, min(200, $limit));

        $stmt = $pdo->prepare(
            'SELECT p.id, p.slug, p.caption, p.post_type, MIN(s.created_at) AS saved_at
               FROM post_saves s
               JOIN media_posts p ON p.id = s.media_post_id
              WHERE p.is_published = 1
                AND (s.member_id = ? OR (s.member_id IS NULL AND s.fingerprint_hash = ?))
              GROUP BY p.id, p.slug, p.caption, p.post_type
              ORDER BY saved_at DESC
              LIMIT ' . $limit
        );
        $stmt->execute(array($memberId, $fingerprint));

        return self::withThumbnails($pdo, $stmt->fetchAll());
    }

    /** Completed gifts, newest first. */
    public static function givingHistory(int $memberId, string $email, int $limit = 100): array
    {
        $pdo = Database::getInstance()->getConnection();
        $limit = max(1, min(500, $limit));
        list($where, $params) = self::givingScope($memberId, $email);

        $stmt = $pdo->prepare(
            "SELECT id, amount, currency, category, payment_method, payment_reference, created_at
               FROM donations
              WHERE payment_status = 'completed' AND " . $where . '
              ORDER BY created_at DESC
              LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Totals per currency.
     *
     * Grouped by currency rather than summed into one number: adding NGN to USD and
     * calling it a lifetime total would be a lie the member has no way to spot.
     */
    public static function givingTotals(int $memberId, string $email): array
    {
        $pdo = Database::getInstance()->getConnection();
        list($where, $params) = self::givingScope($memberId, $email);

        $stmt = $pdo->prepare(
            "SELECT currency, SUM(amount) AS total, COUNT(*) AS gifts
               FROM donations
              WHERE payment_status = 'completed' AND " . $where . '
              GROUP BY currency
              ORDER BY total DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * "Mine, or still unclaimed but matching how I would have given."
     *
     * The second half is what makes the list correct before a claim has ever run —
     * a gift given while signed in shows up straight away instead of at the next login.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function givingScope(int $memberId, string $email): array
    {
        $email = Member::normaliseEmail($email);
        if ($email === '') {
            return array('member_id = ?', array($memberId));
        }
        return array(
            '(member_id = ? OR (member_id IS NULL AND LOWER(donor_email) = ?))',
            array($memberId, $email),
        );
    }

    /**
     * Attaches the first thumbnail of each post.
     *
     * A second query rather than a correlated subquery in the grouped statement above:
     * ONLY_FULL_GROUP_BY rejects an ungrouped column there, and listing every column in
     * GROUP BY to satisfy it is worse than one extra cheap query.
     */
    private static function withThumbnails(PDO $pdo, array $posts): array
    {
        if (!$posts) {
            return array();
        }

        $ids = array();
        foreach ($posts as $post) {
            $ids[] = (int) $post['id'];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            'SELECT media_post_id, thumbnail_path, file_path
               FROM media_post_items
              WHERE media_post_id IN (' . $placeholders . ')
              ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute($ids);

        $thumbs = array();
        foreach ($stmt->fetchAll() as $item) {
            $postId = (int) $item['media_post_id'];
            if (isset($thumbs[$postId])) {
                continue; // the query is already in presentation order, so first wins
            }
            $thumbs[$postId] = $item['thumbnail_path'] ?: $item['file_path'];
        }

        foreach ($posts as $i => $post) {
            $posts[$i]['thumb'] = $thumbs[(int) $post['id']] ?? null;
        }

        return $posts;
    }
}
