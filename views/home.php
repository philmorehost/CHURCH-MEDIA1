<?php
declare(strict_types=1);

$pdo = Database::getInstance()->getConnection();
$s = settings();

$recentPosts = $pdo->query('
    SELECT p.id, p.slug, p.caption,
      (SELECT type FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover_type,
      (SELECT file_path FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover_path,
      (SELECT thumbnail_path FROM media_post_items WHERE media_post_id = p.id ORDER BY sort_order ASC LIMIT 1) AS cover_thumb
    FROM media_posts p WHERE p.is_published = 1 ORDER BY p.created_at DESC LIMIT 6
')->fetchAll();

$upcomingEvents = $pdo->query('SELECT * FROM events WHERE is_published = 1 AND start_at >= NOW() ORDER BY start_at ASC LIMIT 3')->fetchAll();
$recentSermons = $pdo->query('SELECT * FROM sermons WHERE is_published = 1 ORDER BY published_at DESC LIMIT 3')->fetchAll();
$serviceTimes = $s['service_times'] ? (json_decode((string) $s['service_times'], true) ?: []) : [];
$isLive = !empty($s['livestream_is_live']);
?>

<section class="hero<?= $s['hero_image_path'] ? ' has-image' : '' ?>">
  <?php if ($s['hero_image_path']): ?>
    <img class="hero-img" src="<?= e(uploadUrl($s['hero_image_path'])) ?>" alt="" fetchpriority="high">
    <div class="hero-shade"></div>
  <?php endif; ?>
  <div class="hero-content">
    <span class="eyebrow"><?= e($s['hero_eyebrow'] ?? 'Welcome Home') ?></span>
    <h1><?= e($s['hero_tagline'] ?? $s['site_tagline'] ?? $s['site_title']) ?></h1>
    <?php if ($s['hero_scripture'] ?? null): ?><p class="scripture"><?= e($s['hero_scripture']) ?></p><?php endif; ?>
    <div class="hero-actions">
      <?php if ($isLive): ?>
        <a href="/live" class="btn btn-gold">▶ Watch Live Now</a>
      <?php else: ?>
        <a href="<?= e($s['hero_cta_primary_url'] ?? '/about') ?>" class="btn btn-gold"><?= e($s['hero_cta_primary_label'] ?? 'Plan Your Visit') ?></a>
      <?php endif; ?>
      <a href="<?= e($s['hero_cta_secondary_url'] ?? '/feed') ?>" class="btn btn-ghost"><?= e($s['hero_cta_secondary_label'] ?? 'Watch the Feed') ?></a>
    </div>
  </div>
  <div class="hero-scroll">Scroll</div>
</section>

<section class="section reveal">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Community</span>
      <h2>From Our Feed</h2>
      <p>Moments from worship, youth nights, and everyday life together.</p>
    </div>
    <?php if (!$recentPosts): ?>
      <div class="empty-state">No posts yet — check back soon, or <a href="/admin" style="color:var(--gold-soft);">publish the first one</a>.</div>
    <?php else: ?>
      <div class="grid grid-4">
        <?php foreach ($recentPosts as $p): ?>
          <a href="/feed?post=<?= (int) $p['id'] ?>" class="media-card">
            <?php $img = $p['cover_type'] === 'video' ? $p['cover_thumb'] : $p['cover_path']; ?>
            <?php if ($img): ?><img src="<?= e(uploadUrl($img)) ?>" alt="" loading="lazy"><?php endif; ?>
            <span class="badge-type"><?= $p['cover_type'] === 'video' ? '▶ Reel' : 'Photo' ?></span>
            <div class="overlay"><div class="cap"><?= e(mb_strimwidth((string) $p['caption'], 0, 60, '…')) ?></div></div>
          </a>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center; margin-top:36px;"><a href="/feed" class="btn btn-outline">View Full Feed</a></div>
    <?php endif; ?>
  </div>
</section>

<section class="section reveal" style="background:var(--bg-1);">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Save the Date</span>
      <h2>What's Happening</h2>
    </div>
    <?php if (!$upcomingEvents): ?>
      <div class="empty-state">No upcoming events posted right now.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($upcomingEvents as $ev): ?>
          <a href="/events/<?= e($ev['slug']) ?>" class="glass-card info-card">
            <div class="cover">
              <?php if ($ev['cover_image']): ?><img src="<?= e(uploadUrl($ev['cover_image'])) ?>" alt="" loading="lazy"><?php endif; ?>
              <div class="date-badge">
                <div class="d"><?= e(date('j', strtotime($ev['start_at']))) ?></div>
                <div class="m"><?= e(date('M', strtotime($ev['start_at']))) ?></div>
              </div>
            </div>
            <div class="body">
              <h3><?= e($ev['title']) ?></h3>
              <div class="meta">
                <span>🕘 <?= e(date('g:i A', strtotime($ev['start_at']))) ?></span>
                <?php if ($ev['location']): ?><span>📍 <?= e($ev['location']) ?></span><?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center; margin-top:36px;"><a href="/events" class="btn btn-outline">See All Events</a></div>
    <?php endif; ?>
  </div>
</section>

<section class="section reveal">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Sunday Word</span>
      <h2>Latest Sermons</h2>
    </div>
    <?php if (!$recentSermons): ?>
      <div class="empty-state">No sermons published yet.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($recentSermons as $sm): ?>
          <a href="/sermons/<?= e($sm['slug']) ?>" class="glass-card info-card">
            <div class="cover">
              <?php if ($sm['cover_image']): ?><img src="<?= e(uploadUrl($sm['cover_image'])) ?>" alt="" loading="lazy"><?php endif; ?>
            </div>
            <div class="body">
              <h3><?= e($sm['title']) ?></h3>
              <div class="meta">
                <?php if ($sm['speaker']): ?><span><?= e($sm['speaker']) ?></span><?php endif; ?>
                <span><?= e(date('M j, Y', strtotime($sm['published_at']))) ?></span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center; margin-top:36px;"><a href="/sermons" class="btn btn-outline">Browse All Sermons</a></div>
    <?php endif; ?>
  </div>
</section>

<?php if ($serviceTimes): ?>
<section class="section reveal" style="background:var(--bg-1);">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Join Us</span>
      <h2>Service Times</h2>
    </div>
    <div class="service-strip">
      <?php foreach ($serviceTimes as $st): ?>
        <div class="service-pill">
          <div class="label"><?= e($st['label']) ?></div>
          <div class="time"><?= e($st['time']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section reveal">
  <div class="container" style="text-align:center;">
    <h2 style="font-size:clamp(26px,4vw,38px);">However far, however near — there's a seat for you.</h2>
    <div class="hero-actions" style="margin-top:24px;">
      <a href="/contact" class="btn btn-gold">Get in Touch</a>
      <a href="/give" class="btn btn-ghost">Give Online</a>
    </div>
  </div>
</section>
