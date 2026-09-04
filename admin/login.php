<?php
declare(strict_types=1);

if (Auth::check()) {
    redirect('/admin');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!RateLimiter::attempt('admin_login', clientIp(), 15, 300)) {
        $errors[] = 'Too many login attempts. Please wait a few minutes and try again.';
    } elseif (Auth::attempt($username, $password)) {
        redirect('/admin');
    } else {
        $errors[] = 'Invalid credentials, or this account has been suspended.';
    }
    keepOld(['username' => $username]);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin Login · <?= e(setting('site_title')) ?></title>
<style>
  :root{--bg-0:#0b0a14;--bg-1:#141227;--card:#181632cc;--border:#2c2850;--gold:#e8b95f;--gold-soft:#f3d38f;--ink:#f1eefc;--ink-dim:#a9a4c9;--danger:#ff6b6b;}
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);
    background:radial-gradient(ellipse 80% 60% at 20% -10%,#3a2a6b55,transparent),radial-gradient(ellipse 60% 50% at 100% 0%,#6b3a5a44,transparent),linear-gradient(180deg,var(--bg-0),var(--bg-1));
    display:flex;align-items:center;justify-content:center;padding:20px;}
  .card{width:100%;max-width:380px;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:34px;backdrop-filter:blur(20px);box-shadow:0 20px 60px #00000055;}
  .mark{width:52px;height:52px;margin:0 auto 16px;border-radius:14px;background:linear-gradient(135deg,var(--gold),#c98a3d);display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-weight:700;font-size:24px;color:#1a1530;}
  h1{font-size:18px;text-align:center;margin:0 0 26px;font-weight:600;}
  label{display:block;font-size:12.5px;color:var(--ink-dim);margin:0 0 6px;font-weight:600;}
  input{width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:#0f0d1f;color:var(--ink);font-size:14px;margin-bottom:16px;}
  input:focus{outline:none;border-color:var(--gold);}
  .btn{width:100%;background:linear-gradient(135deg,var(--gold-soft),var(--gold));color:#1a1530;border:none;padding:12px;border-radius:12px;font-weight:700;font-size:14px;cursor:pointer;}
  .alert{background:#ff6b6b18;border:1px solid #ff6b6b44;color:#ffb3b3;padding:11px 14px;border-radius:10px;font-size:13px;margin-bottom:18px;}
</style>
</head>
<body>
<div class="card">
  <div class="mark">C</div>
  <h1><?= e(setting('site_title')) ?> — Admin</h1>
  <?php foreach ($errors as $error): ?><div class="alert"><?= e($error) ?></div><?php endforeach; ?>
  <form method="post">
    <?= Csrf::field() ?>
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= old('username') ?>" autofocus required>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
    <button class="btn" type="submit">Sign In</button>
  </form>
</div>
</body>
</html>
