<?php
declare(strict_types=1);

/**
 * One reel or post, at its own address.
 *
 * This page exists for the share sheet. WhatsApp and Facebook fetch whatever URL they are handed
 * and read the og: tags off the page they land on, so a post can only be previewed with its own
 * cover if it has a page of its own to be previewed from. The app used to share `/feed`, which has
 * no idea which post was meant, and the result was the church logo on every shared reel.
 *
 * `$post` and `$media` are prepared by the route in core/routes.php, which also sets `$metaImage`
 * to the generated share card. See core/ShareCard.php for how the card is composed.
 */
$first = $media[0] ?? null;

$videoUrl = ($first !== null && ($first['type'] ?? '') === 'video' && !empty($first['file_path']))
    ? uploadUrl($first['file_path'])
    : null;
$posterUrl = ($first !== null && !empty($first['thumbnail_path']))
    ? uploadUrl($first['thumbnail_path'])
    : null;
$imageUrl = ($first !== null && ($first['type'] ?? '') !== 'video' && !empty($first['file_path']))
    ? uploadUrl($first['file_path'])
    : null;

$caption = trim((string) ($post['caption'] ?? ''));
$isReel = (string) ($post['post_type'] ?? '') === 'vertical_reel';
$author = trim((string) ($post['author_name'] ?? ''));
$shareUrl = baseUrl('/post/' . (int) $post['id']);
$created = !empty($post['created_at']) ? date('j F Y', (int) strtotime((string) $post['created_at'])) : '';
?>

<article style="max-width:720px;margin:0 auto;padding:24px 18px 56px;">

  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
    <span style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;opacity:0.65;">
      <?= $isReel ? 'Reel' : 'From the feed' ?>
    </span>
    <?php if ($created !== ''): ?>
      <span style="font-size:13px;opacity:0.7;"><?= e($created) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!empty($videoUrl)): ?>
    <video controls playsinline preload="metadata"
           <?= !empty($posterUrl) ? 'poster="' . e($posterUrl) . '"' : '' ?>
           style="width:100%;max-height:78vh;background:#000;border-radius:14px;display:block;">
      <source src="<?= e($videoUrl) ?>">
      Your browser cannot play this video.
    </video>
  <?php elseif (!empty($imageUrl)): ?>
    <img src="<?= e($imageUrl) ?>"
         alt="<?= e($caption !== '' ? mb_strimwidth($caption, 0, 100, '…') : 'Post') ?>"
         style="width:100%;border-radius:14px;display:block;">
  <?php endif; ?>

  <?php if ($caption !== ''): ?>
    <p style="font-size:16px;line-height:1.7;margin:18px 0 0;"><?= nl2br(e($caption)) ?></p>
  <?php endif; ?>

  <p style="font-size:13px;opacity:0.65;margin:14px 0 0;">
    <?php if ($author !== ''): ?>Posted by <?= e($author) ?> · <?php endif; ?>
    <?= (int) ($post['likes_count'] ?? 0) ?> likes · <?= (int) ($post['views_count'] ?? 0) ?> views
  </p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;">
    <button class="btn btn-gold" type="button" id="sharePost">Share this</button>
    <a class="btn" href="/feed" style="text-decoration:none;">See more reels</a>
  </div>
</article>

<script>
// The native sheet where there is one, WhatsApp otherwise, so the button does something useful in
// every browser rather than only on phones.
(function () {
  var button = document.getElementById('sharePost');
  if (!button) return;
  button.addEventListener('click', function () {
    var url = <?= json_encode($shareUrl, JSON_UNESCAPED_SLASHES) ?>;
    var text = <?= json_encode($caption !== '' ? mb_strimwidth($caption, 0, 120, '…') : 'Watch this') ?>;
    if (navigator.share) {
      navigator.share({ title: document.title, text: text, url: url }).catch(function () {});
      return;
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(text + ' ' + url), '_blank', 'noopener');
  });
})();
</script>
