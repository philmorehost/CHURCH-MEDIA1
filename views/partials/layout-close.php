<?php
declare(strict_types=1);
$s = settings();
$serviceTimes = $s['service_times'] ? (is_array($s['service_times']) ? $s['service_times'] : (json_decode((string) $s['service_times'], true) ?: [])) : [];
$socialIcons = [
    'Facebook' => [
        'url' => $s['facebook_url'] ?? null,
        'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
    ],
    'Instagram' => [
        'url' => $s['instagram_url'] ?? null,
        'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
    ],
    'YouTube' => [
        'url' => $s['youtube_url'] ?? null,
        'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.016 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
    ],
    'TikTok' => [
        'url' => $s['tiktok_url'] ?? null,
        'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-.99-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.82.57-1.31 1.52-1.36 2.52-.03.71.21 1.43.68 1.95.6.66 1.49.98 2.37.9.89-.04 1.71-.52 2.15-1.28.32-.57.48-1.24.47-1.89.01-5.11.01-10.22.01-15.33z"/></svg>',
    ],
    'X (Twitter)' => [
        'url' => $s['twitter_url'] ?? null,
        'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
    ],
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
          <?php foreach ($socialIcons as $label => $item): ?>
            <?php if (!empty($item['url'])): ?><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"><?= $item['svg'] ?></a><?php endif; ?>
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
        <a href="/advertise">Advertise with Us</a>
        <a href="/ad-manager">Publisher Portal</a>
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
        <a href="/page/privacy-policy">Privacy Policy</a>
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
<?php if (class_exists('Analytics') && Analytics::enabled()): ?>
  <?php // Anonymous traffic beacon — fire-and-forget, no personal data, no IP. ?>
  <script src="<?= asset('js/analytics.js') ?>"
          data-analytics-endpoint="/api/analytics"
          data-analytics-enabled="1"
          defer></script>
<?php endif; ?>
</body>
</html>
