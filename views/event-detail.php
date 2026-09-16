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
$metaImage = baseUrl(ShareCard::urlFor('event', (int) $event['id'], (string) $event['slug']));

$rsvpMode = Rsvp::modeFor($event);
$takesRsvps = Rsvp::takesRsvps($event);
$rsvpClosed = Rsvp::closed($event);
$rsvpCounts = $takesRsvps ? Rsvp::counts((int) $event['id']) : null;
$seatsLeft = $takesRsvps ? Rsvp::seatsLeft($event) : null;
$rsvpOk = flash('rsvp_ok');
$rsvpError = flash('rsvp_error');
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

    <?php if ($takesRsvps && !$rsvpClosed): ?>
      <div class="glass-card" style="padding:24px; margin-top:36px; text-align:left;">
        <h2 style="font-size:20px; margin-bottom:6px;">Will you be coming?</h2>
        <p style="color:var(--ink-dim); font-size:14px; margin-bottom:18px;">
          <?php if ($seatsLeft === null): ?>
            Let us know so we can plan for you.
          <?php elseif ($seatsLeft > 0): ?>
            <strong style="color:var(--gold-soft);"><?= number_format($seatsLeft) ?></strong> of <?= number_format(Rsvp::capacity($event)) ?> place(s) still available.
          <?php else: ?>
            This event is fully booked<?= $event['waitlist_enabled'] ? ' — join the waiting list and we will contact you if a place frees up' : '' ?>.
          <?php endif; ?>
        </p>

        <?php if ($rsvpOk): ?><div class="alert success" style="margin-bottom:16px;"><?= e($rsvpOk) ?></div><?php endif; ?>
        <?php if ($rsvpError): ?><div class="alert error" style="margin-bottom:16px;"><?= e($rsvpError) ?></div><?php endif; ?>

        <form method="post" action="/events/<?= e($event['slug']) ?>" style="display:grid; gap:12px;">
          <?= Csrf::field() ?>
          <div>
            <label for="rsvp_name">Your name *</label>
            <input type="text" id="rsvp_name" name="name" required maxlength="150" value="<?= e((string) formOld('name')) ?>" placeholder="e.g. GRACE ADEBAYO">
          </div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px;">
            <div>
              <label for="rsvp_email">Email <small style="color:var(--ink-faint);">(so we can confirm)</small></label>
              <input type="email" id="rsvp_email" name="email" maxlength="190" value="<?= e((string) formOld('email')) ?>" placeholder="you@example.com">
            </div>
            <div>
              <label for="rsvp_phone">Phone <small style="color:var(--ink-faint);">(optional)</small></label>
              <input type="tel" id="rsvp_phone" name="phone" maxlength="45" value="<?= e((string) formOld('phone')) ?>" placeholder="+234 800 000 0000">
            </div>
          </div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px;">
            <?php if (!empty($event['allow_guests'])): ?>
              <div>
                <label for="rsvp_guests">People coming with you</label>
                <select id="rsvp_guests" name="guests">
                  <?php for ($g = 0; $g <= Rsvp::MAX_GUESTS; $g++): ?>
                    <option value="<?= $g ?>" <?= (int) formOld('guests') === $g ? 'selected' : '' ?>><?= $g === 0 ? 'Just me' : $g . ' guest(s)' ?></option>
                  <?php endfor; ?>
                </select>
              </div>
            <?php endif; ?>
            <div>
              <label for="rsvp_status">I am…</label>
              <select id="rsvp_status" name="status">
                <option value="going" <?= formOld('status', 'going') === 'going' ? 'selected' : '' ?>>Coming</option>
                <option value="maybe" <?= formOld('status') === 'maybe' ? 'selected' : '' ?>>Maybe</option>
                <option value="declined" <?= formOld('status') === 'declined' ? 'selected' : '' ?>>Sorry, I cannot make it</option>
              </select>
            </div>
          </div>
          <div>
            <label for="rsvp_note">Anything we should know? <small style="color:var(--ink-faint);">(optional)</small></label>
            <input type="text" id="rsvp_note" name="note" maxlength="500" value="<?= e((string) formOld('note')) ?>">
          </div>
          <div>
            <button class="btn btn-gold" type="submit">Send my RSVP</button>
          </div>
        </form>
      </div>
    <?php elseif ($rsvpMode === 'external' && !empty($event['rsvp_url'])): ?>
      <div class="glass-card" style="padding:22px; margin-top:36px; text-align:center;">
        <p style="color:var(--ink-dim); margin-bottom:14px;">RSVPs for this event are handled on another page.</p>
        <a href="<?= e($event['rsvp_url']) ?>" target="_blank" rel="noopener" class="btn btn-gold">RSVP Now</a>
      </div>
    <?php elseif ($takesRsvps && $rsvpClosed): ?>
      <div class="glass-card" style="padding:22px; margin-top:36px; text-align:center;">
        <p style="color:var(--ink-dim); margin:0;">RSVPs for this event have closed.</p>
        <?php if ($rsvpCounts && $rsvpCounts['going'] > 0): ?>
          <p style="color:var(--ink-faint); font-size:13px; margin:6px 0 0;"><?= number_format($rsvpCounts['going']) ?> guest(s) signed up.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="glass-card" style="padding:18px; margin-top:18px; text-align:center;">
      <p style="color:var(--ink-faint); font-size:12.5px; margin-bottom:12px;">Add this event to your own calendar</p>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a class="btn btn-ghost" href="<?= e(Rsvp::icsUrl($event)) ?>">📅 Apple / Outlook (.ics)</a>
        <a class="btn btn-ghost" href="<?= e(Rsvp::googleUrl($event)) ?>" target="_blank" rel="noopener">📅 Google Calendar</a>
      </div>
    </div>

    <div style="text-align:center; margin-top:26px;">
      <a href="/events" class="btn btn-ghost">← Back to Events</a>
    </div>
  </div>
</section>
