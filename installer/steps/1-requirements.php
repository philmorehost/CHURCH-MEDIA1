<?php
declare(strict_types=1);

/** Stage 1 — PHP/extension/writable-path requirements, then license validation. */

$checks = [
    'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'GD extension (image/WebP)' => extension_loaded('gd') && function_exists('imagewebp'),
    'cURL extension' => extension_loaded('curl'),
    'mbstring extension' => extension_loaded('mbstring'),
    'fileinfo extension' => extension_loaded('fileinfo'),
    'config/ is writable' => is_writable(CONFIG_PATH),
    'storage/ is writable' => is_writable(STORAGE_PATH),
    'public/uploads/ is writable' => is_writable(UPLOADS_PATH),
];
$allPass = !in_array(false, $checks, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    if (!$allPass) {
        $errors[] = 'Please resolve the failing requirements above before continuing.';
    } else {
        $licenseKey = trim($_POST['license_key'] ?? '');
        if (LicenseGuard::validate($licenseKey, $_SERVER['HTTP_HOST'] ?? 'localhost')) {
            $_SESSION['install']['license_key'] = $licenseKey;
            $_SESSION['install']['max_step'] = max($_SESSION['install']['max_step'], 2);
            redirect('/install?step=2');
        }
        $errors[] = 'That license key could not be validated for this domain. Double-check the key or contact support.';
    }
}
?>
<h2>Let's get you set up</h2>
<p class="sub">First, we'll confirm your server meets the requirements, then verify your license.</p>

<?php foreach ($errors as $error): ?>
  <div class="alert error"><?= e($error) ?></div>
<?php endforeach; ?>

<ul class="check-list">
  <?php foreach ($checks as $label => $pass): ?>
    <li>
      <span><?= e($label) ?></span>
      <span class="badge <?= $pass ? 'ok' : 'fail' ?>"><?= $pass ? 'PASS' : 'FAIL' ?></span>
    </li>
  <?php endforeach; ?>
</ul>

<form method="post">
  <?= Csrf::field() ?>
  <label for="license_key">License Key</label>
  <input type="text" id="license_key" name="license_key" placeholder="XXXX-XXXX-XXXX-XXXX" value="<?= old('license_key') ?>" <?= APP_IS_LOCAL ? '' : 'required' ?>>
  <?php if (APP_IS_LOCAL): ?>
    <p class="hint">Local/dev environment detected (app_env = local) — license check is bypassed automatically.</p>
  <?php endif; ?>
  <button class="btn" type="submit" <?= $allPass ? '' : 'disabled' ?>>Continue</button>
</form>
