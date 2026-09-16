<?php
declare(strict_types=1);

$error = flash('member_error');
$old = $_SESSION['_form_old'] ?? [];
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card">
    <div class="form-banner">
      <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
      <div>
        <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
        <h1 class="form-title">Create your account</h1>
      </div>
    </div>
    <div class="form-desc">An account lets you keep your place in a reading plan, save what you want to come back to, and choose what we notify you about.</div>

    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="/member/register">
      <?= Csrf::field() ?>
      <input type="text" name="company" value="" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

      <div class="form-field">
        <label class="form-label" for="name">Full Name *</label>
        <input type="text" id="name" name="name" value="<?= e((string) ($old['name'] ?? '')) ?>" required placeholder="e.g. GRACE ADEYEMI">
      </div>

      <div class="form-field">
        <label class="form-label" for="email">Email *</label>
        <input type="email" id="email" name="email" value="<?= e((string) ($old['email'] ?? '')) ?>" required placeholder="you@example.com">
      </div>

      <div class="form-field">
        <label class="form-label" for="phone">Phone <span style="font-weight:400;opacity:0.7;">— optional</span></label>
        <input type="tel" id="phone" name="phone" value="<?= e((string) ($old['phone'] ?? '')) ?>" placeholder="+234 812 345 6789">
      </div>

      <div class="form-field">
        <label class="form-label" for="password">Password *</label>
        <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
      </div>

      <div class="form-field">
        <label class="form-label" for="password_confirm">Repeat Password *</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password" placeholder="Type it again">
      </div>

      <button type="submit" class="form-submit"><span>Create Account</span></button>
    </form>

    <p class="form-desc" style="margin-top:18px;text-align:center;">
      Already have an account? <a href="/member/login">Sign in</a>
    </p>
  </div>
</section>
