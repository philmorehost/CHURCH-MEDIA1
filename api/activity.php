<?php
declare(strict_types=1);

/**
 * GET /api/activity — unified recent activity for the app's notifications
 * center: newest published posts (reels), upcoming events, and recent sermons.
 * Public + anonymous (the app has no login). Newest-first, capped at 25.
 */

$pdo = Database::getInstance()->getConnection();

$posts = $pdo->query('
    SELECT p.id, p.caption, p.created_at, p.post_type,
           (SELECT file_path FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover
    FROM media_posts p
    WHERE p.is_published = 1
    ORDER BY p.created_at DESC LIMIT 12
')->fetchAll();

$events = $pdo->query('
    SELECT id, slug, title, start_at AS created_at, location
    FROM events
    WHERE is_published = 1 AND start_at >= NOW()
    ORDER BY start_at ASC LIMIT 6
')->fetchAll();

$sermons = $pdo->query('
    SELECT id, slug, title, published_at AS created_at, speaker
    FROM sermons
    WHERE is_published = 1
    ORDER BY published_at DESC LIMIT 6
')->fetchAll();

$items = [];
foreach ($posts as $p) {
    $items[] = [
        'type' => 'post',
        'id' => (int) $p['id'],
        'title' => 'New reel',
        'body' => $p['caption'] !== '' ? mb_strimwidth((string) $p['caption'], 0, 120, '…') : 'A new reel was just published.',
        'created_at' => $p['created_at'],
        'thumb' => $p['cover'] ?: null,
        'target' => ['screen' => 'feed', 'post_id' => (int) $p['id']],
    ];
}
foreach ($events as $e) {
    $items[] = [
        'type' => 'event',
        'id' => (int) $e['id'],
        'title' => 'Upcoming event',
        'body' => mb_strimwidth((string) $e['title'], 0, 120, '…') . ($e['location'] ? ' · ' . $e['location'] : ''),
        'created_at' => $e['created_at'],
        'thumb' => null,
        'target' => ['screen' => 'event', 'slug' => $e['slug']],
    ];
}
foreach ($sermons as $sm) {
    $items[] = [
        'type' => 'sermon',
        'id' => (int) $sm['id'],
        'title' => 'New sermon',
        'body' => mb_strimwidth((string) $sm['title'], 0, 120, '…') . ($sm['speaker'] ? ' · ' . $sm['speaker'] : ''),
        'created_at' => $sm['created_at'],
        'thumb' => null,
        'target' => ['screen' => 'sermon', 'slug' => $sm['slug']],
    ];
}

usort($items, fn (array $a, array $b): int => strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']));
$items = array_slice($items, 0, 25);

jsonResponse(['status' => 'success', 'data' => $items]);
