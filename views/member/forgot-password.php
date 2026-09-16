<?php
declare(strict_types=1);

$error = flash('member_error');
$notice = flash('member_notice');
$sent = ($_GET['sent'] ?? '') === '1';
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card">
    <div class="form-banner">
      <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
      <div>
        <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
        <h1 class="form-title">Reset your password</h1>
      </div>
    </div>

    <?php if ($sent): ?>
      <div class="form-success" style="padding:14px 18px;margin-bottom:16px;">
        <?= e($notice ?: 'If that address has an account, a reset link is on its way.') ?>
      </div>
      <p class="form-desc">Check your inbox, and the spam folder just in case. The link works for two hours.</p>
      <a class="form-submit ghost" href="/member/login">Back to sign in</a>
    <?php else: ?>
      <div class="form-desc">Enter the email address you registered with and we will send you a link to choose a new password.</div>

      <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

      <form method="post" action="/member/forgot-password">
        <?= Csrf::field() ?>
        <div class="form-field">
          <label class="form-label" for="email">Email</label>
          <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.com">
        </div>
        <button type="submit" class="form-submit"><span>Send Reset Link</span></button>
      </form>

      <p class="form-desc" style="margin-top:18px;text-align:center;">
        <a href="/member/login">Back to sign in</a>
      </p>
    <?php endif; ?>
  </div>
</section>
