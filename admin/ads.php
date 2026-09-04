<?php
declare(strict_types=1);

Auth::requireRole('admin');

$pdo = Database::getInstance()->getConnection();
$adminUser = Auth::user();
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

// Manage Durations
if ($action === 'durations') {
    if (!Auth::isSuperAdmin()) {
        http_response_code(403);
        exit('Only super admin can manage duration options.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_duration'])) {
        Csrf::requireValid();
        $title = trim((string) ($_POST['title'] ?? ''));
        $days = (int) ($_POST['days'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '' || $days <= 0) {
            $errors[] = 'Please enter a valid title and number of days.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO ad_durations (title, days, sort_order) VALUES (?, ?, ?)');
            $stmt->execute([$title, $days, $sortOrder]);
            flash('success', 'Duration option added.');
            redirect('/admin/ads?action=durations');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_duration'])) {
        Csrf::requireValid();
        $durId = (int) ($_POST['duration_id'] ?? 0);
        $pdo->prepare('DELETE FROM ad_durations WHERE id = ?')->execute([$durId]);
        flash('success', 'Duration option deleted.');
        redirect('/admin/ads?action=durations');
    }

    $durations = $pdo->query('SELECT * FROM ad_durations ORDER BY sort_order ASC, days ASC')->fetchAll();
}

// Approve Ad
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stmt = $pdo->prepare('SELECT a.*, p.name AS pub_name, p.email AS pub_email, p.token AS pub_token FROM ads a JOIN ad_publishers p ON a.publisher_id = p.id WHERE a.id = ?');
    $stmt->execute([$id]);
    $ad = $stmt->fetch();

    if ($ad) {
        $days = (int) $ad['duration_days'];
        $startAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $pdo->prepare('UPDATE ads SET status = "approved", start_at = ?, expires_at = ? WHERE id = ?')
            ->execute([$startAt, $expiresAt, $id]);

        $managerUrl = baseUrl('ad-manager?token=' . rawurlencode($ad['pub_token']));

        try {
            Mailer::send(
                $ad['pub_email'],
                'Your Advert Has Been Approved! · ' . setting('site_title'),
                "Hi {$ad['pub_name']},\n\n" .
                "Great news! Your advert \"{$ad['title']}\" has been approved and is now LIVE on our platform.\n\n" .
                "Display Duration: {$days} Days\n" .
                "Starts: {$startAt}\n" .
                "Expires: {$expiresAt}\n\n" .
                "You can monitor your advert stats, impressions, clicks, and create additional ads in your Publisher Ad Manager using your secure link:\n" .
                "{$managerUrl}\n\n" .
                "Thank you for advertising with us!"
            );
        } catch (Throwable $e) {
            // Mailer failure shouldn't block approval
        }

        flash('success', 'Ad approved! Active immediately until ' . date('M j, Y', strtotime($expiresAt)) . '. Notification sent to publisher.');
    }
    redirect('/admin/ads');
}

// Reject Ad
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $pdo->prepare('UPDATE ads SET status = "rejected" WHERE id = ?')->execute([$id]);
    flash('success', 'Ad rejected.');
    redirect('/admin/ads');
}

// Delete Ad
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stmt = $pdo->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$id]);
    $ad = $stmt->fetch();
    if ($ad) {
        if (!empty($ad['file_path'])) {
            @unlink(UPLOADS_PATH . '/' . $ad['file_path']);
        }
        if (!empty($ad['thumbnail_path'])) {
            @unlink(UPLOADS_PATH . '/' . $ad['thumbnail_path']);
        }
        $pdo->prepare('DELETE FROM ads WHERE id = ?')->execute([$id]);
        flash('success', 'Ad deleted.');
    }
    redirect('/admin/ads');
}

// List Ads
$statusFilter = $_GET['status'] ?? 'all';
$sql = 'SELECT a.*, p.name AS pub_name, p.email AS pub_email, p.phone AS pub_phone, p.token AS pub_token FROM ads a JOIN ad_publishers p ON a.publisher_id = p.id';
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $sql .= ' WHERE a.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY a.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$adsList = $stmt->fetchAll();

$pageTitle = 'Ads Management';
$activeNav = 'ads';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'durations'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/ads">← Back to Ads</a>
  </div>

  <div class="card" style="max-width:600px; margin-bottom:24px;">
    <h2>Add Duration Option</h2>
    <form method="post" action="/admin/ads?action=durations">
      <?= Csrf::field() ?>
      <input type="hidden" name="add_duration" value="1">
      <div class="row two">
        <div>
          <label for="title">Title (e.g. 14 Days)</label>
          <input type="text" id="title" name="title" required placeholder="e.g. 14 Days Special">
        </div>
        <div>
          <label for="days">Days Duration</label>
          <input type="number" id="days" name="days" min="1" required placeholder="14">
        </div>
      </div>
      <label for="sort_order">Sort Order</label>
      <input type="number" id="sort_order" name="sort_order" value="0">
      <button type="submit" class="btn" style="margin-top:12px;">Add Duration</button>
    </form>
  </div>

  <div class="card">
    <h2>Active Duration Options</h2>
    <table>
      <tr><th>Title</th><th>Days</th><th>Sort Order</th><th></th></tr>
      <?php foreach ($durations as $d): ?>
        <tr>
          <td><strong><?= e($d['title']) ?></strong></td>
          <td><?= (int) $d['days'] ?> days</td>
          <td><?= (int) $d['sort_order'] ?></td>
          <td>
            <form method="post" action="/admin/ads?action=durations" onsubmit="return confirm('Delete this duration?');" style="display:inline;">
              <?= Csrf::field() ?>
              <input type="hidden" name="delete_duration" value="1">
              <input type="hidden" name="duration_id" value="<?= (int) $d['id'] ?>">
              <button class="btn danger sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
    <div>
      <a class="btn secondary sm <?= $statusFilter === 'all' ? 'active' : '' ?>" href="/admin/ads?status=all">All Ads</a>
      <a class="btn secondary sm <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="/admin/ads?status=pending">Pending</a>
      <a class="btn secondary sm <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="/admin/ads?status=approved">Approved</a>
      <a class="btn secondary sm <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="/admin/ads?status=rejected">Rejected</a>
    </div>
    <?php if (Auth::isSuperAdmin()): ?>
      <a class="btn secondary sm" href="/admin/ads?action=durations">⚙ Manage Display Durations</a>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (!$adsList): ?>
      <div class="empty">No advertisements found.</div>
    <?php else: ?>
      <table>
        <tr>
          <th>Ad / Media</th>
          <th>Publisher</th>
          <th>Target / Duration</th>
          <th>Stats</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
        <?php foreach ($adsList as $ad): ?>
          <tr>
            <td>
              <div style="display:flex; gap:12px; align-items:center;">
                <div style="width:50px; height:88px; background:#110e1b; border-radius:4px; overflow:hidden; position:relative; flex-shrink:0; border:1px solid var(--border);">
                  <?php if ($ad['media_type'] === 'image'): ?>
                    <img src="<?= e(uploadUrl($ad['file_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                  <?php else: ?>
                    <?php if (!empty($ad['thumbnail_path'])): ?>
                      <img src="<?= e(uploadUrl($ad['thumbnail_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                      <video src="<?= e(uploadUrl($ad['file_path'])) ?>" style="width:100%; height:100%; object-fit:cover;" muted></video>
                    <?php endif; ?>
                    <span style="position:absolute; bottom:2px; right:2px; background:rgba(0,0,0,0.7); color:#fff; font-size:10px; padding:1px 3px; border-radius:2px;">▶ reel</span>
                  <?php endif; ?>
                </div>
                <div>
                  <strong><?= e($ad['title']) ?></strong><br>
                  <span class="badge info"><?= e(strtoupper($ad['media_type'])) ?></span>
                  <?php if (!empty($ad['destination_url'])): ?>
                    <br><a href="<?= e($ad['destination_url']) ?>" target="_blank" style="font-size:11px; color:var(--gold-soft);">↗ <?= e(mb_substr($ad['destination_url'], 0, 30)) ?>…</a>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <td>
              <strong><?= e($ad['pub_name']) ?></strong><br>
              <span style="font-size:12px; color:var(--ink-faint);"><?= e($ad['pub_email']) ?></span>
              <?php if ($ad['pub_phone']): ?><br><span style="font-size:12px; color:var(--ink-faint);"><?= e($ad['pub_phone']) ?></span><?php endif; ?>
              <br><a href="<?= e(baseUrl('ad-manager?token=' . rawurlencode($ad['pub_token']))) ?>" target="_blank" style="font-size:11px; color:var(--gold-soft);">🔑 Manager Link</a>
            </td>

            <td>
              <span class="badge ok"><?= e(strtoupper($ad['target_platform'])) ?></span><br>
              <small style="color:var(--ink-dim);"><?= (int) $ad['duration_days'] ?> Days Duration</small>
              <?php if ($ad['start_at'] && $ad['expires_at']): ?>
                <br><small style="font-size:11px; color:var(--ink-faint);">Live: <?= e(date('M j', strtotime($ad['start_at']))) ?> – <?= e(date('M j, Y', strtotime($ad['expires_at']))) ?></small>
              <?php endif; ?>
            </td>

            <td>
              <strong style="font-size:14px;"><?= (int) $ad['views_count'] ?></strong> <small style="color:var(--ink-faint);">views</small><br>
              <strong style="font-size:14px; color:var(--gold-soft);"><?= (int) $ad['clicks_count'] ?></strong> <small style="color:var(--ink-faint);">clicks</small>
            </td>

            <td>
              <?php if ($ad['status'] === 'pending'): ?>
                <span class="badge warn">pending</span>
              <?php elseif ($ad['status'] === 'approved'): ?>
                <?php if (strtotime($ad['expires_at']) <= time()): ?>
                  <span class="badge fail">expired</span>
                <?php else: ?>
                  <span class="badge ok">active</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge fail">rejected</span>
              <?php endif; ?>
            </td>

            <td style="white-space:nowrap;">
              <?php if ($ad['status'] === 'pending'): ?>
                <form method="post" action="/admin/ads?action=approve&id=<?= (int) $ad['id'] ?>" style="display:inline;">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn sm" onclick="return confirm('Approve this advert? It will go live immediately.');">Approve</button>
                </form>
                <form method="post" action="/admin/ads?action=reject&id=<?= (int) $ad['id'] ?>" style="display:inline;">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn sm danger">Reject</button>
                </form>
              <?php endif; ?>
              <form method="post" action="/admin/ads?action=delete&id=<?= (int) $ad['id'] ?>" style="display:inline;" onsubmit="return confirm('Permanently delete this advert?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn danger sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
