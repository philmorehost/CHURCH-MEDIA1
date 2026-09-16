<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$scope = Unit::scopeClause($user, 'org_unit_id');
$scopeSql = $scope !== '' ? ' AND ' . $scope : '';
$action = $_GET['action'] ?? '';
$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();

// Super admin / scoped admin can assign a request to a church.
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'prayer_requests', $id, $user) && $unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE prayer_requests SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $id]);
        flash('success', 'Assigned to ' . Unit::label($unitId) . '.');
    } else {
        flash('error', 'Could not reassign that request.');
    }
    redirect('/admin/prayer');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'prayer_requests', $id, $user)) {
        redirect('/admin/prayer');
    }
    if (($_POST['do'] ?? '') === 'status') {
        $status = in_array($_POST['status'] ?? '', ['new', 'prayed', 'archived'], true) ? $_POST['status'] : 'new';
        $pdo->prepare('UPDATE prayer_requests SET status = ? WHERE id = ?')->execute([$status, $id]);
    } elseif (($_POST['do'] ?? '') === 'toggle_public') {
        $pdo->prepare('UPDATE prayer_requests SET is_public = NOT is_public WHERE id = ?')->execute([$id]);
    } elseif (($_POST['do'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM prayer_requests WHERE id = ?')->execute([$id]);
    }
    redirect('/admin/prayer');
}

$requests = $pdo->query('SELECT * FROM prayer_requests WHERE 1=1' . $scopeSql . ' ORDER BY FIELD(status, "new", "prayed", "archived"), created_at DESC LIMIT 200')->fetchAll();

$pageTitle = 'Prayer Wall';
$activeNav = 'prayer';
require __DIR__ . '/partials/layout-open.php';
?>

<div class="card">
  <h2>Prayer Requests</h2>
  <p class="sub">Submitted anonymously or with contact info from the public "Prayer" page. Mark public ones to feature on the site prayer wall.</p>
  <?php if (!$requests): ?>
    <div class="empty">No prayer requests yet.</div>
  <?php else: ?>
    <table>
      <tr><th>Message</th><th>From</th><th>Church</th><th>Public</th><th>Status</th><th>Submitted</th><th></th></tr>
      <?php foreach ($requests as $r): ?>
      <tr>
        <td style="max-width:340px;"><?= e(mb_strimwidth((string) $r['message'], 0, 120, '…')) ?></td>
        <td><?= e($r['name'] ?: 'Anonymous') ?><?= $r['email'] ? '<br><small style="color:var(--ink-faint);">' . e($r['email']) . '</small>' : '' ?></td>
        <td>
          <?php if (!empty($r['org_unit_id'])): ?>
            <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $r['org_unit_id']] ?? '') ?></span>
          <?php else: ?>
            <span class="badge warn">Unassigned</span>
          <?php endif; ?>
          <div style="margin-top:6px;">
            <?php $reassignId = (int) $r['id']; $reassignUnitId = !empty($r['org_unit_id']) ? (int) $r['org_unit_id'] : null; $showUnassignedOnly = false; $assignAction = '/admin/prayer?action=reassign'; require __DIR__ . '/partials/unit-assign.php'; ?>
          </div>
        </td>
        <td>
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="toggle_public"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="badge <?= $r['is_public'] ? 'ok' : '' ?>" style="border:none;cursor:pointer;"><?= $r['is_public'] ? 'public' : 'private' ?></button>
          </form>
        </td>
        <td>
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <select name="status" onchange="this.form.submit()" style="width:auto;margin:0;padding:4px 8px;font-size:12px;">
              <option value="new" <?= $r['status'] === 'new' ? 'selected' : '' ?>>New</option>
              <option value="prayed" <?= $r['status'] === 'prayed' ? 'selected' : '' ?>>Prayed</option>
              <option value="archived" <?= $r['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
          </form>
        </td>
        <td><?= e(timeAgo($r['created_at'])) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this request?');" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="btn danger sm">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
