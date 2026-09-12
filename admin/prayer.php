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
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'status') {
        $status = in_array($_POST['status'] ?? '', PrayerWall::STATUSES, true) ? (string) $_POST['status'] : 'new';
        $pdo->prepare('UPDATE prayer_requests SET status = ? WHERE id = ?')->execute([$status, $id]);
    } elseif ($do === 'toggle_public') {
        $pdo->prepare('UPDATE prayer_requests SET is_public = NOT is_public WHERE id = ?')->execute([$id]);
    } elseif ($do === 'toggle_anonymous') {
        // Lets the team correct a mistaken submission without ever editing the
        // request itself. Turning anonymity off reveals the stored name publicly.
        $pdo->prepare('UPDATE prayer_requests SET is_anonymous = NOT is_anonymous WHERE id = ?')->execute([$id]);
    } elseif ($do === 'mark_answered') {
        PrayerWall::markAnswered($id, true, (string) ($_POST['answer_note'] ?? ''));
        // An answered prayer belongs on the answered wall, so publish it if the
        // giver had left it private — otherwise the note would have nowhere to go.
        $pdo->prepare('UPDATE prayer_requests SET status = "prayed" WHERE id = ? AND status != "archived"')->execute([$id]);
        flash('success', 'Marked as answered.');
    } elseif ($do === 'reopen') {
        PrayerWall::markAnswered($id, false);
        flash('success', 'Moved back to the open wall.');
    } elseif ($do === 'toggle_featured') {
        $current = $pdo->prepare('SELECT is_featured FROM prayer_requests WHERE id = ?');
        $current->execute([$id]);
        PrayerWall::setFeatured($id, (int) $current->fetchColumn() !== 1);
    } elseif ($do === 'delete') {
        $pdo->prepare('DELETE FROM prayer_requests WHERE id = ?')->execute([$id]);
    }
    redirect('/admin/prayer');
}

$requests = $pdo->query('SELECT * FROM prayer_requests WHERE 1=1' . $scopeSql . ' ORDER BY is_featured DESC, FIELD(status, "new", "prayed", "archived"), answered_at IS NOT NULL, created_at DESC LIMIT 200')->fetchAll();

// How many people have tapped "I prayed for this", to show next to each request.
// Scoped to the ids already listed, so this can never read outside the admin's reach.
$prayedCounts = [];
$listedIds = array_map(static fn(array $r): int => (int) $r['id'], $requests);
if ($listedIds !== []) {
    try {
        $placeholders = implode(',', array_fill(0, count($listedIds), '?'));
        $countStmt = $pdo->prepare(
            'SELECT request_id, COUNT(*) AS n FROM prayer_participants'
            . ' WHERE request_id IN (' . $placeholders . ') GROUP BY request_id'
        );
        $countStmt->execute($listedIds);
        foreach ($countStmt->fetchAll() as $row) {
            $prayedCounts[(int) $row['request_id']] = (int) $row['n'];
        }
    } catch (Throwable $e) {
        // Table not migrated yet — the stored counter is still shown.
    }
}

$pageTitle = 'Prayer Wall';
$activeNav = 'prayer';
require __DIR__ . '/partials/layout-open.php';
?>

<div class="card">
  <h2>Prayer Requests</h2>
  <p class="sub">Submitted from the public "Prayer" page. Marking one public puts it on the wall; marking it answered moves it to the Answered Prayers wall with your note attached.</p>
  <?php if (!$requests): ?>
    <div class="empty">No prayer requests yet.</div>
  <?php else: ?>
    <table>
      <tr>
        <th>Message</th><th>From</th><th>Church</th><th>On the wall</th>
        <th>🙏</th><th>Answered</th><th>Status</th><th>Submitted</th><th></th>
      </tr>
      <?php foreach ($requests as $r): ?>
      <?php
        $isAnonymous = (int) ($r['is_anonymous'] ?? 0) === 1;
        $isAnswered = !empty($r['answered_at']);
        $isFeatured = (int) ($r['is_featured'] ?? 0) === 1;
        $isPublic = (int) $r['is_public'] === 1;
      ?>
      <tr<?= $isFeatured ? ' style="background:#e8b95f0d;"' : '' ?>>
        <td style="max-width:340px;">
          <?php if ($isFeatured): ?><span class="badge warn">featured</span> <?php endif; ?>
          <?= e(mb_strimwidth((string) $r['message'], 0, 140, '…')) ?>
        </td>
        <td>
          <?php if ($isAnonymous): ?><span class="badge info">anonymous</span><br><?php endif; ?>
          <?= e($r['name'] ?: 'No name given') ?>
          <?php if ($r['email']): ?><br><small style="color:var(--ink-faint);"><?= e($r['email']) ?></small><?php endif; ?>
        </td>
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
            <button type="submit" class="badge <?= $isPublic ? 'ok' : '' ?>" style="border:none;cursor:pointer;"><?= $isPublic ? 'public' : 'private' ?></button>
          </form>
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="toggle_anonymous"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="badge <?= $isAnonymous ? 'info' : '' ?>" style="border:none;cursor:pointer;" title="<?= $isAnonymous ? 'The name is hidden publicly. Click to show it.' : 'The name is shown publicly. Click to hide it.' ?>"><?= $isAnonymous ? 'name hidden' : 'name shown' ?></button>
          </form>
        </td>
        <td>
          <strong><?= number_format((int) ($prayedCounts[(int) $r['id']] ?? 0)) ?></strong>
          <div><small style="color:var(--ink-faint);"><?= number_format((int) ($r['increment_count'] ?? 0)) ?> counted</small></div>
        </td>
        <td style="min-width:230px;">
          <?php if ($isAnswered): ?>
            <span class="badge ok">answered</span>
            <div><small style="color:var(--ink-faint);"><?= e(timeAgo((string) $r['answered_at'])) ?></small></div>
            <?php if (!empty($r['answer_note'])): ?>
              <div><small style="color:var(--ink-dim);">"<?= e(mb_strimwidth((string) $r['answer_note'], 0, 90, '…')) ?>"</small></div>
            <?php endif; ?>
            <form method="post" style="margin-top:6px;">
              <?= Csrf::field() ?><input type="hidden" name="do" value="reopen"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn secondary sm">Reopen</button>
            </form>
          <?php else: ?>
            <form method="post">
              <?= Csrf::field() ?><input type="hidden" name="do" value="mark_answered"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="text" name="answer_note" maxlength="1000" placeholder="How did God answer?" style="margin:0 0 6px;padding:6px 8px;font-size:12px;">
              <button type="submit" class="btn sm">Mark answered</button>
            </form>
          <?php endif; ?>
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
        <td style="white-space:nowrap;">
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="toggle_featured"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="btn secondary sm"><?= $isFeatured ? 'Unfeature' : 'Feature' ?></button>
          </form>
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
