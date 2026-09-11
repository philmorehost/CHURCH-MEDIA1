<?php
declare(strict_types=1);

/**
 * GET /api/units — the configured church hierarchy (flat, with full labels).
 *
 * Levels are whatever the super admin has set up (by default
 * Province → Zone → Area → Parish), so clients should render the tree from
 * `parent_id`/`type` rather than assuming a fixed depth.
 */

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

jsonResponse(['status' => 'success', 'levels' => Unit::levels(), 'data' => $data]);