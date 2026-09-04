<?php
declare(strict_types=1);
$metaTitle = 'Sermons';
$pdo = Database::getInstance()->getConnection();
$series = trim((string) ($_GET['series'] ?? ''));

$allSeries = $pdo->query('SELECT DISTINCT series FROM sermons WHERE is_published = 1 AND series IS NOT NULL AND series != "" ORDER BY series ASC')->fetchAll(PDO::FETCH_COLUMN);

if ($series !== '') {
    $stmt = $pdo->prepare('SELECT * FROM sermons WHERE is_published = 1 AND series = ? ORDER BY published_at DESC LIMIT 60');
    $stmt->execute([$series]);
} else {
    $stmt = $pdo->query('SELECT * FROM sermons WHERE is_published = 1 ORDER BY published_at DESC LIMIT 60');
}
$sermons = $stmt->fetchAll();
?>

<section class="section" style="padding-top:56px;">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">The Word</span>
      <h2>Sermons</h2>
      <p>Catch up on past messages, by series or speaker.</p>
    </div>

    <?php if ($allSeries): ?>
      <div class="chip-row">
        <a href="/sermons" class="chip <?= $series === '' ? 'active' : '' ?>">All</a>
        <?php foreach ($allSeries as $s): ?>
          <a href="/sermons?series=<?= urlencode($s) ?>" class="chip <?= $series === $s ? 'active' : '' ?>"><?= e($s) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$sermons): ?>
      <div class="empty-state">No sermons published yet.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($sermons as $sm): ?>
          <a href="/sermons/<?= e($sm['slug']) ?>" class="glass-card info-card reveal in">
            <div class="cover">
              <?php if ($sm['cover_image']): ?><img src="<?= e(uploadUrl($sm['cover_image'])) ?>" alt="" loading="lazy"><?php endif; ?>
            </div>
            <div class="body">
              <h3><?= e($sm['title']) ?></h3>
              <div class="meta">
                <?php if ($sm['speaker']): ?><span><?= e($sm['speaker']) ?></span><?php endif; ?>
                <span><?= e(date('M j, Y', strtotime($sm['published_at']))) ?></span>
              </div>
              <?php if ($sm['scripture_ref']): ?><p style="color:var(--gold-soft); font-size:12.5px; margin:0;"><?= e($sm['scripture_ref']) ?></p><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
