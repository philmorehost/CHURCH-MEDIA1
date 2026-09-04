<?php
declare(strict_types=1);

/** Stage 3 — create the super admin account and set initial branding/settings. */

// Safety net for update/recovery: if this database already has a super admin,
// never create a second one — jump straight to the finish step.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $pdo = Database::getInstance()->getConnection();
        $hasAdmin = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_super_admin = 1')->fetchColumn();
        if ($hasAdmin > 0) {
            $_SESSION['install']['existing_install'] = true;
            $_SESSION['install']['max_step'] = max($_SESSION['install']['max_step'], 4);
            redirect('/install?step=4');
        }
    } catch (Throwable) {
        // ignore — show the form normally
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $siteTitle = trim($_POST['site_title'] ?? '') ?: 'Grace & Life Church';
    $siteTagline = trim($_POST['site_tagline'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Africa/Lagos');
    keepOld(compact('name', 'username', 'email', 'siteTitle', 'siteTagline', 'contactEmail', 'timezone'));

    if ($name === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid name, username, and email.';
    } elseif (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        $errors[] = 'Username may only contain letters, numbers, dots, dashes, and underscores.';
    } else {
        try {
            $pdo = Database::getInstance()->getConnection();

            $logoPath = null;
            $faviconPath = null;
            if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $file = MediaProcessor::processImage($_FILES['logo']['tmp_name'], UPLOADS_WEBP_PATH);
                if ($file) {
                    $logoPath = 'webp/' . $file;
                }
            }
            if (!empty($_FILES['favicon']['tmp_name']) && is_uploaded_file($_FILES['favicon']['tmp_name'])) {
                $file = MediaProcessor::processImage($_FILES['favicon']['tmp_name'], UPLOADS_WEBP_PATH);
                if ($file) {
                    $faviconPath = 'webp/' . $file;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password, role, is_super_admin, notify_on_login) VALUES (?, ?, ?, ?, "admin", 1, 1)');
            $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_ARGON2ID)]);

            $exists = (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
            if ($exists === 0) {
                $siteDefaults = require CONFIG_PATH . '/site.php';
                $pdo->prepare('INSERT INTO settings (site_title, site_tagline, contact_email, timezone, logo_path, favicon_path, license_key, service_times) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$siteTitle, $siteTagline, $contactEmail ?: $email, $timezone, $logoPath, $faviconPath, $_SESSION['install']['license_key'] ?? null, $siteDefaults['service_times'] ?? '[]']);
            } else {
                $pdo->prepare('UPDATE settings SET site_title = ?, site_tagline = ?, contact_email = ?, timezone = ?, logo_path = COALESCE(?, logo_path), favicon_path = COALESCE(?, favicon_path) WHERE id = (SELECT id FROM (SELECT id FROM settings LIMIT 1) t)')
                    ->execute([$siteTitle, $siteTagline, $contactEmail ?: $email, $timezone, $logoPath, $faviconPath]);
            }

            clearOld();
            $_SESSION['install']['max_step'] = max($_SESSION['install']['max_step'], 4);
            redirect('/install?step=4');
        } catch (Throwable $e) {
            $errors[] = 'Could not create the admin account: ' . $e->getMessage();
        }
    }
}
?>
<h2>Create your admin account</h2>
<p class="sub">This is the only account with full access — keep it safe.</p>

<?php foreach ($errors as $error): ?>
  <div class="alert error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <?= Csrf::field() ?>
  <div class="row">
    <div>
      <label for="name">Full Name</label>
      <input type="text" id="name" name="name" value="<?= old('name') ?>" required>
    </div>
    <div>
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= old('username') ?>" required>
    </div>
  </div>
  <label for="email">Email</label>
  <input type="email" id="email" name="email" value="<?= old('email') ?>" required>
  <div class="row">
    <div>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" minlength="10" required>
    </div>
    <div>
      <label for="password_confirm">Confirm Password</label>
      <input type="password" id="password_confirm" name="password_confirm" minlength="10" required>
    </div>
  </div>

  <label for="site_title" style="margin-top:8px;">Site / Church Name</label>
  <input type="text" id="site_title" name="site_title" value="<?= old('site_title', 'Grace & Life Church') ?>" required>
  <label for="site_tagline">Tagline</label>
  <input type="text" id="site_tagline" name="site_tagline" value="<?= old('site_tagline', 'A place to belong, believe, and become') ?>">
  <div class="row">
    <div>
      <label for="contact_email">Public Contact Email</label>
      <input type="email" id="contact_email" name="contact_email" value="<?= old('contact_email') ?>">
    </div>
    <div>
      <label for="timezone">Timezone</label>
      <input type="text" id="timezone" name="timezone" value="<?= old('timezone', 'Africa/Lagos') ?>">
    </div>
  </div>
  <div class="row">
    <div>
      <label for="logo">Logo (optional — can add later)</label>
      <input type="file" id="logo" name="logo" accept="image/*">
    </div>
    <div>
      <label for="favicon">Favicon (optional — can add later)</label>
      <input type="file" id="favicon" name="favicon" accept="image/*">
    </div>
  </div>
  <button class="btn" type="submit">Create Account &amp; Continue</button>
</form>
