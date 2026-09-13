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
