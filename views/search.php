<?php
declare(strict_types=1);
$metaTitle = 'Search';
$q = trim((string) ($_GET['q'] ?? ''));
$results = ['posts' => [], 'sermons' => [], 'events' => []];

if (mb_strlen($q) >= 2) {
    $pdo = Database::getInstance()->getConnection();
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare('SELECT id, slug, caption AS title FROM media_posts WHERE is_published = 1 AND caption LIKE ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$like]);
    $results['posts'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, slug, title FROM sermons WHERE is_published = 1 AND (title LIKE ? OR description LIKE ? OR speaker LIKE ?) ORDER BY published_at DESC LIMIT 10');
    $stmt->execute([$like, $like, $like]);
    $results['sermons'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, slug, title FROM events WHERE is_published = 1 AND (title LIKE ? OR description LIKE ?) ORDER BY start_at DESC LIMIT 10');
    $stmt->execute([$like, $like]);
    $results['events'] = $stmt->fetchAll();
}
$totalResults = count($results['posts']) + count($results['sermons']) + count($results['events']);
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:760px;">
    <div class="section-head">
      <span class="eyebrow">Find Something</span>
      <h2>Search</h2>
    </div>

    <form method="get" action="/search" style="max-width:520px; margin:0 auto 48px; display:flex; gap:10px;">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search sermons, events, posts…" style="flex:1; padding:13px 16px; border-radius:12px; border:1px solid var(--border-soft); background:#ffffff08; color:var(--ink); font-size:14px;" autofocus>
      <button class="btn btn-gold" type="submit">Search</button>
    </form>

    <?php if ($q === ''): ?>
      <div class="empty-state">Start typing to search across sermons, events, and the media feed.</div>
    <?php elseif (mb_strlen($q) < 2): ?>
      <div class="empty-state">Enter at least 2 characters.</div>
    <?php elseif ($totalResults === 0): ?>
      <div class="empty-state">No results for "<?= e($q) ?>".</div>
    <?php else: ?>
      <?php if ($results['sermons']): ?>
        <h3 style="font-size:15px; color:var(--gold-soft); margin-bottom:14px;">Sermons</h3>
        <?php foreach ($results['sermons'] as $r): ?><a href="/sermons/<?= e($r['slug']) ?>" class="glass-card" style="display:block; padding:16px 20px; margin-bottom:10px;"><?= e($r['title']) ?></a><?php endforeach; ?>
      <?php endif; ?>
      <?php if ($results['events']): ?>
        <h3 style="font-size:15px; color:var(--gold-soft); margin:24px 0 14px;">Events</h3>
        <?php foreach ($results['events'] as $r): ?><a href="/events/<?= e($r['slug']) ?>" class="glass-card" style="display:block; padding:16px 20px; margin-bottom:10px;"><?= e($r['title']) ?></a><?php endforeach; ?>
      <?php endif; ?>
      <?php if ($results['posts']): ?>
        <h3 style="font-size:15px; color:var(--gold-soft); margin:24px 0 14px;">Feed Posts</h3>
        <?php foreach ($results['posts'] as $r): ?><a href="/feed" class="glass-card" style="display:block; padding:16px 20px; margin-bottom:10px;"><?= e($r['title'] ?: 'Untitled post') ?></a><?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
