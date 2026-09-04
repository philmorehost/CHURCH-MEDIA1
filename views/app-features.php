<?php
declare(strict_types=1);
$metaTitle = 'App Features';
$metaDescription = 'Explore everything the ' . setting('site_title') . ' app offers — reels, the offline Bible, events, sermons, prayer, and push notifications.';
$s = settings();
$appUrl = trim((string) ($s['app_download_url'] ?? ''));
$hasApp = $appUrl !== '';
$mark = e(mb_substr((string) $s['site_title'], 0, 1));
?>

<style>
  /* App Features page — consistent with the site's dark/gold design system. */
  .appf-hero{
    position:relative;
    overflow:hidden;
    text-align:center;
    padding:130px 24px 90px;
    background:
      radial-gradient(1200px 500px at 50% -10%, rgba(232,185,95,.14), transparent 60%),
      var(--bg-0);
  }
  .appf-hero .appf-phone{
    width:96px; height:96px; margin:0 auto 22px; border-radius:26px;
    display:flex; align-items:center; justify-content:center;
    font-size:44px; font-weight:800; color:var(--bg-0);
    background:linear-gradient(135deg,var(--gold-soft),var(--gold));
    box-shadow:0 18px 50px rgba(232,185,95,.35);
  }
  .appf-hero h1{font-size:clamp(34px,6vw,56px); margin:0 0 12px; color:var(--ink);}
  .appf-hero p{color:var(--ink-dim); font-size:16px; max-width:560px; margin:0 auto 30px; line-height:1.7;}
  .appf-hero .hero-actions{display:flex; gap:14px; justify-content:center; flex-wrap:wrap;}
  .appf-hero .hero-actions .btn{min-width:200px;}

  .appf-section{padding:70px 0;}
  .appf-section-head{max-width:620px; margin:0 auto 44px; text-align:center;}
  .appf-section-head h2{font-size:clamp(26px,3.6vw,38px); margin:0 0 10px; color:var(--ink);}
  .appf-section-head p{color:var(--ink-dim); margin:0;}

  .appf-card{
    background:linear-gradient(160deg,var(--panel-solid),#141224);
    border:1px solid var(--border);
    border-radius:20px;
    padding:28px 24px;
    transition:transform .25s ease, border-color .25s ease, box-shadow .25s ease;
    height:100%;
  }
  .appf-card:hover{transform:translateY(-4px); border-color:#e8b95f44; box-shadow:0 18px 44px rgba(0,0,0,.35);}
  .appf-card .ic{
    width:52px; height:52px; border-radius:15px; margin-bottom:18px;
    display:flex; align-items:center; justify-content:center;
    font-size:24px;
    background:rgba(232,185,95,.12);
    border:1px solid rgba(232,185,95,.25);
  }
  .appf-card h3{font-size:17px; margin:0 0 8px; color:var(--ink);}
  .appf-card p{font-size:13.5px; color:var(--ink-dim); line-height:1.65; margin:0;}
  .appf-card ul{margin:12px 0 0; padding-left:0; list-style:none;}
  .appf-card li{font-size:13px; color:var(--ink-dim); line-height:1.7; padding-left:20px; position:relative;}
  .appf-card li::before{content:"✦"; position:absolute; left:0; top:0; color:var(--gold); font-size:11px;}

  .appf-cta{
    text-align:center; padding:70px 24px;
    background:radial-gradient(900px 380px at 50% 0%, rgba(232,185,95,.14), transparent 60%);
  }
  .appf-cta h2{font-size:clamp(26px,4vw,40px); color:var(--ink); margin:0 0 12px;}
  .appf-cta p{color:var(--ink-dim); max-width:520px; margin:0 auto 26px; line-height:1.7;}
  .appf-store{
    display:inline-flex; align-items:center; gap:12px; margin-top:6px;
    background:#fff; color:#111; font-weight:700; font-size:15px;
    padding:15px 22px; border-radius:16px; text-decoration:none;
    box-shadow:0 12px 30px rgba(0,0,0,.4);
    transition:transform .2s ease, box-shadow .2s ease;
  }
  .appf-store:hover{transform:translateY(-2px); box-shadow:0 16px 36px rgba(0,0,0,.5);}
  .appf-store svg{width:26px; height:26px;}
  .appf-store small{display:block; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#555; line-height:1;}
  .appf-store strong{font-size:16px; line-height:1.1;}

  .appf-facts{display:flex; gap:12px; justify-content:center; flex-wrap:wrap; margin-top:34px;}
  .appf-fact{padding:14px 22px; border:1px solid var(--border); border-radius:14px; background:#ffffff06;}
  .appf-fact b{display:block; color:var(--gold-soft); font-size:20px;}
  .appf-fact span{font-size:12px; color:var(--ink-faint);}
</style>

<section class="appf-hero">
  <div class="appf-phone"><?= $mark ?></div>
  <p class="eyebrow">The <?= e((string) $s['site_title']) ?> App</p>
  <h1>Your whole church, in your pocket</h1>
  <p>Watch reels, read the Bible offline, follow events and sermons, pray, and never miss an announcement — with instant push notifications.</p>
  <div class="hero-actions">
    <?php if ($hasApp): ?>
      <a class="btn btn-gold" href="<?= e($appUrl) ?>" target="_blank" rel="noopener">Get it on Google Play ↗</a>
    <?php endif; ?>
    <a class="btn btn-ghost" href="#features">Explore Features ↓</a>
  </div>
</section>

<section class="appf-section" id="features">
  <div class="container">
    <div class="appf-section-head">
      <p class="eyebrow">What's inside</p>
      <h2>Everything you love about church, in one app</h2>
      <p>Built to feel fast, personal, and offline-friendly — whether you're at home, in service, or on the go.</p>
    </div>

    <div class="grid grid-3">
      <div class="appf-card">
        <div class="ic">🎬</div>
        <h3>Reels &amp; Media Feed</h3>
        <ul>
          <li>Full-screen vertical reels that swipe like your favourite apps</li>
          <li>Likes, saves, and Instagram-style comments with replies</li>
          <li>Categories to filter — worship, sermon clips, youth, events</li>
          <li>Pinned reels surface first so nothing important is missed</li>
        </ul>
      </div>

      <div class="appf-card">
        <div class="ic">📖</div>
        <h3>Offline Holy Bible</h3>
        <ul>
          <li>KJV &amp; BBE read fully offline — no internet needed</li>
          <li>Modern online versions (NIV, NLT, NKJV) with languages</li>
          <li>Book, chapter &amp; verse dropdowns — pick, don't type</li>
          <li>Bookmarks, highlights, notes, and font sizing</li>
          <li>Search a verse and it scrolls straight to it</li>
        </ul>
      </div>

      <div class="appf-card">
        <div class="ic">🗓️</div>
        <h3>Events &amp; Sermons</h3>
        <ul>
          <li>Upcoming events with details and RSVP links</li>
          <li>Sermon archive with audio, video, speaker &amp; scripture</li>
          <li>Fresh content from every parish in your network</li>
        </ul>
      </div>

      <div class="appf-card">
        <div class="ic">🙏</div>
        <h3>Prayer Wall</h3>
        <ul>
          <li>Share prayer requests and stand with the community</li>
          <li>Public and private requests</li>
          <li>A community that prays together</li>
        </ul>
      </div>

      <div class="appf-card">
        <div class="ic">🔔</div>
        <h3>Push Notifications</h3>
        <ul>
          <li>Instant alerts for new posts, events, and sermons</li>
          <li>Church announcements delivered straight to your phone</li>
          <li>Tap a notification to jump straight to the content</li>
        </ul>
      </div>

      <div class="appf-card">
        <div class="ic">📍</div>
        <h3>Parishes &amp; More</h3>
        <ul>
          <li>Browse the Province → Zone → Area → Parish network</li>
          <li>Live streaming, contact, giving, and search</li>
          <li>Automatic app updates so you always have the latest</li>
        </ul>
      </div>
    </div>

    <div class="appf-facts">
      <div class="appf-fact"><b>2+</b><span>Offline Bible versions</span></div>
      <div class="appf-fact"><b>Instant</b><span>Reels &amp; notifications</span></div>
      <div class="appf-fact"><b>100%</b><span>Free to download</span></div>
      <div class="appf-fact"><b>Multi</b><span>Parish &amp; province network</span></div>
    </div>
  </div>
</section>

<?php if ($hasApp): ?>
<section class="appf-cta">
  <div class="container">
    <h2>Get the <?= e((string) $s['site_title']) ?> app today</h2>
    <p>Join the community on the go — reels, the Bible, announcements, and everything your church shares, right on your phone.</p>
    <a class="appf-store" href="<?= e($appUrl) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false">
        <path fill="#00A0FF" d="M67.9 12.9C42.1 23.2 25 47.5 25 78.8v354.4c0 31.3 17.1 55.6 42.9 65.9l247.8-247.9L67.9 12.9z"/>
        <path fill="#FFCE00" d="m430.8 292.7-84.9-48.2-76.4 76.4 76.4 76.4 84.9-48.2c41.1-23.2 41.1-81.2 0-104.4z"/>
        <path fill="#EA4335" d="M269.5 255.9 345.9 179.5l-278-157.7C39.4 33 25.5 53.7 25 78.8v2.6l244.5 174.5z"/>
        <path fill="#FF3D00" d="M345.9 332.3 269.5 255.9 25 430.4v2.6c.5 25.1 14.4 45.8 42.9 57l278-157.7z"/>
        <path fill="#00A0FF" d="M269.5 255.9 25 81.4v0l0 0 0 0 244.5 174.5z"/>
      </svg>
      <span><small>Get it on</small><strong>Google Play</strong></span>
    </a>
  </div>
</section>
<?php endif; ?>
