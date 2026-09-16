<?php
declare(strict_types=1);

Auth::requireRole('admin');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();

$action = $_GET['action'] ?? 'list';

// Verify or reject manual bank transfer receipts
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'failed';
    $stmt = $pdo->prepare('UPDATE donations SET payment_status = ? WHERE id = ? AND payment_method = "manual_bank"');
    $stmt->execute([$status, $targetId]);
    flash('success', $status === 'completed' ? 'Bank transfer donation verified as completed.' : 'Bank transfer donation marked as failed.');
    redirect('/admin/donations');
}

// Search and Filter parameters
$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['category'] ?? ''));
$method = trim((string) ($_GET['method'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(donor_name LIKE ? OR donor_email LIKE ? OR donor_phone LIKE ? OR payment_reference LIKE ? OR description LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($cat !== '') {
    $where[] = 'category = ?';
    $params[] = $cat;
}
if ($method !== '') {
    $where[] = 'payment_method = ?';
    $params[] = $method;
}
if ($statusFilter !== '') {
    $where[] = 'payment_status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

// CSV Export
if ($action === 'export_csv') {
    $exportStmt = $pdo->prepare("SELECT id, donor_name, donor_email, donor_phone, category, amount, currency, description, payment_method, payment_status, payment_reference, created_at FROM donations WHERE {$whereSql} ORDER BY created_at DESC");
    $exportStmt->execute($params);
    $rows = $exportStmt->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'ID' => $r['id'],
            'Donor Name' => $r['donor_name'] ?: 'Anonymous Giver',
            'Email' => $r['donor_email'],
            'Phone' => $r['donor_phone'],
            'Category' => $r['category'],
            'Amount (NGN)' => $r['amount'],
            'Description / Note' => $r['description'],
            'Payment Method' => $r['payment_method'] === 'online' ? 'Online (Payhub)' : 'Manual Bank Transfer',
            'Payment Status' => strtoupper($r['payment_status']),
            'Reference' => $r['payment_reference'],
            'Date' => $r['created_at'],
        ];
    }
    $headers = ['ID', 'Donor Name', 'Email', 'Phone', 'Category', 'Amount (NGN)', 'Description / Note', 'Payment Method', 'Payment Status', 'Reference', 'Date'];
    csvDownload('donations_report_' . date('Y-m-d') . '.csv', $headers, $data);
}

// Summary Statistics
$onlineSum = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM donations WHERE payment_status = "completed" AND payment_method = "online"')->fetchColumn();
$manualSum = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM donations WHERE payment_status = "completed" AND payment_method = "manual_bank"')->fetchColumn();
$pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM donations WHERE payment_status = "pending" AND payment_method = "manual_bank"')->fetchColumn();
$totalTransactions = (int) $pdo->query('SELECT COUNT(*) FROM donations')->fetchColumn();

// Fetch donations list
$stmt = $pdo->prepare("SELECT * FROM donations WHERE {$whereSql} ORDER BY created_at DESC");
$stmt->execute($params);
$donations = $stmt->fetchAll();

$pageTitle = 'Donations & Giving Report';
$activeNav = 'donations';
require __DIR__ . '/partials/layout-open.php';
?>

<!-- KPI Summary Stat Cards -->
<div class="stats-row" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
  <div class="card" style="padding:18px; margin:0;">
    <span class="sub" style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">Completed Online Giving</span>
    <div style="font-size:24px; font-weight:800; color:#34d399; margin-top:4px;">₦<?= number_format($onlineSum, 2) ?></div>
  </div>
  <div class="card" style="padding:18px; margin:0;">
    <span class="sub" style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">Verified Bank Transfers</span>
    <div style="font-size:24px; font-weight:800; color:var(--gold-soft); margin-top:4px;">₦<?= number_format($manualSum, 2) ?></div>
  </div>
  <div class="card" style="padding:18px; margin:0;">
    <span class="sub" style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">Pending Bank Receipts</span>
    <div style="font-size:24px; font-weight:800; color:<?= $pendingCount > 0 ? '#f59e0b' : 'var(--ink-dim)' ?>; margin-top:4px;"><?= $pendingCount ?></div>
  </div>
  <div class="card" style="padding:18px; margin:0;">
    <span class="sub" style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">Total Transactions</span>
    <div style="font-size:24px; font-weight:800; color:var(--ink-base); margin-top:4px;"><?= number_format($totalTransactions) ?></div>
  </div>
</div>

<!-- Search & Filters -->
<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <h2>Filter Giving Records</h2>
    <a href="/admin/donations?action=export_csv&q=<?= urlencode($q) ?>&category=<?= urlencode($cat) ?>&method=<?= urlencode($method) ?>&status=<?= urlencode($statusFilter) ?>" class="btn secondary sm">⬇ Export CSV Report</a>
  </div>

  <form method="get" action="/admin/donations" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
    <div>
      <label for="q">Search</label>
      <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Donor, email, ref, note...">
    </div>
    <div>
      <label for="category">Category</label>
      <select id="category" name="category">
        <option value="">All Categories</option>
        <option value="Tithe" <?= $cat === 'Tithe' ? 'selected' : '' ?>>Tithe</option>
        <option value="Offering" <?= $cat === 'Offering' ? 'selected' : '' ?>>Offering</option>
        <option value="Missions" <?= $cat === 'Missions' ? 'selected' : '' ?>>Missions</option>
        <option value="Building Fund" <?= $cat === 'Building Fund' ? 'selected' : '' ?>>Building Fund</option>
        <option value="Special Seed" <?= $cat === 'Special Seed' ? 'selected' : '' ?>>Special Seed</option>
        <option value="Thanksgiving" <?= $cat === 'Thanksgiving' ? 'selected' : '' ?>>Thanksgiving</option>
        <option value="Other" <?= $cat === 'Other' ? 'selected' : '' ?>>Other</option>
      </select>
    </div>
    <div>
      <label for="method">Payment Method</label>
      <select id="method" name="method">
        <option value="">All Methods</option>
        <option value="online" <?= $method === 'online' ? 'selected' : '' ?>>Online (Payhub)</option>
        <option value="manual_bank" <?= $method === 'manual_bank' ? 'selected' : '' ?>>Manual Bank Transfer</option>
      </select>
    </div>
    <div>
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">All Statuses</option>
        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed / Verified</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending Review</option>
        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed / Rejected</option>
      </select>
    </div>
    <div>
      <button type="submit" class="btn" style="width:100%;">Filter</button>
    </div>
  </form>
</div>

<!-- Donations Data Table -->
<div class="card">
  <h2>Giving Transactions List (<?= count($donations) ?>)</h2>

  <?php if (!$donations): ?>
    <div class="empty">No giving transactions found matching your criteria.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Donor Details</th>
            <th>Category</th>
            <th>Amount (NGN)</th>
            <th>Description / Note</th>
            <th>Method &amp; Reference</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($donations as $r): ?>
            <tr>
              <td>
                <strong><?= e($r['donor_name'] ?: 'Anonymous Giver') ?></strong><br>
                <small style="color:var(--ink-faint);"><?= e($r['donor_email']) ?></small>
                <?php if ($r['donor_phone']): ?>
                  <br><small style="color:var(--ink-faint);"><?= e($r['donor_phone']) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge info" style="font-size:11px;"><?= e($r['category']) ?></span>
              </td>
              <td style="font-weight:700; font-size:15px; color:var(--gold-soft);">
                ₦<?= number_format((float) $r['amount'], 2) ?>
              </td>
              <td style="max-width:240px; font-size:13px; line-height:1.4;">
                <?= $r['description'] ? e($r['description']) : '<span style="color:var(--ink-faint); italic">—</span>' ?>
              </td>
              <td>
                <span class="badge <?= $r['payment_method'] === 'online' ? 'ok' : 'warn' ?>" style="font-size:10px;">
                  <?= $r['payment_method'] === 'online' ? 'Online Gateway' : 'Bank Transfer' ?>
                </span><br>
                <small style="font-family:monospace; font-size:10px; color:var(--ink-faint);"><?= e($r['payment_reference']) ?></small>
              </td>
              <td>
                <?php if ($r['payment_status'] === 'completed'): ?>
                  <span class="badge ok">Completed</span>
                <?php elseif ($r['payment_status'] === 'pending'): ?>
                  <span class="badge warn">Pending Review</span>
                <?php else: ?>
                  <span class="badge fail">Failed</span>
                <?php endif; ?>
              </td>
              <td>
                <small style="color:var(--ink-faint);"><?= date('M j, Y h:i A', strtotime($r['created_at'])) ?></small>
              </td>
              <td>
                <?php if ($r['receipt_path']): ?>
                  <a class="btn sm secondary" href="<?= e(uploadUrl($r['receipt_path'])) ?>" target="_blank" style="margin-bottom:4px; display:inline-block;">🔍 View Receipt</a>
                <?php endif; ?>

                <?php if ($r['payment_method'] === 'manual_bank' && $r['payment_status'] === 'pending'): ?>
                  <form method="post" action="/admin/donations?action=verify" style="display:inline-block; margin-top:2px;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="status" value="completed">
                    <button type="submit" class="btn sm ok" style="padding:4px 8px; font-size:11px;" onclick="return confirm('Verify and approve this bank transfer donation?');">Approve</button>
                  </form>
                  <form method="post" action="/admin/donations?action=verify" style="display:inline-block; margin-top:2px;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="status" value="failed">
                    <button type="submit" class="btn sm fail" style="padding:4px 8px; font-size:11px;" onclick="return confirm('Reject and mark this transfer as failed?');">Reject</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
