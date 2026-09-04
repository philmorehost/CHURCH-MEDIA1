<?php
declare(strict_types=1);

/** GET /api/events?scope=upcoming|past&page=1, or ?slug=... for a single event — church events for web + apps. */

$pdo = Database::getInstance()->getConnection();

if ($slug = trim((string) ($_GET['slug'] ?? ''))) {
    $stmt = $pdo->prepare('SELECT id, title, slug, description, cover_image, start_at, end_at, location, rsvp_enabled, rsvp_url FROM events WHERE slug = ? AND is_published = 1');
    $stmt->execute([$slug]);
    $event = $stmt->fetch();
    if (!$event) {
        jsonResponse(['status' => 'error', 'message' => 'Event not found.'], 404);
    }
    $event['id'] = (int) $event['id'];
    $event['rsvp_enabled'] = (bool) $event['rsvp_enabled'];
    $event['cover_image_url'] = uploadUrl($event['cover_image']);
    unset($event['cover_image']);
    jsonResponse(['status' => 'success', 'data' => $event]);
}

$scope = ($_GET['scope'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(30, max(1, (int) ($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;

$order = $scope === 'upcoming' ? 'ASC' : 'DESC';
$comparator = $scope === 'upcoming' ? '>=' : '<';

$stmt = $pdo->prepare("
    SELECT id, title, slug, description, cover_image, start_at, end_at, location, rsvp_enabled, rsvp_url
    FROM events
    WHERE is_published = 1 AND start_at $comparator NOW()
    ORDER BY start_at $order
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue('limit', $perPage + 1, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

$hasMore = count($events) > $perPage;
$events = array_slice($events, 0, $perPage);

foreach ($events as &$event) {
    $event['id'] = (int) $event['id'];
    $event['rsvp_enabled'] = (bool) $event['rsvp_enabled'];
    $event['cover_image_url'] = uploadUrl($event['cover_image']);
    unset($event['cover_image']);
}
unset($event);

jsonResponse(['status' => 'success', 'scope' => $scope, 'page' => $page, 'has_more' => $hasMore, 'data' => $events]);
