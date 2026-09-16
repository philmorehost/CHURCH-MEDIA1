<?php
declare(strict_types=1);

/**
 * GET /news/{slug} — one story.
 *
 * The body is printed raw. That is the whole point of storing it sanitised: `News::save()` rebuilds the
 * HTML against a whitelist on the way in — tags, attributes, URL schemes and even the CSS properties a
 * `style` attribute may keep — so what reaches this line has already been through the filter. Escaping it
 * here as well would print the markup an editor wrote as visible text. The trust boundary is the write,
 * and it is in one place.
 *
 * @var string $slug
 */

$post = News::publishedBySlug((string) $slug);

if ($post === null) {
    // A draft is a 404 here rather than a redirect, and so is a story that was deleted after it was
    // shared: a link somebody sent should say plainly that it is gone instead of quietly showing
    // something else.
    http_response_code(404);
    require VIEWS_PATH . '/404.php';
    return;
}

// Fire-and-forget, church-scoped, and deliberately on the post row rather than in analytics_events —
// see the note on the route for why the shared analytics helper cannot be used for news.
News::countView((int) $post['id']);

$related = News::related($post, 3);

$postUrl = '/news/' . rawurlencode((string) $post['slug']);
$shareUrl = baseUrl(ltrim($postUrl, '/'));

$publishedAt = (string) ($post['published_at'] ?: $post['created_at']);
$updatedAt = (string) ($post['updated_at'] ?: $publishedAt);

$metaTitle = News::metaTitle($post);
$metaDescription = News::metaDescription($post);
if (trim($metaDescription) === '') {
    $metaDescription = News::excerptFrom((string) $post['body'], 155);
}
$metaCanonical = $shareUrl;
$metaOgType = 'article';
$metaPublishedTime = date('c', strtotime($publishedAt));

// The featured photo is the preview image when there is one; without it the layout falls back to the
// church logo rather than showing a blank card.
$featuredUrl = !empty($post['featured_path']) ? uploadUrl((string) $post['featured_path']) : null;
if ($featuredUrl !== null) {
    $metaImage = $featuredUrl;
    $metaImageWidth = (int) $post['featured_width'];
    $metaImageHeight = (int) $post['featured_height'];
}

$categoryName = trim((string) ($post['category_name'] ?? ''));
$categorySlug = trim((string) ($post['category_slug'] ?? ''));

$shareLinks = [
    'WhatsApp' => 'https://wa.me/?text=' . rawurlencode((string) $post['title'] . ' — ' . $shareUrl),
    'X' => 'https://twitter.com/intent/tweet?text=' . rawurlencode((string) $post['title']) . '&url=' . rawurlencode($shareUrl),
    'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($shareUrl),
];
?>

<article class="news-article">
  <div class="container news-article-inner">

    <nav class="news-crumbs" aria-label="<?= e(t('news.breadcrumb')) ?>">
      <a href="/"><?= e(t('nav.home')) ?></a><span aria-hidden="true">/</span>
      <a href="/news"><?= e(t('news.title')) ?></a>
      <?php if ($categoryName !== ''): ?>
        <span aria-hidden="true">/</span>
        <a href="/news/category/<?= e($categorySlug) ?>"><?= e($categoryName) ?></a>
      <?php endif; ?>
    </nav>

    <header class="news-article-head">
      <?php if ($categoryName !== ''): ?>
        <a class="news-pill" href="/news/category/<?= e($categorySlug) ?>"><?= e($categoryName) ?></a>
      <?php endif; ?>
      <h1 class="news-article-title"><?= e($post['title']) ?></h1>
      <?php if (trim((string) $post['excerpt']) !== ''): ?>
        <p class="news-article-standfirst"><?= e($post['excerpt']) ?></p>
      <?php endif; ?>
      <div class="news-meta news-article-meta">
        <span><?= e(t('news.by', [':name' => (string) $post['author_name']])) ?></span>
        <span aria-hidden="true">·</span>
        <time datetime="<?= e(date('c', strtotime($publishedAt))) ?>">
          <?= e(date('F j, Y', strtotime($publishedAt))) ?>
        </time>
        <span aria-hidden="true">·</span>
        <span><?= e(t('news.min_read', [':count' => (string) News::readingMinutes((string) $post['body'])])) ?></span>
      </div>
    </header>

    <?php if ($featuredUrl !== null): ?>
      <?php /* The stored image is already capped at 1280px on its long edge, so nothing larger than that
               can reach a reader's phone — and the dimensions go on the tag so the space is reserved and
               the text does not jump as it arrives. */ ?>
      <figure class="news-article-figure">
        <img src="<?= e($featuredUrl) ?>" alt="<?= e($post['featured_alt'] ?? '') ?>"
             loading="eager" decoding="async" fetchpriority="high"
             <?php if ((int) $post['featured_width'] > 0 && (int) $post['featured_height'] > 0): ?>
               width="<?= (int) $post['featured_width'] ?>" height="<?= (int) $post['featured_height'] ?>"
             <?php endif; ?>>
      </figure>
    <?php endif; ?>

    <?php /* Sanitised by News::save() on the way in — see the note at the top of this file. */ ?>
    <div class="news-body"><?= (string) $post['body'] ?></div>

    <?php if ($updatedAt !== $publishedAt && strtotime($updatedAt) > strtotime($publishedAt)): ?>
      <p class="news-updated"><?= e(t('news.updated', [':date' => date('F j, Y', strtotime($updatedAt))])) ?></p>
    <?php endif; ?>

    <div class="news-share">
      <span class="news-share-label"><?= e(t('news.share')) ?></span>
      <?php foreach ($shareLinks as $label => $href): ?>
        <?php /* Plain links, not buttons with event handlers: they work with JavaScript off and they are
                 not something the Content-Security-Policy has to be loosened for. */ ?>
        <a class="chip" href="<?= e($href) ?>" target="_blank" rel="noopener nofollow"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php /* A copy field rather than a copy button: reading the address off the screen works on every
               device and needs no JavaScript at all. An `onfocus="this.select()"` would be ignored
               anyway — the public site sends `script-src 'self'`, which covers inline event handlers. */ ?>
      <input class="news-share-url" type="text" value="<?= e($shareUrl) ?>" readonly
             aria-label="<?= e(t('news.share_url')) ?>">
    </div>

    <div class="news-back">
      <a class="btn btn-ghost btn-sm" href="<?= e($categoryName !== '' ? '/news/category/' . $categorySlug : '/news') ?>">
        ← <?= e($categoryName !== '' ? $categoryName : t('news.title')) ?>
      </a>
    </div>
  </div>
</article>

<?php if ($related): ?>
  <section class="section news-related" style="padding-top:0;">
    <div class="container">
      <div class="section-head">
        <h2><?= e(t('news.related')) ?></h2>
      </div>
      <div class="grid grid-3 news-grid">
        <?php foreach ($related as $more): ?>
          <article class="news-card">
            <a class="news-card-media" href="/news/<?= e($more['slug']) ?>" tabindex="-1" aria-hidden="true">
              <?php if (!empty($more['featured_path'])): ?>
                <img src="<?= e(uploadUrl($more['featured_path'])) ?>" alt="" loading="lazy" decoding="async">
              <?php else: ?>
                <span class="news-blank"></span>
              <?php endif; ?>
            </a>
            <div class="news-card-body">
              <?php if (!empty($more['category_name'])): ?>
                <span class="news-pill"><?= e($more['category_name']) ?></span>
              <?php endif; ?>
              <h3 class="news-card-title">
                <a href="/news/<?= e($more['slug']) ?>"><?= e($more['title']) ?></a>
              </h3>
              <div class="news-meta">
                <time datetime="<?= e(date('c', strtotime((string) $more['published_at']))) ?>">
                  <?= e(date('F j, Y', strtotime((string) $more['published_at']))) ?>
                </time>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php
/*
 * `NewsArticle` is the shape a search engine expects for a single story, and it is what makes a result
 * eligible for the story-specific treatment — the headline, the outlet, the date, the image — instead of
 * a plain blue link. `headline` is trimmed to 110 characters because that is what the schema recommends
 * and what a result actually shows; the full title is in `<title>` and in og:title regardless.
 *
 * `JSON_UNESCAPED_UNICODE` keeps Yoruba readable in the source of the page. `JSON_UNESCAPED_SLASHES` is
 * deliberately absent: it would let a title containing `</script>` close this block.
 */
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => mb_strimwidth((string) $post['title'], 0, 110, '…'),
    'description' => $metaDescription,
    'datePublished' => date('c', strtotime($publishedAt)),
    'dateModified' => date('c', strtotime($updatedAt)),
    'inLanguage' => Lang::current(),
    'url' => $shareUrl,
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $shareUrl],
    'author' => ['@type' => 'Organization', 'name' => (string) $post['author_name']],
    'publisher' => ['@type' => 'Organization', 'name' => (string) setting('site_title')],
];

$logoPath = (string) setting('logo_path');
if ($logoPath !== '') {
    $schema['publisher']['logo'] = ['@type' => 'ImageObject', 'url' => (string) uploadUrl($logoPath)];
}
if ($featuredUrl !== null) {
    $schema['image'] = [$featuredUrl];
}
if ($categoryName !== '') {
    $schema['articleSection'] = $categoryName;
}

$breadcrumb = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => t('nav.home'), 'item' => baseUrl()],
    ['@type' => 'ListItem', 'position' => 2, 'name' => t('news.title'), 'item' => baseUrl('news')],
];
if ($categoryName !== '') {
    $breadcrumb[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $categoryName, 'item' => baseUrl('news/category/' . rawurlencode($categorySlug))];
}
$breadcrumb[] = [
    '@type' => 'ListItem',
    'position' => count($breadcrumb) + 1,
    'name' => (string) $post['title'],
    'item' => $shareUrl,
];
$schema['breadcrumb'] = ['@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumb];
?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE) ?></script>
