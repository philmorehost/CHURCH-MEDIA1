<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$scope = Unit::scopeClause($user, 'org_unit_id');
$scopeSql = $scope !== '' ? ' AND ' . $scope : '';
// Qualified variant for JOIN queries: attendance_records and users also carry
// org_unit_id, so an unqualified column in the WHERE clause would be ambiguous.
$scopeNSql = Unit::scopeClause($user, 'n.org_unit_id');
$scopeNSql = $scopeNSql !== '' ? ' AND ' . $scopeNSql : '';
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$statusFilter = $_GET['status'] ?? '';

if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($action === 'edit' && !Unit::recordInScope($pdo, 'newcomers', $id, $user)) {
        flash('error', 'You can only manage newcomers for your own church.');
        redirect('/admin/newcomers');
    }
    $name = trim($_POST['name'] ?? '');
    $whatsapp = trim($_POST['whatsapp_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gender = in_array($_POST['gender'] ?? '', ['male', 'female'], true) ? $_POST['gender'] : null;
    // Target is the Youth church, so age group is no longer collected on the
    // form; store 'youth' so existing queries/reports keep working.
    $ageGroup = 'youth';
    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
    $visitDate = trim($_POST['visit_date'] ?? '') ?: null;
    $status = in_array($_POST['follow_up_status'] ?? '', ['new', 'contacted', 'followed_up', 'returned', 'inactive'], true) ? $_POST['follow_up_status'] : 'new';
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '') {
        $errors[] = 'Name is required.';
    } else {
        if ($action === 'create') {
            $pdo->prepare('INSERT INTO newcomers (org_unit_id, name, whatsapp_phone, address, gender, age_group, attendance_id, visit_date, follow_up_status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user['org_unit_id'] ?? null, $name, $whatsapp, $address, $gender, $ageGroup, $attendanceId > 0 ? $attendanceId : null, $visitDate, $status, $notes, $user['id'] ?? null]);
            flash('success', 'Newcomer added.');
        } else {
            $pdo->prepare('UPDATE newcomers SET name=?, whatsapp_phone=?, address=?, gender=?, age_group=?, attendance_id=?, visit_date=?, follow_up_status=?, notes=? WHERE id=?')
                ->execute([$name, $whatsapp, $address, $gender, $ageGroup, $attendanceId > 0 ? $attendanceId : null, $visitDate, $status, $notes, $id]);
            flash('success', 'Newcomer updated.');
        }
        redirect('/admin/newcomers');
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'newcomers', $targetId, $user)) {
        flash('error', 'You can only manage newcomers for your own church.');
        redirect('/admin/newcomers');
    }
    $pdo->prepare('DELETE FROM newcomers WHERE id = ?')->execute([$targetId]);
    flash('success', 'Newcomer removed.');
    redirect('/admin/newcomers');
}

// Quick follow-up status change straight from the list (no need to open edit).
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['follow_up_status'] ?? '';
    if (!in_array($newStatus, ['new', 'contacted', 'followed_up', 'returned', 'inactive'], true)) {
        flash('error', 'Invalid follow-up status.');
        redirect('/admin/newcomers');
    }
    if (!Unit::recordInScope($pdo, 'newcomers', $targetId, $user)) {
        flash('error', 'You can only manage newcomers for your own church.');
        redirect('/admin/newcomers');
    }
    $pdo->prepare('UPDATE newcomers SET follow_up_status = ? WHERE id = ?')->execute([$newStatus, $targetId]);
    $statusLabel = match ($newStatus) {
        'contacted' => 'Contacted',
        'followed_up' => 'Followed Up',
        'returned' => 'Returned',
        'inactive' => 'Inactive',
        default => 'New',
    };
    flash('success', 'Follow-up status updated to ' . $statusLabel . '.');
    redirect('/admin/newcomers' . ($statusFilter !== '' ? '?status=' . rawurlencode($statusFilter) : ''));
}

// CSV export of newcomers (scoped to the current church + status filter).
if ($action === 'export_csv') {
    $statusWhere = '';
    $statusParams = [];
    if ($statusFilter !== '') {
        $statusWhere = ' AND n.follow_up_status = ?';
        $statusParams[] = $statusFilter;
    }
    $stmt = $pdo->prepare('SELECT n.name, n.whatsapp_phone, n.address, n.gender, n.visit_date, n.follow_up_status, n.notes, n.created_at, a.service_date AS attended_on, a.service_name AS attended_service FROM newcomers n LEFT JOIN attendance_records a ON a.id = n.attendance_id WHERE 1=1' . $scopeNSql . $statusWhere . ' ORDER BY n.created_at DESC, n.id DESC');
    $stmt->execute($statusParams);
    $csv = array_map(fn (array $r): array => [
        $r['name'],
        $r['whatsapp_phone'] ?? '',
        $r['address'] ?? '',
        $r['gender'] ?? '',
        $r['visit_date'] ?? '',
        $r['attended_on'] ?? '',
        $r['attended_service'] ?? '',
        $r['follow_up_status'] ?? '',
        $r['notes'] ?? '',
        $r['created_at'] ?? '',
    ], $stmt->fetchAll());
    csvDownload('newcomers-' . date('Y-m-d') . ($statusFilter !== '' ? '-' . $statusFilter : '') . '.csv', ['Name', 'WhatsApp Phone', 'Address', 'Gender', 'Visit Date', 'Attended Date', 'Attended Service', 'Follow-up Status', 'Notes', 'Added'], $csv);
}

// Saves the same CSV on the server and flashes a shareable link (Google-Forms style).
if ($action === 'save_export_csv') {
    $statusWhere = '';
    $statusParams = [];
    if ($statusFilter !== '') {
        $statusWhere = ' AND n.follow_up_status = ?';
        $statusParams[] = $statusFilter;
    }
    $stmt = $pdo->prepare('SELECT n.name, n.whatsapp_phone, n.address, n.gender, n.visit_date, n.follow_up_status, n.notes, n.created_at, a.service_date AS attended_on, a.service_name AS attended_service FROM newcomers n LEFT JOIN attendance_records a ON a.id = n.attendance_id WHERE 1=1' . $scopeNSql . $statusWhere . ' ORDER BY n.created_at DESC, n.id DESC');
    $stmt->execute($statusParams);
    $csv = array_map(fn (array $r): array => [
        $r['name'], $r['whatsapp_phone'] ?? '', $r['address'] ?? '', $r['gender'] ?? '',
        $r['visit_date'] ?? '', $r['attended_on'] ?? '', $r['attended_service'] ?? '',
        $r['follow_up_status'] ?? '', $r['notes'] ?? '', $r['created_at'] ?? '',
    ], $stmt->fetchAll());
    $saved = saveExportFile($pdo, 'newcomers', 'Newcomers' . ($statusFilter !== '' ? ' - ' . ucfirst($statusFilter) : ''), ['Name', 'WhatsApp Phone', 'Address', 'Gender', 'Visit Date', 'Attended Date', 'Attended Service', 'Follow-up Status', 'Notes', 'Added'], $csv, null, (int) ($user['id'] ?? 0));
    flash('success', 'Shareable CSV created: ' . $saved['url']);
    redirect('/admin/newcomers' . ($statusFilter !== '' ? '?status=' . rawurlencode($statusFilter) : ''));
}

$editing = null;
if ($action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM newcomers WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing || !Unit::recordInScope($pdo, 'newcomers', $id, $user)) {
        redirect('/admin/newcomers');
    }
}

$attendanceOptions = [];
$newcomers = [];
$statusCounts = ['new' => 0, 'contacted' => 0, 'followed_up' => 0, 'returned' => 0, 'inactive' => 0, 'total' => 0];
// Quick-add from an attendance row: /admin/newcomers?action=create&attendance_id=X
$prefillAttendanceId = (int) ($_GET['attendance_id'] ?? 0);
$prefillVisitDate = null;
// Attendance options are needed by both the form (create/edit) and the list.
if (in_array($action, ['list', 'create', 'edit'], true)) {
    $attendanceOptions = $pdo->query('SELECT id, service_date, service_name, topic FROM attendance_records WHERE 1=1' . $scopeSql . ' ORDER BY service_date DESC, id DESC LIMIT 100')->fetchAll();
    if ($prefillAttendanceId > 0) {
        foreach ($attendanceOptions as $a) {
            if ((int) $a['id'] === $prefillAttendanceId) {
                $prefillVisitDate = $a['service_date'];
                break;
            }
        }
    }
}
if ($action === 'list') {
    $statusWhere = '';
    $statusParams = [];
    if ($statusFilter !== '') {
        $statusWhere = ' AND n.follow_up_status = ?';
        $statusParams[] = $statusFilter;
    }
    $stmt = $pdo->prepare('SELECT n.*, a.service_date AS attended_on, a.service_name AS attended_service, u.name AS recorded_by FROM newcomers n LEFT JOIN attendance_records a ON a.id = n.attendance_id LEFT JOIN users u ON u.id = n.created_by WHERE 1=1' . $scopeNSql . $statusWhere . ' ORDER BY n.created_at DESC, n.id DESC LIMIT 300');
    $stmt->execute($statusParams);
    $newcomers = $stmt->fetchAll();
    $agg = $pdo->query('SELECT follow_up_status, COUNT(*) AS c FROM newcomers WHERE 1=1' . $scopeSql . ' GROUP BY follow_up_status')->fetchAll();
    foreach ($agg as $row) {
        $statusCounts[$row['follow_up_status']] = (int) $row['c'];
        $statusCounts['total'] += (int) $row['c'];
    }
}

$pageTitle = 'Newcomers';
$activeNav = 'newcomers';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <div class="card" style="max-width:640px;">
    <h2><?= $action === 'create' ? 'Add Newcomer' : 'Edit Newcomer' ?></h2>
    <p class="sub">Capture the details of a first-time guest so you can follow up after the service.</p>
    <form method="post" action="/admin/newcomers?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>">
      <?= Csrf::field() ?>
      <label for="name">Full Name</label>
      <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required placeholder="e.g. Sarah Johnson">
      <div class="row two">
        <div>
          <label for="whatsapp_phone">WhatsApp Phone Number</label>
          <input type="tel" id="whatsapp_phone" name="whatsapp_phone" value="<?= e($editing['whatsapp_phone'] ?? '') ?>" placeholder="+234 812 345 6789">
        </div>
        <div>
          <label for="gender">Gender</label>
          <select id="gender" name="gender">
            <option value="">— Select —</option>
            <option value="male" <?= ($editing['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($editing['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
          </select>
        </div>
      </div>
      <label for="visit_date">Visit Date</label>
      <input type="date" id="visit_date" name="visit_date" value="<?= e($editing['visit_date'] ?? ($prefillVisitDate ?? date('Y-m-d'))) ?>">
      <label for="address">Address</label>
      <input type="text" id="address" name="address" value="<?= e($editing['address'] ?? '') ?>" placeholder="Street, City">
      <label for="attendance_id">Attended Service</label>
      <select id="attendance_id" name="attendance_id">
        <option value="0">— None / Not logged —</option>
        <?php $selectedAttendanceId = (int) ($editing['attendance_id'] ?? ($prefillAttendanceId > 0 ? $prefillAttendanceId : 0)); ?>
        <?php foreach ($attendanceOptions as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= $selectedAttendanceId === (int) $a['id'] ? 'selected' : '' ?>>
            <?= e(date('M j, Y', strtotime((string) $a['service_date']))) ?> · <?= e($a['service_name']) ?><?= $a['topic'] ? ' — ' . e((string) $a['topic']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="row two">
        <div>
          <label for="follow_up_status">Follow-up Status</label>
          <select id="follow_up_status" name="follow_up_status">
            <option value="new" <?= ($editing['follow_up_status'] ?? 'new') === 'new' ? 'selected' : '' ?>>New (not yet contacted)</option>
            <option value="contacted" <?= ($editing['follow_up_status'] ?? '') === 'contacted' ? 'selected' : '' ?>>Contacted</option>
            <option value="followed_up" <?= ($editing['follow_up_status'] ?? '') === 'followed_up' ? 'selected' : '' ?>>Followed up</option>
            <option value="returned" <?= ($editing['follow_up_status'] ?? '') === 'returned' ? 'selected' : '' ?>>Returned (came back)</option>
            <option value="inactive" <?= ($editing['follow_up_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive / Not interested</option>
          </select>
        </div>
        <div></div>
      </div>
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes"><?= e($editing['notes'] ?? '') ?></textarea>
      <button class="btn" type="submit"><?= $action === 'create' ? 'Add Newcomer' : 'Save Changes' ?></button>
      <a href="/admin/newcomers" class="btn secondary">Cancel</a>
    </form>
  </div>
<?php else: ?>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="/admin/newcomers?action=create" class="btn">+ Add Newcomer</a>
      <a href="/admin/newcomers?action=export_csv<?= $statusFilter !== '' ? '&status=' . e($statusFilter) : '' ?>" class="btn secondary">⬇ Export CSV</a>
      <a href="/admin/newcomers?action=save_export_csv<?= $statusFilter !== '' ? '&status=' . e($statusFilter) : '' ?>" class="btn secondary">🔗 Save &amp; Share Link</a>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <a class="btn sm <?= $statusFilter === '' ? '' : 'secondary' ?>" href="/admin/newcomers">All (<?= (int) $statusCounts['total'] ?>)</a>
      <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'followed_up' => 'Followed Up', 'returned' => 'Returned', 'inactive' => 'Inactive'] as $key => $label): ?>
        <a class="btn sm <?= $statusFilter === $key ? '' : 'secondary' ?>" href="/admin/newcomers?status=<?= e($key) ?>"><?= e($label) ?> (<?= (int) $statusCounts[$key] ?>)</a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$newcomers): ?>
    <div class="card empty">No newcomers yet. Click "+ Add Newcomer" to record a first-time guest.</div>
  <?php else: ?>
  <table>
    <tr>
      <th>Name</th>
      <th>WhatsApp</th>
      <th>Address</th>
      <th>Gender</th>
      <th>Visited</th>
      <th>Status</th>
      <th></th>
    </tr>
    <?php foreach ($newcomers as $n): ?>
      <tr>
        <td><strong><?= e($n['name']) ?></strong><?= $n['notes'] ? '<br><small style="color:var(--ink-faint);">' . e((string) $n['notes']) . '</small>' : '' ?></td>
        <td>
          <?php if ($n['whatsapp_phone']): ?>
            <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', (string) $n['whatsapp_phone'])) ?>" target="_blank" rel="noopener" style="color:var(--gold);"><?= e($n['whatsapp_phone']) ?> ↗</a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= e((string) $n['address']) ?></td>
        <td><?= $n['gender'] ? e(ucfirst((string) $n['gender'])) : '—' ?></td>
        <td>
          <?php if ($n['visit_date']): ?><?= e(date('M j, Y', strtotime((string) $n['visit_date']))) ?><?php else: ?>—<?php endif; ?>
          <?php if ($n['attended_on']): ?><br><small style="color:var(--ink-faint);"><?= e(date('M j', strtotime((string) $n['attended_on']))) ?> · <?= e((string) $n['attended_service']) ?></small><?php endif; ?>
        </td>
        <td>
          <form method="post" action="/admin/newcomers?action=update_status<?= $statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '' ?>" class="status-form" title="Change follow-up status">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <select name="follow_up_status" class="status-select status--<?= e($n['follow_up_status'] ?? 'new') ?>" onchange="this.form.submit()" aria-label="Follow-up status for <?= e($n['name']) ?>">
              <option value="new" <?= ($n['follow_up_status'] ?? 'new') === 'new' ? 'selected' : '' ?>>New</option>
              <option value="contacted" <?= ($n['follow_up_status'] ?? '') === 'contacted' ? 'selected' : '' ?>>Contacted</option>
              <option value="followed_up" <?= ($n['follow_up_status'] ?? '') === 'followed_up' ? 'selected' : '' ?>>Followed Up</option>
              <option value="returned" <?= ($n['follow_up_status'] ?? '') === 'returned' ? 'selected' : '' ?>>Returned</option>
              <option value="inactive" <?= ($n['follow_up_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </form>
        </td>
        <td class="actions">
          <a class="btn sm secondary" href="/admin/newcomers?action=edit&id=<?= (int) $n['id'] ?>">Edit</a>
          <form method="post" action="/admin/newcomers?action=delete" style="display:inline;" onsubmit="return confirm('Remove this newcomer?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button class="btn sm danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
