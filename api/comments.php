<?php
declare(strict_types=1);

/**
 * GET  /api/comments?post_id=5 — threaded published comments for a post
 *      (top-level comments with nested `replies`, image, likes, liked flag).
 *
 * POST /api/comments — add a comment or reply (JSON or multipart/form-data):
 *   {post_id, parent_id?, name?, message?, image? (multipart file)}
 *
 * POST /api/comments {action: 'like', comment_id} — toggle a comment like.
 */

$pdo = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $postId = (int) ($_GET['post_id'] ?? 0);
    if ($postId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'post_id is required.'], 400);
    }
    $fp = Fingerprint::hash();
    $stmt = $pdo->prepare('
        SELECT c.id, c.name, c.message, c.image_path, c.likes_count, c.created_at,
               (SELECT COUNT(*) FROM post_comments r WHERE r.parent_id = c.id AND r.is_published = 1) AS reply_count,
               EXISTS(SELECT 1 FROM post_comment_likes l WHERE l.comment_id = c.id AND l.fingerprint_hash = ?) AS liked
        FROM post_comments c
        WHERE c.media_post_id = ? AND c.is_published = 1 AND c.parent_id IS NULL
        ORDER BY c.created_at DESC LIMIT 100');
    $stmt->execute([$fp, $postId]);
    $comments = $stmt->fetchAll();

    $replyStmt = $pdo->prepare('
        SELECT c.id, c.name, c.message, c.image_path, c.likes_count, c.created_at, 0 AS reply_count,
               EXISTS(SELECT 1 FROM post_comment_likes l WHERE l.comment_id = c.id AND l.fingerprint_hash = ?) AS liked
        FROM post_comments c
        WHERE c.media_post_id = ? AND c.is_published = 1 AND c.parent_id = ?
        ORDER BY c.created_at ASC LIMIT 50');
    foreach ($comments as &$c) {
        $replyStmt->execute([$fp, $postId, (int) $c['id']]);
        $c['replies'] = $replyStmt->fetchAll();
        $c['id'] = (int) $c['id'];
        $c['likes_count'] = (int) $c['likes_count'];
        $c['reply_count'] = (int) $c['reply_count'];
        $c['liked'] = (bool) $c['liked'];
        foreach ($c['replies'] as &$r) {
            $r['id'] = (int) $r['id'];
            $r['likes_count'] = (int) $r['likes_count'];
            $r['reply_count'] = (int) $r['reply_count'];
            $r['liked'] = (bool) $r['liked'];
        }
        unset($r);
    }
    unset($c);
    jsonResponse(['status' => 'success', 'data' => $comments]);
}

if ($method === 'POST') {
    $fingerprint = Fingerprint::hash();
    if (!RateLimiter::attemptConfigured('comments', $fingerprint)) {
        jsonResponse(['status' => 'error', 'message' => 'Too many requests, slow down.'], 429);
    }

    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    $action = (string) ($input['action'] ?? '');

    // Toggle a comment like.
    if ($action === 'like') {
        $commentId = (int) ($input['comment_id'] ?? 0);
        if ($commentId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'comment_id is required.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id FROM post_comments WHERE id = ? AND is_published = 1');
        $stmt->execute([$commentId]);
        if (!$stmt->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'Comment not found.'], 404);
        }
        $del = $pdo->prepare('DELETE FROM post_comment_likes WHERE comment_id = ? AND fingerprint_hash = ?');
        $del->execute([$commentId, $fingerprint]);
        if ($del->rowCount() > 0) {
            $pdo->prepare('UPDATE post_comments SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = ?')->execute([$commentId]);
            $liked = false;
        } else {
            $pdo->prepare('INSERT IGNORE INTO post_comment_likes (comment_id, fingerprint_hash) VALUES (?, ?)')->execute([$commentId, $fingerprint]);
            $pdo->prepare('UPDATE post_comments SET likes_count = likes_count + 1 WHERE id = ?')->execute([$commentId]);
            $liked = true;
        }
        $count = (int) $pdo->query('SELECT likes_count FROM post_comments WHERE id = ' . $commentId)->fetchColumn();
        jsonResponse(['status' => 'success', 'data' => ['comment_id' => $commentId, 'liked' => $liked, 'likes_count' => $count]]);
    }

    // Add a comment or reply.
    $postId = (int) ($input['post_id'] ?? 0);
    $parentId = (int) ($input['parent_id'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    $message = trim((string) ($input['message'] ?? ''));

    if ($postId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'post_id is required.'], 400);
    }
    if ($message === '' && empty($_FILES['image']['name'])) {
        jsonResponse(['status' => 'error', 'message' => 'Write a comment or attach an image.'], 422);
    }
    if ($message !== '' && mb_strlen($message) > 1000) {
        jsonResponse(['status' => 'error', 'message' => 'Comment must be between 1 and 1000 characters.'], 422);
    }
    if ($name !== '' && mb_strlen($name) > 100) {
        jsonResponse(['status' => 'error', 'message' => 'Name is too long.'], 422);
    }

    $exists = $pdo->prepare('SELECT id FROM media_posts WHERE id = ? AND is_published = 1');
    $exists->execute([$postId]);
    if (!$exists->fetchColumn()) {
        jsonResponse(['status' => 'error', 'message' => 'Post not found.'], 404);
    }

    // Verify the parent (if given) belongs to the same post.
    if ($parentId > 0) {
        $chk = $pdo->prepare('SELECT id FROM post_comments WHERE id = ? AND media_post_id = ? AND is_published = 1');
        $chk->execute([$parentId, $postId]);
        if (!$chk->fetchColumn()) {
            $parentId = 0;
        }
    }

    // Optional image attachment — auto-converted to the smallest webp.
    $imagePath = null;
    if (!empty($_FILES['image']['name'])) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            jsonResponse(['status' => 'error', 'message' => 'Image upload failed — the file may be too large.'], 422);
        }
        if (!is_uploaded_file($_FILES['image']['tmp_name'] ?? '') || !($filename = MediaProcessor::processImage($_FILES['image']['tmp_name'], UPLOADS_WEBP_PATH))) {
            jsonResponse(['status' => 'error', 'message' => 'Image could not be processed — use JPG, PNG, GIF, WebP, BMP, or AVIF.'], 422);
        }
        $imagePath = 'webp/' . $filename;
    }

    $stmt = $pdo->prepare('INSERT INTO post_comments (media_post_id, parent_id, name, message, image_path, fingerprint_hash) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$postId, $parentId > 0 ? $parentId : null, $name !== '' ? $name : null, $message, $imagePath, $fingerprint]);
    $commentId = (int) $pdo->lastInsertId();

    jsonResponse(['status' => 'success', 'data' => [
        'id' => $commentId,
        'parent_id' => $parentId,
        'name' => $name !== '' ? $name : null,
        'message' => $message,
        'image_path' => $imagePath,
        'likes_count' => 0,
        'liked' => false,
        'reply_count' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]]);
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
