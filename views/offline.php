<?php
declare(strict_types=1);

/**
 * Shown when the phone has no connection and the page was not already cached.
 *
 * This is a real page rather than the browser's error screen, and it is **precached by the service
 * worker** — `public/sw.js` fetches it at install time and serves it whenever a navigation request
 * fails. That is the whole point of it: without one, an installed app that is opened in a tunnel shows
 * the browser's "no internet" page, which is where a person decides the app is broken.
 *
 * Because it is precached it must not depend on anything that changes: it names the church and offers a
 * way back, and nothing else. Anything dynamic here would be frozen at install time and shown later as
 * though it were current. The church name is the one exception, and it is not really one: a service
 * worker is registered per origin, and each church is reached on its own domain, so the cached copy
 * belongs to the church whose site was installed and can only ever be shown on that church's origin.
 */

$s = settings();
$church = trim((string) ($s['site_title'] ?? ''));
?>
<section class="section" style="padding-top:56px; text-align:center;">
  <div class="container">
    <span class="eyebrow"><?= e(t('error.offline.eyebrow')) ?></span>
    <h1 style="font-size:clamp(32px,6vw,56px); margin-top:10px;"><?= e(t('error.offline.title')) ?></h1>
    <p style="color:var(--ink-dim); max-width:480px; margin:0 auto 30px;">
      <?php if ($church !== ''): ?>
        <?= e(t('error.offline.body', [':church' => $church])) ?>
      <?php else: ?>
        <?= e(t('error.offline.body_no_church')) ?>
      <?php endif; ?>
    </p>
    <div class="hero-actions">
      <a href="/" class="btn btn-gold"><?= e(t('error.offline.retry')) ?></a>
      <a href="/feed" class="btn btn-ghost"><?= e(t('common.browse_feed')) ?></a>
    </div>
  </div>
</section>
