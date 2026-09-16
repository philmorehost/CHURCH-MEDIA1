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
            $errors[] = t('auth.err.account_required');
        } else {
            $pdo = Database::getInstance()->getConnection();
            // Check if alt_email column exists before using it in WHERE clause
            $hasAltEmail = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'alt_email'")->fetchColumn() > 0;

            // Scoped to the church this site serves. Without it, anybody on one church's site
            // could start a reset for another church's admin and have the OTP arrive branded with
            // the wrong church's name — and could learn from the answer that the username exists.
            $sql = 'SELECT * FROM users WHERE (username = ? OR email = ?';
            $params = [$usernameOrEmail, $usernameOrEmail];
            if ($hasAltEmail) {
                $sql .= ' OR alt_email = ?';
                $params[] = $usernameOrEmail;
            }
            $sql .= ') AND tenant_id = ? LIMIT 1';
            $params[] = (int) (Tenant::id() ?? 0);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch();

            if (!$user) {
                $errors[] = t('auth.err.account_not_found');
            } else {
                $otp = sprintf('%06d', mt_rand(100000, 999999));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                $pdo->prepare('UPDATE users SET reset_otp = ?, reset_otp_expires_at = ? WHERE id = ?')
                    ->execute([$otp, $expiresAt, (int) $user['id']]);

                $mailBody = "Hi {$user['name']},\n\nYour Password Reset OTP code is: {$otp}\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.";
                $sentPrimary = false;
                try {
                    $sentPrimary = Mailer::send((string) $user['email'], 'Password Reset OTP · ' . setting('site_title'), $mailBody);
                } catch (Throwable $e) {}

                if (!empty($user['alt_email']) && filter_var($user['alt_email'], FILTER_VALIDATE_EMAIL)) {
                    try {
                        Mailer::send((string) $user['alt_email'], 'Password Reset OTP (Backup) · ' . setting('site_title'), $mailBody);
                    } catch (Throwable $e) {}
                }

                $_SESSION['forgot_user_id'] = (int) $user['id'];
                $step = 'verify';

                if (!$sentPrimary && empty(setting('smtp_host'))) {
                    flash('warn_smtp', t('auth.flash.smtp_missing'));
                } else {
                    flash('success_otp', t('auth.flash.otp_sent'));
                }
            }
        }
    } elseif ($action === 'reset_pin') {
        $usernameOrEmail = trim((string) ($_POST['account_input_pin'] ?? ''));
        $pin = trim((string) ($_POST['unblock_pin'] ?? ''));
        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

        if ($usernameOrEmail === '' || $pin === '' || $newPassword === '') {
            $errors[] = t('auth.err.fields_required');
            $step = 'pin_reset';
        } elseif (strlen($newPassword) < 10) {
            $errors[] = t('auth.err.password_short');
            $step = 'pin_reset';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = t('auth.err.password_mismatch');
            $step = 'pin_reset';
        } else {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND tenant_id = ? LIMIT 1');
            $stmt->execute([$usernameOrEmail, $usernameOrEmail, (int) (Tenant::id() ?? 0)]);
            $user = $stmt->fetch();

            if (!$user) {
                $errors[] = t('auth.err.account_missing');
                $step = 'pin_reset';
            } elseif (empty($user['unblock_pin_hash']) || !password_verify($pin, $user['unblock_pin_hash'])) {
                $errors[] = t('auth.err.pin_wrong');
                $step = 'pin_reset';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
                $pdo->prepare('UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expires_at = NULL, is_suspended = 0 WHERE id = ?')
                    ->execute([$newHash, (int) $user['id']]);

                flash('success', t('auth.flash.reset_by_pin'));
                redirect('/admin/login');
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = (int) ($_SESSION['forgot_user_id'] ?? 0);
        $otp = trim((string) ($_POST['otp'] ?? ''));
        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

        if ($userId <= 0) {
            $errors[] = t('auth.err.session_expired');
            $step = 'request';
        } else {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$userId, (int) (Tenant::id() ?? 0)]);
            $user = $stmt->fetch();

            if (!$user || empty($user['reset_otp']) || $user['reset_otp'] !== $otp) {
                $errors[] = t('auth.err.otp_invalid');
                $step = 'verify';
            } elseif (empty($user['reset_otp_expires_at']) || strtotime((string) $user['reset_otp_expires_at']) <= time()) {
                $errors[] = t('auth.err.otp_expired');
                $step = 'request';
            } elseif (strlen($newPassword) < 10) {
                $errors[] = t('auth.err.password_short');
                $step = 'verify';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = t('auth.err.password_mismatch');
                $step = 'verify';
            } else {
                // Update password, clear OTP, unsuspend account
                $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
                $pdo->prepare('UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expires_at = NULL, is_suspended = 0 WHERE id = ?')
                    ->execute([$newHash, $userId]);

                unset($_SESSION['forgot_user_id']);
                flash('success', t('auth.flash.reset_done'));
                redirect('/admin/login');
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= e(Lang::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('auth.forgot.page_title')) ?> · <?= e(setting('site_title')) ?></title>
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
  <h1><?= e(t('auth.forgot.title')) ?></h1>

  <?php if ($msg = flash('success_otp')): ?>
    <div class="alert ok"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('warn_smtp')): ?>
    <div class="alert" style="background:#f59e0b22; border-color:#f59e0b66; color:#fcd34d;"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php foreach ($errors as $error): ?><div class="alert"><?= e($error) ?></div><?php endforeach; ?>

  <?php
  /*
   * The PIN branch is tested first, and that ordering is the fix for a link that never worked.
   *
   * It used to read `if ($step === 'request') … elseif ($step === 'pin_reset' || $_GET['mode'] === 'pin')`,
   * and on a plain GET `$step` is *always* `'request'` — so the first branch always won and both
   * "Reset Password using Security PIN" links reloaded the same form they were clicked from. The PIN form
   * was reachable only by getting the fields wrong on it, which is to say not reachable at all.
   */
  $pinMode = $step === 'pin_reset' || (isset($_GET['mode']) && $_GET['mode'] === 'pin');
  ?>
  <?php if ($pinMode): ?>
    <p class="sub"><?= e(t('auth.forgot.sub_pin')) ?></p>
    <form method="post" action="/forgot-password">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="reset_pin">
      <label for="account_input_pin"><?= e(t('auth.field.account')) ?></label>
      <input type="text" id="account_input_pin" name="account_input_pin" value="<?= e($_POST['account_input_pin'] ?? '') ?>" autofocus required placeholder="<?= e(t('auth.field.account_hint')) ?>">

      <label for="unblock_pin"><?= e(t('auth.field.pin')) ?></label>
      <input type="password" id="unblock_pin" name="unblock_pin" pattern="[0-9]{4,6}" maxlength="6" required placeholder="<?= e(t('auth.field.pin_hint')) ?>">

      <label for="password"><?= e(t('auth.field.new_password')) ?></label>
      <input type="password" id="password" name="password" minlength="10" required placeholder="<?= e(t('auth.field.new_password_hint')) ?>">

      <label for="password_confirm"><?= e(t('auth.field.confirm_password')) ?></label>
      <input type="password" id="password_confirm" name="password_confirm" minlength="10" required placeholder="<?= e(t('auth.field.confirm_hint')) ?>">

      <button class="btn" type="submit"><?= e(t('auth.forgot.use_pin')) ?></button>
    </form>
  <?php elseif ($step === 'request'): ?>
    <p class="sub"><?= e(t('auth.forgot.sub_request')) ?></p>
    <form method="post" action="/forgot-password">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="request_otp">
      <label for="account_input"><?= e(t('auth.field.account')) ?></label>
      <input type="text" id="account_input" name="account_input" value="<?= e($usernameOrEmail) ?>" autofocus required placeholder="<?= e(t('auth.field.account_hint')) ?>">
      <button class="btn" type="submit"><?= e(t('auth.forgot.send_otp')) ?></button>
    </form>
    <div style="border-top:1px solid var(--border); margin-top:20px; padding-top:16px; text-align:center;">
      <a href="/forgot-password?mode=pin" style="color:var(--gold-soft); font-size:13px; text-decoration:none;"><?= e(t('auth.forgot.or_pin')) ?></a>
    </div>
  <?php else: ?>
    <p class="sub"><?= e(t('auth.forgot.sub_otp')) ?></p>
    <form method="post" action="/forgot-password">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="reset_password">
      <label for="otp"><?= e(t('auth.field.otp')) ?></label>
      <input type="text" id="otp" name="otp" pattern="[0-9]{6}" maxlength="6" autofocus required placeholder="<?= e(t('auth.field.otp_hint')) ?>" style="text-align:center; font-size:18px; letter-spacing:4px;">

      <label for="password"><?= e(t('auth.field.new_password')) ?></label>
      <input type="password" id="password" name="password" minlength="10" required placeholder="<?= e(t('auth.field.new_password_hint')) ?>">

      <label for="password_confirm"><?= e(t('auth.field.confirm_password')) ?></label>
      <input type="password" id="password_confirm" name="password_confirm" minlength="10" required placeholder="<?= e(t('auth.field.confirm_hint')) ?>">

      <button class="btn" type="submit"><?= e(t('auth.forgot.reset_restore')) ?></button>
    </form>
    <div style="border-top:1px solid var(--border); margin-top:20px; padding-top:16px; text-align:center;">
      <a href="/forgot-password?mode=pin" style="color:var(--gold-soft); font-size:13px; text-decoration:none;"><?= e(t('auth.forgot.no_email')) ?></a>
    </div>
  <?php endif; ?>

  <div style="text-align:center; margin-top:20px;">
    <a href="/admin/login" style="color:var(--gold-soft); font-size:13px; text-decoration:none;"><?= e(t('auth.back_to_login')) ?></a>
  </div>
</div>
</body>
</html>
