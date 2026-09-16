<?php
declare(strict_types=1);

/**
 * GET /news and GET /news/category/{slug} — the news archive.
 *
 * The public half of the feature whose authoring screens are `admin/news.php`. Everything it reads comes
 * from `core/News.php`, so every query here is already confined to the church being served; this file
 * makes no decisions about who may see what.
 *
 * Four things it does on purpose:
 *
 * 1. **Pages are twelve items, and the lead story is one of the twelve.** `News::leadStory()` and
 *    `News::published()` share an ORDER BY, so the lead is the first row of the list it heads — which
 *    means the page has to fetch twelve rows and then drop the lead, not fetch twelve *plus* a lead.
 *    Getting that wrong duplicates the twelfth story on page two and is invisible for a church with
 *    four posts.
 * 2. **The page count comes from `News::publishedCount()`, not `News::count('published')`.** The two
 *    differ on a post dated ahead of its time, and the difference is a "next" link onto an empty page.
 * 3. **A search result is `noindex`.** `/news?q=…` produces a thin, near-duplicate list for every term
 *    anybody types; letting a crawler keep those is how a church's real stories get buried under its
 *    own search pages.
 * 4. **The canonical carries the page number.** Without it every page of the archive claims to be page
 *    one, and pages two onwards are not indexed at all.
 *
 * @var string      $action 'index' or 'category'
 * @var string|null $slug   the category slug when $action is 'category'
 */

$action = ($action ?? 'index') === 'category' ? 'category' : 'index';

$category = null;
if ($action === 'category') {
    $category = News::categoryBySlug((string) ($slug ?? ''));
    if ($category === null) {
        // A category that does not exist is indistinguishable, to a visitor, from a story that was
        // deleted — and the honest answer is the same page either way.
        http_response_code(404);
        require VIEWS_PATH . '/404.php';
        return;
    }
}

$perPage = 12;
$search = trim((string) ($_GET['q'] ?? ''));
$categoryId = $category !== null ? (int) $category['id'] : null;

$total = News::publishedCount($categoryId, $search);
$pages = max(1, (int) ceil($total / $perPage));
// A page number from the query string is a number a stranger typed, so it is clamped rather than
// trusted: `?page=9999` shows the last page instead of an empty one.
$page = max(1, min((int) ($_GET['page'] ?? 1), $pages));
$offset = ($page - 1) * $perPage;

$posts = News::published($categoryId, $perPage, $offset, $search);

// The lead story heads the front page only — not page two, where a reader has already scrolled past it,
// and not a category archive, where the newest post in that category is already the first card.
$lead = ($action === 'index' && $page === 1 && $search === '') ? News::leadStory() : null;
if ($lead !== null) {
    $posts = array_values(array_filter($posts, static fn (array $p): bool => (int) $p['id'] !== (int) $lead['id']));
}

$categories = News::categories();

/** The archive's own path, which every page, chip and canonical URL is built from. */
$archivePath = $category !== null ? '/news/category/' . (string) $category['slug'] : '/news';

/** A URL for page $n, carrying the search term along so paging never loses the reader's query. */
$pageUrl = static function (int $n) use ($archivePath, $search): string {
    $query = [];
    if ($n > 1) {
        $query['page'] = $n;
    }
    if ($search !== '') {
        $query['q'] = $search;
    }
    return $archivePath . ($query ? '?' . http_build_query($query) : '');
};

$heading = $category !== null ? (string) $category['name'] : t('news.title');
$intro = ($category !== null && trim((string) $category['description']) !== '')
    ? (string) $category['description']
    : t('news.intro', [':church' => (string) setting('site_title')]);

$metaTitle = $category !== null ? $heading : t('news.title');
$metaDescription = mb_strimwidth($intro, 0, 155, '…');

// See the note at the top: a search page is a thin duplicate and a `?page=` URL is the same list, so the
// canonical points at the archive itself and the crawler is told not to keep the search variant.
$metaCanonical = baseUrl(ltrim($search !== '' ? $archivePath : $pageUrl($page), '/'));
if ($search !== '') {
    $metaRobots = 'noindex, follow';
}

/** Dimension attributes, so the browser reserves the right height and the page does not jump as photos load. */
$imageAttrs = static function (array $post): string {
    $w = (int) ($post['featured_width'] ?? 0);
    $h = (int) ($post['featured_height'] ?? 0);
    return ($w > 0 && $h > 0) ? ' width="' . $w . '" height="' . $h . '"' : '';
};

$postUrl = static fn (array $post): string => '/news/' . rawurlencode((string) $post['slug']);

$dateLine = static fn (array $post): string => date('F j, Y', strtotime((string) ($post['published_at'] ?? $post['created_at'])));
?>

<section class="section news-archive">
  <div class="container">

    <?php if ($category !== null): ?>
      <nav class="news-crumbs" aria-label="<?= e(t('news.breadcrumb')) ?>">
        <a href="/"><?= e(t('nav.home')) ?></a><span aria-hidden="true">/</span>
        <a href="/news"><?= e(t('news.title')) ?></a><span aria-hidden="true">/</span>
        <span aria-current="page"><?= e($heading) ?></span>
      </nav>
    <?php endif; ?>

    <div class="section-head">
      <span class="eyebrow"><?= e(t('news.eyebrow')) ?></span>
      <h1 class="news-title"><?= e($heading) ?></h1>
      <p><?= e($intro) ?></p>
    </div>

    <?php if ($categories || $total > 0): ?>
      <div class="news-tools">
        <div class="chip-row news-chips">
          <a href="/news" class="chip <?= $category === null ? 'active' : '' ?>"><?= e(t('news.all_categories')) ?></a>
          <?php foreach ($categories as $chip): ?>
            <a href="/news/category/<?= e($chip['slug']) ?>" class="chip <?= $category !== null && (int) $chip['id'] === (int) $category['id'] ? 'active' : '' ?>">
              <?= e($chip['name']) ?>
              <?php if ((int) ($chip['post_count'] ?? 0) > 0): ?>
                <span class="news-chip-count"><?= (int) $chip['post_count'] ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php /* A plain GET form: it works with JavaScript off, the result is a URL a reader can share,
                 and it is the one search control on the site that is scoped to news rather than to
                 everything. `action` is the archive's own path so a search inside a category stays in it. */ ?>
        <form class="news-search" method="get" action="<?= e($archivePath) ?>" role="search">
          <label for="news-q" class="news-search-label"><?= e(t('news.search_label')) ?></label>
          <input type="search" id="news-q" name="q" value="<?= e($search) ?>"
                 placeholder="<?= e(t('news.search_placeholder')) ?>" maxlength="80">
          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('news.search_button')) ?></button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($lead !== null): ?>
      <article class="news-lead">
        <a class="news-lead-media" href="<?= e($postUrl($lead)) ?>" tabindex="-1" aria-hidden="true">
          <?php if (!empty($lead['featured_path'])): ?>
            <img src="<?= e(uploadUrl($lead['featured_path'])) ?>" alt=""
                 loading="eager" decoding="async" fetchpriority="high"<?= $imageAttrs($lead) ?>>
          <?php else: ?>
            <span class="news-blank"></span>
          <?php endif; ?>
        </a>
        <div class="news-lead-body">
          <span class="news-flag"><?= e(t('news.lead_badge')) ?></span>
          <?php if (!empty($lead['category_name'])): ?>
            <a class="news-pill" href="/news/category/<?= e($lead['category_slug']) ?>"><?= e($lead['category_name']) ?></a>
          <?php endif; ?>
          <h2 class="news-lead-title">
            <a href="<?= e($postUrl($lead)) ?>"><?= e($lead['title']) ?></a>
          </h2>
          <?php if (trim((string) $lead['excerpt']) !== ''): ?>
            <p class="news-lead-text"><?= e(mb_strimwidth((string) $lead['excerpt'], 0, 220, '…')) ?></p>
          <?php endif; ?>
          <div class="news-meta">
            <time datetime="<?= e(date('c', strtotime((string) $lead['published_at']))) ?>"><?= e($dateLine($lead)) ?></time>
            <span aria-hidden="true">·</span>
            <span><?= e(t('news.min_read', [':count' => (string) News::readingMinutes((string) $lead['body'])])) ?></span>
          </div>
          <a class="btn btn-gold btn-sm" href="<?= e($postUrl($lead)) ?>"><?= e(t('news.read_more')) ?></a>
        </div>
      </article>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <div class="empty-state">
        <?php if ($search !== ''): ?>
          <?= e(t('news.no_results', [':q' => $search])) ?>
        <?php else: ?>
          <?= e(t('news.empty')) ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="grid grid-3 news-grid">
        <?php foreach ($posts as $post): ?>
          <article class="news-card">
            <a class="news-card-media" href="<?= e($postUrl($post)) ?>" tabindex="-1" aria-hidden="true">
              <?php if (!empty($post['featured_path'])): ?>
                <img src="<?= e(uploadUrl($post['featured_path'])) ?>" alt=""
                     loading="lazy" decoding="async"<?= $imageAttrs($post) ?>>
              <?php else: ?>
                <span class="news-blank"></span>
              <?php endif; ?>
            </a>
            <div class="news-card-body">
              <?php if (!empty($post['category_name'])): ?>
                <a class="news-pill" href="/news/category/<?= e($post['category_slug']) ?>"><?= e($post['category_name']) ?></a>
              <?php endif; ?>
              <h3 class="news-card-title">
                <a href="<?= e($postUrl($post)) ?>"><?= e($post['title']) ?></a>
              </h3>
              <?php if (trim((string) $post['excerpt']) !== ''): ?>
                <p class="news-card-text"><?= e(mb_strimwidth((string) $post['excerpt'], 0, 130, '…')) ?></p>
              <?php endif; ?>
              <div class="news-meta">
                <time datetime="<?= e(date('c', strtotime((string) $post['published_at']))) ?>"><?= e($dateLine($post)) ?></time>
                <span aria-hidden="true">·</span>
                <span><?= e(t('news.min_read', [':count' => (string) News::readingMinutes((string) $post['body'])])) ?></span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <?php
      // Five numbers at most, centred on the page being read, with the first and last still one click
      // away. A church with three posts and a church with three hundred both get a bar that fits.
      $windowSize = 5;
      $from = max(1, min($page - (int) floor($windowSize / 2), $pages - $windowSize + 1));
      $to = min($pages, $from + $windowSize - 1);
      ?>
      <nav class="pagination news-pagination" aria-label="<?= e(t('news.pagination')) ?>">
        <?php if ($page > 1): ?>
          <a class="chip" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">← <?= e(t('news.newer')) ?></a>
        <?php endif; ?>

        <?php if ($from > 1): ?>
          <a class="chip" href="<?= e($pageUrl(1)) ?>">1</a>
          <span class="news-gap" aria-hidden="true">…</span>
        <?php endif; ?>

        <?php for ($n = $from; $n <= $to; $n++): ?>
          <a class="chip <?= $n === $page ? 'active' : '' ?>" href="<?= e($pageUrl($n)) ?>"
             <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
        <?php endfor; ?>

        <?php if ($to < $pages): ?>
          <span class="news-gap" aria-hidden="true">…</span>
          <a class="chip" href="<?= e($pageUrl($pages)) ?>"><?= $pages ?></a>
        <?php endif; ?>

        <?php if ($page < $pages): ?>
          <a class="chip" href="<?= e($pageUrl($page + 1)) ?>" rel="next"><?= e(t('news.older')) ?> →</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</section>

<?php
/*
 * Structured data.
 *
 * `CollectionPage` with an `ItemList` is what tells a search engine this URL is a hub of stories rather
 * than one story, and it is the shape that lets a result show a carousel of the church's own headlines.
 *
 * The encode flags matter here and are not the layout's: `JSON_UNESCAPED_SLASHES` is deliberately NOT
 * used, because a headline containing `</script>` would then close this block and everything after it
 * would be parsed as page markup. With the default escaping that becomes `<\/script>`, which is still
 * the same string once a JSON parser has read it.
 */
$listItems = [];
$position = 0;
if ($lead !== null) {
    $position++;
    $listItems[] = [
        '@type' => 'ListItem',
        'position' => $position,
        'name' => (string) $lead['title'],
        'url' => baseUrl(ltrim($postUrl($lead), '/')),
    ];
}
foreach ($posts as $post) {
    $position++;
    $listItems[] = [
        '@type' => 'ListItem',
        'position' => $position,
        'name' => (string) $post['title'],
        'url' => baseUrl(ltrim($postUrl($post), '/')),
    ];
}

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $heading,
    'description' => $intro,
    'url' => $metaCanonical,
    'inLanguage' => Lang::current(),
    'isPartOf' => ['@type' => 'WebSite', 'name' => (string) setting('site_title'), 'url' => baseUrl()],
];
if ($listItems) {
    $schema['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $listItems];
}
if ($category !== null) {
    $schema['breadcrumb'] = [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => t('nav.home'), 'item' => baseUrl()],
            ['@type' => 'ListItem', 'position' => 2, 'name' => t('news.title'), 'item' => baseUrl('news')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $heading, 'item' => baseUrl(ltrim($archivePath, '/'))],
        ],
    ];
}
?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE) ?></script>
