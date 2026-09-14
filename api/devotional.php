<?php
declare(strict_types=1);

/**
 * GET /api/devotional              the entry for today
 * GET /api/devotional?date=Y-m-d   a specific day
 * GET /api/devotional?unit=slug    that church's entry, ahead of the church-wide one
 * GET /api/devotional?limit=5      how many past entries to list
 *
 * Serves the app's home card and its "read today's devotional" screen. The website answers the
 * same question in views/devotional.php, and the two must not disagree: both go through
 * Devotional, so the rule about a church's own entry beating the church-wide one is applied in
 * one place.
 *
 * **What `unit` does.** Passing the church the reader belongs to makes its own entry win over the
 * church-wide one for the same day, which is what `Devotional::between()` decides for the website
 * too. Omitting it, or passing a slug that no longer exists, means "no filtering" — the reader
 * still gets a devotional rather than an empty card, which is the right failure for a home screen.
 *
 * `data` is null when the day has nothing published. That is a normal answer, not an error: a
 * church that has not written one today has not broken anything, and the app should show its
 * empty state rather than an error.
 */

$pdo = Database::getInstance()->getConnection();

$requested = trim((string) ($_GET['date'] ?? ''));
if ($requested === '') {
    $requested = date('Y-m-d');
}

// A malformed date is refused rather than quietly swapped for today: silently answering a
// different question than the one asked is how a cached permalink starts showing the wrong entry.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) || strtotime($requested) === false) {
    jsonResponse(['status' => 'error', 'message' => 'date must be in YYYY-MM-DD form.'], 400);
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 14;
$limit = max(1, min(50, $limit));

$unitId = Devotional::ALL_UNITS;
$unitSlug = trim((string) ($_GET['unit'] ?? ''));
if ($unitSlug !== '') {
    $found = Unit::findBySlug($unitSlug);
    if ($found !== null) {
        $unitId = (int) $found['id'];
    }
}

/**
 * Shapes a row for the response.
 *
 * `body` is sent whole rather than trimmed: the app renders it on its own screen, where the
 * reader has come specifically to read it. The notification, which has one line to work with,
 * trims it separately in DevotionalPush.
 */
$shape = static function (array $row): array {
    return array(
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'scripture_reference' => (string) ($row['scripture_reference'] ?? ''),
        'scripture_text' => (string) ($row['scripture_text'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'publish_on' => (string) $row['publish_on'],
        'unit_id' => (int) $row['org_unit_id'],
        'audio_url' => uploadUrl($row['audio_path'] ?? null),
        // Lets the app offer "listen to the sermon this came from" when it was generated from one.
        'sermon_id' => !empty($row['sermon_id']) ? (int) $row['sermon_id'] : null,
    );
};

$entry = Devotional::forDate($requested, $unitId);

$recent = array();
foreach (Devotional::latest($limit, $unitId) as $row) {
    // The day being asked about is already in `data`; repeating it in the list makes the app
    // render the same entry twice.
    if ((string) $row['publish_on'] === $requested) {
        continue;
    }
    $recent[] = array(
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'publish_on' => (string) $row['publish_on'],
        'scripture_reference' => (string) ($row['scripture_reference'] ?? ''),
    );
}

jsonResponse(array(
    'status' => 'success',
    'date' => $requested,
    'is_today' => $requested === date('Y-m-d'),
    'data' => $entry !== null ? $shape($entry) : null,
    'recent' => $recent,
));
