<?php
declare(strict_types=1);
$metaTitle = 'Get the App';
$metaDescription = 'Download ' . setting('site_title') . ' on Google Play.';
$metaRobots = 'noindex, nofollow';
$s = settings();
$appUrl = trim((string) ($s['app_download_url'] ?? ''));
if ($appUrl === '') {
    $appUrl = '/';
}
?>

<style>
  .app-landing{min-height:88vh; display:flex; align-items:center; justify-content:center; padding:40px 20px;}
  .app-card{max-width:440px; width:100%; text-align:center; background:var(--panel-solid); border:1px solid var(--border); border-radius:24px; padding:38px 30px; box-shadow:0 30px 70px rgba(0,0,0,.45);}
  .app-mark{width:84px; height:84px; border-radius:22px; margin:0 auto 18px; display:flex; align-items:center; justify-content:center; font-size:38px; font-weight:800; color:var(--bg-0); background:linear-gradient(135deg,var(--gold-soft),var(--gold)); box-shadow:0 10px 26px rgba(232,185,95,.35);}
  .app-card h1{font-size:24px; margin:0 0 8px; color:var(--ink);}
  .app-card p{color:var(--ink-dim); font-size:14px; line-height:1.6; margin:0 0 24px;}
  .app-play{display:flex; align-items:center; justify-content:center; gap:12px; background:#fff; color:#111; border-radius:16px; padding:14px 18px; font-weight:700; font-size:15px; text-decoration:none; box-shadow:0 10px 24px rgba(0,0,0,.3); transition:transform .2s ease, box-shadow .2s ease;}
  .app-play:hover{transform:translateY(-2px); box-shadow:0 14px 30px rgba(0,0,0,.4);}
  .app-play svg{width:26px; height:26px;}
  .app-continue{display:inline-block; margin-top:18px; color:var(--ink-faint); font-size:13px; text-decoration:none; border-bottom:1px solid transparent;}
  .app-continue:hover{color:var(--gold-soft); border-bottom-color:var(--gold-soft);}
</style>

<section class="app-landing">
  <div class="app-card">
    <div class="app-mark"><?= e(mb_substr((string) $s['site_title'], 0, 1)) ?></div>
    <h1>Get the <?= e((string) $s['site_title']) ?> App</h1>
    <p>Enjoy faster reels, real-time push notifications, the offline Bible, and your whole church in one place.</p>
    <a class="app-play" href="<?= e($appUrl) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false">
        <path fill="#00A0FF" d="M67.9 12.9C42.1 23.2 25 47.5 25 78.8v354.4c0 31.3 17.1 55.6 42.9 65.9l247.8-247.9L67.9 12.9z"/>
        <path fill="#FFCE00" d="m430.8 292.7-84.9-48.2-76.4 76.4 76.4 76.4 84.9-48.2c41.1-23.2 41.1-81.2 0-104.4z"/>
        <path fill="#EA4335" d="M269.5 255.9 345.9 179.5l-278-157.7C39.4 33 25.5 53.7 25 78.8v2.6l244.5 174.5z"/>
        <path fill="#FF3D00" d="M345.9 332.3 269.5 255.9 25 430.4v2.6c.5 25.1 14.4 45.8 42.9 57l278-157.7z"/>
        <path fill="#00A0FF" d="M269.5 255.9 25 81.4v0l0 0 0 0 244.5 174.5z"/>
      </svg>
      Get it on Google Play
    </a>
    <br>
    <a class="app-continue" href="/" onclick="try{localStorage.setItem('cm_skip_app','1')}catch(e){}">Continue to website →</a>
  </div>
</section>
