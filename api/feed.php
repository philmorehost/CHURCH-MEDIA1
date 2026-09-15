<?php
declare(strict_types=1);

/** GET /api/feed?page=1&per_page=10&category=worship — paginated Reels-style feed for web + apps. */

if (!RateLimiter::attemptConfigured('feed', Fingerprint::hash())) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests, slow down.'], 429);
}

$pdo = Database::getInstance()->getConnection();

/*
 * The reviewer's preview — `/api/feed?preview_ad=N`.
 *
 * Gated on an admin session, because an advert under review is not public and this endpoint is. The id is
 * resolved through `AdFeed::find()`, which is church-scoped, so an admin cannot preview another church's
 * advert by guessing a number — the same rule the rest of the codebase applies to a request id.
 *
 * The item is shaped by `AdFeed::feedItem()`, the same call the live feed below makes. That is the whole
 * point: a preview that built its own card could look right while the thing that actually runs looked
 * wrong, which is the failure this feature exists to prevent.
 */
$previewAdId = (int) ($_GET['preview_ad'] ?? 0);
if ($previewAdId > 0) {
    $previewUser = Auth::user();
    $isAdmin = $previewUser !== null
        && ((string) ($previewUser['role'] ?? '') === 'admin' || !empty($previewUser['is_super_admin']));

    if (!$isAdmin) {
        jsonResponse(['status' => 'error', 'message' => 'Not available'], 403);
    }

    $previewAd = AdFeed::find($previewAdId);
    if ($previewAd === null) {
        jsonResponse(['status' => 'error', 'message' => 'Advert not found'], 404);
    }

    // One item, one page. `has_more` is false so the scroller cannot ask for a second page of a preview.
    jsonResponse([
        'status' => 'success',
        'page' => 1,
        'has_more' => false,
        'data' => [AdFeed::feedItem($previewAd)],
    ]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(30, max(1, (int) ($_GET['per_page'] ?? 10)));
$offset = ($page - 1) * $perPage;
$categorySlug = trim((string) ($_GET['category'] ?? ''));
$unitSlug = trim((string) ($_GET['unit'] ?? ''));
$savedOnly = !empty($_GET['saved']) && $_GET['saved'] === '1';
$fingerprint = Fingerprint::hash();

$where = 'p.is_published = 1';
$params = [];
if ($categorySlug !== '') {
    $where .= ' AND EXISTS (SELECT 1 FROM media_post_categories mpc JOIN media_categories c ON c.id = mpc.media_category_id WHERE mpc.media_post_id = p.id AND c.slug = :slug)';
    $params['slug'] = $categorySlug;
}
if ($savedOnly) {
    $where .= ' AND EXISTS (SELECT 1 FROM post_saves ps WHERE ps.media_post_id = p.id AND ps.fingerprint_hash = :fp)';
    $params['fp'] = $fingerprint;
}
if ($unitSlug !== '') {
    // Filter to a unit and everything under it (a zone shows its areas + parishes).
    $found = Unit::findBySlug($unitSlug);
    $unitId = $found !== null ? (int) $found['id'] : 0;
    if ($unitId > 0) {
        $unitIds = Unit::subtreeIds($unitId);
        $in = [];
        foreach ($unitIds as $i => $uid) {
            $in[] = ':unit' . $i;
            $params['unit' . $i] = $uid;
        }
        $where .= ' AND p.org_unit_id IN (' . implode(',', $in) . ')';
    } else {
        $where .= ' AND 1 = 0'; // unknown unit → nothing
    }
}

// Pinned ordering only kicks in once the pinned columns exist (migration).
$pinnedCols = mediaPinnedColumnsExist($pdo);
$pinnedSelect = $pinnedCols ? ', p.is_pinned, p.pinned_at, p.pinned_expires_at' : '';
$pinnedOrder = $pinnedCols ? '(p.is_pinned = 1 AND p.pinned_expires_at > NOW()) DESC, p.pinned_at ASC, ' : '';

$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.caption, p.post_type, p.likes_count, p.views_count, p.saves_count, p.created_at, p.org_unit_id$pinnedSelect, u.name AS author_name, u.username AS author_username,
      (SELECT COUNT(*) FROM post_comments pc WHERE pc.media_post_id = p.id AND pc.is_published = 1) AS comments_count
    FROM media_posts p JOIN users u ON u.id = p.user_id
    WHERE $where
    ORDER BY {$pinnedOrder}p.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue('limit', $perPage + 1, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$hasMore = count($posts) > $perPage;
$posts = array_slice($posts, 0, $perPage);

$fingerprint = Fingerprint::hash();
$itemStmt = $pdo->prepare('SELECT type, source, file_path, thumbnail_path, alt_text, processing_status, converted_at FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC');
$catStmt = $pdo->prepare('SELECT c.id, c.name, c.slug FROM media_categories c JOIN media_post_categories mpc ON mpc.media_category_id = c.id WHERE mpc.media_post_id = ?');
$likedStmt = $pdo->prepare('SELECT 1 FROM post_likes WHERE media_post_id = ? AND fingerprint_hash = ?');
$savedStmt = $pdo->prepare('SELECT 1 FROM post_saves WHERE media_post_id = ? AND fingerprint_hash = ?');

foreach ($posts as &$post) {
    $itemStmt->execute([$post['id']]);
    $post['media_items'] = array_map(function ($item) {
        $item['file_url'] = uploadUrl($item['file_path']);
        $item['thumbnail_url'] = uploadUrl($item['thumbnail_path']);
        $item['conversion_status'] = videoConversionStatus($item);
        unset($item['file_path'], $item['thumbnail_path']);
        return $item;
    }, $itemStmt->fetchAll());

    $catStmt->execute([$post['id']]);
    $post['categories'] = array_map(function (array $c): array {
        $c['id'] = (int) $c['id'];
        return $c;
    }, $catStmt->fetchAll());

    $likedStmt->execute([$post['id'], $fingerprint]);
    $post['liked_by_viewer'] = (bool) $likedStmt->fetchColumn();

    $savedStmt->execute([$post['id'], $fingerprint]);
    $post['saved_by_viewer'] = (bool) $savedStmt->fetchColumn();

    $post['unit'] = [];
    $post['unit_label'] = '';
    if (!empty($post['org_unit_id'])) {
        $post['unit'] = array_map(fn (array $u): array => [
            'id' => (int) $u['id'],
            'type' => $u['type'],
            'name' => $u['name'],
            'slug' => $u['slug'],
        ], Unit::path((int) $post['org_unit_id']));
        $post['unit_label'] = implode(' · ', array_column($post['unit'], 'name'));
    }
    unset($post['org_unit_id']);

    $post['author_username'] = (string) $post['author_username'];
    $post['id'] = (int) $post['id'];
    $post['likes_count'] = (int) $post['likes_count'];
    $post['views_count'] = (int) $post['views_count'];
    $post['saves_count'] = (int) $post['saves_count'];
    $post['comments_count'] = (int) $post['comments_count'];
    if ($pinnedCols) {
        // Emulated prepares return TINYINT as a string ('1'); the app's strict
        // parser needs a real bool or it throws and the whole feed fails.
        $post['is_pinned'] = (bool) $post['is_pinned'];
    }
}
unset($post);

/*
 * The adverts are chosen once, here, and their shaping lives in AdFeed — the same class the app API and
 * the reviewer's preview use.
 *
 * This block used to hold its own query and its own inline array. Both are gone, and with them three live
 * faults: the query was not confined to the church being served (so church A's paid adverts ran on church
 * B's site); the feed item's id was the string 'ad_13', which `Post.fromJson`'s `as int` cannot parse (so
 * one approved advert made the feed unparseable in BOTH apps); and nothing anywhere rendered an advert —
 * no ad branch existed in `feed.js`, so the web feed drew a post that could never be clicked.
 *
 * The per-advert display frequency is deliberately not consulted. `setting('ad_display_frequency')` was
 * read here and its result, `$paidMinInterval`, was then never used by anything — the advert was served on
 * every page regardless. That is a real gap against what the packages promise, but applying it needs a
 * per-visitor "when did this advert last appear" record, which is its own piece of work rather than
 * something to fake here. Recorded in the roadmap under 7h-4b.
 */
$activeAds = AdFeed::activeFor('web', AdFeed::PER_PAGE);

$feedItems = [];
$adIdx = 0;
foreach ($posts as $i => $post) {
    $feedItems[] = $post;
    if (($i + 1) % 3 === 0 && isset($activeAds[$adIdx])) {
        $feedItems[] = AdFeed::feedItem($activeAds[$adIdx++]);
    }
}

jsonResponse(['status' => 'success', 'page' => $page, 'has_more' => $hasMore, 'data' => $feedItems]);
