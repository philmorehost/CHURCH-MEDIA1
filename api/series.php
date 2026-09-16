<?php
declare(strict_types=1);

/**
 * GET /api/series — the sermon series, for the website and the mobile app.
 * GET /api/series?slug=... — one series together with its episodes, in episode order.
 *
 * Read-only and published-only. It goes through Series::all() rather than querying the table
 * directly, so tenant scoping is handled in one place instead of being re-implemented here.
 *
 * The website reaches this data through the views, but the app has no other way to learn that an
 * episode belongs to a series, or what order the episodes come in.
 */

$pdo = Database::getInstance()->getConnection();

/** Shaped once, used by both the list and the single-series response. */
$shapeSeries = static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['is_published'] = !empty($row['is_published']);
    $row['sermon_count'] = (int) ($row['sermon_count'] ?? 0);
    $row['cover_image_url'] = uploadUrl($row['cover_image'] ?? null);
    $row['url'] = baseUrl('/series/' . $row['slug']);
    $row['latest_at'] = $row['latest_at'] ?? null;

    unset($row['cover_image']);

    return $row;
};

/** Matches the shape api/sermons.php returns, so a client can reuse one model for both. */
$shapeEpisode = static function (array $row, string $seriesSlug): array {
    $row['id'] = (int) $row['id'];
    $row['series_id'] = !empty($row['series_id']) ? (int) $row['series_id'] : null;
    $row['series_position'] = isset($row['series_position']) && $row['series_position'] !== null
        ? (int) $row['series_position']
        : null;
    $row['duration_seconds'] = !empty($row['duration_seconds']) ? (int) $row['duration_seconds'] : null;
    $row['is_explicit'] = !empty($row['is_explicit']);
    $row['published_at'] = (string) $row['published_at'];

    // Every episode here belongs to the series being requested, so the slug is passed in rather
    // than looked up. It keeps the episode shape identical to api/sermons.php.
    $row['series_slug'] = $seriesSlug;

    // The external link arrives under two different names depending on where the row came from:
    // Series::sermons() does SELECT *, so the column is still called audio_url, whereas a query
    // that selects explicit columns aliases it to keep it out of the way. Accepting either is
    // what stops this from silently falling back to the uploaded file and playing nothing.
    $external = trim((string) ($row['audio_external'] ?? $row['audio_url'] ?? ''));
    $row['audio_url'] = $external !== '' ? $external : uploadUrl($row['audio_path'] ?? null);
    $row['audio_source'] = $external !== '' ? 'external' : (!empty($row['audio_path']) ? 'upload' : null);
    $row['cover_image_url'] = uploadUrl($row['cover_image'] ?? null);

    unset($row['cover_image'], $row['audio_path'], $row['audio_external']);

    return $row;
};

$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug !== '') {
    $series = Series::findBySlug($slug);

    if ($series === null || empty($series['is_published'])) {
        jsonResponse(['status' => 'error', 'message' => 'Series not found.'], 404);
    }

    // Series::sermons() orders by episode number with the unnumbered ones falling back to date,
    // so the app gets the same order the website and the podcast feed show.
    $episodes = Series::sermons((int) $series['id'], true, 300);
    $seriesSlug = (string) $series['slug'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sermons WHERE series_id = ? AND is_published = 1');
    $stmt->execute([(int) $series['id']]);
    $series['sermon_count'] = (int) $stmt->fetchColumn();

    jsonResponse([
        'status' => 'success',
        'data' => [
            'series' => $shapeSeries($series),
            'episodes' => array_map(
                static fn (array $e): array => $shapeEpisode($e, $seriesSlug),
                $episodes
            ),
        ],
    ]);
}

$series = Series::all([], true);

jsonResponse([
    'status' => 'success',
    'data' => array_map($shapeSeries, $series),
]);
