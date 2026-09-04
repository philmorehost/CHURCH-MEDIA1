<?php
declare(strict_types=1);

/** GET /api/search?q=... — combined search across posts, sermons, and events. */

if (!RateLimiter::attemptConfigured('search', Fingerprint::hash())) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests, slow down.'], 429);
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    jsonResponse(['status' => 'error', 'message' => 'Search query must be at least 2 characters.'], 400);
}

$pdo = Database::getInstance()->getConnection();
$like = '%' . $q . '%';

$posts = $pdo->prepare('SELECT id, slug, caption AS title, "post" AS result_type FROM media_posts WHERE is_published = 1 AND caption LIKE ? ORDER BY created_at DESC LIMIT 10');
$posts->execute([$like]);

$sermons = $pdo->prepare('SELECT id, slug, title, "sermon" AS result_type FROM sermons WHERE is_published = 1 AND (title LIKE ? OR description LIKE ? OR speaker LIKE ?) ORDER BY published_at DESC LIMIT 10');
$sermons->execute([$like, $like, $like]);

$events = $pdo->prepare('SELECT id, slug, title, "event" AS result_type FROM events WHERE is_published = 1 AND (title LIKE ? OR description LIKE ?) ORDER BY start_at DESC LIMIT 10');
$events->execute([$like, $like]);

jsonResponse([
    'status' => 'success',
    'query' => $q,
    'data' => [
        'posts' => $posts->fetchAll(),
        'sermons' => $sermons->fetchAll(),
        'events' => $events->fetchAll(),
    ],
]);
