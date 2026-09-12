<?php
declare(strict_types=1);
$metaTitle = 'Prayer Wall';
$metaDescription = 'Share a prayer request with our team, pray for others, and read the prayers God has answered.';

// The wall, the answered list and the counts all come from PrayerWall, which owns
// the rule that an anonymous request never shows a name publicly.
$prayerWall = PrayerWall::wall(30);
$answeredWall = PrayerWall::answered(12);
$prayerStats = PrayerWall::stats();
$prayedIds = PrayerWall::participated(array_column($prayerWall, 'id'), Fingerprint::hash());

// Featured requests get their own strip above the wall.
$featured = array_values(array_filter($prayerWall, static fn(array $p): bool => !empty($p['is_featured'])));

$prayerOk = flash('prayer_ok');
$prayerError = flash('prayer_error');

/** Renders the "I prayed for this" button, in its finished state if already prayed. */
$prayButton = static function (array $prayer) use ($prayedIds): string {
    $id = (int) $prayer['id'];
    $count = (int) $prayer['prayer_count'];
    $done = in_array($id, $prayedIds, true);
    $label = $done ? 'You prayed' : 'I prayed for this';
    return '<button type="button" class="btn btn-ghost btn-sm pray-btn"'
        . ' data-pray data-request-id="' . $id . '"'
        . ($done ? ' data-prayed="1" disabled' : '')
        . ' title="' . e($label) . '">'
        . '<span aria-hidden="true">🙏</span> '
        . '<span data-pray-count>' . number_format($count) . '</span>'
        . '<span class="pray-label">' . e($label) . '</span>'
        . '</button>';
};
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:900px;">
    <div class="section-head">
      <span class="eyebrow">We're With You</span>
      <h2>Prayer Wall</h2>
      <p>Share a request — our team prays over every submission. Tick "share on the wall" if you would like others to pray alongside you, and "keep me anonymous" to leave your name off the public list.</p>
    </div>

    <div class="chip-row" style="margin-bottom:36px;">
      <span class="chip"><?= number_format($prayerStats['open']) ?> on the wall</span>
      <span class="chip"><?= number_format($prayerStats['prayers']) ?> prayers offered</span>
      <span class="chip"><?= number_format($prayerStats['answered']) ?> answered</span>
    </div>

    <form class="glass-card" style="padding:28px; max-width:620px; margin:0 auto 60px;" method="post" action="/prayer" data-remote-form="/api/prayer">
      <div data-form-message class="form-message"></div>
      <?php if ($prayerOk): ?><div class="alert success"><?= e($prayerOk) ?></div><?php endif; ?>
      <?php if ($prayerError): ?><div class="alert error"><?= e($prayerError) ?></div><?php endif; ?>
      <?= Csrf::field() ?>
      <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="grid grid-2" style="gap:16px;">
        <div class="form-field" style="margin-bottom:0;">
          <label for="name">Name (optional)</label>
          <input type="text" id="name" name="name" maxlength="150" value="<?= e((string) formOld('name')) ?>" data-prayer-name>
        </div>
        <div class="form-field" style="margin-bottom:0;">
          <label for="email">Email (optional)</label>
          <input type="email" id="email" name="email" maxlength="150" value="<?= e((string) formOld('email')) ?>">
        </div>
      </div>
      <div class="form-field">
        <label for="message">Your Prayer Request</label>
        <textarea id="message" name="message" maxlength="2000" required data-prayer-message><?= e((string) formOld('message')) ?></textarea>
      </div>
      <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--ink-dim); margin-bottom:10px;">
        <input type="checkbox" name="is_public" value="1" style="width:auto;" <?= formOld('is_public') ? 'checked' : '' ?>> Share on the public prayer wall
      </label>
      <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--ink-dim); margin-bottom:10px;">
        <input type="checkbox" name="is_anonymous" value="1" style="width:auto;" data-anonymous-toggle <?= formOld('is_anonymous') ? 'checked' : '' ?>> Keep my name anonymous on the wall
      </label>
      <p data-anonymous-hint hidden style="font-size:12px; color:var(--gold-soft); margin:0 0 8px;">Your name will not appear on the public wall. Only our pastoral team will see it.</p>
      <p style="font-size:12px; color:var(--ink-faint); margin:0 0 18px;">Only our pastoral team sees your name and contact details — ticking the box above hides it from everyone else.</p>
      <button class="btn btn-gold btn-block" type="submit">Submit Request</button>
    </form>

    <?php if ($featured): ?>
      <div class="section-head">
        <h2 style="font-size:24px;">Standing With These</h2>
      </div>
      <div class="grid grid-2" style="margin-bottom:56px;">
        <?php foreach ($featured as $p): ?>
          <div class="glass-card" style="padding:24px; border-color:#e8b95f55; display:flex; flex-direction:column; gap:14px;">
            <div style="font-size:10.5px; letter-spacing:.14em; text-transform:uppercase; color:var(--gold-soft);">Featured</div>
            <p style="color:var(--ink); font-size:15px; line-height:1.7; margin:0; white-space:pre-line; overflow-wrap:anywhere;">"<?= e(mb_strimwidth($p['message'], 0, 400, '…')) ?>"</p>
            <div style="margin-top:auto;">
              <div style="font-size:12px; color:var(--ink-faint); margin-bottom:12px;">— <?= e($p['name']) ?> · <?= e(timeAgo($p['created_at'])) ?></div>
              <?= $prayButton($p) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="section-head">
      <h2 style="font-size:24px;">Praying Together</h2>
      <p>Tap the button to let someone know you prayed. One prayer per person is counted.</p>
    </div>
    <?php if (!$prayerWall): ?>
      <div class="empty-state">No public prayers yet — be the first to share.</div>
    <?php else: ?>
      <div class="grid grid-3" style="margin-bottom:64px;">
        <?php foreach ($prayerWall as $p): ?>
          <div class="glass-card" style="padding:22px; display:flex; flex-direction:column; gap:14px;">
            <p style="color:var(--ink-dim); font-size:13.5px; line-height:1.7; margin:0; white-space:pre-line; overflow-wrap:anywhere;">"<?= e(mb_strimwidth($p['message'], 0, 400, '…')) ?>"</p>
            <div style="margin-top:auto;">
              <div style="font-size:12px; color:var(--ink-faint); margin-bottom:12px;">— <?= e($p['name']) ?> · <?= e(timeAgo($p['created_at'])) ?></div>
              <?= $prayButton($p) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="section-head">
      <h2 style="font-size:24px;">Answered Prayers</h2>
      <p>God has been faithful. These are the requests He has answered.</p>
    </div>
    <?php if (!$answeredWall): ?>
      <div class="empty-state">No answered prayers have been shared yet.</div>
    <?php else: ?>
      <div class="grid grid-2">
        <?php foreach ($answeredWall as $a): ?>
          <div class="glass-card" style="padding:22px; border-color:#5fe0a455;">
            <div style="font-size:10.5px; letter-spacing:.14em; text-transform:uppercase; color:var(--success); margin-bottom:12px;">Answered</div>
            <p style="color:var(--ink-dim); font-size:13.5px; line-height:1.7; margin:0 0 12px; white-space:pre-line; overflow-wrap:anywhere;">"<?= e(mb_strimwidth($a['message'], 0, 400, '…')) ?>"</p>
            <?php if ($a['answer_note']): ?>
              <p style="color:var(--ink); font-size:13.5px; line-height:1.7; margin:0 0 14px; padding-left:14px; border-left:2px solid #5fe0a455; white-space:pre-line; overflow-wrap:anywhere;"><?= e($a['answer_note']) ?></p>
            <?php endif; ?>
            <div style="font-size:12px; color:var(--ink-faint);">— <?= e($a['name']) ?> · answered <?= e(timeAgo((string) $a['answered_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

