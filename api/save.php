<?php
declare(strict_types=1);

/** POST /api/save {post_id} — toggles an anonymous bookmark, deduped by fingerprint. */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'POST required.'], 405);
}

$fingerprint = Fingerprint::hash();
if (!RateLimiter::attemptConfigured('likes', $fingerprint)) {
    jsonResponse(['status' => 'error', 'message' => 'Too many requests, slow down.'], 429);
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$postId = (int) ($input['post_id'] ?? 0);
if ($postId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'post_id is required.'], 400);
}

$pdo = Database::getInstance()->getConnection();
$exists = $pdo->prepare('SELECT id FROM media_posts WHERE id = ? AND is_published = 1');
$exists->execute([$postId]);
if (!$exists->fetchColumn()) {
    jsonResponse(['status' => 'error', 'message' => 'Post not found.'], 404);
}

// A signed-in member's bookmark is matched by account as well as by browser, so saving
// on a laptop and unsaving on a phone works. Anonymous visitors keep the previous
// fingerprint-only behaviour, because `member_id = NULL` never matches anything.
$memberId = MemberAuth::check() ? MemberAuth::id() : null;

$where = 'media_post_id = ? AND (fingerprint_hash = ?' . ($memberId !== null ? ' OR member_id = ?' : '') . ')';
$params = $memberId !== null ? [$postId, $fingerprint, $memberId] : [$postId, $fingerprint];

$pdo->beginTransaction();
try {
    $savedStmt = $pdo->prepare('SELECT id FROM post_saves WHERE ' . $where);
    $savedStmt->execute($params);
    $existingIds = $savedStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($existingIds) {
        // Deleting every match and decrementing by that count keeps saves_count honest
        // when the same post was bookmarked from more than one device — deleting one and
        // decrementing by one would leave the post looking saved when it is not.
        $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
        $pdo->prepare('DELETE FROM post_saves WHERE id IN (' . $placeholders . ')')->execute($existingIds);
        $pdo->prepare('UPDATE media_posts SET saves_count = GREATEST(0, saves_count - ?) WHERE id = ?')
            ->execute([count($existingIds), $postId]);
        $saved = false;
    } else {
        $pdo->prepare('INSERT INTO post_saves (media_post_id, fingerprint_hash, member_id) VALUES (?, ?, ?)')
            ->execute([$postId, $fingerprint, $memberId]);
        $pdo->prepare('UPDATE media_posts SET saves_count = saves_count + 1 WHERE id = ?')->execute([$postId]);
        $saved = true;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['status' => 'error', 'message' => 'Could not update bookmark.'], 500);
}

$countStmt = $pdo->prepare('SELECT saves_count FROM media_posts WHERE id = ?');
$countStmt->execute([$postId]);

jsonResponse(['status' => 'success', 'saved' => $saved, 'saves_count' => (int) $countStmt->fetchColumn()]);
