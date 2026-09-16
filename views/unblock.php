<?php
declare(strict_types=1);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $pin = trim((string) ($_POST['unblock_pin'] ?? ''));

    if ($username === '' || $password === '' || $pin === '') {
        $errors[] = 'Username, password, and Security Unblock PIN are all required.';
    } else {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid username or password.';
        } elseif (empty($user['unblock_pin_hash']) || !password_verify($pin, $user['unblock_pin_hash'])) {
            $errors[] = 'Incorrect Security Unblock PIN.';
        } else {
            // Unblock user IP from ip_rules if blacklisted
            $ip = clientIp();
            $pdo->prepare('DELETE FROM ip_rules WHERE ip_address = ? AND type = "blacklist"')->execute([$ip]);

            // Unsuspend user account
            $pdo->prepare('UPDATE users SET is_suspended = 0 WHERE id = ?')->execute([(int) $user['id']]);

            $success = true;
            flash('success', 'Security unblock successful! Your IP and account have been restored. You may now log in.');
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
<title>Unblock Security Access · <?= e(setting('site_title')) ?></title>
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
  <div class="mark">🔓</div>
  <h1>Unblock Security Access</h1>
  <p class="sub">Blocked by security or account suspended? Enter your credentials and your secret Unblock PIN to restore access immediately.</p>

  <?php if ($success): ?>
    <div class="alert ok">✅ Account and IP successfully unblocked! You can now log in.</div>
    <a href="/admin/login" class="btn" style="display:block; text-align:center; text-decoration:none; box-sizing:border-box;">Proceed to Admin Login →</a>
  <?php else: ?>
    <?php foreach ($errors as $error): ?><div class="alert"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post" action="/unblock">
      <?= Csrf::field() ?>
      <label for="username">Username or Email</label>
      <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" autofocus required>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
      <label for="unblock_pin">Security Unblock PIN (4 to 6 digits)</label>
      <input type="password" id="unblock_pin" name="unblock_pin" pattern="[0-9]{4,6}" maxlength="6" required placeholder="Your secret Unblock PIN">
      <button class="btn" type="submit">Restore Access &amp; Unblock IP</button>
    </form>
    <div style="text-align:center; margin-top:20px;">
      <a href="/admin/login" style="color:var(--gold-soft); font-size:13px; text-decoration:none;">← Return to Login</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
