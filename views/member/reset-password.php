<?php
declare(strict_types=1);

/**
 * $token and $tokenValid are supplied by the route in core/routes.php.
 *
 * The token is checked before the form is drawn so an expired or already-used link
 * says so immediately, rather than after the member has typed a new password twice.
 */
$error = flash('member_error');
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card">
    <div class="form-banner">
      <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
      <div>
        <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
        <h1 class="form-title">Choose a new password</h1>
      </div>
    </div>

    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$tokenValid): ?>
      <div class="form-desc">That reset link has expired, or it was already used. Reset links work once, for two hours.</div>
      <a class="form-submit" href="/member/forgot-password">Request a new link</a>
    <?php else: ?>
      <form method="post" action="/member/reset-password">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e((string) $token) ?>">

        <div class="form-field">
          <label class="form-label" for="password">New Password</label>
          <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
        </div>

        <div class="form-field">
          <label class="form-label" for="password_confirm">Repeat New Password</label>
          <input type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password" placeholder="Type it again">
        </div>

        <button type="submit" class="form-submit"><span>Save New Password</span></button>
      </form>
    <?php endif; ?>
  </div>
</section>
