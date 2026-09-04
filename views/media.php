<?php
declare(strict_types=1);
$metaTitle = 'Media Gallery';
$metaDescription = 'All media — images and videos — from every parish, area, and zone, in one place.';

$pdo = Database::getInstance()->getConnection();
$categories = $pdo->query('SELECT c.slug, c.name FROM media_categories c WHERE EXISTS (SELECT 1 FROM media_post_categories mpc JOIN media_posts p ON p.id = mpc.media_post_id WHERE mpc.media_category_id = c.id AND p.is_published = 1) ORDER BY c.name ASC')->fetchAll();
?>
<link rel="stylesheet" href="<?= asset('css/unit.css') ?>">

<div class="unit-page">
  <header class="unit-hero">
    <p class="unit-eyebrow">All Media</p>
    <h1 class="unit-name">Media Gallery</h1>
    <?php if ($categories): ?>
    <div class="unit-chips" id="unitChips">
      <button type="button" class="unit-chip active" data-category="">All</button>
      <?php foreach ($categories as $cat): ?>
        <button type="button" class="unit-chip" data-category="<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="unit-controls">
      <button type="button" class="btn" id="unitShuffle">🔀 Shuffle: On</button>
      <span class="unit-count" id="unitCount"></span>
    </div>
  </header>
  <!-- empty data-slug = fetch the whole gallery via /api/media -->
  <div class="unit-grid" id="unitGrid" data-slug="">
    <div class="unit-loading">Loading media…</div>
  </div>
</div>

<script src="<?= asset('js/unit.js') ?>"></script>
