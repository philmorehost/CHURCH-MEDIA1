<?php
declare(strict_types=1);
$metaTitle = 'Sermons';
$pdo = Database::getInstance()->getConnection();

// ?series= is either a series slug (the new table) or, for sermons added before series existed,
// the free-text name they still carry. Both are honoured so no old link stops working.
$seriesParam = trim((string) ($_GET['series'] ?? ''));
$activeSeries = $seriesParam !== '' ? Series::findBySlug($seriesParam) : null;
$activeText = ($activeSeries === null && $seriesParam !== '') ? $seriesParam : '';

$seriesRows = Series::all([], true);

// Free-text series that were never linked to a series row. Without this they would vanish from
// the filter bar entirely, and the sermons in them would look like they had no series at all.
$legacySeries = $pdo->query('SELECT DISTINCT series FROM sermons WHERE is_published = 1 AND series_id IS NULL AND series IS NOT NULL AND series != "" ORDER BY series ASC')->fetchAll(PDO::FETCH_COLUMN);

if ($activeSeries !== null) {
    $stmt = $pdo->prepare('SELECT * FROM sermons WHERE is_published = 1 AND series_id = ? ORDER BY series_position IS NULL, series_position ASC, published_at DESC LIMIT 60');
    $stmt->execute([(int) $activeSeries['id']]);
} elseif ($activeText !== '') {
    $stmt = $pdo->prepare('SELECT * FROM sermons WHERE is_published = 1 AND series = ? ORDER BY published_at DESC LIMIT 60');
    $stmt->execute([$activeText]);
} else {
    $stmt = $pdo->query('SELECT * FROM sermons WHERE is_published = 1 ORDER BY published_at DESC LIMIT 60');
}
$sermons = $stmt->fetchAll();
$noFilter = $activeSeries === null && $activeText === '';
?>

<section class="section" style="padding-top:56px;">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">The Word</span>
      <h2><?= $activeSeries !== null ? e($activeSeries['title']) : 'Sermons' ?></h2>
      <p>
        <?php if ($activeSeries !== null): ?>
          <?= e(Series::blurb($activeSeries, 200)) ?>
        <?php else: ?>
          Catch up on past messages, by series or speaker.
        <?php endif; ?>
      </p>
    </div>

    <?php if ($seriesRows || $legacySeries): ?>
      <div class="chip-row">
        <a href="/sermons" class="chip <?= $noFilter ? 'active' : '' ?>">All</a>
        <?php foreach ($seriesRows as $sr): ?>
          <a href="/series/<?= e($sr['slug']) ?>"
             class="chip <?= $activeSeries !== null && (int) $activeSeries['id'] === (int) $sr['id'] ? 'active' : '' ?>">
            <?= e($sr['title']) ?>
          </a>
        <?php endforeach; ?>
        <?php foreach ($legacySeries as $ls): ?>
          <?php if ($ls === '' || $ls === null) { continue; } ?>
          <a href="/sermons?series=<?= urlencode((string) $ls) ?>"
             class="chip <?= $activeText === (string) $ls ? 'active' : '' ?>"><?= e((string) $ls) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($activeSeries !== null): ?>
      <p style="margin:-6px 0 22px;"><a href="/series/<?= e($activeSeries['slug']) ?>" style="color:var(--gold-soft);">Open the full series page →</a></p>
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
              <h3>
                <?php if (!empty($sm['series_position'])): ?>
                  <span style="color:var(--gold-soft);"><?= (int) $sm['series_position'] ?>.</span>
                <?php endif; ?>
                <?= e($sm['title']) ?>
              </h3>
              <div class="meta">
                <?php if ($sm['speaker']): ?><span><?= e($sm['speaker']) ?></span><?php endif; ?>
                <span><?= e(date('M j, Y', strtotime($sm['published_at']))) ?></span>
                <?php if (!empty($sm['duration_seconds'])): ?>
                  <span><?= e(PodcastFeed::humanDuration((int) $sm['duration_seconds'])) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($sm['scripture_ref']): ?><p style="color:var(--gold-soft); font-size:12.5px; margin:0;"><?= e($sm['scripture_ref']) ?></p><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

