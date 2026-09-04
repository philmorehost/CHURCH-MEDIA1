<?php
declare(strict_types=1);

$sent = flash('register_sent') !== null || ($_GET['sent'] ?? '') === '1';
$error = flash('register_error');
// True when a weak/mismatched password rejected the submission — the page will
// scroll straight to the password field so the registrant only fixes that.
$pwFocus = !empty($_SESSION['register_pw_focus']);
unset($_SESSION['register_pw_focus']);
$metaTitle = 'Register Your Church';
$metaRobots = 'noindex, nofollow';
$old = $_SESSION['_form_old'] ?? [];
$unitsJson = json_encode(Unit::treeLight(), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
$oldJson = json_encode([
    'province_id' => (int) ($old['province_id'] ?? 0),
    'zone_id' => (int) ($old['zone_id'] ?? 0),
    'area_id' => (int) ($old['area_id'] ?? 0),
    'parish_id' => (int) ($old['parish_id'] ?? 0),
    'parish_name' => (string) ($old['parish_name'] ?? ''),
], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card" style="max-width:720px;">
    <?php if ($sent): ?>
      <div class="form-success">
        <div class="form-check">
          <svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none"/><path fill="none" d="M14 27l8 8 16-16"/></svg>
        </div>
        <h1 class="form-title center">Submission received!</h1>
        <p class="form-desc center">Thank you. Your church admin registration has been <strong>received</strong> and sent for review — you'll be notified once it's approved. God bless you.</p>
        <?php $waNumber = preg_replace('/[^0-9]/', '', (string) setting('contact_phone')); if ($waNumber !== ''): ?>
          <p class="form-desc center" style="margin-top:4px;">For <strong>instant review &amp; approval</strong>, contact the super admin on WhatsApp:</p>
          <a class="form-submit" href="https://wa.me/<?= e($waNumber) ?>" target="_blank" rel="noopener" style="margin-bottom:10px;">💬 Chat with the Admin on WhatsApp</a>
        <?php endif; ?>
        <a class="form-submit ghost" href="/">Back to Homepage</a>
      </div>
    <?php else: ?>
      <div class="form-banner">
        <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
        <div>
          <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
          <h1 class="form-title">Register Your Church</h1>
        </div>
      </div>
      <div class="form-desc">Register your parish's admin account. Once approved, you'll be able to manage your church's media, events, sermons, forms, and more. Church names are stored in <strong>CAPS</strong>.</div>

      <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

      <form method="post" action="/register" id="registerForm"
            data-units='<?= e($unitsJson) ?>'
            data-old='<?= e($oldJson) ?>'<?= $pwFocus ? ' data-focus-password="1"' : '' ?>>
        <input type="text" name="company" value="" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

        <div class="form-field">
          <label class="form-label" for="name"><span class="field-num">1</span><span>Full Name *</span></label>
          <input type="text" id="name" name="name" value="<?= e((string) ($old['name'] ?? '')) ?>" required placeholder="e.g. PASTOR JOHN ADE">
        </div>

        <div class="form-field">
          <label class="form-label" for="email"><span class="field-num">2</span><span>Email Address *</span></label>
          <input type="email" id="email" name="email" value="<?= e((string) ($old['email'] ?? '')) ?>" required placeholder="you@church.org">
        </div>

        <div class="form-field">
          <label class="form-label" for="phone"><span class="field-num">3</span><span>WhatsApp Phone (optional)</span></label>
          <input type="tel" id="phone" name="phone" value="<?= e((string) ($old['phone'] ?? '')) ?>" placeholder="+234 812 345 6789">
        </div>

        <div class="form-field">
          <label class="form-label" for="password"><span class="field-num">4</span><span>Password (strong — cPanel strength 65) *</span></label>
          <input type="password" id="password" name="password" minlength="8" required placeholder="Strong password (letters, numbers & symbols)" data-password autocomplete="new-password" style="<?= $pwFocus ? 'border-color:#ff6b6b;' : '' ?>">
          <div data-strength-bar style="height:6px;border-radius:6px;background:#ffffff12;margin-top:8px;overflow:hidden;">
            <div data-strength-fill style="height:100%;width:0;background:#ff6b6b;transition:width .12s ease;"></div>
          </div>
          <div data-strength-label style="font-size:12px;color:var(--ink-faint);margin-top:4px;">Enter a strong password — cPanel requires strength 65+ (mix uppercase, lowercase, numbers &amp; symbols).</div>
          <div data-password-suggestion style="display:none;margin-top:8px;font-size:13px;color:var(--gold-soft);">
            🔒 Too weak — try <strong data-suggestion-text></strong>
            <button type="button" class="btn secondary sm" data-suggestion-use style="margin-left:6px;">Use</button>
          </div>
        </div>

        <div class="form-field">
          <label class="form-label" for="password_confirm"><span class="field-num">5</span><span>Confirm Password *</span></label>
          <input type="password" id="password_confirm" name="password_confirm" minlength="8" required placeholder="Repeat your password" data-confirm>
          <div data-password-match style="display:none;font-size:12px;margin-top:4px;"></div>
        </div>

        <div class="form-field">
          <label class="form-label" for="province"><span class="field-num">6</span><span>Your Church Location *</span></label>
          <div class="cascade-selects">
            <select id="province" data-province required><option value="">Select Province…</option></select>
            <select id="zone" data-zone required><option value="">Select Zone…</option></select>
            <select id="area" data-area required><option value="">Select Area…</option></select>
            <input type="text" id="parish" data-parish placeholder="Type your Parish church name (CAPS)" required>
            <datalist id="parishOptions" data-parish-list></datalist>
            <input type="hidden" name="province_id" data-province-id>
            <input type="hidden" name="zone_id" data-zone-id>
            <input type="hidden" name="area_id" data-area-id>
            <input type="hidden" name="parish_id" data-parish-id>
            <input type="hidden" name="parish_name" data-parish-name>
          </div>
          <div class="cascade-note">Select your Province, Zone, and Area, then type the Parish church name — it is automatically converted to CAPS. If the parish was added before, it appears as a suggestion as you type.</div>
        </div>

        <div class="form-field">
          <label class="form-label" for="role"><span class="field-num">7</span><span>Your Role *</span></label>
          <select id="role" name="role" data-role>
            <option value="admin" <?= ($old['role'] ?? 'admin') === 'admin' ? 'selected' : '' ?>>Church Admin</option>
            <option value="editor" <?= ($old['role'] ?? '') === 'editor' ? 'selected' : '' ?>>Editor</option>
            <option value="media_team" <?= ($old['role'] ?? '') === 'media_team' ? 'selected' : '' ?>>Media Team</option>
          </select>
          <div class="cascade-note">Used to suggest your username/email (e.g. sopadmin, sopeditor, sopmedia).</div>
        </div>

        <div class="form-field">
          <label class="form-label" for="username"><span class="field-num">8</span><span>Username &amp; Email Address *</span></label>
          <input type="text" id="username" name="username" value="<?= e((string) ($old['username'] ?? '')) ?>" required placeholder="e.g. sopadmin" data-username>
          <div class="cascade-note" id="usernameHint"><?= setting('email_domain') ? 'This becomes your login AND your corporate email: <strong>@' . e((string) setting('email_domain')) . '</strong>.' : 'This becomes your login username and your corporate email address.' ?></div>
          <div id="usernameSuggestions" data-suggestions style="display:none;margin-top:10px;">
            <span style="font-size:12px;color:var(--ink-faint);font-weight:700;">Suggested:</span>
            <button type="button" class="btn secondary sm" data-suggestion style="margin-left:6px;"></button>
            <button type="button" class="btn secondary sm" data-suggestion style="margin-left:6px;"></button>
          </div>
        </div>

        <div class="form-field">
          <label class="form-label" for="alt_email"><span class="field-num">9</span><span>Alternative Email <span style="color:var(--ink-faint);font-weight:400;">(optional)</span></span></label>
          <input type="email" id="alt_email" name="alt_email" value="<?= e((string) ($old['alt_email'] ?? '')) ?>" placeholder="backup@personal.com">
          <div class="cascade-note">If provided, emails sent to your new church address<?= setting('email_domain') ? ' (e.g. sopadmin@' . e((string) setting('email_domain')) . ')' : '' ?> will also be <strong>forwarded</strong> to this backup inbox — so you never miss a message.</div>
        </div>

        <button type="submit" class="form-submit"><span>Submit for Approval</span></button>
      </form>

      <div style="border-top:1px solid var(--border);margin:30px 34px 36px;padding-top:22px;text-align:center;">
        <button type="button" class="btn secondary" data-flag-toggle style="font-size:13px;">🏷 Church name wrong? Report the correct spelling</button>
        <form method="post" action="/register" data-flag-form style="display:none;margin-top:14px;text-align:left;">
          <?= Csrf::field() ?>
          <input type="hidden" name="flag_submit" value="1">
          <label class="form-label" for="flag_current">Current church name</label>
          <input type="text" id="flag_current" name="flag_current" required placeholder="As it is currently spelled">
          <label class="form-label" for="flag_suggested" style="margin-top:12px;">Correct spelling</label>
          <input type="text" id="flag_suggested" name="flag_suggested" required placeholder="Correct spelling (auto-CAPS)">
          <label class="form-label" for="flag_by" style="margin-top:12px;">Your name (optional)</label>
          <input type="text" id="flag_by" name="flag_by" placeholder="Who is reporting this?">
          <button type="submit" class="btn" style="margin-top:14px;">Submit correction for review</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<script src="<?= asset('js/register.js') ?>"></script>
