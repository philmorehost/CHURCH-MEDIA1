<?php
declare(strict_types=1);
/** @var string $metaTitle */
/** @var string $metaDescription */
/** @var string|null $metaRobots */
$s = settings();
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$metaTitle ??= $s['site_title'];
$metaDescription ??= $s['meta_description'] ?? $s['site_tagline'] ?? '';
$isLive = !empty($s['livestream_is_live']);

/*
 * Top-level navigation.
 *
 * The site has 13 public pages. Listed side by side they wrapped onto a second
 * row on laptop screens, so they are grouped into four dropdowns — Media, The
 * Word, Community and Connect — leaving a five-item bar. Every route that was
 * reachable before is still one click away inside a group.
 *
 * A parent's `href` is its landing page (also what a keyboard or no-JS visitor
 * can still click). Add entries to a group's `children`, or append a new
 * top-level array with `'children' => []` to put an item back on the bar itself.
 */
$navTree = [
    ['href' => '/', 'label' => 'Home', 'children' => []],
    [
        'href' => '/feed',
        'label' => 'Media',
        'children' => [
            ['href' => '/feed', 'label' => 'Video Feed'],
            ['href' => '/media', 'label' => 'Media Gallery'],
            ['href' => '/live', 'label' => 'Watch Live'],
        ],
    ],
    [
        'href' => '/sermons',
        'label' => 'The Word',
        'children' => [
            ['href' => '/sermons', 'label' => 'Sermons'],
            ['href' => '/bible', 'label' => 'Holy Bible'],
        ],
    ],
    [
        'href' => '/events',
        'label' => 'Community',
        'children' => [
            ['href' => '/events', 'label' => 'Events'],
            ['href' => '/units', 'label' => 'Parishes'],
            ['href' => '/testimonies', 'label' => 'Testimonies'],
        ],
    ],
    [
        'href' => '/about',
        'label' => 'Connect',
        'children' => [
            ['href' => '/about', 'label' => 'About Us'],
            ['href' => '/contact', 'label' => 'Contact'],
            ['href' => '/advertise', 'label' => 'Advertise With Us'],
            ['href' => '/register', 'label' => 'Register'],
        ],
    ],
];
try {
    $navPages = Database::getInstance()->getConnection()
        ->query('SELECT id, parent_id, slug, nav_label, title FROM pages WHERE is_published = 1 AND in_nav = 1 ORDER BY sort_order ASC, id ASC')
        ->fetchAll();

    $pageItems = [];
    $subPages = [];

    foreach ($navPages as $pg) {
        $href = $pg['slug'] === 'about' ? '/about' : '/page/' . rawurlencode((string) $pg['slug']);
        $label = $pg['nav_label'] ?: $pg['title'];
        $item = ['id' => (int) $pg['id'], 'href' => $href, 'label' => $label, 'children' => []];

        if (!empty($pg['parent_id'])) {
            $subPages[] = ['parent_id' => (int) $pg['parent_id'], 'item' => $item];
        } else {
            $pageItems[(int) $pg['id']] = $item;
        }
    }

    foreach ($subPages as $sp) {
        $pId = $sp['parent_id'];
        if (isset($pageItems[$pId])) {
            $pageItems[$pId]['children'][] = $sp['item'];
        }
    }

    foreach ($pageItems as $pId => $pItem) {
        $foundIndex = null;
        foreach ($navTree as $idx => $tItem) {
            if ($tItem['href'] === $pItem['href']) {
                $foundIndex = $idx;
                break;
            }
        }
        if ($foundIndex !== null) {
            $navTree[$foundIndex]['children'] = array_merge($navTree[$foundIndex]['children'] ?? [], $pItem['children']);
        } else {
            $navTree[] = $pItem;
        }
    }
} catch (Throwable $e) {
    error_log('CMS nav skipped: ' . $e->getMessage());
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($metaTitle) ?><?= $metaTitle !== $s['site_title'] ? ' · ' . e($s['site_title']) : '' ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<?php if (!empty($metaRobots)): ?><meta name="robots" content="<?= e($metaRobots) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e(baseUrl($path === '/' ? '' : ltrim($path, '/'))) ?>">
<link rel="icon" href="/favicon.ico">
<meta property="og:title" content="<?= e($metaTitle) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= e(baseUrl($path)) ?>">
<?php if ($s['logo_path'] ?? null): ?><meta property="og:image" content="<?= e(uploadUrl($s['logo_path'])) ?>"><?php endif; ?>
<meta name="theme-color" content="#0a0912">
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Church',
    'name' => $s['site_title'],
    'url' => baseUrl(),
    'description' => $metaDescription,
], JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>

<?php
$goText = trim((string) ($s['go_declaration_text'] ?? ''));
$goEnabled = !empty($s['go_declaration_enabled']) && $goText !== '';
$goTitle = trim((string) ($s['go_declaration_title'] ?? "G.O. Declaration"));
$goMode = ($s['go_declaration_mode'] ?? 'marquee') === 'static' ? 'static' : 'marquee';
?>
<?php if ($goEnabled): ?>
  <div class="go-declaration-bar go-declaration-<?= $goMode ?>">
    <div class="go-declaration-badge">
      <span class="go-icon">✨</span>
      <strong><?= e($goTitle) ?>:</strong>
    </div>
    <?php if ($goMode === 'marquee'): ?>
      <div class="go-marquee-track">
        <div class="go-marquee-content">
          <span><?= e($goText) ?></span>
          <span class="go-sep">✦</span>
          <span><?= e($goText) ?></span>
          <span class="go-sep">✦</span>
          <span><?= e($goText) ?></span>
          <span class="go-sep">✦</span>
        </div>
      </div>
    <?php else: ?>
      <div class="go-static-content">
        <span><?= e($goText) ?></span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<header class="site-header">
  <div class="nav-row container">
    <a href="/" class="nav-brand">
      <span class="mark"><?php if ($s['logo_path'] ?? null): ?><img src="<?= e(uploadUrl($s['logo_path'])) ?>" alt=""><?php else: ?><?= e(mb_substr($s['site_title'], 0, 1)) ?><?php endif; ?></span>
      <?= e($s['site_title']) ?>
    </a>
    <nav data-nav-links class="nav-links">
      <?php foreach ($navTree as $item): ?>
        <?php
          $href = (string) ($item['href'] ?? '');
          $label = (string) ($item['label'] ?? '');
          $children = $item['children'] ?? [];
          $isActive = ($href !== '' && $path === $href);
          if (!$isActive && $children) {
              foreach ($children as $c) {
                  if ($path === ($c['href'] ?? '')) { $isActive = true; break; }
              }
          }
          $menuId = 'nav-menu-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($label));
        ?>
        <?php if ($children): ?>
          <div class="nav-dropdown-wrap">
            <a href="<?= e($href !== '' ? $href : '#') ?>" class="nav-item-link <?= $isActive ? 'active' : '' ?>" aria-haspopup="true">
              <?= e($label) ?><span class="nav-caret" aria-hidden="true">▾</span>
            </a>
            <button type="button" class="nav-dropdown-toggle" data-nav-dropdown-toggle
                    aria-expanded="false" aria-controls="<?= e($menuId) ?>"
                    aria-label="Show <?= e($label) ?> menu"><span aria-hidden="true">▾</span></button>
            <div class="nav-dropdown-menu" id="<?= e($menuId) ?>">
              <?php foreach ($children as $child): ?>
                <a href="<?= e($child['href']) ?>" class="<?= $path === $child['href'] ? 'active' : '' ?>">
                  <?= e($child['label']) ?><?php if (($child['href'] ?? '') === '/live' && $isLive): ?> <span class="nav-live"><span class="dot"></span>LIVE</span><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <a href="<?= e($href) ?>" class="<?= $isActive ? 'active' : '' ?>">
            <?= e($label) ?><?php if ($href === '/live' && $isLive): ?> <span class="nav-live"><span class="dot"></span>LIVE</span><?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <button class="nav-toggle" data-nav-toggle aria-label="Menu">☰</button>
  </div>
</header>

<main>
