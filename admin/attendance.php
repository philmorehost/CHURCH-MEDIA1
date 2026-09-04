<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$scope = Unit::scopeClause($user, 'org_unit_id');
$scopeSql = $scope !== '' ? ' AND ' . $scope : '';
// Qualified variant for JOIN queries: users also carries org_unit_id, so an
// unqualified column in the WHERE clause would be ambiguous.
$scopeASql = Unit::scopeClause($user, 'a.org_unit_id');
$scopeASql = $scopeASql !== '' ? ' AND ' . $scopeASql : '';
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($action === 'edit' && !Unit::recordInScope($pdo, 'attendance_records', $id, $user)) {
        flash('error', 'You can only manage attendance for your own church.');
        redirect('/admin/attendance');
    }
    $serviceDate = trim($_POST['service_date'] ?? '');
    $serviceName = trim($_POST['service_name'] ?? '');
    $topic = trim($_POST['topic'] ?? '');
    $bibleText = trim($_POST['bible_text'] ?? '');
    $male = max(0, (int) ($_POST['male_count'] ?? 0));
    $female = max(0, (int) ($_POST['female_count'] ?? 0));
    $notes = trim($_POST['notes'] ?? '');

    if ($serviceDate === '' || $serviceName === '') {
        $errors[] = 'Date and service name are required.';
    } else {
        if ($action === 'create') {
            $pdo->prepare('INSERT INTO attendance_records (org_unit_id, service_date, service_name, topic, bible_text, male_count, female_count, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user['org_unit_id'] ?? null, $serviceDate, $serviceName, $topic, $bibleText, $male, $female, $notes, $user['id'] ?? null]);
            flash('success', 'Attendance added.');
        } else {
            $pdo->prepare('UPDATE attendance_records SET service_date=?, service_name=?, topic=?, bible_text=?, male_count=?, female_count=?, notes=? WHERE id=?')
                ->execute([$serviceDate, $serviceName, $topic, $bibleText, $male, $female, $notes, $id]);
            flash('success', 'Attendance updated.');
        }
        redirect('/admin/attendance');
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'attendance_records', $targetId, $user)) {
        flash('error', 'You can only manage attendance for your own church.');
        redirect('/admin/attendance');
    }
    $pdo->prepare('DELETE FROM attendance_records WHERE id = ?')->execute([$targetId]);
    flash('success', 'Attendance record removed.');
    redirect('/admin/attendance');
}

// CSV export of all attendance records (scoped to the current church).
if ($action === 'export_csv') {
    $rows = $pdo->query('SELECT service_date, service_name, topic, bible_text, male_count, female_count, notes FROM attendance_records WHERE 1=1' . $scopeSql . ' ORDER BY service_date DESC, id DESC')->fetchAll();
    $csv = array_map(fn (array $r): array => [
        $r['service_date'],
        $r['service_name'],
        $r['topic'] ?? '',
        $r['bible_text'] ?? '',
        (int) $r['male_count'],
        (int) $r['female_count'],
        (int) $r['male_count'] + (int) $r['female_count'],
        $r['notes'] ?? '',
    ], $rows);
    csvDownload('attendance-' . date('Y-m-d') . '.csv', ['Date', 'Service', 'Topic', 'Bible Text', 'Males', 'Females', 'Total', 'Notes'], $csv);
}

// Saves the same CSV on the server and flashes a shareable link (Google-Forms style).
if ($action === 'save_export_csv') {
    $rows = $pdo->query('SELECT service_date, service_name, topic, bible_text, male_count, female_count, notes FROM attendance_records WHERE 1=1' . $scopeSql . ' ORDER BY service_date DESC, id DESC')->fetchAll();
    $csv = array_map(fn (array $r): array => [
        $r['service_date'], $r['service_name'], $r['topic'] ?? '', $r['bible_text'] ?? '',
        (int) $r['male_count'], (int) $r['female_count'], (int) $r['male_count'] + (int) $r['female_count'], $r['notes'] ?? '',
    ], $rows);
    $saved = saveExportFile($pdo, 'attendance', 'Attendance', ['Date', 'Service', 'Topic', 'Bible Text', 'Males', 'Females', 'Total', 'Notes'], $csv, null, (int) ($user['id'] ?? 0));
    flash('success', 'Shareable CSV created: ' . $saved['url']);
    redirect('/admin/attendance');
}

$editing = null;
if ($action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM attendance_records WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing || !Unit::recordInScope($pdo, 'attendance_records', $id, $user)) {
        redirect('/admin/attendance');
    }
}

$records = [];
$summary = ['services' => 0, 'male' => 0, 'female' => 0, 'total' => 0];
$trend = [];
$compare = [];
$trendMode = $_GET['trend'] ?? 'weekly';
if (!in_array($trendMode, ['weekly', 'monthly'], true)) {
    $trendMode = 'weekly';
}
if ($action === 'list') {
    $records = $pdo->query('SELECT a.*, u.name AS recorded_by FROM attendance_records a LEFT JOIN users u ON u.id = a.created_by WHERE 1=1' . $scopeASql . ' ORDER BY a.service_date DESC, a.id DESC LIMIT 200')->fetchAll();
    $agg = $pdo->query('SELECT COUNT(*) AS services, COALESCE(SUM(male_count),0) AS male, COALESCE(SUM(female_count),0) AS female, COALESCE(SUM(male_count + female_count),0) AS total FROM attendance_records WHERE 1=1' . $scopeSql)->fetch();
    $summary = $agg ?: $summary;

    if ($trendMode === 'monthly') {
        // Monthly aggregate for the last 12 months (oldest → newest).
        $trendStmt = $pdo->query('
            SELECT MIN(service_date) AS period_start,
                   SUM(male_count + female_count) AS total
            FROM attendance_records
            WHERE 1=1' . $scopeSql . '
              AND service_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY YEAR(service_date), MONTH(service_date)
            ORDER BY period_start ASC');
        $trendMap = [];
        foreach ($trendStmt->fetchAll() as $row) {
            $trendMap[date('Y-m', strtotime((string) $row['period_start']))] = (int) $row['total'];
        }
        $filled = [];
        $month = new DateTimeImmutable('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $d = $month->modify('-' . $i . ' months');
            $key = $d->format('Y-m');
            $filled[] = ['label' => $d->format('M y'), 'total' => $trendMap[$key] ?? 0];
        }
        $trend = $filled;
    } else {
        // Weekly growth trend for the last 12 weeks (from the earliest day of
        // the window, so the bars read left-to-right oldest → newest).
        $trendStmt = $pdo->query('
            SELECT MIN(service_date) AS week_start,
                   SUM(male_count + female_count) AS total
            FROM attendance_records
            WHERE 1=1' . $scopeSql . '
              AND service_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
            GROUP BY YEARWEEK(service_date, 1)
            ORDER BY week_start ASC');
        foreach ($trendStmt->fetchAll() as $row) {
            $trend[] = ['label' => date('M j', strtotime((string) $row['week_start'])), 'total' => (int) $row['total']];
        }
        // Fill gaps so the chart always spans 12 bars (weeks with no record = 0).
        $trendMap = [];
        foreach ($trend as $t) {
            $trendMap[$t['label']] = $t['total'];
        }
        $filled = [];
        $day = new DateTimeImmutable('today');
        for ($i = 11; $i >= 0; $i--) {
            $d = $day->modify('-' . $i . ' weeks');
            $label = $d->format('M j');
            $filled[] = ['label' => $label, 'total' => $trendMap[$label] ?? 0];
        }
        $trend = $filled;
    }

    // Monthly attendance vs. newcomers comparison (last 6 months). This pairs
    // total attendance with the number of newcomers added each month so growth
    // in the service and follow-up funnel can be read side by side.
    $attByMonth = [];
    $stmt = $pdo->query('SELECT DATE_FORMAT(service_date, "%Y-%m") AS ym, SUM(male_count + female_count) AS total FROM attendance_records WHERE 1=1' . $scopeSql . ' AND service_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym');
    foreach ($stmt->fetchAll() as $row) {
        $attByMonth[$row['ym']] = (int) $row['total'];
    }
    $newByMonth = [];
    $stmt = $pdo->query('SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym, COUNT(*) AS total FROM newcomers WHERE 1=1' . $scopeSql . ' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY ym');
    foreach ($stmt->fetchAll() as $row) {
        $newByMonth[$row['ym']] = (int) $row['total'];
    }
    $compare = [];
    $month = new DateTimeImmutable('first day of this month');
    for ($i = 5; $i >= 0; $i--) {
        $d = $month->modify('-' . $i . ' months');
        $ym = $d->format('Y-m');
        $compare[] = [
            'label' => $d->format('M y'),
            'attendance' => $attByMonth[$ym] ?? 0,
            'newcomers' => $newByMonth[$ym] ?? 0,
        ];
    }
}

$pageTitle = 'Attendance';
$activeNav = 'attendance';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <div class="card" style="max-width:640px;">
    <h2><?= $action === 'create' ? 'Add Attendance Record' : 'Edit Attendance Record' ?></h2>
    <form method="post" action="/admin/attendance?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>">
      <?= Csrf::field() ?>
      <div class="row two">
        <div>
          <label for="service_date">Date</label>
          <input type="date" id="service_date" name="service_date" value="<?= e($editing['service_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div>
          <label for="service_name">Service</label>
          <input type="text" id="service_name" name="service_name" value="<?= e($editing['service_name'] ?? '') ?>" placeholder="Sunday Worship" list="service-names" required>
          <datalist id="service-names">
            <option value="Sunday Worship">
            <option value="Sunday School">
            <option value="Midweek Service">
            <option value="Prayer Meeting">
            <option value="Youth Service">
            <option value="Choir Rehearsal">
            <option value="Special Service">
          </datalist>
        </div>
      </div>
      <label for="topic">Topic / Message Title</label>
      <input type="text" id="topic" name="topic" value="<?= e($editing['topic'] ?? '') ?>" placeholder="Faith in Action">
      <label for="bible_text">Bible Text / Scripture</label>
      <input type="text" id="bible_text" name="bible_text" value="<?= e($editing['bible_text'] ?? '') ?>" placeholder="James 2:14-26">
      <div class="row three">
        <div>
          <label for="male_count">Males</label>
          <input type="number" id="male_count" name="male_count" value="<?= (int) ($editing['male_count'] ?? 0) ?>" min="0" required>
        </div>
        <div>
          <label for="female_count">Females</label>
          <input type="number" id="female_count" name="female_count" value="<?= (int) ($editing['female_count'] ?? 0) ?>" min="0" required>
        </div>
        <div style="display:flex;align-items:flex-end;">
          <p class="sub" style="margin:0 0 6px;">Youth church attendance by gender.</p>
        </div>
      </div>
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes"><?= e($editing['notes'] ?? '') ?></textarea>
      <button class="btn" type="submit"><?= $action === 'create' ? 'Add Record' : 'Save Changes' ?></button>
      <a href="/admin/attendance" class="btn secondary">Cancel</a>
    </form>
  </div>
<?php else: ?>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
    <a href="/admin/attendance?action=create" class="btn">+ Add Attendance</a>
    <a href="/admin/attendance?action=export_csv" class="btn secondary">⬇ Export CSV</a>
    <a href="/admin/attendance?action=save_export_csv" class="btn secondary">🔗 Save &amp; Share Link</a>
  </div>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;">
    <div class="card" style="padding:16px;">
      <div style="color:var(--ink-faint);font-size:12px;text-transform:uppercase;letter-spacing:.5px;">Services Logged</div>
      <div style="font-size:26px;font-weight:700;color:var(--ink);margin-top:4px;"><?= (int) $summary['services'] ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="color:var(--ink-faint);font-size:12px;text-transform:uppercase;letter-spacing:.5px;">Total Attendance</div>
      <div style="font-size:26px;font-weight:700;color:var(--ink);margin-top:4px;"><?= (int) $summary['total'] ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="color:var(--ink-faint);font-size:12px;text-transform:uppercase;letter-spacing:.5px;">Males</div>
      <div style="font-size:26px;font-weight:700;color:var(--gold);margin-top:4px;"><?= (int) $summary['male'] ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="color:var(--ink-faint);font-size:12px;text-transform:uppercase;letter-spacing:.5px;">Females</div>
      <div style="font-size:26px;font-weight:700;color:var(--ink-dim);margin-top:4px;"><?= (int) $summary['female'] ?></div>
    </div>
  </div>

  <?php if ($trend): ?>
  <div class="card" style="padding:20px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
      <h2 style="margin:0;">Growth Trend</h2>
      <div style="display:flex;gap:6px;">
        <a class="btn sm <?= $trendMode === 'weekly' ? '' : 'secondary' ?>" href="/admin/attendance?trend=weekly">Weekly</a>
        <a class="btn sm <?= $trendMode === 'monthly' ? '' : 'secondary' ?>" href="/admin/attendance?trend=monthly">Monthly</a>
      </div>
    </div>
    <p class="sub" style="margin-bottom:18px;">Total youth attendance (males + females) per <?= $trendMode === 'monthly' ? 'month' : 'week' ?>. Periods with no record are shown as zero.</p>
    <div class="trend-chart">
      <?php $trendMax = max(array_column($trend, 'total')); $trendMax = $trendMax > 0 ? $trendMax : 1; ?>
      <?php foreach ($trend as $t): ?>
        <div class="trend-col">
          <div class="trend-value"><?= (int) $t['total'] ?></div>
          <div class="trend-bar-wrap">
            <div class="trend-bar" style="height:<?= max(2, round(((int) $t['total'] / $trendMax) * 100)) ?>%;"></div>
          </div>
          <div class="trend-label"><?= e($t['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($compare): ?>
  <div class="card" style="padding:20px;margin-bottom:20px;">
    <h2 style="margin:0 0 4px;">Attendance vs. Newcomers</h2>
    <p class="sub" style="margin-bottom:16px;">Total attendance vs. newcomers added each month (last 6 months) — growth vs. follow-up funnel at a glance.</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;font-size:12px;color:var(--ink-dim);">
      <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:3px;background:linear-gradient(180deg,var(--gold-soft),var(--gold));display:inline-block;"></span> Attendance</span>
      <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:3px;background:linear-gradient(180deg,#6fb3ff,#3d7eff);display:inline-block;"></span> Newcomers</span>
    </div>
    <div class="trend-chart">
      <?php $compareMax = max(array_merge(array_column($compare, 'attendance'), array_column($compare, 'newcomers'))); $compareMax = $compareMax > 0 ? $compareMax : 1; ?>
      <?php foreach ($compare as $c): ?>
        <div class="trend-col">
          <div class="trend-value"><?= (int) ($c['attendance'] + $c['newcomers']) ?></div>
          <div class="trend-bar-wrap">
            <div style="display:flex;align-items:flex-end;gap:3px;width:100%;height:100%;justify-content:center;">
              <div class="trend-bar" style="height:<?= max(2, round(((int) $c['attendance'] / $compareMax) * 100)) ?>%;"></div>
              <div class="trend-bar trend-bar--newcomer" style="height:<?= max(2, round(((int) $c['newcomers'] / $compareMax) * 100)) ?>%;"></div>
            </div>
          </div>
          <div class="trend-label"><?= e($c['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$records): ?>
    <div class="card empty">No attendance records yet. Click "+ Add Attendance" to record your first service.</div>
  <?php else: ?>
  <table>
    <tr>
      <th>Date</th>
      <th>Service</th>
      <th>Topic</th>
      <th>Bible Text</th>
      <th>Males</th>
      <th>Females</th>
      <th>Total</th>
      <th>Recorded By</th>
      <th></th>
    </tr>
    <?php foreach ($records as $r): ?>
      <tr>
        <td><?= e(date('M j, Y', strtotime((string) $r['service_date']))) ?></td>
        <td><?= e($r['service_name']) ?></td>
        <td><?= e((string) $r['topic']) ?></td>
        <td><?= e((string) $r['bible_text']) ?></td>
        <td><?= (int) $r['male_count'] ?></td>
        <td><?= (int) $r['female_count'] ?></td>
        <td><strong><?= (int) $r['male_count'] + (int) $r['female_count'] ?></strong></td>
        <td><?= e((string) ($r['recorded_by'] ?? '')) ?></td>
        <td class="actions">
          <a class="btn sm secondary" href="/admin/newcomers?action=create&attendance_id=<?= (int) $r['id'] ?>" title="Quick-add a newcomer who attended this service">+ Newcomer</a>
          <a class="btn sm secondary" href="/admin/attendance?action=edit&id=<?= (int) $r['id'] ?>">Edit</a>
          <form method="post" action="/admin/attendance?action=delete" style="display:inline;" onsubmit="return confirm('Delete this attendance record?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn sm danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
