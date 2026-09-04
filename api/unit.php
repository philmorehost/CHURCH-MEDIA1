<?php
declare(strict_types=1);

/** GET /api/unit?slug=...&shuffle=1&per_page=60 — one unit's info + all media beneath it (roll-up). */

$pdo = Database::getInstance()->getConnection();
$slug = trim((string) ($_GET['slug'] ?? ''));
$shuffle = !empty($_GET['shuffle']) && $_GET['shuffle'] === '1';

$stmt = $pdo->prepare('SELECT * FROM org_units WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$unit = $stmt->fetch();
if (!$unit) {
    jsonResponse(['status' => 'error', 'message' => 'Unit not found.'], 404);
}

$unit['id'] = (int) $unit['id'];
$unit['parent_id'] = $unit['parent_id'] !== null ? (int) $unit['parent_id'] : null;
$unit['path'] = array_map(fn (array $u): array => [
    'id' => (int) $u['id'],
    'type' => $u['type'],
    'name' => $u['name'],
    'slug' => $u['slug'],
], Unit::path((int) $unit['id']));
$unit['label'] = implode(' · ', array_column($unit['path'], 'name'));

$unitIds = Unit::subtreeIds((int) $unit['id']);
$in = implode(',', array_fill(0, count($unitIds), '?'));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 60)));
// Pinned ordering only kicks in once the pinned columns exist (migration).
$pinnedCols = mediaPinnedColumnsExist($pdo);
$pinnedSelect = $pinnedCols ? ', p.is_pinned, p.pinned_at, p.pinned_expires_at' : '';
$pinnedExpr = $pinnedCols ? '(p.is_pinned = 1 AND p.pinned_expires_at > NOW()) DESC, ' : '';
$order = $shuffle
    ? ($pinnedExpr . 'RAND()')
    : ($pinnedExpr . 'p.pinned_at ASC, p.created_at DESC');
$categorySlug = trim((string) ($_GET['category'] ?? ''));

$where = "p.is_published = 1 AND p.org_unit_id IN ($in)";
$bind = $unitIds;
if ($categorySlug !== '') {
    $where .= " AND EXISTS (SELECT 1 FROM media_post_categories mpc JOIN media_categories c ON c.id = mpc.media_category_id WHERE mpc.media_post_id = p.id AND c.slug = ?)";
    $bind[] = $categorySlug;
}

$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.caption, p.post_type, p.created_at$pinnedSelect, u.name AS author_name, u.username AS author_username,
      (SELECT COUNT(*) FROM post_comments pc WHERE pc.media_post_id = p.id AND pc.is_published = 1) AS comments_count
    FROM media_posts p JOIN users u ON u.id = p.user_id
    WHERE $where
    ORDER BY $order
    LIMIT ?
");
foreach ($bind as $i => $v) {
    $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(count($bind) + 1, $perPage, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$itemStmt = $pdo->prepare('SELECT type, source, file_path, thumbnail_path, alt_text, processing_status, converted_at FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC');
$catStmt = $pdo->prepare('SELECT c.id, c.name, c.slug FROM media_categories c JOIN media_post_categories mpc ON mpc.media_category_id = c.id WHERE mpc.media_post_id = ?');

foreach ($posts as &$post) {
    $itemStmt->execute([$post['id']]);
    $post['media_items'] = array_map(function (array $item): array {
        $item['file_url'] = uploadUrl($item['file_path']);
        $item['thumbnail_url'] = uploadUrl($item['thumbnail_path']);
        $item['conversion_status'] = videoConversionStatus($item);
        unset($item['file_path'], $item['thumbnail_path']);
        return $item;
    }, $itemStmt->fetchAll());
    $catStmt->execute([$post['id']]);
    $post['categories'] = $catStmt->fetchAll();
    $post['id'] = (int) $post['id'];
    $post['comments_count'] = (int) $post['comments_count'];
    if ($pinnedCols) {
        // Emulated prepares return TINYINT as a string; the app needs a bool.
        $post['is_pinned'] = (bool) $post['is_pinned'];
    }
}
unset($post);

jsonResponse(['status' => 'success', 'unit' => $unit, 'data' => $posts]);
