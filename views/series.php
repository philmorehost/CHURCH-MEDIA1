<?php
declare(strict_types=1);

/**
 * /series — every published sermon series.
 *
 * Gathered from the series table rather than by grouping the sermons, so a series with no
 * published episodes yet still appears (with its description and artwork), and a series that
 * has been renamed shows its new name.
 */
$metaTitle = 'Sermon Series';

$series = Series::all([], true);

$feedAvailable = count(array_filter($series, static fn (array $s): bool => (int) ($s['sermon_count'] ?? 0) > 0)) > 0;
?>

<section class="section" style="padding-top:56px;">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">The Word</span>
      <h2>Sermon Series</h2>
      <p>Teaching runs, in order — follow one from the beginning, or subscribe to the podcast.</p>
    </div>

    <?php if ($feedAvailable): ?>
      <div class="chip-row">
        <a href="/podcast" class="chip">🎧 Subscribe to the podcast</a>
        <a href="/podcast.xml" class="chip">Feed link</a>
      </div>
    <?php endif; ?>

    <?php if (!$series): ?>
      <div class="empty-state">No series published yet.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($series as $sr): ?>
          <a href="/series/<?= e($sr['slug']) ?>" class="glass-card info-card reveal in">
            <div class="cover">
              <?php if (!empty($sr['cover_image'])): ?>
                <img src="<?= e(uploadUrl((string) $sr['cover_image'])) ?>" alt="" loading="lazy">
              <?php endif; ?>
            </div>
            <div class="body">
              <h3><?= e($sr['title']) ?></h3>
              <div class="meta">
                <span>
                  <?php $n = (int) ($sr['sermon_count'] ?? 0); ?>
                  <?= $n === 1 ? '1 episode' : $n . ' episodes' ?>
                </span>
                <?php if (!empty($sr['latest_at'])): ?>
                  <span>latest <?= e(date('M j, Y', strtotime((string) $sr['latest_at']))) ?></span>
                <?php endif; ?>
              </div>
              <?php if (trim((string) ($sr['description'] ?? '')) !== ''): ?>
                <p style="color:var(--ink-dim);font-size:13px;margin:0;">
                  <?= e(Series::blurb($sr, 140)) ?>
                </p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
