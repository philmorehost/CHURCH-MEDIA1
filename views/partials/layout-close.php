<?php
declare(strict_types=1);
$s = settings();
$serviceTimes = $s['service_times'] ? (is_array($s['service_times']) ? $s['service_times'] : (json_decode((string) $s['service_times'], true) ?: [])) : [];
$socials = [
    'Facebook' => $s['facebook_url'] ?? null,
    'Instagram' => $s['instagram_url'] ?? null,
    'YouTube' => $s['youtube_url'] ?? null,
    'TikTok' => $s['tiktok_url'] ?? null,
];
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="footer-brand">
          <span class="mark"><?php if ($s['logo_path'] ?? null): ?><img src="<?= e(uploadUrl($s['logo_path'])) ?>" alt=""><?php else: ?><?= e(mb_substr($s['site_title'], 0, 1)) ?><?php endif; ?></span>
          <?= e($s['site_title']) ?>
        </div>
        <p class="footer-about"><?= e($s['footer_about_text'] ?? $s['site_tagline'] ?? '') ?></p>
        <div class="footer-social">
          <?php foreach ($socials as $label => $url): ?>
            <?php if ($url): ?><a href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e($label) ?>"><?= e(mb_substr($label, 0, 1)) ?></a><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <h4>Explore</h4>
        <a href="/feed">Media Feed</a>
        <a href="/events">Events</a>
        <a href="/sermons">Sermons</a>
        <a href="/live">Watch Live</a>
        <a href="/prayer">Prayer Wall</a>
        <a href="/app-features">App Features</a>
      </div>
      <div>
        <h4>Connect</h4>
        <a href="/about">About Us</a>
        <a href="/contact">Contact</a>
        <a href="/give">Give</a>
        <?php if ($s['contact_email'] ?? null): ?><a href="mailto:<?= e($s['contact_email']) ?>"><?= e($s['contact_email']) ?></a><?php endif; ?>
        <?php if ($s['contact_phone'] ?? null): ?><a href="tel:<?= e($s['contact_phone']) ?>"><?= e($s['contact_phone']) ?></a><?php endif; ?>
      </div>
      <div>
        <h4>Service Times</h4>
        <?php if (!$serviceTimes): ?><p class="footer-about">Check back soon for our schedule.</p><?php endif; ?>
        <?php foreach ($serviceTimes as $st): ?>
          <div style="margin-bottom:10px;">
            <div style="color:var(--ink); font-size:13.5px; font-weight:600;"><?= e($st['label']) ?></div>
            <div style="color:var(--ink-faint); font-size:12.5px;"><?= e($st['time']) ?></div>
          </div>
        <?php endforeach; ?>
        <form data-remote-form="/api/newsletter" style="margin-top:14px;">
          <label style="font-size:12px; color:var(--ink-dim); display:block; margin-bottom:8px;">Get updates by email</label>
          <div style="display:flex; gap:8px;">
            <input type="email" name="email" required placeholder="you@example.com" style="flex:1; padding:10px 12px; border-radius:10px; border:1px solid var(--border-soft); background:#ffffff08; color:var(--ink); font-size:13px;">
            <button class="btn btn-gold btn-sm" type="submit">Join</button>
          </div>
          <div data-form-message class="form-message"></div>
        </form>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e($s['site_title']) ?>. All rights reserved.</span>
      <div class="legal-links">
        <a href="/prayer">Prayer Wall</a>
        <a href="/search">Search</a>
        <a href="/privacy-policy">Privacy Policy</a>
        <a href="/admin">Admin</a>
      </div>
    </div>
  </div>
</footer>

<?php
// Floating "Get it on Google Play" button — left middle, on selected/all pages.
$appDownloadUrl = trim((string) ($s['app_download_url'] ?? ''));
$appDownloadEnabled = !empty($s['app_download_enabled']) && $appDownloadUrl !== '';
if ($appDownloadEnabled) {
    $currentPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
    $appPagesRaw = strtolower(trim((string) ($s['app_download_pages'] ?? 'all')));
    if ($appPagesRaw === 'all' || $appPagesRaw === '') {
        $appDownloadEnabled = true;
    } else {
        $allowedPaths = array_values(array_filter(array_map('trim', explode(',', $appPagesRaw)), fn ($p) => $p !== ''));
        $appDownloadEnabled = in_array($currentPath, $allowedPaths, true);
    }
}
?>
<?php if ($appDownloadEnabled): ?>
<div class="app-download-fab" id="appDownloadFab">
  <button type="button" class="app-download-fab__close" id="appDownloadClose" aria-label="Hide app download button">✕</button>
  <a class="app-download-fab__link" href="<?= e($appDownloadUrl) ?>" target="_blank" rel="noopener" aria-label="Get the app on Google Play">
    <svg class="app-download-fab__icon" viewBox="0 0 512 512" aria-hidden="true" focusable="false">
      <path fill="#00A0FF" d="M67.9 12.9C42.1 23.2 25 47.5 25 78.8v354.4c0 31.3 17.1 55.6 42.9 65.9l247.8-247.9L67.9 12.9z"/>
      <path fill="#FFCE00" d="m430.8 292.7-84.9-48.2-76.4 76.4 76.4 76.4 84.9-48.2c41.1-23.2 41.1-81.2 0-104.4z"/>
      <path fill="#EA4335" d="M269.5 255.9 345.9 179.5l-278-157.7C39.4 33 25.5 53.7 25 78.8v2.6l244.5 174.5z"/>
      <path fill="#FF3D00" d="M345.9 332.3 269.5 255.9 25 430.4v2.6c.5 25.1 14.4 45.8 42.9 57l278-157.7z"/>
      <path fill="#00A0FF" d="M269.5 255.9 25 81.4v0l0 0 0 0 244.5 174.5z"/>
    </svg>
    <span class="app-download-fab__text">
      <small>Get it on</small>
      <strong>Google Play</strong>
    </span>
  </a>
</div>
<?php endif; ?>

<?php
// "Admin & App only" mode: optional (off/banner/interstitial/force), Android
// phone only. Search engines and desktop/iOS visitors are never redirected.
// All behaviour lives in js/app-download.js (CSP-safe — the site blocks inline
// scripts), configured via the data-* attributes below.
$appRedirectMode = trim((string) ($s['app_redirect_mode'] ?? 'off'));
$appRedirectUrl = trim((string) ($s['app_download_url'] ?? ''));
$appRedirectOn = in_array($appRedirectMode, ['banner', 'interstitial', 'force'], true) && $appRedirectUrl !== '';
$currentPathForRedirect = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$isAppPage = $currentPathForRedirect === '/app';
?>

<?php if ($appRedirectOn && !$isAppPage): ?>
<div id="appRedirectConfig" data-mode="<?= e($appRedirectMode) ?>" data-url="<?= e($appRedirectUrl) ?>" hidden></div>
<?php endif; ?>

<?php if ($appRedirectMode === 'banner' && !$isAppPage && $appRedirectUrl !== ''): ?>
<div class="app-banner" id="appBanner" hidden>
  <div class="app-banner__inner">
    <span class="app-banner__mark"><?= e(mb_substr((string) $s['site_title'], 0, 1)) ?></span>
    <div class="app-banner__text">
      <strong>Get the <?= e((string) $s['site_title']) ?> app</strong>
      <small>Reels, notifications &amp; the offline Bible</small>
    </div>
    <a class="app-banner__cta" href="<?= e($appRedirectUrl) ?>" target="_blank" rel="noopener">Get it on Google Play</a>
    <button type="button" class="app-banner__close" id="appBannerClose" aria-label="Dismiss app banner">✕</button>
  </div>
</div>
<?php endif; ?>

<script src="<?= asset('js/app-download.js') ?>"></script>
<script src="<?= asset('js/site.js') ?>"></script>
</body>
</html>
