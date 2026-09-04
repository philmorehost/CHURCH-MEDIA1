<?php
declare(strict_types=1);

Auth::requireRole('admin');
$pdo = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$currentUser = Auth::user();

// The super admin (created during install, flagged is_super_admin) is the owner
// account. Only it may manage itself — other admins (even promoted ones) are
// blocked from editing, suspending, or deleting it.
$superAdminId = (int) $pdo->query('SELECT id FROM users WHERE is_super_admin = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
$isSuperAdmin = ((int) $currentUser['id'] === $superAdminId);

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin', 'editor', 'media_team'], true) ? $_POST['role'] : 'media_team';
    $orgUnitIdRaw = (string) ($_POST['org_unit_id'] ?? '');
    $orgUnitId = $orgUnitIdRaw !== '' ? (int) $orgUnitIdRaw : null;
    if ($orgUnitId !== null && !Auth::isSuperAdmin()) {
        $myScope = !empty($currentUser['org_unit_id']) ? Unit::subtreeIds((int) $currentUser['org_unit_id']) : [];
        if (!in_array($orgUnitId, $myScope, true)) {
            $errors[] = 'You can only assign users to a unit within your own scope.';
        }
    }

    if ($name === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid name, username, and email.';
    } elseif (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    } else {
        try {
            $pdo->prepare('INSERT INTO users (name, username, email, password, role, org_unit_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $username, $email, password_hash($password, PASSWORD_ARGON2ID), $role, $orgUnitId]);
            flash('success', 'User created.');
            redirect('/admin/users');
        } catch (Throwable $e) {
            $errors[] = 'That username or email is already taken.';
        }
    }
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin', 'editor', 'media_team'], true) ? $_POST['role'] : 'media_team';
    $orgUnitIdRaw = (string) ($_POST['org_unit_id'] ?? '');
    $orgUnitId = $orgUnitIdRaw !== '' ? (int) $orgUnitIdRaw : null;
    if ($orgUnitId !== null && !Auth::isSuperAdmin()) {
        $myScope = !empty($currentUser['org_unit_id']) ? Unit::subtreeIds((int) $currentUser['org_unit_id']) : [];
        if (!in_array($orgUnitId, $myScope, true)) {
            $errors[] = 'You can only assign users to a unit within your own scope.';
        }
    }
    if ($targetId === (int) $currentUser['id']) {
        // Never allow changing your own role — prevents locking yourself out.
        $role = $currentUser['role'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    if ($targetId === $superAdminId && !$isSuperAdmin) {
        $errors[] = 'The super admin account is protected and cannot be edited.';
    } elseif (!$stmt->fetch()) {
        $errors[] = 'User not found.';
    } elseif ($name === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid name, username, and email.';
    } elseif ($password !== '' && strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters if you change it.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?');
        $check->execute([$username, $email, $targetId]);
        if ($check->fetch()) {
            $errors[] = 'That username or email is already taken.';
        } else {
            if ($password !== '') {
                $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, role = ?, password = ?, org_unit_id = ? WHERE id = ?')
                    ->execute([$name, $username, $email, $role, password_hash($password, PASSWORD_ARGON2ID), $orgUnitId, $targetId]);
            } else {
                $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, role = ?, org_unit_id = ? WHERE id = ?')
                    ->execute([$name, $username, $email, $role, $orgUnitId, $targetId]);
            }
            flash('success', 'User updated.');
            redirect('/admin/users');
        }
    }
}

if ($action === 'toggle_suspend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if ($targetId === $superAdminId && !$isSuperAdmin) {
        flash('error', 'The super admin account is protected and cannot be suspended.');
    } elseif ($targetId !== $currentUser['id']) {
        $pdo->prepare('UPDATE users SET is_suspended = NOT is_suspended WHERE id = ?')->execute([$targetId]);
    }
    redirect('/admin/users');
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if ($targetId === $superAdminId && !$isSuperAdmin) {
        flash('error', 'The super admin account is protected and cannot be deleted.');
    } elseif ($targetId !== $currentUser['id']) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        flash('success', 'User removed.');
    } else {
        flash('error', "You can't delete your own account.");
    }
    redirect('/admin/users');
}

$editUser = null;
if ($action === 'edit') {
    if ($id === $superAdminId && !$isSuperAdmin) {
        flash('error', 'The super admin account is protected and cannot be edited.');
        redirect('/admin/users');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Re-render the form with the submitted values after a validation error
        // (a successful save above has already redirected).
        $editUser = [
            'id' => (int) ($_POST['id'] ?? 0),
            'name' => trim($_POST['name'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'role' => $_POST['role'] ?? 'media_team',
            'org_unit_id' => (string) ($_POST['org_unit_id'] ?? '') !== '' ? (int) $_POST['org_unit_id'] : null,
        ];
        if ((int) $editUser['id'] === (int) $currentUser['id']) {
            $editUser['role'] = $currentUser['role'];
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, name, username, email, role, org_unit_id FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $editUser = $stmt->fetch() ?: null;
    }
    if (!$editUser || !isset($editUser['id']) || !$editUser['id']) {
        redirect('/admin/users');
    }
}

$users = $pdo->query('SELECT id, name, username, email, role, is_suspended, last_login_at, last_login_ip, org_unit_id FROM users ORDER BY id ASC')->fetchAll();

// Assignable units: any level (province/zone/area/parish) for the super admin;
// otherwise only units inside the current admin's own subtree.
$allUnits = Unit::all('type ASC, name ASC');
$labels = Unit::labelsById();
$myUnitScope = (Auth::isSuperAdmin() || empty($currentUser['org_unit_id'])) ? null : Unit::subtreeIds((int) $currentUser['org_unit_id']);
$unitOptions = [];
foreach ($allUnits as $u) {
    if ($myUnitScope !== null && !in_array((int) $u['id'], $myUnitScope, true)) {
        continue;
    }
    $unitOptions[$u['type']][] = ['id' => (int) $u['id'], 'label' => $labels[(int) $u['id']] ?? $u['name']];
}

$pageTitle = 'Users';
$activeNav = 'users';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'create'): ?>
  <div class="card" style="max-width:520px;">
    <h2>Add Team Account</h2>
    <form method="post" action="/admin/users?action=create">
      <?= Csrf::field() ?>
      <label for="name">Full Name</label>
      <input type="text" id="name" name="name" required>
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" minlength="10" required>
      <label for="role">Role</label>
      <select id="role" name="role">
        <option value="media_team">Media Team — upload &amp; manage posts</option>
        <option value="editor">Editor — posts, events, sermons</option>
        <option value="admin">Admin — full access</option>
      </select>
      <label for="org_unit_id">Home Unit / Scope</label>
      <select id="org_unit_id" name="org_unit_id">
        <option value="">— none —</option>
        <?php foreach ($unitOptions as $type => $items): ?>
          <optgroup label="<?= ucfirst(e($type)) ?>">
            <?php foreach ($items as $u): ?>
              <option value="<?= $u['id'] ?>"><?= e($u['label']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <div class="btn-row">
        <button class="btn" type="submit">Create Account</button>
        <a class="btn secondary" href="/admin/users">Cancel</a>
      </div>
    </form>
  </div>
<?php elseif ($action === 'edit' && $editUser): ?>
  <div class="card" style="max-width:520px;">
    <h2>Edit User</h2>
    <form method="post" action="/admin/users?action=edit">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
      <label for="name">Full Name</label>
      <input type="text" id="name" name="name" value="<?= e($editUser['name']) ?>" required>
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= e($editUser['username']) ?>" required>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($editUser['email']) ?>" required>
      <label for="password">New Password <small style="color:var(--ink-faint);">(leave blank to keep current)</small></label>
      <input type="password" id="password" name="password" minlength="10">
      <label for="role">Role</label>
      <?php if ((int) $editUser['id'] === (int) $currentUser['id']): ?>
        <input type="hidden" name="role" value="<?= e($editUser['role']) ?>">
        <div class="form-note">You cannot change your own role.</div>
      <?php else: ?>
      <select id="role" name="role">
        <option value="media_team" <?= $editUser['role'] === 'media_team' ? 'selected' : '' ?>>Media Team — upload &amp; manage posts</option>
        <option value="editor" <?= $editUser['role'] === 'editor' ? 'selected' : '' ?>>Editor — posts, events, sermons</option>
        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin — full access</option>
      </select>
      <?php endif; ?>
      <label for="org_unit_id">Home Unit / Scope</label>
      <select id="org_unit_id" name="org_unit_id">
        <option value="">— none —</option>
        <?php foreach ($unitOptions as $type => $items): ?>
          <optgroup label="<?= ucfirst(e($type)) ?>">
            <?php foreach ($items as $u): ?>
              <option value="<?= $u['id'] ?>" <?= ((int) ($editUser['org_unit_id'] ?? 0)) === $u['id'] ? 'selected' : '' ?>><?= e($u['label']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <div class="btn-row">
        <button class="btn" type="submit">Save Changes</button>
        <a class="btn secondary" href="/admin/users">Cancel</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;"><a class="btn" href="/admin/users?action=create">+ Add Team Account</a></div>
  <div class="card">
    <table>
      <tr><th>Name</th><th>Username</th><th>Role</th><th>Last Login</th><th>Status</th><th></th></tr>
      <?php foreach ($users as $u): ?>
      <?php $protected = ($superAdminId === (int) $u['id'] && !$isSuperAdmin); ?>
      <tr>
        <td><?= e($u['name']) ?><br><small style="color:var(--ink-faint);"><?= e($u['email']) ?></small>
            <br><small style="color:var(--ink-dim);">📍 <?= $u['org_unit_id'] ? e(Unit::label((int) $u['org_unit_id'])) : 'no unit' ?></small></td>
        <td><?= e($u['username']) ?></td>
        <td><span class="badge info"><?= e($u['role']) ?></span></td>
        <td><?= $u['last_login_at'] ? e(timeAgo($u['last_login_at']) . ' from ' . $u['last_login_ip']) : 'never' ?></td>
        <td>
          <?php if ((int) $u['id'] === $currentUser['id']): ?>
            <span class="badge ok">you</span>
          <?php elseif ($protected): ?>
            <span class="badge info">protected</span>
          <?php else: ?>
            <form method="post" action="/admin/users?action=toggle_suspend" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="badge <?= $u['is_suspended'] ? 'fail' : 'ok' ?>" style="border:none;cursor:pointer;">
                <?= $u['is_suspended'] ? 'suspended' : 'active' ?>
              </button>
            </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($protected): ?>
            <span class="badge info">protected</span>
          <?php else: ?>
          <a class="btn sm" href="/admin/users?action=edit&id=<?= (int) $u['id'] ?>">Edit</a>
          <?php if ((int) $u['id'] !== $currentUser['id']): ?>
          <form method="post" action="/admin/users?action=delete" onsubmit="return confirm('Delete this user?');" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="btn danger sm">Delete</button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
