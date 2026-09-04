<?php
declare(strict_types=1);

/** GET /api/team — published team/leadership members, for the About page. */

$pdo = Database::getInstance()->getConnection();
$rows = $pdo->query('SELECT id, name, role_title, photo, bio FROM team_members WHERE is_published = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();

foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['photo_url'] = uploadUrl($row['photo']);
    unset($row['photo']);
}
unset($row);

jsonResponse(['status' => 'success', 'data' => $rows]);
