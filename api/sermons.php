<?php
declare(strict_types=1);

/**
 * GET /api/sermons?page=1&series=...&speaker=..., or ?slug=... for a single sermon.
 *
 * Serves the website and the mobile app. Two things here need care.
 *
 * **`audio_url` is an output field and a column name at the same time.** The column holds an
 * external link (a podcast host, say) and the response field holds a playable URL. Selecting the
 * column straight into the response meant the field was overwritten with the uploaded file's URL,
 * so an episode published with only an external link reached the app with no audio at all. The
 * column is now selected under another name and the two are resolved deliberately.
 *
 * **`?series=` used to be the free-text name on the sermon.** It now accepts a series slug too,
 * because that is what a link carries. Both are tried, so an old client and a new one keep
 * working against the same endpoint.
 */

$pdo = Database::getInstance()->getConnection();

/**
 * Shapes a raw row for the response.
 *
 * `series` stays a plain string because the app renders it directly; the structured fields are
 * added alongside it rather than replacing it.
 */
$shape = static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['series_id'] = !empty($row['series_id']) ? (int) $row['series_id'] : null;
    $row['series_position'] = isset($row['series_position']) && $row['series_position'] !== null
        ? (int) $row['series_position']
        : null;
    $row['duration_seconds'] = !empty($row['duration_seconds']) ? (int) $row['duration_seconds'] : null;
    $row['is_explicit'] = !empty($row['is_explicit']);
    $row['published_at'] = (string) $row['published_at'];

    // The slug of the owning series, so the app can open the series from an episode. A subquery
    // rather than a join, because the WHERE clauses below are written against an unaliased
    // table and joining would mean prefixing every column in them.
    $row['series_slug'] = $row['series_slug'] ?? null;

    $external = trim((string) ($row['audio_external'] ?? ''));

    // An external link wins over an uploaded file, the same rule the feed and the website player
    // use. The three disagreeing about which audio to play would be worse than either choice.
    $row['audio_url'] = $external !== '' ? $external : uploadUrl($row['audio_path'] ?? null);
    $row['audio_source'] = $external !== '' ? 'external' : (!empty($row['audio_path']) ? 'upload' : null);

    $row['cover_image_url'] = uploadUrl($row['cover_image'] ?? null);

    unset($row['cover_image'], $row['audio_path'], $row['audio_external']);

    return $row;
};

$columns = 'id, title, slug, speaker, series, series_id, series_position, scripture_ref, description, '
    . 'audio_path, audio_url AS audio_external, duration_seconds, episode_guid, is_explicit, '
    . 'video_embed_url, cover_image, published_at, '
    . '(SELECT ss.slug FROM sermon_series ss WHERE ss.id = sermons.series_id) AS series_slug';

if ($slug = trim((string) ($_GET['slug'] ?? ''))) {
    $stmt = $pdo->prepare("SELECT $columns FROM sermons WHERE slug = ? AND is_published = 1");
    $stmt->execute([$slug]);
    $sermon = $stmt->fetch();
    if (!$sermon) {
        jsonResponse(['status' => 'error', 'message' => 'Sermon not found.'], 404);
    }
    jsonResponse(['status' => 'success', 'data' => $shape($sermon)]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(30, max(1, (int) ($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;

$where = ['is_published = 1'];
$params = [];

if ($series = trim((string) ($_GET['series'] ?? ''))) {
    // A slug is the new way in; the free-text name is still accepted for anything already
    // shipped to the app or sitting in someone's bookmarks.
    $lookup = $pdo->prepare('SELECT id FROM sermon_series WHERE slug = ? LIMIT 1');
    $lookup->execute([$series]);
    $resolvedSeriesId = (int) $lookup->fetchColumn();

    if ($resolvedSeriesId > 0) {
        $where[] = 'series_id = :series_id';
        $params['series_id'] = $resolvedSeriesId;
    } else {
        $where[] = 'series = :series';
        $params['series'] = $series;
    }
}

if ($seriesIdParam = (int) ($_GET['series_id'] ?? 0)) {
    $where[] = 'series_id = :series_id_only';
    $params['series_id_only'] = $seriesIdParam;
}

if ($speaker = trim((string) ($_GET['speaker'] ?? ''))) {
    $where[] = 'speaker = :speaker';
    $params['speaker'] = $speaker;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT $columns
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

jsonResponse([
    'status' => 'success',
    'page' => $page,
    'has_more' => $hasMore,
    'data' => array_map($shape, $sermons),
]);
