<?php
declare(strict_types=1);

Auth::requireLogin();
$pdo = Database::getInstance()->getConnection();

// Non-super admins only see their own church's posts (strict per-unit match).
$user = Auth::user();
$scopeClause = '';
$myUnitLabel = '';
if ($user && empty($user['is_super_admin'])) {
    $myUnitLabel = !empty($user['org_unit_id']) ? Unit::label((int) $user['org_unit_id']) : '';
    $scopeIds = !empty($user['org_unit_id']) ? [(int) $user['org_unit_id']] : [];
    $scopeClause = $scopeIds ? ' AND p.org_unit_id IN (' . implode(',', array_map('intval', $scopeIds)) . ')' : ' AND 1 = 0';
}

$stats = [
    'posts' => (int) $pdo->query('SELECT COUNT(*) FROM media_posts')->fetchColumn(),
    'events' => (int) $pdo->query('SELECT COUNT(*) FROM events WHERE start_at >= NOW()')->fetchColumn(),
    'sermons' => (int) $pdo->query('SELECT COUNT(*) FROM sermons')->fetchColumn(),
    'prayers_new' => (int) $pdo->query("SELECT COUNT(*) FROM prayer_requests WHERE status = 'new'")->fetchColumn(),
    'subscribers' => (int) $pdo->query('SELECT COUNT(*) FROM newsletter_subscribers WHERE is_active = 1')->fetchColumn(),
    'blocked_ips' => (int) $pdo->query("SELECT COUNT(*) FROM ip_rules WHERE type = 'blacklist'")->fetchColumn(),
];
if ($scopeClause !== '') {
    $stats['my_posts'] = (int) $pdo->query('SELECT COUNT(*) FROM media_posts p WHERE 1=1' . $scopeClause)->fetchColumn();
}

// Newcomer follow-up scope (mirrors the per-church isolation on /admin/newcomers).
$newcomerScope = '';
if ($user && empty($user['is_super_admin'])) {
    $newcomerScope = $scopeIds ? ' AND n.org_unit_id IN (' . implode(',', array_map('intval', $scopeIds)) . ')' : ' AND 1 = 0';
}
$stats['newcomers_week'] = (int) $pdo->query('SELECT COUNT(*) FROM newcomers n WHERE 1=1' . $newcomerScope . ' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
$recentNewcomers = $pdo->query('
    SELECT n.name, n.whatsapp_phone, n.follow_up_status, n.visit_date, n.created_at
    FROM newcomers n
    WHERE 1=1' . $newcomerScope . '
    ORDER BY n.created_at DESC LIMIT 6
')->fetchAll();

$recentPosts = $pdo->query('
    SELECT p.id, p.caption, p.post_type, p.likes_count, p.views_count, p.created_at, u.name AS author
    FROM media_posts p JOIN users u ON u.id = p.user_id
    WHERE 1=1' . $scopeClause . '
    ORDER BY p.created_at DESC LIMIT 6
')->fetchAll();

$recentSecurity = $pdo->query('
    SELECT ip_address, username_attempted, event_type, created_at
    FROM security_logs ORDER BY created_at DESC LIMIT 8
')->fetchAll();

$unitNotifications = [];
$unitUnread = 0;
if ($user && !empty($user['org_unit_id'])) {
    $unitId = (int) $user['org_unit_id'];
    $unitUnread = (int) $pdo->query("SELECT COUNT(*) FROM notification_recipients WHERE org_unit_id = {$unitId} AND read_at IS NULL")->fetchColumn();
    $unitNotifications = $pdo->query("SELECT n.id, n.title, n.body, n.created_at, nr.read_at FROM notifications n JOIN notification_recipients nr ON nr.notification_id = n.id AND nr.org_unit_id = {$unitId} ORDER BY n.created_at DESC LIMIT 4")->fetchAll();
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/partials/layout-open.php';
?>

<?php if ($myUnitLabel !== ''): ?>
<div class="card" style="margin-bottom:18px;">
  <h2 style="margin:0 0 4px;">📍 My Unit</h2>
  <p style="margin:0;color:var(--ink-dim);"><?= e($myUnitLabel) ?></p>
  <p style="margin:6px 0 0;color:var(--ink-dim);"><strong><?= $stats['my_posts'] ?? 0 ?></strong> post(s) in your scope.</p>
</div>
<?php endif; ?>

<?php if (!empty($user['org_unit_id']) && $unitNotifications): ?>
<div class="card" style="margin-bottom:18px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
    <h2 style="margin:0;">🔔 Notifications <?= $unitUnread ? '<span class="badge danger">' . $unitUnread . ' new</span>' : '' ?></h2>
    <a href="/admin/notifications" style="color:var(--gold-soft);font-size:13px;">View all →</a>
  </div>
  <?php foreach ($unitNotifications as $n): ?>
  <div style="padding:9px 0;border-bottom:1px solid var(--border);">
    <strong><?= e($n['title']) ?><?= $n['read_at'] ? '' : ' <span class="badge info">new</span>' ?></strong>
    <div style="color:var(--ink-dim);font-size:13px;"><?= e(mb_strimwidth((string) $n['body'], 0, 110, '…')) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid cols-4" style="margin-bottom:22px;">
  <div class="stat"><div class="num"><?= $stats['posts'] ?></div><div class="label">Media Posts</div></div>
  <div class="stat"><div class="num"><?= $stats['events'] ?></div><div class="label">Upcoming Events</div></div>
  <div class="stat"><div class="num"><?= $stats['sermons'] ?></div><div class="label">Sermons</div></div>
  <div class="stat"><div class="num"><?= $stats['prayers_new'] ?></div><div class="label">New Prayer Requests</div></div>
  <div class="stat"><div class="num" style="color:var(--gold-soft);"><?= $stats['newcomers_week'] ?></div><div class="label">Newcomers (7 days)</div></div>
  <div class="stat"><div class="num"><?= $stats['subscribers'] ?></div><div class="label">Newsletter Subscribers</div></div>
  <div class="stat"><div class="num" style="color:<?= $stats['blocked_ips'] ? 'var(--danger)' : 'var(--success)' ?>;"><?= $stats['blocked_ips'] ?></div><div class="label">Blocked IPs</div></div>
</div>

<div class="grid cols-2">
  <div class="card">
    <h2>Recent Media Posts</h2>
    <p class="sub">Latest uploads across the feed</p>
    <?php if (!$recentPosts): ?>
      <div class="empty">No posts yet — <a href="/admin/media" style="color:var(--gold-soft);">create your first one</a>.</div>
    <?php else: ?>
      <table class="resp-table">
        <tr><th>Caption</th><th>Type</th><th>Likes</th><th>Views</th><th>Posted</th></tr>
        <?php foreach ($recentPosts as $p): ?>
        <tr>
          <td data-label="Caption"><?= e(mb_strimwidth((string) $p['caption'], 0, 40, '…')) ?></td>
          <td data-label="Type"><span class="badge info"><?= e(str_replace('_', ' ', $p['post_type'])) ?></span></td>
          <td data-label="Likes"><?= $p['likes_count'] ?></td>
          <td data-label="Views"><?= $p['views_count'] ?></td>
          <td data-label="Posted"><?= e(timeAgo($p['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Recent Security Activity</h2>
    <p class="sub">Login attempts across the admin panel</p>
    <?php if (!$recentSecurity): ?>
      <div class="empty">No activity logged yet.</div>
    <?php else: ?>
      <table class="resp-table">
        <tr><th>Event</th><th>User</th><th>IP</th><th>When</th></tr>
        <?php foreach ($recentSecurity as $log): ?>
        <tr>
          <td data-label="Event">
            <?php if ($log['event_type'] === 'successful_login'): ?><span class="badge ok">success</span>
            <?php elseif ($log['event_type'] === 'failed_login'): ?><span class="badge fail">failed</span>
            <?php else: ?><span class="badge warn">blocked</span><?php endif; ?>
          </td>
          <td data-label="User"><?= e($log['username_attempted'] ?? '—') ?></td>
          <td data-label="IP"><?= e($log['ip_address']) ?></td>
          <td data-label="When"><?= e(timeAgo($log['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:18px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
    <h2 style="margin:0;">Recent Newcomers</h2>
    <a href="/admin/newcomers" style="color:var(--gold-soft);font-size:13px;">Manage →</a>
  </div>
  <p class="sub">Latest first-time guests for follow-up</p>
  <?php if (!$recentNewcomers): ?>
    <div class="empty">No newcomers yet — <a href="/admin/newcomers?action=create" style="color:var(--gold-soft);">add one</a>.</div>
  <?php else: ?>
    <table class="resp-table">
      <tr><th>Name</th><th>WhatsApp</th><th>Status</th><th>Added</th></tr>
      <?php foreach ($recentNewcomers as $nc): ?>
      <tr>
        <td data-label="Name"><strong><?= e($nc['name']) ?></strong></td>
        <td data-label="WhatsApp">
          <?php if ($nc['whatsapp_phone']): ?>
            <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', (string) $nc['whatsapp_phone'])) ?>" target="_blank" rel="noopener" style="color:var(--gold);"><?= e($nc['whatsapp_phone']) ?> ↗</a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td data-label="Status">
          <?php
            $statusBadge = match ($nc['follow_up_status'] ?? 'new') {
              'contacted' => ['Contacted', 'info'],
              'followed_up' => ['Followed Up', 'warn'],
              'returned' => ['Returned', 'ok'],
              'inactive' => ['Inactive', 'fail'],
              default => ['New', 'warn'],
            };
          ?>
          <span class="badge <?= $statusBadge[1] ?>"><?= $statusBadge[0] ?></span>
        </td>
        <td data-label="Added"><?= e(timeAgo($nc['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
