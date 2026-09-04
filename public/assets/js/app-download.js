/* App-download UI: floating button dismiss, "Admin & App only" redirect/banner.
 *
 * This lives in an external file on purpose: the site sends a strict
 * Content-Security-Policy (script-src 'self') that blocks inline <script>
 * blocks, so any inline app-redirect code would silently never run in
 * production. Config is passed via data-* attributes on #appRedirectConfig.
 */
(function () {
  'use strict';

  /* ---------- Floating "Get it on Google Play" button dismiss ---------- */
  var fab = document.getElementById('appDownloadFab');
  var fabClose = document.getElementById('appDownloadClose');
  if (fab) {
    try {
      if (localStorage.getItem('cm_hide_download_fab') === '1') {
        fab.style.display = 'none';
      }
    } catch (e) { /* storage unavailable */ }
  }
  if (fab && fabClose) {
    fabClose.addEventListener('click', function (e) {
      e.preventDefault();
      try { localStorage.setItem('cm_hide_download_fab', '1'); } catch (err) { /* ignore */ }
      fab.style.display = 'none';
    });
  }

  /* ---------- "Admin & App only" mode (banner / interstitial / force) ---------- */
  var cfg = document.getElementById('appRedirectConfig');
  if (!cfg) { return; }

  var mode = cfg.getAttribute('data-mode') || 'off';
  var url = cfg.getAttribute('data-url') || '';

  // Android phone only — excludes tablets, iPhones, desktops, and bots.
  var ua = navigator.userAgent || '';
  var isAndroidPhone = /Android/i.test(ua) && /Mobile/i.test(ua) && !/iPad/i.test(ua);
  if (!isAndroidPhone) { return; }

  if (mode === 'force') {
    location.replace(url);
    return;
  }

  if (mode === 'interstitial') {
    try {
      if (localStorage.getItem('cm_skip_app') === '1') { return; }
    } catch (e) { /* storage unavailable — still redirect */ }
    location.replace('/app');
    return;
  }

  // Banner mode: show a small dismissible strip instead of redirecting.
  var bar = document.getElementById('appBanner');
  if (!bar) { return; }
  try {
    if (localStorage.getItem('cm_dismiss_app_banner') === '1') { return; }
  } catch (e) { /* storage unavailable — still show */ }
  bar.hidden = false;
  var barClose = document.getElementById('appBannerClose');
  if (barClose) {
    barClose.addEventListener('click', function () {
      try { localStorage.setItem('cm_dismiss_app_banner', '1'); } catch (e) { /* ignore */ }
      bar.hidden = true;
    });
  }
})();
