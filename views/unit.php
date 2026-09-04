<?php
declare(strict_types=1);
/** @var string $slug */

$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare('SELECT * FROM org_units WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$unit = $stmt->fetch();
if (!$unit) {
    http_response_code(404);
    render('404', [], false);
    return;
}

$path = Unit::path((int) $unit['id']);
$subtree = Unit::subtreeIds((int) $unit['id']);
$subIn = implode(',', array_map('intval', $subtree));
$unitCategories = $pdo->query("SELECT DISTINCT c.slug, c.name FROM media_categories c JOIN media_post_categories mpc ON mpc.media_category_id = c.id JOIN media_posts p ON p.id = mpc.media_post_id WHERE p.is_published = 1 AND p.org_unit_id IN ($subIn) ORDER BY c.name ASC")->fetchAll();
$metaTitle = $unit['name'] . ' · Media';
$metaDescription = 'Browse all media from ' . $unit['name'] . '.';
?>
<link rel="stylesheet" href="<?= asset('css/unit.css') ?>">

<div class="unit-page">
  <header class="unit-hero">
    <a class="unit-back" href="/">&larr; Home</a>
    <p class="unit-eyebrow"><?= e($unit['type']) ?></p>
    <h1 class="unit-name"><?= e($unit['name']) ?></h1>
    <nav class="unit-breadcrumb" aria-label="Breadcrumb">
      <?php foreach ($path as $i => $u): ?>
        <a href="/unit/<?= e($u['slug']) ?>"><?= e($u['name']) ?></a>
        <?php if ($i < count($path) - 1): ?><span>/</span><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="unit-controls">
      <button type="button" class="btn" id="unitShuffle">🔀 Shuffle: On</button>
      <span class="unit-count" id="unitCount"></span>
    </div>
  </header>
  <?php if ($unitCategories): ?>
  <div class="unit-chips" id="unitChips">
    <button type="button" class="unit-chip active" data-category="">All</button>
    <?php foreach ($unitCategories as $cat): ?>
      <button type="button" class="unit-chip" data-category="<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="unit-grid" id="unitGrid" data-slug="<?= e($unit['slug']) ?>">
    <div class="unit-loading">Loading media…</div>
  </div>
</div>

<script src="<?= asset('js/unit.js') ?>"></script>
