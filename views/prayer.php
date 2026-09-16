<?php
declare(strict_types=1);
$metaTitle = 'Prayer Wall';
$prayers = Database::getInstance()->getConnection()
    ->query("SELECT name, message, created_at FROM prayer_requests WHERE is_public = 1 AND status != 'archived' ORDER BY created_at DESC LIMIT 30")
    ->fetchAll();
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:900px;">
    <div class="section-head">
      <span class="eyebrow">We're With You</span>
      <h2>Prayer Wall</h2>
      <p>Share a request — our team prays over every submission. Mark it public to be encouraged by others praying alongside you.</p>
    </div>

    <form class="glass-card" style="padding:28px; max-width:620px; margin:0 auto 60px;" data-remote-form="/api/prayer">
      <div data-form-message class="form-message"></div>
      <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off">
      <div class="grid grid-2" style="gap:16px;">
        <div class="form-field" style="margin-bottom:0;">
          <label for="name">Name (optional)</label>
          <input type="text" id="name" name="name">
        </div>
        <div class="form-field" style="margin-bottom:0;">
          <label for="email">Email (optional)</label>
          <input type="email" id="email" name="email">
        </div>
      </div>
      <div class="form-field">
        <label for="message">Your Prayer Request</label>
        <textarea id="message" name="message" required></textarea>
      </div>
      <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--ink-dim); margin-bottom:18px;">
        <input type="checkbox" name="is_public" value="1" style="width:auto;"> Share on the public prayer wall
      </label>
      <button class="btn btn-gold btn-block" type="submit">Submit Request</button>
    </form>

    <div class="section-head">
      <h2 style="font-size:24px;">Praying Together</h2>
    </div>
    <?php if (!$prayers): ?>
      <div class="empty-state">No public prayers yet — be the first to share.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($prayers as $p): ?>
          <div class="glass-card" style="padding:22px;">
            <p style="color:var(--ink-dim); font-size:13.5px; margin-bottom:14px;">"<?= e(mb_strimwidth($p['message'], 0, 160, '…')) ?>"</p>
            <div style="font-size:12px; color:var(--ink-faint);">— <?= e($p['name'] ?: 'Anonymous') ?> · <?= e(timeAgo($p['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
