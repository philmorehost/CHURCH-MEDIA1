<?php
declare(strict_types=1);
/** @var string $slug */
$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare('SELECT * FROM events WHERE slug = ? AND is_published = 1');
$stmt->execute([$slug]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    require VIEWS_PATH . '/404.php';
    return;
}
$metaTitle = $event['title'];
$metaDescription = $event['description'] ? mb_strimwidth($event['description'], 0, 155, '…') : null;
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:820px;">
    <div class="eyebrow" style="text-align:center; display:block; margin-bottom:14px;">Event</div>
    <h1 style="text-align:center; font-size:clamp(28px,5vw,44px);"><?= e($event['title']) ?></h1>
    <div class="meta" style="justify-content:center; margin-bottom:32px; font-size:14px;">
      <span>🗓 <?= e(date('l, F j, Y', strtotime($event['start_at']))) ?></span>
      <span>🕘 <?= e(date('g:i A', strtotime($event['start_at']))) ?><?= $event['end_at'] ? ' – ' . e(date('g:i A, M j', strtotime($event['end_at']))) : '' ?></span>
      <?php if ($event['location']): ?><span>📍 <?= e($event['location']) ?></span><?php endif; ?>
    </div>

    <?php if ($event['cover_image']): ?>
      <div class="glass-card" style="aspect-ratio:16/8; margin-bottom:32px;">
        <img src="<?= e(uploadUrl($event['cover_image'])) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
      </div>
    <?php endif; ?>

    <?php if ($event['description']): ?>
      <div style="color:var(--ink-dim); font-size:15.5px; line-height:1.8; white-space:pre-line;"><?= e($event['description']) ?></div>
    <?php endif; ?>

    <div style="text-align:center; margin-top:40px;">
      <?php if ($event['rsvp_enabled'] && $event['rsvp_url']): ?>
        <a href="<?= e($event['rsvp_url']) ?>" target="_blank" rel="noopener" class="btn btn-gold">RSVP Now</a>
      <?php endif; ?>
      <a href="/events" class="btn btn-ghost">← Back to Events</a>
    </div>
  </div>
</section>
