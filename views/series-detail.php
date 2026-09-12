<?php
declare(strict_types=1);

/**
 * /series/{slug} — one teaching run, in episode order.
 *
 * The slug arrives through render()'s data. An unknown or unpublished slug is a 404 rather than
 * an empty page, so a link to a withdrawn series does not look like a broken site.
 */
$row = Series::findBySlug((string) ($slug ?? ''));

if ($row === null || empty($row['is_published'])) {
    http_response_code(404);
    $metaTitle = 'Series not found';
    require VIEWS_PATH . '/404.php';
    return;
}

$episodes = Series::sermons((int) $row['id'], true, 200);

$metaTitle = (string) $row['title'];
$metaDescription = Series::blurb($row, 160);
$hasAudio = false;
foreach ($episodes as $ep) {
    if (!empty($ep['audio_url']) || !empty($ep['audio_path'])) {
        $hasAudio = true;
        break;
    }
}
?>

<section class="section" style="padding-top:56px;">
  <div class="container">
    <p style="margin-bottom:18px;"><a href="/series" style="color:var(--gold-soft);">← All series</a></p>

    <div class="section-head">
      <span class="eyebrow">Series</span>
      <h2><?= e($row['title']) ?></h2>
      <?php if (trim((string) ($row['description'] ?? '')) !== ''): ?>
        <p><?= e((string) $row['description']) ?></p>
      <?php endif; ?>
      <p style="color:var(--ink-dim);font-size:13px;">
        <?= count($episodes) === 1 ? '1 episode' : count($episodes) . ' episodes' ?>
      </p>
    </div>

    <?php if ($hasAudio): ?>
      <div class="chip-row">
        <a href="/podcast" class="chip">🎧 Subscribe to this podcast</a>
        <a href="/podcast.xml" class="chip">Feed link</a>
      </div>
    <?php endif; ?>

    <?php if (!$episodes): ?>
      <div class="empty-state">No episodes published in this series yet.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($episodes as $i => $ep): ?>
          <a href="/sermons/<?= e($ep['slug']) ?>" class="glass-card info-card reveal in">
            <div class="cover">
              <?php
                // Falls back to the series artwork, which is what a listed episode shows when the
                // individual message has no cover of its own.
                $art = !empty($ep['cover_image']) ? (string) $ep['cover_image'] : (string) ($row['cover_image'] ?? '');
              ?>
              <?php if ($art !== ''): ?><img src="<?= e(uploadUrl($art)) ?>" alt="" loading="lazy"><?php endif; ?>
            </div>
            <div class="body">
              <h3>
                <?php if (!empty($ep['series_position'])): ?>
                  <span style="color:var(--gold-soft);"><?= (int) $ep['series_position'] ?>.</span>
                <?php endif; ?>
                <?= e($ep['title']) ?>
              </h3>
              <div class="meta">
                <?php if (!empty($ep['speaker'])): ?><span><?= e($ep['speaker']) ?></span><?php endif; ?>
                <span><?= e(date('M j, Y', strtotime((string) $ep['published_at']))) ?></span>
                <?php if (!empty($ep['duration_seconds'])): ?>
                  <span><?= e(PodcastFeed::humanDuration((int) $ep['duration_seconds'])) ?></span>
                <?php endif; ?>
              </div>
              <?php if (!empty($ep['scripture_ref'])): ?>
                <p style="color:var(--gold-soft);font-size:12.5px;margin:0;"><?= e($ep['scripture_ref']) ?></p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
