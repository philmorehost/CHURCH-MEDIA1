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

    // The number is stored normalised (2348031234567), because that is the form every
    // other part of the SMS system expects. Consent is stored separately — see below.
    $phoneInput = (string) ($_POST['phone'] ?? '');
    $phone = Sms::checkPhone($phoneInput);
    $smsConsent = isset($_POST['sms_consent']) ? 1 : 0;

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid name and email.';
    } elseif (!$phone['ok']) {
        $errors[] = 'That phone number could not be used: ' . $phone['error'];
    } elseif ($smsConsent === 1 && $phone['msisdn'] === null) {
        // Answering "yes, text me" without giving a number is a mistake worth pointing out.
        $errors[] = 'Add a phone number, or untick the box about text messages.';
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
        // Built once rather than branching per combination of password fields — that
        // pattern was four near-identical statements, and every new column had to be
        // added to all of them.
        $set = ['name = ?', 'email = ?', 'phone = ?', 'sms_consent = ?'];
        $params = [$name, $email, $phone['msisdn'], $smsConsent];

        if ($newPassword !== '') {
            $set[] = 'password = ?';
            $params[] = password_hash($newPassword, PASSWORD_ARGON2ID);
        }

        $params[] = $user['id'];
        $pdo->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        flash('success', 'Profile updated.');
        redirect('/admin/account');
    }
}

$user = Auth::user();

// On a validation error, show what was typed rather than silently reverting to the stored
// values — losing your typing is the worst part of a rejected form, and the phone number
// is the field most likely to be mistyped.
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$nameValue = $isPost ? (string) ($_POST['name'] ?? $user['name']) : (string) $user['name'];
$emailValue = $isPost ? (string) ($_POST['email'] ?? $user['email']) : (string) $user['email'];
$phoneValue = $isPost
    ? (string) ($_POST['phone'] ?? '')
    : (!empty($user['phone']) ? Sms::prettyMsisdn((string) $user['phone']) : '');
$consentChecked = $isPost ? isset($_POST['sms_consent']) : !empty($user['sms_consent']);

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
    <input type="text" id="name" name="name" value="<?= e($nameValue) ?>" required>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($emailValue) ?>" required>

    <hr style="border:none; border-top:1px solid var(--border); margin:18px 0;">
    <p class="sub" style="margin-bottom:14px;">
      Your phone number, if you would like the church to be able to reach you by text message.
      Leave it blank and you will not be texted.
    </p>
    <label for="phone">Phone Number <small style="color:var(--ink-faint);">(optional)</small></label>
    <input type="tel" id="phone" name="phone" inputmode="tel"
           value="<?= e($phoneValue) ?>"
           placeholder="0803 000 0000 or +2348030000000">
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      Numbers are kept in international form, so a landline or an overseas number works too.
    </small>

    <div class="checkbox-row" style="align-items:flex-start;margin-bottom:6px;">
      <input type="checkbox" id="sms_consent" name="sms_consent" value="1"
             <?= $consentChecked ? 'checked' : '' ?>>
      <label for="sms_consent" style="margin:0;line-height:1.5;">
        You may send me text messages on this number
      </label>
    </div>
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:14px;">
      Taking this off stops texts to you even if you keep the number here. Reply STOP to any message
      and it is taken off for you as well.
    </small>

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
