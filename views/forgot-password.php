<?php
declare(strict_types=1);

$errors = [];
$step = 'request';
$usernameOrEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = $_POST['action'] ?? 'request_otp';

    if ($action === 'request_otp') {
        $usernameOrEmail = trim((string) ($_POST['account_input'] ?? ''));
        if ($usernameOrEmail === '') {
            $errors[] = 'Please enter your username or email address.';
        } else {
            $pdo = Database::getInstance()->getConnection();
            // Check if alt_email column exists before using it in WHERE clause
            $hasAltEmail = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'alt_email'")->fetchColumn() > 0;
            $sql = 'SELECT * FROM users WHERE username = ? OR email = ?';
            $params = [$usernameOrEmail, $usernameOrEmail];
            if ($hasAltEmail) {
                $sql .= ' OR alt_email = ?';
                $params[] = $usernameOrEmail;
            }
            $sql .= ' LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch();

            if (!$user) {
                // Generic error for security
                $errors[] = 'No admin account found with that username or email.';
            } else {
                $otp = sprintf('%06d', mt_rand(100000, 999999));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                $pdo->prepare('UPDATE users SET reset_otp = ?, reset_otp_expires_at = ? WHERE id = ?')
                    ->execute([$otp, $expiresAt, (int) $user['id']]);

                // Send OTP to primary email
                $mailBody = "Hi {$user['name']},\n\nYour Password Reset OTP code is: {$otp}\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.";
                try {
                    Mailer::send((string) $user['email'], 'Password Reset OTP · ' . setting('site_title'), $mailBody);
                } catch (Throwable $e) {}

                // Send OTP to alternative email if available
                if (!empty($user['alt_email']) && filter_var($user['alt_email'], FILTER_VALIDATE_EMAIL)) {
                    try {
                        Mailer::send((string) $user['alt_email'], 'Password Reset OTP (Backup) · ' . setting('site_title'), $mailBody);
                    } catch (Throwable $e) {}
                }

                $_SESSION['forgot_user_id'] = (int) $user['id'];
                $step = 'verify';
                flash('success_otp', 'A 6-digit OTP code has been sent to your primary and backup email address.');
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = (int) ($_SESSION['forgot_user_id'] ?? 0);
        $otp = trim((string) ($_POST['otp'] ?? ''));
        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

        if ($userId <= 0) {
            $errors[] = 'Session expired — please request a new OTP.';
            $step = 'request';
        } else {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || empty($user['reset_otp']) || $user['reset_otp'] !== $otp) {
                $errors[] = 'Invalid OTP code.';
                $step = 'verify';
            } elseif (empty($user['reset_otp_expires_at']) || strtotime((string) $user['reset_otp_expires_at']) <= time()) {
                $errors[] = 'OTP code has expired — please request a new one.';
                $step = 'request';
            } elseif (strlen($newPassword) < 10) {
                $errors[] = 'Password must be at least 10 characters long.';
                $step = 'verify';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
                $step = 'verify';
            } else {
                // Update password, clear OTP, unsuspend account
                $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
                $pdo->prepare('UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expires_at = NULL, is_suspended = 0 WHERE id = ?')
                    ->execute([$newHash, $userId]);

                unset($_SESSION['forgot_user_id']);
                flash('success', 'Your password has been reset and your account restored! Please sign in.');
                redirect('/admin/login');
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Forgot Password · <?= e(setting('site_title')) ?></title>
<style>
  :root{--bg-0:#0b0a14;--bg-1:#141227;--card:#181632cc;--border:#2c2850;--gold:#e8b95f;--gold-soft:#f3d38f;--ink:#f1eefc;--ink-dim:#a9a4c9;--danger:#ff6b6b;--success:#34d399;}
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);
    background:radial-gradient(ellipse 80% 60% at 20% -10%,#3a2a6b55,transparent),radial-gradient(ellipse 60% 50% at 100% 0%,#6b3a5a44,transparent),linear-gradient(180deg,var(--bg-0),var(--bg-1));
    display:flex;align-items:center;justify-content:center;padding:20px;}
  .card{width:100%;max-width:420px;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:34px;backdrop-filter:blur(20px);box-shadow:0 20px 60px #00000055;}
  .mark{width:52px;height:52px;margin:0 auto 16px;border-radius:14px;background:linear-gradient(135deg,var(--gold),#c98a3d);display:flex;align-items:center;justify-content:center;font-size:24px;}
  h1{font-size:18px;text-align:center;margin:0 0 8px;font-weight:600;}
  p.sub{font-size:13px;color:var(--ink-dim);text-align:center;margin:0 0 24px;line-height:1.5;}
  label{display:block;font-size:12.5px;color:var(--ink-dim);margin:0 0 6px;font-weight:600;}
  input{width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:#0f0d1f;color:var(--ink);font-size:14px;margin-bottom:16px;}
  input:focus{outline:none;border-color:var(--gold);}
  .btn{width:100%;background:linear-gradient(135deg,var(--gold-soft),var(--gold));color:#1a1530;border:none;padding:12px;border-radius:12px;font-weight:700;font-size:14px;cursor:pointer;}
  .alert{background:#ff6b6b18;border:1px solid #ff6b6b44;color:#ffb3b3;padding:11px 14px;border-radius:10px;font-size:13px;margin-bottom:18px;}
  .alert.ok{background:#10b98118;border-color:#10b98144;color:#6ee7b7;}
</style>
</head>
<body>
<div class="card">
  <div class="mark">🔑</div>
  <h1>Reset Admin Password</h1>

  <?php if ($msg = flash('success_otp')): ?>
    <div class="alert ok"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php foreach ($errors as $error): ?><div class="alert"><?= e($error) ?></div><?php endforeach; ?>

  <?php if ($step === 'request'): ?>
    <p class="sub">Enter your username or email address. We will send a 6-digit OTP code to your primary and backup email address.</p>
    <form method="post" action="/forgot-password">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="request_otp">
      <label for="account_input">Username or Email</label>
      <input type="text" id="account_input" name="account_input" value="<?= e($usernameOrEmail) ?>" autofocus required placeholder="your.username or email">
      <button class="btn" type="submit">Send OTP Code</button>
    </form>
  <?php else: ?>
    <p class="sub">Enter the 6-digit OTP sent to your email, then choose a new password.</p>
    <form method="post" action="/forgot-password">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="reset_password">
      <label for="otp">6-Digit OTP Code</label>
      <input type="text" id="otp" name="otp" pattern="[0-9]{6}" maxlength="6" autofocus required placeholder="e.g. 123456" style="text-align:center; font-size:18px; letter-spacing:4px;">

      <label for="password">New Password (10+ chars)</label>
      <input type="password" id="password" name="password" minlength="10" required placeholder="New password">

      <label for="password_confirm">Confirm New Password</label>
      <input type="password" id="password_confirm" name="password_confirm" minlength="10" required placeholder="Repeat new password">

      <button class="btn" type="submit">Reset Password &amp; Restore Account</button>
    </form>
  <?php endif; ?>

  <div style="text-align:center; margin-top:20px;">
    <a href="/admin/login" style="color:var(--gold-soft); font-size:13px; text-decoration:none;">← Back to Admin Login</a>
  </div>
</div>
</body>
</html>
