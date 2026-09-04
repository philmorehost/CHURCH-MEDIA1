<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$scope = Unit::scopeClause($user, 'org_unit_id');
$scopeSql = $scope !== '' ? ' AND ' . $scope : '';
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();

// Super admin / scoped admin can assign a team member to a church.
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $reassignId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'team_members', $reassignId, $user) && $unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE team_members SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $reassignId]);
        flash('success', 'Assigned to ' . Unit::label($unitId) . '.');
    } else {
        flash('error', 'Could not reassign that team member.');
    }
    redirect('/admin/team');
}

if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($action === 'edit' && !Unit::recordInScope($pdo, 'team_members', $id, $user)) {
        flash('error', 'You can only manage team members for your own church.');
        redirect('/admin/team');
    }
    $name = trim($_POST['name'] ?? '');
    $roleTitle = trim($_POST['role_title'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Auto-assign team members to the creator's church (block creation if they have none).
    if ($action === 'create' && empty($user['is_super_admin']) && empty($user['org_unit_id'])) {
        $errors[] = 'Your account has no Home Church assigned — ask the super admin to set it (Users → Edit → Home Unit) before adding team members.';
    }

    if ($name === '') {
        $errors[] = 'Name is required.';
    } else {
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $filename = MediaProcessor::processImage($_FILES['photo']['tmp_name'], UPLOADS_WEBP_PATH);
            $photoPath = $filename ? 'webp/' . $filename : null;
        }

        if ($action === 'create') {
            $pdo->prepare('INSERT INTO team_members (name, role_title, photo, bio, sort_order, is_published, org_unit_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $roleTitle, $photoPath, $bio, $sortOrder, $isPublished, $user['org_unit_id'] ?? null]);
            flash('success', 'Team member added.');
        } else {
            $sql = 'UPDATE team_members SET name=?, role_title=?, bio=?, sort_order=?, is_published=?';
            $params = [$name, $roleTitle, $bio, $sortOrder, $isPublished];
            if ($photoPath) { $sql .= ', photo=?'; $params[] = $photoPath; }
            $sql .= ' WHERE id=?';
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            flash('success', 'Team member updated.');
        }
        redirect('/admin/team');
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'team_members', $targetId, $user)) {
        flash('error', 'You can only manage team members for your own church.');
        redirect('/admin/team');
    }
    $pdo->prepare('DELETE FROM team_members WHERE id = ?')->execute([$targetId]);
    flash('success', 'Team member removed.');
    redirect('/admin/team');
}

$editing = null;
if ($action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM team_members WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing) {
        redirect('/admin/team');
    }
    if (!Unit::recordInScope($pdo, 'team_members', $id, $user)) {
        flash('error', 'You can only manage team members for your own church.');
        redirect('/admin/team');
    }
}

$members = $action === 'list' ? $pdo->query('SELECT * FROM team_members WHERE 1=1' . $scopeSql . ' ORDER BY sort_order ASC, name ASC')->fetchAll() : [];

$pageTitle = 'Team';
$activeNav = 'team';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <div class="card" style="max-width:560px;">
    <h2><?= $action === 'create' ? 'Add Team Member' : 'Edit Team Member' ?></h2>
    <form method="post" action="/admin/team?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="row two">
        <div>
          <label for="name">Name</label>
          <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
        </div>
        <div>
          <label for="role_title">Role / Title</label>
          <input type="text" id="role_title" name="role_title" value="<?= e($editing['role_title'] ?? '') ?>" placeholder="Lead Pastor">
        </div>
      </div>
      <label for="bio">Bio</label>
      <textarea id="bio" name="bio"><?= e($editing['bio'] ?? '') ?></textarea>
      <div class="row two">
        <div>
          <label for="photo">Photo</label>
          <input type="file" id="photo" name="photo" accept="image/*">
        </div>
        <div>
          <label for="sort_order">Sort Order</label>
          <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
        </div>
      </div>
      <div class="checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" <?= $editing === null || !empty($editing['is_published']) ? 'checked' : '' ?>>
        <label for="is_published" style="margin:0;">Visible on About page</label>
      </div>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'create' ? 'Add Member' : 'Save Changes' ?></button>
        <a class="btn secondary" href="/admin/team">Cancel</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;"><a class="btn" href="/admin/team?action=create">+ Add Team Member</a></div>
  <div class="card">
    <?php if (!$members): ?>
      <div class="empty">No team members yet.</div>
    <?php else: ?>
      <table>
        <tr><th>Photo</th><th>Name</th><th>Role</th><th>Church</th><th>Status</th><th></th></tr>
        <?php foreach ($members as $m): ?>
        <tr>
          <td><?php if ($m['photo']): ?><img class="thumb" src="<?= e(uploadUrl($m['photo'])) ?>" alt=""><?php else: ?><div class="thumb"></div><?php endif; ?></td>
          <td><?= e($m['name']) ?></td>
          <td><?= e($m['role_title'] ?: '—') ?></td>
          <td>
            <?php if (!empty($m['org_unit_id'])): ?>
              <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $m['org_unit_id']] ?? '') ?></span>
            <?php else: ?>
              <span class="badge warn">Unassigned</span>
            <?php endif; ?>
            <div style="margin-top:6px;">
              <?php $reassignId = (int) $m['id']; $reassignUnitId = !empty($m['org_unit_id']) ? (int) $m['org_unit_id'] : null; $showUnassignedOnly = false; $assignAction = '/admin/team?action=reassign'; require __DIR__ . '/partials/unit-assign.php'; ?>
            </div>
          </td>
          <td><?= $m['is_published'] ? '<span class="badge ok">visible</span>' : '<span class="badge warn">hidden</span>' ?></td>
          <td>
            <a class="btn secondary sm" href="/admin/team?action=edit&id=<?= (int) $m['id'] ?>">Edit</a>
            <form method="post" action="/admin/team?action=delete" onsubmit="return confirm('Remove this team member?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
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
