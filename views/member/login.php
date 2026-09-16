<?php
declare(strict_types=1);

$error = flash('member_error');
$notice = flash('member_notice');
$old = $_SESSION['_form_old'] ?? [];
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card">
    <div class="form-banner">
      <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
      <div>
        <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
        <h1 class="form-title">Member Signin</h1>
      </div>
    </div>

    <?php if ($notice): ?><div class="form-success" style="padding:14px 18px;margin-bottom:16px;"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="/member/login">
      <?= Csrf::field() ?>

      <div class="form-field">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e((string) ($old['email'] ?? '')) ?>" required autocomplete="email" placeholder="you@example.com">
      </div>

      <div class="form-field">
        <label class="form-label" for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password">
      </div>

      <button type="submit" class="form-submit"><span>Sign In</span></button>
    </form>

    <p class="form-desc" style="margin-top:18px;text-align:center;">
      <a href="/member/forgot-password">Forgot your password?</a>
      &nbsp;·&nbsp;
      <a href="/member/register">Create an account</a>
    </p>
  </div>
</section>
