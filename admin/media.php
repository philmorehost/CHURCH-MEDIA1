<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor', 'media_team');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$action = $_GET['action'] ?? 'list';
$errors = [];

// Unit scoping: strictly per-church — non-super admins only manage posts that
// belong to the exact unit they are assigned to (no roll-up of sub-units).
$scopeIds = [];
$scopeClause = '';
if (!$user || empty($user['is_super_admin'])) {
    $scopeIds = !empty($user['org_unit_id']) ? [(int) $user['org_unit_id']] : [];
    $scopeClause = $scopeIds ? ' AND p.org_unit_id IN (' . implode(',', array_map('intval', $scopeIds)) . ')' : ' AND 1 = 0';
}
$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();

function mediaPostOrgUnit(PDO $pdo, int $postId): ?int
{
    $stmt = $pdo->prepare('SELECT org_unit_id FROM media_posts WHERE id = ?');
    $stmt->execute([$postId]);
    $oid = $stmt->fetchColumn();
    return ($oid === false || $oid === null) ? null : (int) $oid;
}
function mediaInScope(array $scopeIds, ?int $orgUnitId): bool
{
    return $orgUnitId !== null && in_array($orgUnitId, $scopeIds, true);
}

/** Loads a single media item and enforces the church scope on its post. */
function mediaItemInScope(PDO $pdo, array $scopeIds, int $postId, int $itemId): ?array
{
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $postId))) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM media_post_items WHERE id = ? AND media_post_id = ?');
    $stmt->execute([$itemId, $postId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function removeMediaFiles(?string $filePath, ?string $thumbPath): void
{
    if ($filePath && !str_starts_with((string) $filePath, 'http')) {
        @unlink(UPLOADS_PATH . '/' . $filePath);
    }
    if ($thumbPath && !str_starts_with((string) $thumbPath, 'http')) {
        @unlink(UPLOADS_PATH . '/' . $thumbPath);
    }
}

/** Deletes a post's media files (disk) then the post row itself. */
function deletePostAndFiles(PDO $pdo, int $postId): void
{
    $itemStmt = $pdo->prepare('SELECT type, file_path, thumbnail_path FROM media_post_items WHERE media_post_id = ?');
    $itemStmt->execute([$postId]);
    foreach ($itemStmt->fetchAll() as $item) {
        removeMediaFiles($item['file_path'], $item['thumbnail_path']);
    }
    $pdo->prepare('DELETE FROM media_posts WHERE id = ?')->execute([$postId]);
}

$categories = $pdo->query('SELECT * FROM media_categories ORDER BY name ASC')->fetchAll();

$allowedImageMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
$allowedVideoMime = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'];
$maxUploadBytes = 200 * 1024 * 1024; // 200MB per file — raise upload_max_filesize/post_max_size in php.ini to match

// media.php is require()d inside Router::dispatchFlatFile(), so its top-level
// variables live in method scope — NOT the global scope. Functions in this file
// cannot read them via `global`; expose them as constants instead.
define('MEDIA_ALLOWED_IMAGE_MIME', $allowedImageMime);
define('MEDIA_ALLOWED_VIDEO_MIME', $allowedVideoMime);
define('MEDIA_MAX_UPLOAD_BYTES', $maxUploadBytes);

function mediaSlug(PDO $pdo, string $caption): string
{
    $base = slugify($caption !== '' ? mb_substr($caption, 0, 60) : 'post');
    $slug = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM media_posts WHERE slug = ?');
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . (++$i);
    }
}

/** Normalizes $_FILES['media'] into ['kind'=>'image'|'video', 'tmp'=>..., 'ext'=>...] items, collecting any errors. */
function collectMediaFiles(?array $files, array &$errors): array
{
    $items = [];
    if (!$files || !is_array($files['tmp_name'])) {
        return $items;
    }
    $videoExt = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
        'video/webm' => 'webm',
    ];
    foreach ($files['tmp_name'] as $i => $tmpName) {
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            continue;
        }
        $name = $files['name'][$i] ?? 'file';
        if ((int) $files['size'][$i] > MEDIA_MAX_UPLOAD_BYTES) {
            $errors[] = $name . ' is too large (max ' . round(MEDIA_MAX_UPLOAD_BYTES / 1024 / 1024) . 'MB).';
            continue;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (in_array($mime, MEDIA_ALLOWED_IMAGE_MIME, true)) {
            $items[] = ['kind' => 'image', 'tmp' => $tmpName];
        } elseif (in_array($mime, MEDIA_ALLOWED_VIDEO_MIME, true)) {
            $items[] = ['kind' => 'video', 'tmp' => $tmpName, 'ext' => $videoExt[$mime] ?? 'mp4'];
        } else {
            $errors[] = $name . ' has an unsupported file type.';
        }
    }
    return $items;
}

/**
 * Inserts media_post_items for a post. Uploaded videos are stored as their
 * original file and marked 'ready' so they play in the feed immediately —
 * uploads are never blocked on FFmpeg. If FFmpeg is available later, the
 * returned ids are queued for a background 9:16 crop via action=process.
 */
function storeMediaItems(PDO $pdo, int $postId, array $items, ?array $covers): array
{
    $itemStmt = $pdo->prepare('INSERT INTO media_post_items (media_post_id, type, source, file_path, thumbnail_path, processing_status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $convertible = [];

    foreach ($items as $order => $item) {
        $coverTmp = null;
        if ($covers && !empty($covers['tmp_name'][$order]) && is_uploaded_file($covers['tmp_name'][$order])) {
            $coverTmp = $covers['tmp_name'][$order];
        }
        // XHR flow sends a per-index cover as cover_<order> (client-captured frame).
        if (!empty($_FILES['cover_' . $order]['tmp_name']) && is_uploaded_file($_FILES['cover_' . $order]['tmp_name'])) {
            $coverTmp = $_FILES['cover_' . $order]['tmp_name'];
        }

        if ($item['kind'] === 'image') {
            $filename = MediaProcessor::processImage($item['tmp'], UPLOADS_WEBP_PATH);
            if (!$filename) {
                throw new RuntimeException('Could not process an uploaded image.');
            }
            $itemStmt->execute([$postId, 'image', 'upload', 'webp/' . $filename, null, 'ready', $order]);
        } elseif ($item['kind'] === 'youtube') {
            $thumb = $item['thumb'];
            if ($coverTmp) {
                $coverFile = MediaProcessor::processImage($coverTmp, UPLOADS_THUMBS_PATH, 82);
                if ($coverFile) {
                    $thumb = 'thumbs/' . $coverFile;
                }
            }
            $itemStmt->execute([$postId, 'video', 'youtube', $item['url'], $thumb, 'ready', $order]);
        } else { // video upload — keep the original so it is ready to play right away
            if (!is_dir(UPLOADS_ORIGINALS_PATH)) {
                mkdir(UPLOADS_ORIGINALS_PATH, 0775, true);
            }
            $origName = uniqid('orig_', true) . '.' . ($item['ext'] ?? 'mp4');
            if (!move_uploaded_file($item['tmp'], UPLOADS_ORIGINALS_PATH . '/' . $origName)) {
                throw new RuntimeException('Could not save the uploaded video.');
            }
            $thumb = null;
            if ($coverTmp) {
                $coverFile = MediaProcessor::processImage($coverTmp, UPLOADS_THUMBS_PATH, 82);
                if ($coverFile) {
                    $thumb = 'thumbs/' . $coverFile;
                }
            }
            $itemStmt->execute([$postId, 'video', 'upload', 'originals/' . $origName, $thumb, 'ready', $order]);
            $convertible[] = (int) $pdo->lastInsertId();
        }
    }
    return $convertible;
}

if ($action === 'category_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        $pdo->prepare('INSERT IGNORE INTO media_categories (name, slug) VALUES (?, ?)')->execute([$name, slugify($name)]);
        flash('success', 'Category added.');
    }
    redirect('/admin/media?action=create');
}

/** Move a post to a different church (from the list's Church column). */
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    // Scope: you can only move a post you can already manage, and only to a
    // unit you're allowed to assign to.
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $targetId))) {
        flash('error', 'You can only manage media in your own parish/zone.');
    } elseif ($unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE media_posts SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $targetId]);
        flash('success', 'Post moved to ' . Unit::label($unitId) . '.');
    } else {
        flash('error', 'Could not move that post to the selected church.');
    }
    redirect('/admin/media');
}

/** Pin a post to the top of its church's feed (max 3 active per church, 3-day expiry). */
if ($action === 'pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $targetId))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    $orgUnitId = mediaPostOrgUnit($pdo, $targetId);
    if ($orgUnitId === null) {
        flash('error', 'Assign this post to a church before pinning it.');
        redirect('/admin/media');
    }
    $stmt = $pdo->prepare('SELECT is_published FROM media_posts WHERE id = ?');
    $stmt->execute([$targetId]);
    if (!(int) $stmt->fetchColumn()) {
        flash('error', 'Only published posts can be pinned.');
        redirect('/admin/media');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM media_posts WHERE org_unit_id = ? AND is_pinned = 1 AND pinned_expires_at > NOW()');
    $stmt->execute([$orgUnitId]);
    $count = (int) $stmt->fetchColumn();
    if ($count >= 3) {
        flash('error', 'This church already has 3 pinned posts. Unpin one first.');
        redirect('/admin/media');
    }
    $pdo->prepare('UPDATE media_posts SET is_pinned = 1, pinned_at = NOW(), pinned_expires_at = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id = ?')->execute([$targetId]);
    flash('success', 'Post pinned — it will show first and auto-unpin in 3 days.');
    redirect('/admin/media');
}

/** Unpin a post (frees a pin slot for another reel). */
if ($action === 'unpin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $targetId))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    $pdo->prepare('UPDATE media_posts SET is_pinned = 0, pinned_at = NULL, pinned_expires_at = NULL WHERE id = ?')->execute([$targetId]);
    flash('success', 'Post unpinned.');
    redirect('/admin/media');
}

/** Shared by both the classic form POST and the instant XHR upload. */
function handleCreatePost(PDO $pdo, array $user): array
{
    // Every church account must belong to a church so the post is auto-assigned
    // to them on creation (never silently unassigned).
    if (empty($user['is_super_admin']) && empty($user['org_unit_id'])) {
        return ['errors' => ['Your account has no Home Church assigned — ask the super admin to set it (Users → Edit → Home Unit) before posting media.']];
    }

    $caption = trim($_POST['caption'] ?? '');
    $categoryIds = array_map('intval', $_POST['categories'] ?? []);
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $errors = [];
    $youtubeUrl = trim((string) ($_POST['youtube_url'] ?? ''));
    $videoId = $youtubeUrl !== '' ? youtubeVideoId($youtubeUrl) : null;

    // Church/unit for the new post. Super admins can pick any unit; everyone
    // else is locked to their own church so they can never post elsewhere.
    $orgUnitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (!empty($user['is_super_admin'])) {
        if ($orgUnitId > 0 && !Unit::inAssignableScope($user, $orgUnitId)) {
            $orgUnitId = 0;
        }
        $orgUnitId = $orgUnitId > 0 ? $orgUnitId : (int) ($user['org_unit_id'] ?? 0);
    } else {
        $orgUnitId = (int) ($user['org_unit_id'] ?? 0);
    }
    $postUnitId = $orgUnitId > 0 ? $orgUnitId : null;

    if ($videoId) {
        $youtubeCover = null;
        if (!empty($_FILES['youtube_cover']['tmp_name']) && is_uploaded_file($_FILES['youtube_cover']['tmp_name'])) {
            $youtubeCover = $_FILES['youtube_cover']['tmp_name'];
        }
        $thumb = youtubeThumbnailUrl($videoId);
        if ($youtubeCover) {
            $coverFile = MediaProcessor::processImage($youtubeCover, UPLOADS_THUMBS_PATH, 82);
            if ($coverFile) {
                $thumb = 'thumbs/' . $coverFile;
            }
        }
        $items = [['kind' => 'youtube', 'url' => 'https://www.youtube.com/embed/' . $videoId, 'thumb' => $thumb]];
        $covers = null;
    } else {
        $items = collectMediaFiles($_FILES['media'] ?? null, $errors);
        $covers = $_FILES['cover'] ?? null;
        if ($youtubeUrl !== '' && !$items) {
            $errors[] = "That YouTube link doesn't look valid.";
        }
    }

    if (!$items && !$errors) {
        $errors[] = 'Upload at least one photo or video, or paste a YouTube link.';
    }
    if ($errors) {
        return ['errors' => $errors];
    }

    $pdo->beginTransaction();
    try {
        $hasVideo = (bool) array_filter($items, fn ($i) => $i['kind'] !== 'image');
        $postType = $hasVideo ? 'vertical_reel' : (count($items) > 1 ? 'carousel' : 'single_image');

        $stmt = $pdo->prepare('INSERT INTO media_posts (user_id, slug, caption, post_type, is_published, org_unit_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$user['id'], mediaSlug($pdo, $caption), $caption, $postType, $isPublished, $postUnitId]);
        $postId = (int) $pdo->lastInsertId();

        $pending = storeMediaItems($pdo, $postId, $items, $covers);

        if ($categoryIds) {
            $catStmt = $pdo->prepare('INSERT IGNORE INTO media_post_categories (media_post_id, media_category_id) VALUES (?, ?)');
            foreach ($categoryIds as $catId) {
                $catStmt->execute([$postId, $catId]);
            }
        }

        $pdo->commit();
        if ($isPublished) {
            try {
                Pusher::notifyNewPost($pdo, $postId, $postUnitId, $caption);
            } catch (Throwable $e) {
                error_log('Push notify failed: ' . $e->getMessage());
            }
        }
        return ['post_id' => $postId, 'pending' => $pending, 'errors' => []];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['errors' => ['Failed to save the post: ' . $e->getMessage()]];
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = handleCreatePost($pdo, $user);
    if ($result['errors']) {
        $errors = $result['errors'];
        keepOld(['caption' => trim($_POST['caption'] ?? '')]);
    } else {
        flash('success', 'Post published.');
        redirect('/admin/media');
    }
}

/** Instant XHR upload — saves originals immediately, converts in the background. */
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = handleCreatePost($pdo, $user);
    if ($result['errors']) {
        jsonResponse(['status' => 'error', 'message' => implode(' ', $result['errors'])], 422);
    }
    jsonResponse(['status' => 'success', 'post_id' => $result['post_id'], 'pending' => $result['pending']]);
}

/** Background conversion of a pending video item (called via XHR/beacon after upload). */
if ($action === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'Missing item id.'], 400);
    }
    set_time_limit(600);
    @ignore_user_abort(true);
    $status = MediaProcessor::convertOriginalVideo($pdo, $id);
    jsonResponse(['status' => $status === 'ready' ? 'success' : 'error', 'processing_status' => $status]);
}

/** Reprocess every pending video on a post (from the list). */
if ($action === 'reprocess' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $id))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    set_time_limit(600);
    $stmt = $pdo->prepare("SELECT id FROM media_post_items WHERE media_post_id = ? AND type = 'video' AND source = 'upload' AND file_path LIKE 'originals/%'");
    $stmt->execute([$id]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $done = 0;
    foreach ($ids as $itemId) {
        if (MediaProcessor::convertOriginalVideo($pdo, (int) $itemId) === 'ready') {
            $done++;
        }
    }
    flash('success', $done > 0 ? "$done video(s) converted to reels." : 'Nothing pending to process.');
    redirect('/admin/media');
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $id))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    $pdo->prepare('UPDATE media_posts SET is_published = NOT is_published WHERE id = ?')->execute([$id]);
    redirect('/admin/media');
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $id))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    deletePostAndFiles($pdo, $id);
    flash('success', 'Post deleted.');
    redirect('/admin/media');
}

/** Bulk delete multiple posts at once (from the list checkboxes). */
if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $ids = array_values(array_unique(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
    $ids = array_values(array_filter($ids, fn (int $i): bool => $i > 0));
    if (!$ids) {
        flash('error', 'No posts were selected.');
        redirect('/admin/media');
    }
    $deleted = 0;
    foreach ($ids as $postId) {
        // Respect the strict per-church scope: skip anything not owned by this user.
        if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $postId))) {
            continue;
        }
        deletePostAndFiles($pdo, $postId);
        $deleted++;
    }
    flash('success', $deleted > 0 ? "$deleted post(s) deleted." : 'Nothing was deleted (out of scope).');
    redirect('/admin/media');
}

/** Replace an uploaded media file (image or video) on a post. */
if ($action === 'replace_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $postId = (int) ($_POST['id'] ?? 0);
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $item = mediaItemInScope($pdo, $scopeIds, $postId, $itemId);
    if (!$item) {
        flash('error', 'Item not found or out of scope.');
        redirect('/admin/media');
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || empty($file['name']) || !is_uploaded_file($file['tmp_name'] ?? '')) {
        flash('error', 'Choose a file to replace this item.');
        redirect('/admin/media?action=edit&id=' . $postId);
    }
    $newPath = null;
    $newThumb = $item['thumbnail_path'];
    if ($item['type'] === 'image') {
        $filename = MediaProcessor::processImage($file['tmp_name'], UPLOADS_WEBP_PATH);
        if (!$filename) {
            flash('error', 'Image could not be processed — use JPG, PNG, GIF, WebP, BMP, or AVIF.');
            redirect('/admin/media?action=edit&id=' . $postId);
        }
        $newPath = 'webp/' . $filename;
    } else { // uploaded video
        if (!is_dir(UPLOADS_ORIGINALS_PATH)) {
            mkdir(UPLOADS_ORIGINALS_PATH, 0775, true);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) ?: 'mp4';
        $origName = uniqid('orig_', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOADS_ORIGINALS_PATH . '/' . $origName)) {
            flash('error', 'Could not save the uploaded video.');
            redirect('/admin/media?action=edit&id=' . $postId);
        }
        $newPath = 'originals/' . $origName;
        $newThumb = null; // re-captured when the reel converts
    }
    removeMediaFiles($item['file_path'], $item['thumbnail_path']);
    // Replacing a YouTube link with an uploaded file converts the item to an
    // upload (source='upload') so it plays inline everywhere.
    $pdo->prepare('UPDATE media_post_items SET source = ?, file_path = ?, thumbnail_path = ?, processing_status = ?, converted_at = NULL WHERE id = ?')
        ->execute(['upload', $newPath, $newThumb, 'ready', $itemId]);
    if ($item['type'] === 'video') {
        set_time_limit(600);
        MediaProcessor::convertOriginalVideo($pdo, $itemId);
    }
    flash('success', 'Media replaced.');
    redirect('/admin/media?action=edit&id=' . $postId);
}

/** Replace a video item's cover/thumbnail. */
if ($action === 'replace_cover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $postId = (int) ($_POST['id'] ?? 0);
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $item = mediaItemInScope($pdo, $scopeIds, $postId, $itemId);
    if (!$item) {
        flash('error', 'Item not found or out of scope.');
        redirect('/admin/media');
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || empty($file['name']) || !is_uploaded_file($file['tmp_name'] ?? '')) {
        flash('error', 'Choose a cover image.');
        redirect('/admin/media?action=edit&id=' . $postId);
    }
    $coverFile = MediaProcessor::processImage($file['tmp_name'], UPLOADS_THUMBS_PATH, 82);
    if (!$coverFile) {
        flash('error', 'Cover could not be processed.');
        redirect('/admin/media?action=edit&id=' . $postId);
    }
    removeMediaFiles(null, $item['thumbnail_path']);
    $pdo->prepare('UPDATE media_post_items SET thumbnail_path = ? WHERE id = ?')->execute(['thumbs/' . $coverFile, $itemId]);
    flash('success', 'Cover updated.');
    redirect('/admin/media?action=edit&id=' . $postId);
}

/** Move a media item up/down to reorder the feed. */
if ($action === 'move_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $postId = (int) ($_POST['id'] ?? 0);
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
    $item = mediaItemInScope($pdo, $scopeIds, $postId, $itemId);
    if (!$item) {
        flash('error', 'Item not found or out of scope.');
        redirect('/admin/media');
    }
    $order = (int) $item['sort_order'];
    $stmt = $pdo->prepare('SELECT id, sort_order FROM media_post_items WHERE media_post_id = ? AND id != ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$postId, $itemId]);
    $others = $stmt->fetchAll();
    $swap = null;
    if ($dir === 'up') {
        foreach ($others as $o) {
            if ((int) $o['sort_order'] < $order || ((int) $o['sort_order'] === $order && (int) $o['id'] < $itemId)) {
                $swap = $o;
            }
        }
    } else {
        $rev = array_reverse($others);
        foreach ($rev as $o) {
            if ((int) $o['sort_order'] > $order || ((int) $o['sort_order'] === $order && (int) $o['id'] > $itemId)) {
                $swap = $o;
                break;
            }
        }
    }
    if ($swap) {
        $pdo->prepare('UPDATE media_post_items SET sort_order = ? WHERE id = ?')->execute([(int) $swap['sort_order'], $itemId]);
        $pdo->prepare('UPDATE media_post_items SET sort_order = ? WHERE id = ?')->execute([$order, (int) $swap['id']]);
        flash('success', 'Order updated.');
    } else {
        flash('success', 'Nothing to reorder.');
    }
    redirect('/admin/media?action=edit&id=' . $postId);
}

/** Delete a single media item (and its files). */
if ($action === 'delete_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $postId = (int) ($_POST['id'] ?? 0);
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $item = mediaItemInScope($pdo, $scopeIds, $postId, $itemId);
    if (!$item) {
        flash('error', 'Item not found or out of scope.');
        redirect('/admin/media');
    }
    removeMediaFiles($item['file_path'], $item['thumbnail_path']);
    $pdo->prepare('DELETE FROM media_post_items WHERE id = ?')->execute([$itemId]);
    // Re-sequence remaining items so they stay 0..n in order.
    $stmt = $pdo->prepare('SELECT id FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$postId]);
    $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare('UPDATE media_post_items SET sort_order = ? WHERE id = ?');
    foreach ($remaining as $i => $rid) {
        $upd->execute([$i, (int) $rid]);
    }
    flash('success', 'Item removed.');
    redirect('/admin/media?action=edit&id=' . $postId);
}

/** Append a single photo/video to an existing post. */
if ($action === 'add_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $postId = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $postId))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || empty($file['name']) || !is_uploaded_file($file['tmp_name'] ?? '')) {
        flash('error', 'Choose a photo or video to add.');
        redirect('/admin/media?action=edit&id=' . $postId);
    }
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM media_post_items WHERE media_post_id = ' . $postId)->fetchColumn();
    $mime = (string) ($file['type'] ?? '');
    if (in_array($mime, MEDIA_ALLOWED_IMAGE_MIME, true)) {
        $filename = MediaProcessor::processImage($file['tmp_name'], UPLOADS_WEBP_PATH);
        if (!$filename) {
            flash('error', 'Image could not be processed — use JPG, PNG, GIF, WebP, BMP, or AVIF.');
            redirect('/admin/media?action=edit&id=' . $postId);
        }
        $pdo->prepare('INSERT INTO media_post_items (media_post_id, type, source, file_path, thumbnail_path, processing_status, sort_order) VALUES (?, ?, ?, ?, NULL, ?, ?)')
            ->execute([$postId, 'image', 'upload', 'webp/' . $filename, 'ready', $next]);
    } elseif (in_array($mime, MEDIA_ALLOWED_VIDEO_MIME, true)) {
        if (!is_dir(UPLOADS_ORIGINALS_PATH)) {
            mkdir(UPLOADS_ORIGINALS_PATH, 0775, true);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) ?: 'mp4';
        $origName = uniqid('orig_', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOADS_ORIGINALS_PATH . '/' . $origName)) {
            flash('error', 'Could not save the uploaded video.');
            redirect('/admin/media?action=edit&id=' . $postId);
        }
        $pdo->prepare('INSERT INTO media_post_items (media_post_id, type, source, file_path, thumbnail_path, processing_status, sort_order) VALUES (?, ?, ?, ?, NULL, ?, ?)')
            ->execute([$postId, 'video', 'upload', 'originals/' . $origName, 'ready', $next]);
        $itemId = (int) $pdo->lastInsertId();
        set_time_limit(600);
        MediaProcessor::convertOriginalVideo($pdo, $itemId);
    } else {
        flash('error', 'Unsupported file type.');
        redirect('/admin/media?action=edit&id=' . $postId);
    }
    flash('success', 'Media added to the post.');
    redirect('/admin/media?action=edit&id=' . $postId);
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (!empty($scopeIds) && !mediaInScope($scopeIds, mediaPostOrgUnit($pdo, $id))) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    $caption = trim($_POST['caption'] ?? '');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $categoryIds = array_map('intval', $_POST['categories'] ?? []);
    // Church/unit change is allowed for super admins (any assignable unit);
    // non-super admins keep their own unit (locked to their church).
    $editUnitId = (int) ($_POST['org_unit_id'] ?? 0);
    $currentUnitId = mediaPostOrgUnit($pdo, $id);
    if (!empty($user['is_super_admin'])) {
        if ($editUnitId > 0 && !Unit::inAssignableScope($user, $editUnitId)) {
            $editUnitId = 0;
        }
        $newUnitId = $editUnitId > 0 ? $editUnitId : $currentUnitId;
    } else {
        $newUnitId = $currentUnitId;
    }
    $pdo->prepare('UPDATE media_posts SET caption = ?, is_published = ?, org_unit_id = ? WHERE id = ?')->execute([$caption, $isPublished, $newUnitId, $id]);
    $pdo->prepare('DELETE FROM media_post_categories WHERE media_post_id = ?')->execute([$id]);
    if ($categoryIds) {
        $catStmt = $pdo->prepare('INSERT IGNORE INTO media_post_categories (media_post_id, media_category_id) VALUES (?, ?)');
        foreach ($categoryIds as $catId) {
            $catStmt->execute([$id, $catId]);
        }
    }
    flash('success', 'Post updated.');
    redirect('/admin/media');
}

$editPost = null;
$editItems = [];
if ($action === 'edit') {
    $editPost = $pdo->prepare('SELECT * FROM media_posts WHERE id = ?');
    $editPost->execute([(int) ($_GET['id'] ?? 0)]);
    $editPost = $editPost->fetch() ?: null;
    if ($editPost && !empty($scopeIds) && !mediaInScope($scopeIds, $editPost['org_unit_id'] !== null ? (int) $editPost['org_unit_id'] : null)) {
        flash('error', 'You can only manage media in your own parish/zone.');
        redirect('/admin/media');
    }
    if ($editPost) {
        $itemStmt = $pdo->prepare('SELECT * FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC, id ASC');
        $itemStmt->execute([(int) $editPost['id']]);
        $editItems = $itemStmt->fetchAll();
    }
}

$posts = $action === 'list' ? $pdo->query('
    SELECT p.*, u.name AS author,
      (SELECT file_path FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover,
      (SELECT thumbnail_path FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover_thumb,
      (SELECT type FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover_type,
      (SELECT IF(source = \'youtube\', thumbnail_path, NULL) FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover_source,
      (SELECT file_path FROM media_post_items WHERE media_post_id = p.id AND type = \'video\' AND source = \'upload\' ORDER BY sort_order ASC LIMIT 1) AS video_path,
      (SELECT converted_at FROM media_post_items WHERE media_post_id = p.id AND type = \'video\' AND source = \'upload\' ORDER BY sort_order ASC LIMIT 1) AS video_converted_at
    FROM media_posts p JOIN users u ON u.id = p.user_id
    WHERE 1=1' . $scopeClause . '
    ORDER BY p.created_at DESC LIMIT 60
')->fetchAll() : [];

$pageTitle = $action === 'create' ? 'New Post' : ($action === 'edit' ? 'Edit Post' : 'Media & Reels');
$activeNav = 'media';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>
<?php if (isset($_GET['processed'])): ?><div class="alert success">Post published — videos are converting in the background.</div><?php endif; ?>

<?php if ($action === 'create'): ?>

  <link rel="stylesheet" href="<?= asset('css/admin-media.css') ?>">

  <div class="card composer">
    <h2>Create a Post</h2>
    <p class="sub">Paste a YouTube link, or upload photos/videos. Video covers are captured automatically from the first frame — you can override them.</p>

    <form id="mediaForm" method="post" action="/admin/media?action=create" enctype="multipart/form-data">
      <?= Csrf::field() ?>

      <div class="media-zone">
        <label for="media">Upload photos or video</label>
        <input type="file" id="media" name="media[]" multiple accept="image/*,video/*">
        <div class="media-preview" id="mediaPreview"></div>
        <p class="hint">Video covers are auto-captured as the default — tap a preview to replace it.</p>
      </div>

      <div class="media-zone" style="border-style:dashed;">
        <p style="margin:0;color:var(--ink-dim);font-size:13.5px;line-height:1.6;">
          🎥 <strong>Upload-only feed</strong> — link sharing isn't accepted here. To post a YouTube video,
          <strong>download it as an MP4</strong> and upload it above; it plays instantly and is cropped to the
          vertical reel automatically. This keeps every reel swipeable on all devices.
        </p>
      </div>

      <label for="caption">Caption</label>
      <textarea id="caption" name="caption" placeholder="Write a caption…"><?= old('caption') ?></textarea>

      <label>Categories</label>
      <div class="row three" style="margin-bottom:15px;">
        <?php foreach ($categories as $cat): ?>
          <label style="font-weight:400;display:flex;align-items:center;gap:6px;margin-bottom:0;">
            <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>" style="width:auto;margin:0;"> <?= e($cat['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($user['is_super_admin'])): ?>
        <label for="org_unit_id">Church / Unit</label>
        <select name="org_unit_id" id="org_unit_id">
          <option value="0">— No church / unassigned —</option>
          <?php foreach ($assignableUnits as $au): ?>
            <option value="<?= (int) $au['id'] ?>" <?= (int) ($user['org_unit_id'] ?? 0) === (int) $au['id'] ? 'selected' : '' ?>><?= e($au['name'] . ' (' . $au['type'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint">Choose the church this post belongs to. It will appear on that church's unit page (and roll up to its province/zone).</p>
      <?php else: ?>
        <input type="hidden" name="org_unit_id" value="<?= (int) ($user['org_unit_id'] ?? 0) ?>">
        <p class="hint">This post will belong to <strong><?= e($unitLabels[(int) ($user['org_unit_id'] ?? 0)] ?? 'your church') ?></strong>.</p>
      <?php endif; ?>

      <div class="checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" checked>
        <label for="is_published" style="margin:0;">Publish immediately</label>
      </div>

      <div class="progress-wrap" id="progressWrap" hidden>
        <div class="progress-track"><div class="progress-bar" id="progressBar"></div></div>
        <div class="progress-label" id="progressLabel">Saving…</div>
      </div>

      <div class="btn-row">
        <button class="btn" type="submit" id="publishBtn">Publish Post</button>
        <a class="btn secondary" href="/admin/media">Cancel</a>
      </div>
    </form>
  </div>

  <div class="card" style="max-width:720px;">
    <h2>Add Category</h2>
    <form method="post" action="/admin/media?action=category_create" style="display:flex;gap:10px;align-items:flex-start;">
      <?= Csrf::field() ?>
      <div style="flex:1;"><input type="text" name="name" placeholder="e.g. Missions" required></div>
      <button class="btn secondary" type="submit">Add</button>
    </form>
  </div>

<?php elseif ($action === 'edit' && $editPost): ?>

  <div class="card" style="max-width:720px;">
    <h2>Edit Post #<?= (int) $editPost['id'] ?></h2>
    <form method="post" action="/admin/media?action=edit">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
      <label for="edit_caption">Caption</label>
      <textarea id="edit_caption" name="caption" placeholder="Write a caption…"><?= e((string) $editPost['caption']) ?></textarea>

      <?php if (!empty($user['is_super_admin'])): ?>
        <label for="edit_org_unit_id">Church / Unit</label>
        <select name="org_unit_id" id="edit_org_unit_id">
          <option value="0">— No church / unassigned —</option>
          <?php foreach ($assignableUnits as $au): ?>
            <option value="<?= (int) $au['id'] ?>" <?= (int) ($editPost['org_unit_id'] ?? 0) === (int) $au['id'] ? 'selected' : '' ?>><?= e($au['name'] . ' (' . $au['type'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint">The unit page for this church (and its roll-up) shows this post.</p>
      <?php else: ?>
        <input type="hidden" name="org_unit_id" value="<?= (int) ($editPost['org_unit_id'] ?? 0) ?>">
      <?php endif; ?>

      <label>Categories</label>
      <div class="row three" style="margin-bottom:15px;">
        <?php
        $currentCats = $pdo->prepare('SELECT media_category_id FROM media_post_categories WHERE media_post_id = ?');
        $currentCats->execute([(int) $editPost['id']]);
        $selected = array_map('intval', $currentCats->fetchAll(PDO::FETCH_COLUMN));
        foreach ($categories as $cat):
        ?>
          <label style="font-weight:400;display:flex;align-items:center;gap:6px;margin-bottom:0;">
            <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>" <?= in_array((int) $cat['id'], $selected, true) ? 'checked' : '' ?> style="width:auto;margin:0;"> <?= e($cat['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="checkbox-row">
        <input type="checkbox" id="is_published_edit" name="is_published" <?= $editPost['is_published'] ? 'checked' : '' ?>>
        <label for="is_published_edit" style="margin:0;">Publish</label>
      </div>

      <div class="btn-row">
        <button class="btn" type="submit">Save Changes</button>
        <a class="btn secondary" href="/admin/media">Cancel</a>
      </div>
    </form>
  </div>

  <div class="card" style="max-width:720px;margin-top:16px;">
    <h2>Media Items</h2>
    <p class="sub">Replace files, change video covers, reorder, or remove individual items. Changes appear in the feed immediately.</p>
    <?php if (!$editItems): ?>
      <div class="empty">No media items on this post.</div>
    <?php else: ?>
      <?php foreach ($editItems as $i => $it): ?>
        <?php
          $preview = null;
          if ($it['type'] === 'image') {
              $preview = $it['file_path'];
          } else {
              $preview = $it['thumbnail_path'];
          }
        ?>
        <div style="display:flex;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);">
          <div style="width:74px;height:74px;flex:0 0 74px;border-radius:10px;overflow:hidden;background:#1c1c1c;">
            <?php if ($preview): ?>
              <img src="<?= e(uploadUrl($preview)) ?>" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.visibility='hidden'">
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="margin-bottom:8px;">
              <span class="badge info"><?= e($it['type']) ?></span>
              <span class="badge"><?= $it['source'] === 'youtube' ? 'YouTube' : 'Upload' ?></span>
              <span style="color:var(--ink-faint);font-size:12px;">#<?= (int) $it['id'] ?> · item <?= $i + 1 ?> of <?= count($editItems) ?></span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
              <?php if ($it['source'] === 'upload' || $it['source'] === 'youtube'): ?>
                <form method="post" action="/admin/media?action=replace_item" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
                  <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                  <input type="file" name="file" accept="<?= $it['type'] === 'image' ? 'image/*' : 'video/*' ?>" required style="font-size:12px;max-width:150px;">
                  <button class="btn secondary sm" type="submit"><?= $it['source'] === 'youtube' ? 'Upload MP4 (replace)' : 'Replace' ?></button>
                </form>
                <?php if ($it['type'] === 'video' && $it['source'] === 'upload'): ?>
                  <form method="post" action="/admin/media?action=replace_cover" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                    <input type="file" name="file" accept="image/*" required style="font-size:12px;max-width:130px;">
                    <button class="btn secondary sm" type="submit">New cover</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <form method="post" action="/admin/media?action=move_item" style="display:inline;">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="btn secondary sm" type="submit" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
              </form>
              <form method="post" action="/admin/media?action=move_item" style="display:inline;">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="btn secondary sm" type="submit" title="Move down" <?= $i === count($editItems) - 1 ? 'disabled' : '' ?>>↓</button>
              </form>
              <form method="post" action="/admin/media?action=delete_item" style="display:inline;" onsubmit="return confirm('Remove this media item?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <button class="btn danger sm" type="submit">Delete</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
        <h3 style="margin:0 0 8px;">Add media to this post</h3>
        <form method="post" action="/admin/media?action=add_item" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center;">
          <?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= (int) $editPost['id'] ?>">
          <input type="file" name="file" accept="image/*,video/*" required style="font-size:12px;max-width:210px;">
          <button class="btn" type="submit">Add</button>
        </form>
        <p class="hint">Photos are compressed to webp automatically. Videos are queued for the 9:16 reel crop.</p>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn" href="/admin/media?action=create">+ New Post</a>
  </div>

  <div class="card">
    <?php if (!$posts): ?>
      <div class="empty">No posts yet. Create your first one above.</div>
    <?php else: ?>
      <div id="bulkBar" hidden style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:#ffffff06;margin-bottom:14px;">
        <span id="bulkCount" style="color:var(--ink-dim);font-size:13px;">0 selected</span>
        <form method="post" action="/admin/media?action=bulk_delete" style="display:inline-flex;align-items:center;gap:10px;margin:0;">
          <?= Csrf::field() ?>
          <input type="hidden" name="ids" id="bulkIds" value="">
          <button type="submit" class="btn danger sm" id="bulkDeleteBtn" disabled>Delete selected</button>
        </form>
        <button type="button" class="btn sm secondary" onclick="clearBulkSelection()">Clear</button>
      </div>
      <table>
        <tr><th style="width:34px;"><input type="checkbox" id="bulkSelectAll" title="Select all" aria-label="Select all"></th><th>Cover</th><th>Caption</th><th>Church</th><th>Type</th><th>Status</th><th>Pin</th><th>Likes</th><th>Views</th><th>Saves</th><th>Posted</th><th></th></tr>
        <?php foreach ($posts as $p): ?>
        <tr>
          <td><input type="checkbox" class="bulk-check" value="<?= (int) $p['id'] ?>" aria-label="Select post <?= (int) $p['id'] ?>"></td>
          <td>
            <?php
            $coverSrc = null;
            if ($p['cover']) {
                if ($p['cover_type'] === 'video') {
                    $coverSrc = $p['cover_source'] ?: $p['cover_thumb'];
                } else {
                    $coverSrc = $p['cover'];
                }
            }
            ?>
            <?php if ($coverSrc): ?>
              <img class="thumb" src="<?= e(uploadUrl($coverSrc)) ?>" alt="" onerror="this.style.visibility='hidden'">
            <?php else: ?><div class="thumb"></div><?php endif; ?>
          </td>
          <td><?= e(mb_strimwidth((string) $p['caption'], 0, 50, '…')) ?: '<em>No caption</em>' ?></td>
          <td>
            <?php if (!empty($p['org_unit_id'])): ?>
              <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $p['org_unit_id']] ?? '') ?></span>
            <?php else: ?>
              <span class="badge warn">Unassigned</span>
            <?php endif; ?>
            <div style="margin-top:6px;">
              <?php
                $reassignId = (int) $p['id'];
                $reassignUnitId = !empty($p['org_unit_id']) ? (int) $p['org_unit_id'] : null;
                $showUnassignedOnly = false;
                $assignAction = '/admin/media?action=reassign';
                require __DIR__ . '/partials/unit-assign.php';
              ?>
            </div>
          </td>
          <td>
            <span class="badge info"><?= e(str_replace('_', ' ', $p['post_type'])) ?></span>
            <?php if ($p['video_path']): ?>
              <?php $vstatus = videoConversionStatus(['type' => 'video', 'source' => 'upload', 'file_path' => (string) $p['video_path'], 'converted_at' => $p['video_converted_at']]); ?>
              <?php if ($vstatus === 'converted'): ?>
                <span class="badge ok" title="Crop finished <?= e($p['video_converted_at']) ?>">converted</span>
              <?php elseif ($vstatus === 'pending'): ?>
                <span class="badge warn" title="Still stored as the original; conversion queued">converting…</span>
              <?php else: ?>
                <span class="badge" title="Plays the original video as-is">original</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="/admin/media?action=toggle" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="badge <?= $p['is_published'] ? 'ok' : 'warn' ?>" style="border:none;cursor:pointer;">
                <?= $p['is_published'] ? 'published' : 'draft' ?>
              </button>
            </form>
          </td>
          <td>
            <?php
              $pinnedActive = !empty($p['is_pinned']) && !empty($p['pinned_expires_at']) && strtotime((string) $p['pinned_expires_at']) > time();
              $expiryLabel = $pinnedActive ? date('M j, g:ia', strtotime((string) $p['pinned_expires_at'])) : '';
            ?>
            <?php if ($pinnedActive): ?>
              <span class="badge" style="background:#e8b95f22;color:var(--gold-soft);" title="Pinned until <?= e($expiryLabel) ?>">📌 pinned</span>
              <div style="color:var(--ink-faint);font-size:11px;margin-top:4px;">until <?= e($expiryLabel) ?></div>
              <form method="post" action="/admin/media?action=unpin" style="display:inline;margin-top:4px;">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn sm secondary">Unpin</button>
              </form>
            <?php else: ?>
              <form method="post" action="/admin/media?action=pin" style="display:inline;">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn sm" <?= !$p['is_published'] ? 'disabled title="Publish the post first"' : '' ?>>📌 Pin</button>
              </form>
            <?php endif; ?>
          </td>
          <td><?= (int) $p['likes_count'] ?></td>
          <td><?= (int) $p['views_count'] ?></td>
          <td><?= (int) $p['saves_count'] ?></td>
          <td><?= e(timeAgo($p['created_at'])) ?></td>
          <td style="white-space:nowrap;">
            <?php if ($p['video_path'] && ($vstatus ?? '') === 'pending'): ?>
              <form method="post" action="/admin/media?action=reprocess" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='…';">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn sm">Process</button>
              </form>
            <?php endif; ?>
            <a class="btn sm secondary" href="/admin/media?action=edit&id=<?= (int) $p['id'] ?>">Edit</a>
            <form method="post" action="/admin/media?action=delete" onsubmit="return confirm('Delete this post permanently?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<script>
(function () {
  var all = document.getElementById('bulkSelectAll');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.bulk-check'));
  var bar = document.getElementById('bulkBar');
  var countEl = document.getElementById('bulkCount');
  var idsEl = document.getElementById('bulkIds');
  var btn = document.getElementById('bulkDeleteBtn');
  var form = btn ? btn.closest('form') : null;

  function update() {
    var sel = checks.filter(function (c) { return c.checked; });
    countEl.textContent = sel.length + ' selected';
    idsEl.value = sel.map(function (c) { return c.value; }).join(',');
    btn.disabled = sel.length === 0;
    bar.hidden = sel.length === 0;
  }

  checks.forEach(function (c) { c.addEventListener('change', update); });

  if (all) {
    all.addEventListener('change', function () {
      checks.forEach(function (c) { c.checked = all.checked; });
      update();
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      var n = checks.filter(function (c) { return c.checked; }).length;
      if (!window.confirm('Delete ' + n + ' post' + (n === 1 ? '' : 's') + ' permanently? This cannot be undone.')) {
        e.preventDefault();
      }
    });
  }

  window.clearBulkSelection = function () {
    if (all) all.checked = false;
    checks.forEach(function (c) { c.checked = false; });
    update();
  };

  update();
})();
</script>

<?php if ($action === 'create'): ?>
  <script src="<?= asset('js/admin-media.js') ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
