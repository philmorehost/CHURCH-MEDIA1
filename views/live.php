<?php
declare(strict_types=1);
$metaTitle = 'Live';
$s = settings();
$isLive = !empty($s['livestream_is_live']);
$embedUrl = embedUrl($s['livestream_embed_url'] ?? null);
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:940px;">
    <div class="section-head">
      <span class="eyebrow"><?= $isLive ? 'Streaming Now' : 'Livestream' ?></span>
      <h2><?= $isLive ? '🔴 We Are Live' : 'Watch Online' ?></h2>
      <p><?= $isLive ? 'Join the service right now — glad you\'re here.' : 'Check back at service time, or catch up below.' ?></p>
    </div>

    <?php if ($embedUrl): ?>
      <div class="glass-card" style="aspect-ratio:16/9;">
        <iframe src="<?= e($embedUrl) ?>" style="width:100%; height:100%; border:0;" allowfullscreen allow="autoplay; encrypted-media" loading="lazy"></iframe>
      </div>
    <?php else: ?>
      <div class="empty-state glass-card" style="padding:60px 24px;">
        No stream configured yet.<?php if ($s['youtube_url'] ?? null): ?> Visit our <a href="<?= e($s['youtube_url']) ?>" target="_blank" rel="noopener" style="color:var(--gold-soft);">YouTube channel</a> instead.<?php endif; ?>
      </div>
    <?php endif; ?>

    <?php $serviceTimes = $s['service_times'] ? (json_decode((string) $s['service_times'], true) ?: []) : []; ?>
    <?php if ($serviceTimes): ?>
      <div class="service-strip" style="margin-top:40px;">
        <?php foreach ($serviceTimes as $st): ?>
          <div class="service-pill"><div class="label"><?= e($st['label']) ?></div><div class="time"><?= e($st['time']) ?></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
