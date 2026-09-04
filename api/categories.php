<?php
declare(strict_types=1);

/** GET /api/categories — categories that have at least one published post (used for feed filter chips). */

$pdo = Database::getInstance()->getConnection();
$rows = $pdo->query('
    SELECT c.id, c.name, c.slug
    FROM media_categories c
    WHERE EXISTS (
        SELECT 1 FROM media_post_categories mpc
        JOIN media_posts p ON p.id = mpc.media_post_id
        WHERE mpc.media_category_id = c.id AND p.is_published = 1
    )
    ORDER BY c.name ASC
')->fetchAll();

foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
}
unset($row);

jsonResponse(['status' => 'success', 'data' => $rows]);
