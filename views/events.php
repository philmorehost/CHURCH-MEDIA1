<?php
declare(strict_types=1);
$metaTitle = 'Events';
$scope = ($_GET['scope'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$pdo = Database::getInstance()->getConnection();
$comparator = $scope === 'upcoming' ? '>=' : '<';
$order = $scope === 'upcoming' ? 'ASC' : 'DESC';
$events = $pdo->query("SELECT * FROM events WHERE is_published = 1 AND start_at $comparator NOW() ORDER BY start_at $order LIMIT 60")->fetchAll();
?>

<section class="section" style="padding-top:56px;">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Calendar</span>
      <h2>Events</h2>
      <p>Gatherings, conferences, and moments to be part of.</p>
    </div>

    <div class="chip-row">
      <a href="/events?scope=upcoming" class="chip <?= $scope === 'upcoming' ? 'active' : '' ?>">Upcoming</a>
      <a href="/events?scope=past" class="chip <?= $scope === 'past' ? 'active' : '' ?>">Past</a>
    </div>

    <?php if (!$events): ?>
      <div class="empty-state">No <?= $scope ?> events to show right now.</div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($events as $ev): ?>
          <a href="/events/<?= e($ev['slug']) ?>" class="glass-card info-card reveal in">
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
              <?php if ($ev['description']): ?><p style="color:var(--ink-dim); font-size:13.5px; margin:0;"><?= e(mb_strimwidth($ev['description'], 0, 90, '…')) ?></p><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
