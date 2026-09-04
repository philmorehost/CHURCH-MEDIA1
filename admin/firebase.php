<?php
declare(strict_types=1);

Auth::requireRole('admin');
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can configure push notifications.');
}
$pdo = Database::getInstance()->getConnection();
$errors = [];

$cfgFile = CONFIG_PATH . '/firebase.php';
$keyFile = STORAGE_PATH . '/service-account.json';

$config = is_file($cfgFile) ? (require $cfgFile) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = $_POST['do'] ?? '';

    if ($do === 'save_config') {
        $projectId = trim($_POST['project_id'] ?? '');
        $serviceAccount = trim($_POST['service_account'] ?? '');
        if ($projectId === '' || $serviceAccount === '') {
            $errors[] = 'Project ID and the service-account file path are both required.';
        } else {
            $content = "<?php\n// Firebase Cloud Messaging configuration (written from the admin panel).\nreturn " . var_export(['service_account' => $serviceAccount, 'project_id' => $projectId], true) . ";\n";
            if (@file_put_contents($cfgFile, $content) === false) {
                $errors[] = 'Could not write config/firebase.php — check that the config folder is writable.';
            } else {
                flash('success', 'Firebase config saved.');
                redirect('/admin/firebase');
            }
        }
    } elseif ($do === 'upload_key' || $do === 'paste_key') {
        $raw = null;
        if ($do === 'paste_key') {
            $raw = trim((string) ($_POST['key_json'] ?? ''));
            if ($raw === '') {
                $errors[] = 'Paste the full contents of the service-account JSON file.';
            }
        } else {
            if (empty($_FILES['key_file']['tmp_name']) || !is_uploaded_file($_FILES['key_file']['tmp_name'])) {
                $errors[] = 'Choose a service-account JSON file to upload.';
            } else {
                $raw = (string) file_get_contents($_FILES['key_file']['tmp_name']);
            }
        }
        if ($raw !== null && $raw !== '') {
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $errors[] = 'That file could not be read as JSON — it may be an HTML page, empty, or corrupted. Re-download the "Service account key" file from Firebase.';
            } else {
                $missing = [];
                foreach (['type', 'client_email', 'private_key', 'project_id'] as $k) {
                    if (empty($data[$k])) {
                        $missing[] = $k;
                    }
                }
                if ($missing) {
                    $hint = (isset($data['project_info']) || isset($data['api_key']))
                        ? ' This looks like the Android <code>google-services.json</code> config file, not a service-account key — download the service-account JSON instead.'
                        : '';
                    $errors[] = 'That file is missing: <strong>' . implode(', ', $missing) . '</strong>.' . $hint . ' In Firebase, get the correct file from <em>Project settings → Service accounts → Generate new private key</em>.';
                } else {
                    if (@file_put_contents($keyFile, $raw) === false) {
                        $errors[] = 'Could not save storage/service-account.json — check that the storage folder is writable.';
                    } else {
                        flash('success', 'Service account key saved.');
                        redirect('/admin/firebase');
                    }
                }
            }
        }
    } elseif ($do === 'test_push') {
        $ok = Pusher::broadcast('Test push ✅', 'Push notifications are working on ' . setting('site_title') . '.');
        if ($ok) {
            flash('success', 'Test push sent — check your phone for the notification.');
        } else {
            flash('error', 'Push is not active yet — complete the setup below first.');
        }
        redirect('/admin/firebase');
    }
}

// --- Live status ---
$projectId = (string) ($config['project_id'] ?? '');
$saPath = (string) ($config['service_account'] ?? '');
$keyOk = false;
$keyEmail = '';
if ($saPath === '') {
    $saPath = $keyFile;
}
if (is_file($saPath)) {
    $sa = json_decode((string) file_get_contents($saPath), true);
    if (is_array($sa) && !empty($sa['client_email']) && !empty($sa['private_key'])) {
        $keyOk = true;
        $keyEmail = (string) $sa['client_email'];
    }
}
$deviceCount = (int) $pdo->query('SELECT COUNT(*) FROM device_tokens')->fetchColumn();
$configured = $projectId !== '' && $keyOk;

$pageTitle = 'Push Notifications (Firebase)';
$activeNav = 'firebase';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card" style="margin-bottom:18px;">
  <h2 style="margin:0 0 6px;">📲 Push Notifications — Setup Status</h2>
  <p class="sub">Push lets the mobile app notify the congregation the moment a new reel, event, or sermon is published. This page walks you through enabling it with Firebase (free).</p>
  <table style="max-width:520px;">
    <tr><th>Status</th><th>Detail</th></tr>
    <tr>
      <td><?= $projectId !== '' ? '<span class="badge ok">Configured</span>' : '<span class="badge fail">Not set</span>' ?></td>
      <td>Project ID: <?= $projectId !== '' ? '<code>' . e($projectId) . '</code>' : '—' ?></td>
    </tr>
    <tr>
      <td><?= $keyOk ? '<span class="badge ok">Key OK</span>' : '<span class="badge fail">Key missing</span>' ?></td>
      <td><?= $keyOk ? 'Service account: <code>' . e($keyEmail) . '</code>' : 'No valid service-account.json yet.' ?></td>
    </tr>
    <tr>
      <td><?= $deviceCount > 0 ? '<span class="badge ok">' . $deviceCount . '</span>' : '<span class="badge">0 devices</span>' ?></td>
      <td>Registered app devices (appears after the app is installed and push is on)</td>
    </tr>
    <tr>
      <td><?= $configured ? '<span class="badge ok">Active</span>' : '<span class="badge warn">Inactive</span>' ?></td>
      <td><?= $configured ? 'Push is live — new posts notify subscribers automatically.' : 'Complete the steps below to activate.' ?></td>
    </tr>
  </table>
  <?php if ($configured): ?>
  <form method="post" style="margin-top:14px;" onsubmit="this.querySelector('button').disabled=true;">
    <?= Csrf::field() ?><input type="hidden" name="do" value="test_push">
    <button class="btn" type="submit">Send test push now</button>
  </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:18px;">
  <h2>Step-by-step setup</h2>
  <ol style="line-height:1.9;">
    <li><strong>Create a Firebase project.</strong> Go to <a href="https://console.firebase.google.com/" target="_blank" rel="noopener">console.firebase.google.com</a>, sign in with a Google account, and click <em>Add project</em> (e.g. <code>church-media-push</code>).</li>
    <li><strong>Add your Android app</strong> (package <code>com.churchmedia.app</code>): Project settings → <em>Your apps</em> → <em>Add app</em> → Android. You only need the <strong>Project ID / number</strong> shown on the project dashboard.</li>
    <li><strong>Create a service account key.</strong> Project settings → <em>Service accounts</em> → <em>Generate new private key</em>. This downloads a <code>service-account.json</code> file — keep it safe.</li>
    <li><strong>Upload the key</strong> using the form below (it is saved to <code>storage/service-account.json</code>).</li>
    <li><strong>Save the Project ID</strong> in the config form below (writes <code>config/firebase.php</code>).</li>
    <li><strong>Send a test push</strong> to confirm. Then tell us to wire the app-side token registration (the backend is already ready).</li>
  </ol>
</div>

<div class="card" style="margin-bottom:18px;">
  <h2>1 · Upload service-account.json</h2>
  <p class="sub">Saved to <code>storage/service-account.json</code>. The file is validated before saving.</p>
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?><input type="hidden" name="do" value="upload_key">
    <input type="file" name="key_file" accept=".json,application/json" required>
    <button class="btn" type="submit" style="margin-top:10px;">Upload key</button>
  </form>
  <h3 style="margin:18px 0 6px;">…or paste the file contents</h3>
  <p class="sub">If the file is hard to upload (or your download came out odd), open it in a text editor, copy everything, and paste it here.</p>
  <form method="post">
    <?= Csrf::field() ?><input type="hidden" name="do" value="paste_key">
    <textarea name="key_json" rows="6" placeholder='{
  "type": "service_account",
  "project_id": "…",
  "private_key": "-----BEGIN PRIVATE KEY-----…",
  "client_email": "…"
}' style="font-family:monospace;font-size:12px;"></textarea>
    <button class="btn" type="submit" style="margin-top:10px;">Save pasted key</button>
  </form>
</div>

<div class="card">
  <h2>2 · Save the Firebase config</h2>
  <p class="sub">Writes <code>config/firebase.php</code>. The service-account path is normally <code><?= e(STORAGE_PATH . '/service-account.json') ?></code>.</p>
  <form method="post">
    <?= Csrf::field() ?><input type="hidden" name="do" value="save_config">
    <label for="project_id">Firebase Project ID</label>
    <input type="text" id="project_id" name="project_id" value="<?= e($projectId) ?>" placeholder="church-media-push" required>
    <label for="service_account">Service account file path</label>
    <input type="text" id="service_account" name="service_account" value="<?= e($saPath) ?>" placeholder="<?= e(STORAGE_PATH . '/service-account.json') ?>" required>
    <button class="btn" type="submit" style="margin-top:10px;">Save config</button>
  </form>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
