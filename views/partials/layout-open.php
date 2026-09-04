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

$navLinks = [
    '/' => 'Home',
    '/feed' => 'Feed',
    '/media' => 'Media',
    '/events' => 'Events',
    '/sermons' => 'Sermons',
    '/units' => 'Parishes',
    '/bible' => 'Bible',
    '/live' => 'Live',
    '/about' => 'About',
    '/contact' => 'Contact',
    '/register' => 'Register',
];
try {
    $navPages = Database::getInstance()->getConnection()
        ->query('SELECT slug, nav_label, title FROM pages WHERE is_published = 1 AND in_nav = 1 ORDER BY sort_order ASC, id ASC');
    foreach ($navPages->fetchAll() as $pg) {
        $href = $pg['slug'] === 'about' ? '/about' : '/page/' . rawurlencode((string) $pg['slug']);
        $navLinks[$href] = $pg['nav_label'] ?: $pg['title'];
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

<header class="site-header">
  <div class="nav-row container">
    <a href="/" class="nav-brand">
      <span class="mark"><?php if ($s['logo_path'] ?? null): ?><img src="<?= e(uploadUrl($s['logo_path'])) ?>" alt=""><?php else: ?><?= e(mb_substr($s['site_title'], 0, 1)) ?><?php endif; ?></span>
      <?= e($s['site_title']) ?>
    </a>
    <nav data-nav-links class="nav-links">
      <?php foreach ($navLinks as $href => $label): ?>
        <a href="<?= e($href) ?>" class="<?= $path === $href ? 'active' : '' ?>">
          <?= e($label) ?><?php if ($href === '/live' && $isLive): ?> <span class="nav-live"><span class="dot"></span>LIVE</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <button class="nav-toggle" data-nav-toggle aria-label="Menu">☰</button>
  </div>
</header>

<main>
