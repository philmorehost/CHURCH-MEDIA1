<?php
declare(strict_types=1);

/**
 * GET /api/cells — the home cells the public finder shows, for the app.
 *
 * Optional filters, matching the website: ?q= free text, ?area=<unit id> to restrict to one branch.
 *
 * **The leader's number is omitted unless the cell leader's number was marked publishable.** The
 * flag exists because a personal number on a public page gets copied and called; an API is a second
 * public surface, so it has to honour the same rule. Returning it here "for the app" would quietly
 * undo the choice the church made on the web form.
 */

$query = trim((string) ($_GET['q'] ?? ''));
$areaId = (int) ($_GET['area'] ?? 0);

$cells = HomeCell::search($query, $areaId > 0 ? $areaId : null);

$text = static function ($value): ?string {
    $value = $value !== null ? trim((string) $value) : '';
    return $value === '' ? null : $value;
};

$data = array_map(static function (array $cell) use ($text): array {
    // Both conditions: allowed, and there is actually a number to show.
    $phone = !empty($cell['leader_phone_public']) ? $text($cell['leader_phone'] ?? null) : null;

    return [
        'id' => (int) $cell['id'],
        'name' => (string) $cell['name'],
        'slug' => $text($cell['slug'] ?? null),
        'path_label' => (string) ($cell['path_label'] ?? ''),
        'meeting_day' => $text($cell['meeting_day'] ?? null),
        'meeting_time' => $text($cell['meeting_time'] ?? null),
        'meeting_address' => $text($cell['meeting_address'] ?? null),
        'leader_name' => $text($cell['leader_name'] ?? null),
        'leader_phone' => $phone,
        'leader_phone_display' => $phone !== null ? Phone::display($phone) : null,
        'capacity' => $cell['capacity'] !== null ? (int) $cell['capacity'] : null,
    ];
}, $cells);

jsonResponse(['status' => 'success', 'data' => $data]);
