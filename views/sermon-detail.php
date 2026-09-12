<?php
declare(strict_types=1);
/** @var string $slug */
$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare('SELECT * FROM sermons WHERE slug = ? AND is_published = 1');
$stmt->execute([$slug]);
$sermon = $stmt->fetch();
if (!$sermon) {
    http_response_code(404);
    require VIEWS_PATH . '/404.php';
    return;
}
$metaTitle = $sermon['title'];
$metaDescription = $sermon['description'] ? mb_strimwidth($sermon['description'], 0, 155, '…') : null;
$metaImage = baseUrl(ShareCard::urlFor('sermon', (int) $sermon['id'], (string) $sermon['slug']));
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:820px;">
    <div class="eyebrow" style="text-align:center; display:block; margin-bottom:14px;"><?= e($sermon['series'] ?: 'Sermon') ?></div>
    <h1 style="text-align:center; font-size:clamp(28px,5vw,44px);"><?= e($sermon['title']) ?></h1>
    <div class="meta" style="justify-content:center; margin-bottom:32px; font-size:14px;">
      <?php if ($sermon['speaker']): ?><span>🎙 <?= e($sermon['speaker']) ?></span><?php endif; ?>
      <span>🗓 <?= e(date('F j, Y', strtotime($sermon['published_at']))) ?></span>
      <?php if ($sermon['scripture_ref']): ?><span>📖 <?= e($sermon['scripture_ref']) ?></span><?php endif; ?>
    </div>

    <?php if ($sermon['video_embed_url']): ?>
      <div class="glass-card" style="aspect-ratio:16/9; margin-bottom:28px;">
        <iframe src="<?= e(embedUrl($sermon['video_embed_url'], true)) ?>" style="width:100%; height:100%; border:0;" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture" loading="eager"></iframe>
      </div>
      <?php if (str_contains((string) $sermon['video_embed_url'], 'facebook.com') || str_contains((string) $sermon['video_embed_url'], 'fb.watch')): ?>
        <div style="text-align:center; margin:-18px 0 24px; font-size:13px; color:var(--ink-dim);">
          Video not loading? <a href="<?= e($sermon['video_embed_url']) ?>" target="_blank" rel="noopener" style="color:var(--gold-soft); text-decoration:underline;">Watch directly on Facebook ↗</a>
        </div>
      <?php endif; ?>
    <?php elseif ($sermon['cover_image']): ?>
      <div class="glass-card" style="aspect-ratio:16/8; margin-bottom:28px;">
        <img src="<?= e(uploadUrl($sermon['cover_image'])) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
      </div>
    <?php endif; ?>

    <?php if ($sermon['audio_path']): ?>
      <audio controls style="width:100%; margin-bottom:28px;">
        <source src="<?= e(uploadUrl($sermon['audio_path'])) ?>">
      </audio>
    <?php endif; ?>

    <?php if ($sermon['description']): ?>
      <div style="color:var(--ink-dim); font-size:15.5px; line-height:1.8; white-space:pre-line;"><?= e($sermon['description']) ?></div>
    <?php endif; ?>

    <div style="text-align:center; margin-top:40px;">
      <a href="/sermons" class="btn btn-ghost">← Back to Sermons</a>
    </div>
  </div>
</section>
