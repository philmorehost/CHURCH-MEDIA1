<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');

$pdo = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'list';
$statusFilter = $_GET['status'] ?? 'all';

// Handle actions (Approve, Reject, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve' && $id > 0) {
        $pdo->prepare("UPDATE testimonies SET status = 'approved', approved_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
        flash('success', 'Testimony approved and published to the website.');
        redirect('/admin/testimonies' . ($statusFilter !== 'all' ? '?status=' . urlencode($statusFilter) : ''));
    }

    if ($action === 'reject' && $id > 0) {
        $pdo->prepare("UPDATE testimonies SET status = 'rejected' WHERE id = ?")->execute([$id]);
        flash('success', 'Testimony set to rejected.');
        redirect('/admin/testimonies' . ($statusFilter !== 'all' ? '?status=' . urlencode($statusFilter) : ''));
    }

    if ($action === 'delete' && $id > 0) {
        $stmt = $pdo->prepare('SELECT media_url FROM testimonies WHERE id = ?');
        $stmt->execute([$id]);
        $mediaUrl = $stmt->fetchColumn();
        if ($mediaUrl && is_file(UPLOADS_PATH . '/' . $mediaUrl)) {
            @unlink(UPLOADS_PATH . '/' . $mediaUrl);
        }
        $pdo->prepare('DELETE FROM testimonies WHERE id = ?')->execute([$id]);
        flash('success', 'Testimony deleted permanently.');
        redirect('/admin/testimonies' . ($statusFilter !== 'all' ? '?status=' . urlencode($statusFilter) : ''));
    }

    if ($action === 'save' && $id > 0) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_POST['status'] : 'pending';

        if ($title !== '' && $content !== '' && $name !== '') {
            $pdo->prepare("UPDATE testimonies SET name = ?, email = ?, phone = ?, unit_id = ?, title = ?, content = ?, status = ?, approved_at = IF(? = 'approved', COALESCE(approved_at, CURRENT_TIMESTAMP), approved_at) WHERE id = ?")
                ->execute([$name, $email ?: null, $phone ?: null, $unitId > 0 ? $unitId : null, $title, $content, $status, $status, $id]);
            flash('success', 'Testimony updated successfully.');
        }
        redirect('/admin/testimonies' . ($statusFilter !== 'all' ? '?status=' . urlencode($statusFilter) : ''));
    }
}

// Fetch stats
$stats = [
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM testimonies WHERE status = 'pending'")->fetchColumn(),
    'approved' => (int) $pdo->query("SELECT COUNT(*) FROM testimonies WHERE status = 'approved'")->fetchColumn(),
    'rejected' => (int) $pdo->query("SELECT COUNT(*) FROM testimonies WHERE status = 'rejected'")->fetchColumn(),
    'all' => (int) $pdo->query("SELECT COUNT(*) FROM testimonies")->fetchColumn(),
];

// Query testimonies with parish/unit names
$where = '';
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $where = 'WHERE t.status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare("SELECT t.*, u.name AS unit_name FROM testimonies t LEFT JOIN org_units u ON u.id = t.unit_id {$where} ORDER BY t.id DESC");
$stmt->execute($params);
$testimonies = $stmt->fetchAll();

// Fetch church units for dropdown.
// NOTE: `org_units` has no `is_active` column (see the 2026_08_org_units
// migration in core/Database.php), so it must not be filtered on. Doing so
// raised "Unknown column 'is_active'" here and took this whole page down.
$units = [];
try {
    $units = $pdo->query('SELECT id, name FROM org_units ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    $units = [];
}

$pageTitle = 'Testimonies Review & Approval';
$activeNav = 'testimonies';
require __DIR__ . '/partials/layout-open.php';
?>

<div class="card" style="margin-bottom:24px;">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
    <div>
      <h2 style="margin:0 0 4px;">Testimonies & Praise Reports</h2>
      <p class="sub" style="margin:0;">Review, approve, or moderate member testimony submissions before publishing to the website.</p>
    </div>
  </div>

  <div style="display:flex; gap:12px; margin-top:20px; border-bottom:1px solid var(--border); padding-bottom:12px;">
    <a class="btn sm <?= $statusFilter === 'all' ? '' : 'secondary' ?>" href="/admin/testimonies?status=all">
      All (<?= $stats['all'] ?>)
    </a>
    <a class="btn sm <?= $statusFilter === 'pending' ? '' : 'secondary' ?>" href="/admin/testimonies?status=pending" style="position:relative;">
      Pending Review (<?= $stats['pending'] ?>)
      <?php if ($stats['pending'] > 0): ?>
        <span class="badge warn" style="margin-left:6px; font-size:10px; padding:2px 6px; border-radius:10px;"><?= $stats['pending'] ?></span>
      <?php endif; ?>
    </a>
    <a class="btn sm <?= $statusFilter === 'approved' ? '' : 'secondary' ?>" href="/admin/testimonies?status=approved">
      Approved (<?= $stats['approved'] ?>)
    </a>
    <a class="btn sm <?= $statusFilter === 'rejected' ? '' : 'secondary' ?>" href="/admin/testimonies?status=rejected">
      Rejected (<?= $stats['rejected'] ?>)
    </a>
  </div>
</div>

<div class="card">
  <?php if (!$testimonies): ?>
    <div class="empty">No testimonies found for this filter.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Author & Contact</th>
          <th>Title & Content</th>
          <th>Parish / Unit</th>
          <th>Status</th>
          <th>Submitted</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($testimonies as $t): ?>
          <tr>
            <td style="vertical-align:top; min-width:180px;">
              <strong><?= e($t['name']) ?></strong>
              <?php if ($t['email']): ?>
                <div style="font-size:12px; color:var(--ink-dim);"><?= e($t['email']) ?></div>
              <?php endif; ?>
              <?php if ($t['phone']): ?>
                <div style="font-size:12px; color:var(--ink-dim);"><?= e($t['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td style="vertical-align:top; max-width:400px;">
              <strong style="font-size:15px; color:var(--gold-soft); display:block; margin-bottom:4px;"><?= e($t['title']) ?></strong>
              <div style="font-size:13px; color:var(--ink-dim); max-height:100px; overflow-y:auto; line-height:1.5;">
                <?= nl2br(e($t['content'])) ?>
              </div>
              <?php if ($t['media_url']): ?>
                <div style="margin-top:8px;">
                  <a href="<?= e(uploadUrl($t['media_url'])) ?>" target="_blank" style="font-size:12px; color:var(--gold); font-weight:600;">
                    🖼 View Uploaded Photo Proof
                  </a>
                </div>
              <?php endif; ?>
            </td>
            <td style="vertical-align:top; white-space:nowrap;">
              <span class="badge" style="background:var(--bg-0); color:var(--ink); font-size:12px;">
                <?= e($t['unit_name'] ?: 'Grace Community') ?>
              </span>
            </td>
            <td style="vertical-align:top; white-space:nowrap;">
              <?php if ($t['status'] === 'approved'): ?>
                <span class="badge ok">Approved</span>
              <?php elseif ($t['status'] === 'rejected'): ?>
                <span class="badge danger">Rejected</span>
              <?php else: ?>
                <span class="badge warn">Pending Review</span>
              <?php endif; ?>
            </td>
            <td style="vertical-align:top; white-space:nowrap; font-size:12px; color:var(--ink-dim);">
              <?= e(date('M j, Y H:i', strtotime($t['submitted_at']))) ?>
            </td>
            <td style="vertical-align:top; text-align:right; white-space:nowrap;">
              <div style="display:flex; justify-content:flex-end; gap:6px; flex-wrap:wrap;">
                <?php if ($t['status'] !== 'approved'): ?>
                  <form method="post" action="/admin/testimonies?action=approve<?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?>" style="display:inline;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn sm" style="background:var(--success); color:#1a1530;">Approve</button>
                  </form>
                <?php endif; ?>

                <?php if ($t['status'] !== 'rejected'): ?>
                  <form method="post" action="/admin/testimonies?action=reject<?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?>" style="display:inline;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn sm secondary">Reject</button>
                  </form>
                <?php endif; ?>

                <form method="post" action="/admin/testimonies?action=delete<?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?>" style="display:inline;" onsubmit="return confirm('Delete this testimony permanently?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="btn sm danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
