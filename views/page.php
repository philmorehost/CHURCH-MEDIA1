<?php
declare(strict_types=1);

$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare('SELECT * FROM pages WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$page = $stmt->fetch();

if (!$page) {
    // Self-healing schema check: run Database::migrate() to automatically create missing tables/rows
    Database::migrate();
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
}

if ($page && empty($page['is_published']) && ($slug === 'privacy-policy' || $slug === 'about')) {
    // Default core policy and about pages should always be published and viewable
    $pdo->prepare('UPDATE pages SET is_published = 1 WHERE id = ?')->execute([(int) $page['id']]);
    $page['is_published'] = 1;
}

if (!$page || empty($page['is_published'])) {
    http_response_code(404);
    render('404', [], true);
    return;
}

$metaTitle = (string) $page['title'];
$metaDescription = (string) ($page['meta_description'] ?: $page['eyebrow'] ?: $page['title']);

$sections = json_decode((string) $page['content'], true);
$sections = is_array($sections) ? $sections : [];
$firstType = $sections[0]['type'] ?? null;
?>

<?php if ($firstType !== 'hero'): ?>
  <header class="page-header">
    <div class="container">
      <?php if (!empty($page['eyebrow'])): ?><span class="eyebrow"><?= e($page['eyebrow']) ?></span><?php endif; ?>
      <h1><?= e($page['title']) ?></h1>
    </div>
  </header>
<?php endif; ?>

<?php renderPageSections($sections); ?>
