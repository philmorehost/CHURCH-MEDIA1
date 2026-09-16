<?php
declare(strict_types=1);

/*
 * The reviewer's preview.
 *
 * `/admin/ads?action=preview&id=N` renders THIS view with `$previewAd` set, so the reviewer sees the site's
 * own feed, its own stylesheet, its own JavaScript and — through `AdFeed::feedItem()` — its own shaping,
 * with one advert in it. A preview built as a separate card would be a picture of the advert rather than
 * the advert, and the two can disagree about exactly the things worth checking: the destination link, the
 * media shape, the label that says it is sponsored.
 *
 * The advert itself is fetched from the API rather than printed here, so what is on screen is what would
 * actually be served. The bar above it is the only thing a visitor would not see, and it says so.
 */
$previewAd ??= null;

$metaTitle = $previewAd !== null ? 'Preview: ' . ($previewAd['title'] ?? '') : 'Reels';
$metaDescription = 'Watch the latest reels from ' . e(setting('site_title')) . ' — worship, sermon clips, and moments from the community.';
$categories = Database::getInstance()->getConnection()
    ->query('SELECT c.slug, c.name FROM media_categories c WHERE EXISTS (SELECT 1 FROM media_post_categories mpc WHERE mpc.media_category_id = c.id) ORDER BY c.name ASC')
    ->fetchAll();
?>
<link rel="stylesheet" href="<?= asset('css/feed.css') ?>">

<?php if ($previewAd !== null): ?>
  <div class="ad-preview-bar" role="status">
    <strong class="ad-preview-tag">Preview</strong>
    <span>
      “<?= e($previewAd['title']) ?>” — this is the card a visitor sees. It is
      <strong>not live</strong> (status: <?= e((string) $previewAd['status']) ?>).
    </span>
    <a href="/admin/ads">← Back to Ads</a>
  </div>
<?php endif; ?>

<div class="reels-page<?= $previewAd !== null ? ' ad-preview' : '' ?>">
  <header class="reels-top">
    <a href="/" class="reels-brand"><span class="mark">R</span> Reels</a>
    <nav class="reels-tabs">
      <button type="button" class="tab active" data-view="all">For You</button>
      <button type="button" class="tab" data-view="saved">Saved</button>
    </nav>
    <a href="/search" class="reels-search" aria-label="Search">🔍</a>
  </header>

  <div class="reels-chips" id="reelsChips">
    <button class="chip active" data-category="">All</button>
    <?php foreach ($categories as $cat): ?>
      <button class="chip" data-category="<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></button>
    <?php endforeach; ?>
  </div>

  <button type="button" id="newPostsPill" hidden>⬆ New posts — tap to refresh</button>

  <?php
  /*
   * A preview is pointed at the preview payload, not at the ordinary feed. Without this the reviewer would
   * be shown the live feed — which, for an advert that has not been approved, does not contain it at all,
   * so the page would quietly prove nothing.
   *
   * feed.js appends its own query arguments, so it chooses the separator: see loadPage().
   */
  ?>
  <div class="reels-scroller" id="feedScroller" data-endpoint="<?= e($previewAd !== null ? '/api/feed?preview_ad=' . (int) $previewAd['id'] : '/api/feed') ?>">
    <div class="feed-loading" id="feedLoading">Loading reels…</div>
  </div>
</div>

<!-- Comment sheet -->
<div class="comment-sheet" id="commentSheet" hidden>
  <div class="comment-backdrop" data-close-comments></div>
  <div class="comment-panel">
    <div class="comment-head">
      <span>Comments <span class="comment-live"><span class="live-dot"></span> LIVE</span></span>
      <button type="button" class="comment-close" data-close-comments>✕</button>
    </div>
    <div class="comment-list" id="commentList"><div class="feed-loading">Loading comments…</div></div>
    <div class="comment-reply-bar" id="commentReplyBar" hidden>
      <span id="commentReplyLabel">Replying to…</span>
      <button type="button" id="commentReplyCancel" aria-label="Cancel reply">✕</button>
    </div>
    <form class="comment-form" id="commentForm">
      <input type="text" name="name" id="commentName" maxlength="100" placeholder="Your name (optional)">
      <div class="comment-compose-row">
        <button type="button" class="comment-tool comment-emoji-btn" id="commentEmojiBtn" aria-label="Add emoji">😊</button>
        <div class="comment-emoji-picker" id="commentEmojiPicker" hidden></div>
        <label class="comment-tool comment-attach" aria-label="Attach image">📷
          <input type="file" id="commentImage" accept="image/*" hidden>
        </label>
        <textarea name="message" id="commentMessage" maxlength="1000" rows="2" placeholder="Add a comment…"></textarea>
        <button class="btn" type="submit">Post</button>
      </div>
      <div class="comment-attach-preview" id="commentImagePreview" hidden>
        <img id="commentImagePreviewImg" alt="attachment">
        <button type="button" id="commentImageRemove" aria-label="Remove image">✕</button>
      </div>
    </form>
  </div>
</div>

<template id="feedSlideTemplate">
  <section class="reel-slide">
    <div class="reel-media"></div>
    <div class="reel-scrim"></div>
    <div class="reel-dots"></div>
    <button type="button" class="reel-mute-btn" aria-label="Toggle sound">🔇</button>

    <div class="reel-info">
      <div class="reel-author-row">
        <span class="reel-avatar"></span>
        <span class="reel-username"></span>
        <span class="reel-verified" title="Verified account">✓</span>
        <button type="button" class="reel-follow">Follow</button>
      </div>
      <div class="reel-church"></div>
      <div class="reel-caption">
        <span class="reel-author-name"></span>
        <span class="reel-text"></span>
        <button type="button" class="reel-more">more</button>
      </div>
      <div class="reel-music"><span class="reel-note">♪</span><span>Original audio</span></div>
      <div class="reel-pinned" hidden></div>
      <div class="reel-date"></div>
      <div class="reel-cats"></div>
    </div>

    <div class="reel-actions">
      <button type="button" class="action reel-like" aria-label="Like">
        <span class="icon">♥</span><span class="count like-count">0</span>
      </button>
      <button type="button" class="action reel-comment" aria-label="Comments">
        <span class="icon">💬</span><span class="count comment-count">0</span>
      </button>
      <button type="button" class="action reel-share" aria-label="Share"><span class="icon">↗</span></button>
      <button type="button" class="action reel-save" aria-label="Save"><span class="icon">🔖</span></button>
      <span class="reel-actions-spacer"></span>
      <button type="button" class="action reel-more-actions" aria-label="More">⋯</button>
    </div>

    <div class="reel-mute-badge">Tap to unmute</div>
    <div class="reel-heart-burst">♥</div>
  </section>
</template>

<script src="<?= asset('js/feed.js') ?>"></script>
