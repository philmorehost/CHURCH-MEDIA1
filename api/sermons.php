<?php
declare(strict_types=1);

/** GET /api/sermons?page=1&series=...&speaker=..., or ?slug=... for a single sermon — for web + apps. */

$pdo = Database::getInstance()->getConnection();

if ($slug = trim((string) ($_GET['slug'] ?? ''))) {
    $stmt = $pdo->prepare('SELECT id, title, slug, speaker, series, scripture_ref, description, audio_path, video_embed_url, cover_image, published_at FROM sermons WHERE slug = ? AND is_published = 1');
    $stmt->execute([$slug]);
    $sermon = $stmt->fetch();
    if (!$sermon) {
        jsonResponse(['status' => 'error', 'message' => 'Sermon not found.'], 404);
    }
    $sermon['id'] = (int) $sermon['id'];
    $sermon['cover_image_url'] = uploadUrl($sermon['cover_image']);
    $sermon['audio_url'] = uploadUrl($sermon['audio_path']);
    unset($sermon['cover_image'], $sermon['audio_path']);
    jsonResponse(['status' => 'success', 'data' => $sermon]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(30, max(1, (int) ($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;

$where = ['is_published = 1'];
$params = [];
if ($series = trim((string) ($_GET['series'] ?? ''))) {
    $where[] = 'series = :series';
    $params['series'] = $series;
}
if ($speaker = trim((string) ($_GET['speaker'] ?? ''))) {
    $where[] = 'speaker = :speaker';
    $params['speaker'] = $speaker;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT id, title, slug, speaker, series, scripture_ref, description, audio_path, video_embed_url, cover_image, published_at
    FROM sermons WHERE $whereSql
    ORDER BY published_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue('limit', $perPage + 1, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$sermons = $stmt->fetchAll();

$hasMore = count($sermons) > $perPage;
$sermons = array_slice($sermons, 0, $perPage);

foreach ($sermons as &$sermon) {
    $sermon['id'] = (int) $sermon['id'];
    $sermon['cover_image_url'] = uploadUrl($sermon['cover_image']);
    $sermon['audio_url'] = uploadUrl($sermon['audio_path']);
    unset($sermon['cover_image'], $sermon['audio_path']);
}
unset($sermon);

jsonResponse(['status' => 'success', 'page' => $page, 'has_more' => $hasMore, 'data' => $sermons]);
