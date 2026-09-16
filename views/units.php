<?php
declare(strict_types=1);
$metaTitle = 'Find Your Parish';
$metaDescription = 'Browse media by parish, area, zone, and province. Tap a church to see all its media.';

$pdo = Database::getInstance()->getConnection();
$tree = Unit::tree();

// Direct published-post counts per unit, then roll each up across its subtree.
$direct = [];
foreach ($pdo->query('SELECT org_unit_id, COUNT(*) AS c FROM media_posts WHERE is_published = 1 AND org_unit_id IS NOT NULL GROUP BY org_unit_id')->fetchAll() as $row) {
    $direct[(int) $row['org_unit_id']] = (int) $row['c'];
}
$countOf = function (array &$node) use (&$countOf, $direct): int {
    $total = $direct[(int) $node['id']] ?? 0;
    foreach ($node['children'] as &$child) {
        $total += $countOf($child);
    }
    $node['count'] = $total;
    return $total;
};
foreach ($tree as &$node) {
    $countOf($node);
}
unset($node);
?>
<link rel="stylesheet" href="<?= asset('css/units.css') ?>">

<div class="units-page">
  <header class="units-hero">
    <p class="units-eyebrow"><?= e(setting('site_title')) ?></p>
    <h1>Find Your Parish</h1>
    <p class="units-sub">Tap a church to see all its media — images and videos, mixed together.</p>
  </header>

  <?php if (!$tree): ?>
    <p class="units-sub" style="text-align:center;padding:40px 0;">No parishes set up yet.</p>
  <?php else: ?>
    <?php
    $render = function (array $node, int $depth = 0) use (&$render): void {
        $tag = $depth === 0 ? 'h2' : ($depth === 1 ? 'h3' : ($depth === 2 ? 'h4' : 'h5'));
        echo '<div class="unit-item depth-' . $depth . '">';
        echo '<' . $tag . ' class="unit-heading"><a href="/unit/' . e($node['slug']) . '">' . e($node['name']) . '</a>';
        if (!empty($node['count'])) {
            echo ' <span class="unit-count">' . (int) $node['count'] . '</span>';
        }
        echo '</' . $tag . '>';
        if (!empty($node['children'])) {
            echo '<div class="unit-children">';
            foreach ($node['children'] as $child) {
                $render($child, $depth + 1);
            }
            echo '</div>';
        }
        echo '</div>';
    };
    foreach ($tree as $node) {
        $render($node);
    }
    ?>
  <?php endif; ?>
</div>
