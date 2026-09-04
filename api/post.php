<?php
declare(strict_types=1);

/** GET /api/post?id=5 or ?slug=... — single post detail; also records an anonymous, deduped view. */

$pdo = Database::getInstance()->getConnection();
$id = (int) ($_GET['id'] ?? 0);
$slug = trim((string) ($_GET['slug'] ?? ''));

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.username AS author_username FROM media_posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.is_published = 1');
    $stmt->execute([$id]);
} elseif ($slug !== '') {
    $stmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.username AS author_username FROM media_posts p JOIN users u ON u.id = p.user_id WHERE p.slug = ? AND p.is_published = 1');
    $stmt->execute([$slug]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Provide an id or slug.'], 400);
}

$post = $stmt->fetch();
if (!$post) {
    jsonResponse(['status' => 'error', 'message' => 'Post not found.'], 404);
}

$fingerprint = Fingerprint::hash();
if (RateLimiter::attemptConfigured('views', $fingerprint)) {
    $inserted = $pdo->prepare('INSERT IGNORE INTO post_views (media_post_id, fingerprint_hash) VALUES (?, ?)');
    $inserted->execute([$post['id'], $fingerprint]);
    if ($inserted->rowCount() > 0) {
        $pdo->prepare('UPDATE media_posts SET views_count = views_count + 1 WHERE id = ?')->execute([$post['id']]);
        $post['views_count']++;
    }
}

$itemStmt = $pdo->prepare('SELECT type, source, file_path, thumbnail_path, alt_text, processing_status, converted_at FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC');
$itemStmt->execute([$post['id']]);
$post['media_items'] = array_map(function ($item) {
    $item['file_url'] = uploadUrl($item['file_path']);
    $item['thumbnail_url'] = uploadUrl($item['thumbnail_path']);
    $item['conversion_status'] = videoConversionStatus($item);
    unset($item['file_path'], $item['thumbnail_path']);
    return $item;
}, $itemStmt->fetchAll());

$catStmt = $pdo->prepare('SELECT c.id, c.name, c.slug FROM media_categories c JOIN media_post_categories mpc ON mpc.media_category_id = c.id WHERE mpc.media_post_id = ?');
$catStmt->execute([$post['id']]);
$post['categories'] = $catStmt->fetchAll();

$likedStmt = $pdo->prepare('SELECT 1 FROM post_likes WHERE media_post_id = ? AND fingerprint_hash = ?');
$likedStmt->execute([$post['id'], $fingerprint]);
$post['liked_by_viewer'] = (bool) $likedStmt->fetchColumn();

$savedStmt = $pdo->prepare('SELECT 1 FROM post_saves WHERE media_post_id = ? AND fingerprint_hash = ?');
$savedStmt->execute([$post['id'], $fingerprint]);
$post['saved_by_viewer'] = (bool) $savedStmt->fetchColumn();

$commentsStmt = $pdo->prepare('SELECT COUNT(*) FROM post_comments WHERE media_post_id = ? AND is_published = 1');
$commentsStmt->execute([$post['id']]);
$post['comments_count'] = (int) $commentsStmt->fetchColumn();

$post['author_username'] = (string) $post['author_username'];
$post['id'] = (int) $post['id'];
$post['user_id'] = (int) $post['user_id'];
$post['likes_count'] = (int) $post['likes_count'];
$post['views_count'] = (int) $post['views_count'];
$post['saves_count'] = (int) $post['saves_count'];

jsonResponse(['status' => 'success', 'data' => $post]);
