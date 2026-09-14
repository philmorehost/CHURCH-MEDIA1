<?php
declare(strict_types=1);

/**
 * $member and $prefs are supplied by the route in core/routes.php.
 *
 * The preference checkboxes are built from Member::NOTIFICATION_KEYS rather than a
 * hard-coded list, so adding a category in one place cannot leave this page silently
 * missing a switch. Labels fall back to a humanised key if one is ever added without
 * a label here — never the raw key.
 */
$notice = flash('member_notice');
$error = flash('member_error');

$prefLabels = [
    'devotional' => 'Daily devotional',
    'events' => 'Events and reminders',
    'prayer' => 'Prayer wall activity',
    'giving' => 'Giving and receipts',
    'reading_plan' => 'My reading plan',
];

$verified = !empty($member['is_verified']);
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card" style="max-width:720px;">

    <div class="form-banner">
      <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
      <div>
        <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
        <h1 class="form-title">Hello, <?= e((string) $member['name']) ?></h1>
      </div>
    </div>

    <?php if ($notice): ?><div class="form-success" style="padding:14px 18px;margin-bottom:16px;"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$verified): ?>
      <div class="form-error" style="display:block;">
        <strong>Your email address is not confirmed yet.</strong>
        Confirming it is what lets us send you a password reset if you ever need one.
        <form method="post" action="/member" style="margin-top:10px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="resend">
          <button type="submit" class="form-submit" style="margin:0;"><span>Email me a new link</span></button>
        </form>
      </div>
    <?php endif; ?>

    <h2 class="form-title" style="font-size:18px;margin:22px 0 6px;">Bible reading plan</h2>

    <?php if ($plan === null): ?>
      <?php if (!$planChoices): ?>
        <p class="form-desc">No reading plan has been published yet. When your church adds one it will appear here.</p>
      <?php else: ?>
        <p class="form-desc">Read through the Bible with the whole church. Your place is kept, and the days you read are counted towards a streak.</p>
        <form method="post" action="/member/plan">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="join">
          <div class="form-field">
            <label class="form-label" for="plan_id">Choose a plan</label>
            <select id="plan_id" name="plan_id">
              <?php foreach ($planChoices as $choice): ?>
                <option value="<?= (int) $choice['id'] ?>"><?= e((string) $choice['name']) ?> &middot; <?= (int) $choice['days_count'] ?> days</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="form-submit"><span>Start this plan</span></button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <?php $nextDay = $planProgress['next_day']; ?>
      <p class="form-desc">
        <strong><?= e((string) $plan['name']) ?></strong> &middot;
        <?= (int) $planProgress['done'] ?> of <?= (int) $planProgress['total'] ?> days read
        <?php if ($planProgress['streak'] > 1): ?>
          &middot; <strong><?= (int) $planProgress['streak'] ?> days in a row</strong>
        <?php elseif ($planProgress['streak'] === 1): ?>
          &middot; <strong>1 day in a row</strong>
        <?php endif; ?>
      </p>

      <div style="height:10px;border-radius:6px;background:rgba(0,0,0,0.10);overflow:hidden;margin:0 0 16px;"
           role="progressbar" aria-valuenow="<?= (int) $planProgress['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
        <div style="height:100%;width:<?= (int) $planProgress['percent'] ?>%;background:var(--gold,#d4af37);"></div>
      </div>

      <?php if ($nextDay === null): ?>
        <p class="form-desc"><strong>You have finished this plan.</strong> Well done — you can start another one whenever you like.</p>
      <?php else: ?>
        <?php // The first day not yet ticked, not today's date: a member who has been away for a week
              // should be offered the reading they actually stopped at. ?>
        <div style="border:1px solid rgba(0,0,0,0.12);border-radius:10px;padding:14px 16px;margin-bottom:12px;">
          <div style="font-size:12px;letter-spacing:0.06em;text-transform:uppercase;opacity:0.65;">Next up &middot; Day <?= (int) $nextDay ?></div>
          <div style="font-size:17px;font-weight:600;margin:4px 0 0;"><?= e(ReadingPlan::labelForDay($planReadings)) ?></div>
          <form method="post" action="/member/plan" style="margin-top:12px;">
            <?= Csrf::field() ?>
            <input type="hidden" name="do" value="tick">
            <input type="hidden" name="day" value="<?= (int) $nextDay ?>">
            <button type="submit" class="form-submit" style="margin:0;"><span>Mark day <?= (int) $nextDay ?> as read</span></button>
          </form>
        </div>
      <?php endif; ?>

      <p class="form-desc"><a href="/member/plan">See the whole plan</a>, or catch up on days you missed.</p>

      <form method="post" action="/member/plan">
        <?= Csrf::field() ?>
        <input type="hidden" name="do" value="join">
        <input type="hidden" name="plan_id" value="0">
        <button type="submit" class="form-submit ghost"><span>Leave this plan</span></button>
      </form>
    <?php endif; ?>

    <h2 class="form-title" style="font-size:18px;margin:22px 0 6px;">Your details</h2>
    <form method="post" action="/member">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="profile">

      <div class="form-field">
        <label class="form-label" for="name">Full Name</label>
        <input type="text" id="name" name="name" value="<?= e((string) $member['name']) ?>" required>
      </div>

      <div class="form-field">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" value="<?= e((string) $member['email']) ?>" disabled>
        <small style="opacity:0.7;">Your email is your sign-in name, so it cannot be changed here. Ask an admin if it needs to change.</small>
      </div>

      <div class="form-field">
        <label class="form-label" for="phone">Phone</label>
        <input type="tel" id="phone" name="phone" value="<?= e((string) ($member['phone'] ?? '')) ?>" placeholder="+234 812 345 6789">
      </div>

      <div class="form-field">
        <label class="form-label" style="font-weight:400;">
          <input type="checkbox" name="sms_consent" value="1" <?= !empty($member['sms_consent']) ? 'checked' : '' ?>>
          Send me text messages on that number
        </label>
        <label class="form-label" style="font-weight:400;">
          <input type="checkbox" name="whatsapp_consent" value="1" <?= !empty($member['whatsapp_consent']) ? 'checked' : '' ?>>
          Contact me on WhatsApp
        </label>
      </div>

      <button type="submit" class="form-submit"><span>Save Details</span></button>
    </form>

    <h2 class="form-title" style="font-size:18px;margin:26px 0 6px;">My home church</h2>
    <?php if ($homeCell): ?>
      <p class="form-desc">
        You are at home in <strong><?= e((string) $homeCell['name']) ?></strong>.
        <?php if (!empty($homeCell['slug'])): ?>
          <a href="/unit/<?= e((string) $homeCell['slug']) ?>">Visit its page</a>.
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p class="form-desc">Tell us where you worship and we will keep you posted about that cell.</p>
    <?php endif; ?>

    <form method="post" action="/member">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="homecell">
      <div class="form-field">
        <label class="form-label" for="unit_name">Home church</label>
        <input type="text" id="unit_name" name="unit_name" value="<?= e((string) ($homeCell['name'] ?? '')) ?>" placeholder="Type its name as it appears in the church list">
        <small style="opacity:0.7;">Leave this blank and save to clear it.</small>
      </div>
      <button type="submit" class="form-submit"><span>Save Home Church</span></button>
    </form>

    <h2 class="form-title" style="font-size:18px;margin:26px 0 6px;">My giving</h2>
    <?php if ($totals): ?>
      <p class="form-desc">
        <?php foreach ($totals as $i => $total): ?><?= $i > 0 ? ' &middot; ' : '' ?><strong><?= e((string) $total['currency']) ?> <?= e(number_format((float) $total['total'], 2)) ?></strong> across <?= (int) $total['gifts'] ?> gift<?= (int) $total['gifts'] === 1 ? '' : 's' ?><?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if (!$giving): ?>
      <p class="form-desc">
        No completed gifts are recorded against <?= e((string) $member['email']) ?> yet.
        Gifts given with a different email address will not appear here — ask an admin if something is missing.
      </p>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <thead>
            <tr style="text-align:left;">
              <th style="padding:6px 8px 6px 0;">Date</th>
              <th style="padding:6px 8px;">For</th>
              <th style="padding:6px 8px;">Amount</th>
              <th style="padding:6px 8px;">Method</th>
              <th style="padding:6px 0;">Reference</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($giving as $gift): ?>
              <tr style="border-top:1px solid rgba(0,0,0,0.08);">
                <td style="padding:8px 8px 8px 0;white-space:nowrap;"><?= e(date('j M Y', strtotime((string) $gift['created_at']))) ?></td>
                <td style="padding:8px;"><?= e((string) $gift['category']) ?></td>
                <td style="padding:8px;white-space:nowrap;"><?= e((string) $gift['currency']) ?> <?= e(number_format((float) $gift['amount'], 2)) ?></td>
                <td style="padding:8px;"><?= $gift['payment_method'] === 'manual_bank' ? 'Bank transfer' : 'Online' ?></td>
                <td style="padding:8px 0;font-family:monospace;font-size:12px;"><?= e((string) ($gift['payment_reference'] ?? '')) ?: '&mdash;' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <h2 class="form-title" style="font-size:18px;margin:26px 0 6px;">Saved for later</h2>
    <?php if (!$saved): ?>
      <p class="form-desc">Nothing saved yet. Tap the bookmark on anything in the <a href="/feed">feed</a> and it will be waiting here.</p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;">
        <?php foreach ($saved as $item): ?>
          <a href="/feed" style="text-decoration:none;color:inherit;display:block;">
            <?php if (!empty($item['thumb'])): ?>
              <img src="<?= e((string) uploadUrl((string) $item['thumb'])) ?>" alt="" style="width:100%;aspect-ratio:9/16;object-fit:cover;border-radius:10px;display:block;">
            <?php endif; ?>
            <div style="font-size:13px;margin-top:6px;line-height:1.35;"><?= e(mb_substr(trim((string) ($item['caption'] ?? '')), 0, 70)) ?></div>
            <div style="font-size:11px;opacity:0.6;margin-top:2px;">Saved <?= e(date('j M Y', strtotime((string) $item['saved_at']))) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 class="form-title" style="font-size:18px;margin:26px 0 6px;">What we may notify you about</h2>
    <div class="form-desc">Turn off anything you would rather not hear about. Announcements from the church are not affected.</div>

    <form method="post" action="/member">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="prefs">

      <div class="form-field">
        <?php foreach (Member::NOTIFICATION_KEYS as $key): ?>
          <label class="form-label" style="font-weight:400;display:block;margin-bottom:8px;">
            <input type="checkbox" name="prefs[<?= e($key) ?>]" value="1" <?= !empty($prefs[$key]) ? 'checked' : '' ?>>
            <?= e($prefLabels[$key] ?? ucfirst(str_replace('_', ' ', $key))) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="form-submit"><span>Save Choices</span></button>
    </form>

    <hr style="border:none;border-top:1px solid rgba(0,0,0,0.12);margin:26px 0 18px;">

    <form method="post" action="/member/logout">
      <?= Csrf::field() ?>
      <button type="submit" class="form-submit ghost"><span>Sign Out</span></button>
    </form>

  </div>
</section>
