<?php
declare(strict_types=1);

/** GET /api/units — the Province → Zone → Area → Parish hierarchy (flat, with full labels). */

$labels = Unit::labelsById();
$data = array_map(function (array $u) use ($labels): array {
    return [
        'id' => (int) $u['id'],
        'parent_id' => $u['parent_id'] !== null ? (int) $u['parent_id'] : null,
        'type' => $u['type'],
        'name' => $u['name'],
        'slug' => $u['slug'],
        'label' => $labels[(int) $u['id']] ?? $u['name'],
    ];
}, Unit::all());

jsonResponse(['status' => 'success', 'data' => $data]);
