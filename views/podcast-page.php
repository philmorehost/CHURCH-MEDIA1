<?php
declare(strict_types=1);

/**
 * /podcast — a page for people, as opposed to /podcast.xml which is for apps.
 *
 * The feed URL is what a listener actually needs, so it is shown in full and selectable. A phone
 * cannot easily open a raw RSS document, so this page exists to be the thing you send someone.
 */
$metaTitle = 'Podcast';

$feedUrl = baseUrl('/podcast.xml');
$siteName = (string) (setting('site_title') ?: 'Our Podcast');

$series = Series::all([], true);
$episodes = [];
foreach ($series as $sr) {
    foreach (Series::sermons((int) $sr['id'], true, 20) as $ep) {
        if (!empty($ep['audio_url']) || !empty($ep['audio_path'])) {
            $ep['_series_title'] = (string) $sr['title'];
            $episodes[] = $ep;
        }
    }
}
usort($episodes, static fn (array $a, array $b): int => strcmp((string) $b['published_at'], (string) $a['published_at']));
$episodes = array_slice($episodes, 0, 12);

// Podcast apps can be opened straight to a search for this show, but none of them accept a
// feed URL directly in a link, so the listener has to paste it. Hence the copy button.
$searchTerm = rawurlencode($siteName);
?>

<section class="section" style="padding-top:56px;">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Listen</span>
      <h2>The <?= e($siteName) ?> Podcast</h2>
      <p>Sermons and teaching, delivered to your phone as they are published.</p>
    </div>

    <div class="card" style="max-width:720px;">
      <h3 style="margin-top:0;">Subscribe with your podcast app</h3>
      <ol style="color:var(--ink-dim);line-height:1.9;padding-left:20px;">
        <li>Copy the feed address below.</li>
        <li>Open your podcast app and choose <strong>Add by URL</strong> — in Apple Podcasts that is
          Library → ⋯ → Add a Show by URL.</li>
        <li>Paste the address. New episodes will arrive by themselves.</li>
      </ol>

      <label for="feed-url">Feed address</label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="text" id="feed-url" value="<?= e($feedUrl) ?>" readonly onclick="this.select();"
               style="flex:1;min-width:240px;font-family:ui-monospace,Menlo,Consolas,monospace;">
        <button type="button" class="btn" id="copy-feed">Copy</button>
      </div>

      <div class="chip-row" style="margin-top:22px;">
        <a class="chip" target="_blank" rel="noopener"
           href="https://podcasts.apple.com/search?term=<?= $searchTerm ?>">Apple Podcasts</a>
        <a class="chip" target="_blank" rel="noopener"
           href="https://open.spotify.com/search/<?= $searchTerm ?>">Spotify</a>
        <a class="chip" target="_blank" rel="noopener"
           href="https://www.google.com/search?q=<?= $searchTerm ?>+podcast">Google</a>
        <a class="chip" href="/podcast.xml">Open the feed</a>
      </div>

      <p class="hint" style="margin-top:18px;font-size:12.5px;color:var(--ink-dim);">
        This feed is public and read-only. It lists published sermons that have audio attached —
        an episode appears as soon as it is published, and disappears from the feed if it is
        unpublished or its audio is removed.
      </p>
    </div>

    <?php if ($episodes): ?>
      <div class="section-head" style="margin-top:56px;">
        <h3>Latest episodes</h3>
      </div>
      <div class="grid grid-3">
        <?php foreach ($episodes as $ep): ?>
          <a href="/sermons/<?= e($ep['slug']) ?>" class="glass-card info-card reveal in">
            <div class="cover">
              <?php if (!empty($ep['cover_image'])): ?>
                <img src="<?= e(uploadUrl((string) $ep['cover_image'])) ?>" alt="" loading="lazy">
              <?php endif; ?>
            </div>
            <div class="body">
              <h3><?= e($ep['title']) ?></h3>
              <div class="meta">
                <span><?= e((string) $ep['_series_title']) ?></span>
                <span><?= e(date('M j, Y', strtotime((string) $ep['published_at']))) ?></span>
                <?php if (!empty($ep['duration_seconds'])): ?>
                  <span><?= e(PodcastFeed::humanDuration((int) $ep['duration_seconds'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state" style="margin-top:40px;">No episodes have audio attached yet.</div>
    <?php endif; ?>
  </div>
</section>

<script>
document.getElementById('copy-feed')?.addEventListener('click', function () {
  var field = document.getElementById('feed-url');
  field.select();
  var done = function () {
    var b = document.getElementById('copy-feed');
    var was = b.textContent;
    b.textContent = 'Copied';
    setTimeout(function () { b.textContent = was; }, 2000);
  };
  // navigator.clipboard needs a secure context, which a church site on plain http will not be.
  if (navigator.clipboard) {
    navigator.clipboard.writeText(field.value).then(done, done);
  } else {
    document.execCommand('copy');
    done();
  }
});
</script>
