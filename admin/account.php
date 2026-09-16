<?php
declare(strict_types=1);

/** Self-service profile page — every authenticated role can reach this, unlike /admin/users which is admin-only. */

Auth::requireLogin();
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['new_password_confirm'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid name and email.';
    } elseif ($newPassword !== '') {
        // Auth::user() strips the password hash, so re-fetch it to verify the current password.
        $check = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $check->execute([$user['id']]);
        $hash = (string) $check->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 10) {
            $errors[] = 'New password must be at least 10 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }
    }

    if (!$errors) {
        if ($newPassword !== '') {
            $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?')
                ->execute([$name, $email, password_hash($newPassword, PASSWORD_ARGON2ID), $user['id']]);
        } else {
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $user['id']]);
        }
        flash('success', 'Profile updated.');
        redirect('/admin/account');
    }
}

$user = Auth::user();
$pageTitle = 'My Account';
$activeNav = '';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card" style="max-width:480px;">
  <h2>Profile</h2>
  <p class="sub">Signed in as <strong style="color:var(--ink);"><?= e($user['username']) ?></strong> · <?= e($user['role']) ?></p>
  <form method="post">
    <?= Csrf::field() ?>
    <label for="name">Full Name</label>
    <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" required>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>

    <hr style="border:none; border-top:1px solid var(--border); margin:18px 0;">
    <p class="sub" style="margin-bottom:14px;">Leave the password fields blank to keep your current password.</p>
    <label for="current_password">Current Password</label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
    <label for="new_password">New Password</label>
    <input type="password" id="new_password" name="new_password" minlength="10" autocomplete="new-password">
    <label for="new_password_confirm">Confirm New Password</label>
    <input type="password" id="new_password_confirm" name="new_password_confirm" minlength="10" autocomplete="new-password">

    <button class="btn" type="submit">Save Changes</button>
  </form>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
