<?php
declare(strict_types=1);

Auth::requireRole('admin');

$pdo = Database::getInstance()->getConnection();
$adminUser = Auth::user();
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

// Manage Settings & Durations (Super Admin)
if ($action === 'settings' || $action === 'durations') {
    if (!Auth::isSuperAdmin()) {
        http_response_code(403);
        exit('Only super admin can manage ad settings.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        Csrf::requireValid();
        $payhubEnabled = isset($_POST['payhub_enabled']) ? 1 : 0;
        $payhubPub = trim((string) ($_POST['payhub_public_key'] ?? ''));
        $payhubSec = trim((string) ($_POST['payhub_secret_key'] ?? ''));
        $manualEnabled = isset($_POST['manual_payment_enabled']) ? 1 : 0;
        $manualInstructions = trim((string) ($_POST['manual_payment_instructions'] ?? ''));

        $pdo->prepare('UPDATE settings SET payhub_enabled = ?, payhub_public_key = ?, payhub_secret_key = ?, manual_payment_enabled = ?, manual_payment_instructions = ? WHERE id = (SELECT id FROM (SELECT id FROM settings LIMIT 1) t)')
            ->execute([$payhubEnabled, $payhubPub, $payhubSec, $manualEnabled, $manualInstructions]);

        flash('success', 'Ad settings updated successfully.');
        redirect('/admin/ads?action=settings');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_duration'])) {
        Csrf::requireValid();
        $title = trim((string) ($_POST['title'] ?? ''));
        $days = (int) ($_POST['days'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0.00);
        $isFree = isset($_POST['is_free']) ? 1 : 0;
        $displayFreq = in_array($_POST['display_frequency'] ?? '', ['5_min', '10_min', '15_min', '30_min', 'once_daily'], true) ? $_POST['display_frequency'] : '5_min';
        if ($isFree) {
            $displayFreq = 'once_daily';
            $price = 0.00;
        }
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '' || $days <= 0) {
            $errors[] = 'Please enter a valid title and number of days.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO ad_durations (title, days, price, is_free, display_frequency, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $days, $price, $isFree, $displayFreq, $sortOrder]);
            flash('success', 'Ad package added.');
            redirect('/admin/ads?action=settings');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_duration'])) {
        Csrf::requireValid();
        $durId = (int) ($_POST['duration_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $days = (int) ($_POST['days'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0.00);
        $isFree = isset($_POST['is_free']) ? 1 : 0;
        $displayFreq = in_array($_POST['display_frequency'] ?? '', ['5_min', '10_min', '15_min', '30_min', 'once_daily'], true) ? $_POST['display_frequency'] : '5_min';
        if ($isFree) {
            $displayFreq = 'once_daily';
            $price = 0.00;
        }
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($durId <= 0 || $title === '' || $days <= 0) {
            $errors[] = 'Please enter valid package details.';
        } else {
            $stmt = $pdo->prepare('UPDATE ad_durations SET title = ?, days = ?, price = ?, is_free = ?, display_frequency = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$title, $days, $price, $isFree, $displayFreq, $sortOrder, $durId]);
            flash('success', 'Ad package updated.');
            redirect('/admin/ads?action=settings');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_duration'])) {
        Csrf::requireValid();
        $durId = (int) ($_POST['duration_id'] ?? 0);
        $pdo->prepare('DELETE FROM ad_durations WHERE id = ?')->execute([$durId]);
        flash('success', 'Duration option deleted.');
        redirect('/admin/ads?action=settings');
    }

    $durations = $pdo->query('SELECT * FROM ad_durations ORDER BY sort_order ASC, days ASC')->fetchAll();
}

// Mark Payment as Paid (Super Admin manual review)
if ($action === 'mark_paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $pdo->prepare('UPDATE ads SET payment_status = "paid" WHERE id = ?')->execute([$id]);
    flash('success', 'Payment status updated to Paid.');
    redirect('/admin/ads');
}

// Approve Ad
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stmt = $pdo->prepare('SELECT a.*, p.name AS pub_name, p.email AS pub_email, p.token AS pub_token FROM ads a JOIN ad_publishers p ON a.publisher_id = p.id WHERE a.id = ?');
    $stmt->execute([$id]);
    $ad = $stmt->fetch();

    if ($ad) {
        $days = (int) $ad['duration_days'];
        $startAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        // If manual/unpaid, approving also sets payment_status to paid if not rejected
        $pdo->prepare('UPDATE ads SET status = "approved", payment_status = "paid", start_at = ?, expires_at = ? WHERE id = ?')
            ->execute([$startAt, $expiresAt, $id]);

        $managerUrl = baseUrl('ad-manager?token=' . rawurlencode($ad['pub_token']));

        try {
            Mailer::send(
                $ad['pub_email'],
                'Your Advert Has Been Approved! · ' . setting('site_title'),
                "Hi {$ad['pub_name']},\n\n" .
                "Great news! Your advert \"{$ad['title']}\" has been approved and is now LIVE on our platform.\n\n" .
                "Display Duration: {$days} Days\n" .
                "Starts: {$startAt}\n" .
                "Expires: {$expiresAt}\n\n" .
                "You can monitor your advert stats, impressions, clicks, and create additional ads in your Publisher Ad Manager using your secure link:\n" .
                "{$managerUrl}\n\n" .
                "Thank you for advertising with us!"
            );
        } catch (Throwable $e) {
            // Mailer failure shouldn't block approval
        }

        flash('success', 'Ad approved! Active immediately until ' . date('M j, Y', strtotime($expiresAt)) . '. Notification sent to publisher.');
    }
    redirect('/admin/ads');
}

// Reject Ad
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $pdo->prepare('UPDATE ads SET status = "rejected" WHERE id = ?')->execute([$id]);
    flash('success', 'Ad rejected.');
    redirect('/admin/ads');
}

// Delete Ad
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stmt = $pdo->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$id]);
    $ad = $stmt->fetch();
    if ($ad) {
        if (!empty($ad['file_path'])) {
            @unlink(UPLOADS_PATH . '/' . $ad['file_path']);
        }
        if (!empty($ad['thumbnail_path'])) {
            @unlink(UPLOADS_PATH . '/' . $ad['thumbnail_path']);
        }
        $pdo->prepare('DELETE FROM ads WHERE id = ?')->execute([$id]);
        flash('success', 'Ad deleted.');
    }
    redirect('/admin/ads');
}

// List Ads
$statusFilter = $_GET['status'] ?? 'all';
$sql = 'SELECT a.*, p.name AS pub_name, p.email AS pub_email, p.phone AS pub_phone, p.token AS pub_token FROM ads a JOIN ad_publishers p ON a.publisher_id = p.id';
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $sql .= ' WHERE a.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY a.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$adsList = $stmt->fetchAll();

$pageTitle = 'Ads Management';
$activeNav = 'ads';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'settings' || $action === 'durations'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/ads">← Back to Ads</a>
  </div>

  <div class="card" style="margin-bottom:24px;">
    <h2>Ad Gateway Settings</h2>
    <form method="post" action="/admin/ads?action=settings">
      <?= Csrf::field() ?>
      <input type="hidden" name="save_settings" value="1">

      <h3 style="margin-top:0;">💳 Payhub Payment Gateway (Online Payments)</h3>
      <p class="sub">Payhub integration allows publishers to pay online for ad packages. Get your API keys at <a href="https://merchant.payhub.com.ng" target="_blank">merchant.payhub.com.ng</a>.</p>

      <div class="checkbox-row" style="margin-bottom:12px;">
        <input type="checkbox" id="payhub_enabled" name="payhub_enabled" <?= !empty(setting('payhub_enabled')) ? 'checked' : '' ?>>
        <label for="payhub_enabled" style="margin:0;">Enable Payhub Online Payments</label>
      </div>

      <div class="row two">
        <div>
          <label for="payhub_public_key">Payhub Public Key</label>
          <input type="text" id="payhub_public_key" name="payhub_public_key" value="<?= e((string) setting('payhub_public_key')) ?>" placeholder="YOUR_PUBLIC_KEY">
        </div>
        <div>
          <label for="payhub_secret_key">Payhub Secret Key</label>
          <input type="password" id="payhub_secret_key" name="payhub_secret_key" value="<?= e((string) setting('payhub_secret_key')) ?>" placeholder="sk_live_xxxx">
        </div>
      </div>

      <h3 style="margin-top:20px;">🏦 Manual Bank Transfer Payment Method</h3>
      <p class="sub">Allow advertisers to pay via bank transfer and upload proof of payment for review.</p>
      <div class="checkbox-row" style="margin-bottom:12px;">
        <input type="checkbox" id="manual_payment_enabled" name="manual_payment_enabled" <?= !empty(setting('manual_payment_enabled', 1)) ? 'checked' : '' ?>>
        <label for="manual_payment_enabled" style="margin:0;">Enable Manual Payment Method</label>
      </div>
      <label for="manual_payment_instructions">Bank Account Details &amp; Payment Instructions</label>
      <textarea id="manual_payment_instructions" name="manual_payment_instructions" rows="3" placeholder="Bank Name: GTBank&#10;Account Name: Grace & Life Church&#10;Account Number: 0123456789"><?= e((string) setting('manual_payment_instructions')) ?></textarea>

      <button type="submit" class="btn" style="margin-top:16px;">Save Gateway Settings</button>
    </form>
  </div>

  <div class="card" style="max-width:700px; margin-bottom:24px;">
    <h2>Add Ad Package / Duration</h2>
    <form method="post" action="/admin/ads?action=settings">
      <?= Csrf::field() ?>
      <input type="hidden" name="add_duration" value="1">
      <div class="row two">
        <div>
          <label for="title">Title (e.g. 14 Days Premium)</label>
          <input type="text" id="title" name="title" required placeholder="e.g. 14 Days Special">
        </div>
        <div>
          <label for="days">Days Duration</label>
          <input type="number" id="days" name="days" min="1" required placeholder="14">
        </div>
      </div>
      <div class="row two" style="margin-top:10px;">
        <div>
          <label for="price">Price (₦) *</label>
          <input type="number" step="0.01" id="price" name="price" value="0.00" placeholder="5000">
        </div>
        <div>
          <label for="display_frequency">Display Frequency *</label>
          <select id="display_frequency" name="display_frequency">
            <option value="5_min">Every 5 Minutes (Default for Paid Ads)</option>
            <option value="10_min">Every 10 Minutes</option>
            <option value="15_min">Every 15 Minutes</option>
            <option value="30_min">Every 30 Minutes</option>
            <option value="once_daily">Once Daily</option>
          </select>
        </div>
      </div>
      <div class="row two" style="margin-top:10px;">
        <div>
          <label for="sort_order">Sort Order</label>
          <input type="number" id="sort_order" name="sort_order" value="0">
        </div>
        <div style="display:flex; align-items:center; margin-top:20px;">
          <div class="checkbox-row" style="margin:0;">
            <input type="checkbox" id="is_free" name="is_free" onchange="handleFreeCheckbox(this, 'price', 'display_frequency')">
            <label for="is_free" style="margin:0;">Mark as FREE package</label>
          </div>
        </div>
      </div>
      <button type="submit" class="btn" style="margin-top:16px;">Add Package</button>
    </form>
  </div>

  <div class="card">
    <h2>Configured Ad Packages</h2>
    <table>
      <tr><th>Title</th><th>Days</th><th>Price</th><th>Frequency</th><th>Type</th><th>Sort Order</th><th></th></tr>
      <?php foreach ($durations as $d): ?>
        <?php
          $freqLabels = [
            '5_min' => 'Every 5 mins',
            '10_min' => 'Every 10 mins',
            '15_min' => 'Every 15 mins',
            '30_min' => 'Every 30 mins',
            'once_daily' => 'Once daily',
          ];
          $fLabel = $freqLabels[$d['display_frequency'] ?? '5_min'] ?? 'Every 5 mins';
        ?>
        <tr>
          <td><strong><?= e($d['title']) ?></strong></td>
          <td><?= (int) $d['days'] ?> days</td>
          <td><?= $d['is_free'] ? 'FREE' : '₦' . number_format((float) $d['price'], 2) ?></td>
          <td><span class="badge info"><?= e($fLabel) ?></span></td>
          <td><?= $d['is_free'] ? '<span class="badge ok">Free</span>' : '<span class="badge ok">Paid</span>' ?></td>
          <td><?= (int) $d['sort_order'] ?></td>
          <td style="white-space:nowrap;">
            <button class="btn sm secondary" onclick="openEditModal(<?= e(json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">Edit</button>
            <form method="post" action="/admin/ads?action=settings" onsubmit="return confirm('Delete this duration?');" style="display:inline;">
              <?= Csrf::field() ?>
              <input type="hidden" name="delete_duration" value="1">
              <input type="hidden" name="duration_id" value="<?= (int) $d['id'] ?>">
              <button class="btn danger sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- Edit Package Modal -->
  <div id="editPackageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; overflow-y:auto; padding:30px 15px;">
    <div class="card glass-card" style="max-width:600px; margin:40px auto; padding:32px; border-radius:12px; position:relative;">
      <button type="button" onclick="document.getElementById('editPackageModal').style.display='none';" style="position:absolute; top:16px; right:16px; background:none; border:none; color:inherit; font-size:24px; cursor:pointer;">&times;</button>
      <h2 style="margin-top:0; margin-bottom:20px;">Edit Ad Package</h2>

      <form method="post" action="/admin/ads?action=settings">
        <?= Csrf::field() ?>
        <input type="hidden" name="edit_duration" value="1">
        <input type="hidden" id="edit_duration_id" name="duration_id" value="">

        <div class="row two">
          <div>
            <label for="edit_title">Title</label>
            <input type="text" id="edit_title" name="title" required>
          </div>
          <div>
            <label for="edit_days">Days Duration</label>
            <input type="number" id="edit_days" name="days" min="1" required>
          </div>
        </div>

        <div class="row two" style="margin-top:10px;">
          <div>
            <label for="edit_price">Price (₦)</label>
            <input type="number" step="0.01" id="edit_price" name="price" value="0.00">
          </div>
          <div>
            <label for="edit_display_frequency">Display Frequency</label>
            <select id="edit_display_frequency" name="display_frequency">
              <option value="5_min">Every 5 Minutes</option>
              <option value="10_min">Every 10 Minutes</option>
              <option value="15_min">Every 15 Minutes</option>
              <option value="30_min">Every 30 Minutes</option>
              <option value="once_daily">Once Daily</option>
            </select>
          </div>
        </div>

        <div class="row two" style="margin-top:10px;">
          <div>
            <label for="edit_sort_order">Sort Order</label>
            <input type="number" id="edit_sort_order" name="sort_order" value="0">
          </div>
          <div style="display:flex; align-items:center; margin-top:20px;">
            <div class="checkbox-row" style="margin:0;">
              <input type="checkbox" id="edit_is_free" name="is_free" onchange="handleFreeCheckbox(this, 'edit_price', 'edit_display_frequency')">
              <label for="edit_is_free" style="margin:0;">Mark as FREE package</label>
            </div>
          </div>
        </div>

        <div style="margin-top:24px; text-align:right;">
          <button type="button" class="btn secondary" onclick="document.getElementById('editPackageModal').style.display='none';">Cancel</button>
          <button type="submit" class="btn">Save Package Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  function handleFreeCheckbox(chk, priceId, freqId) {
    var priceEl = document.getElementById(priceId);
    var freqEl = document.getElementById(freqId);
    if (chk.checked) {
      if (priceEl) { priceEl.value = '0.00'; priceEl.disabled = true; }
      if (freqEl) { freqEl.value = 'once_daily'; freqEl.disabled = true; }
    } else {
      if (priceEl) priceEl.disabled = false;
      if (freqEl) freqEl.disabled = false;
    }
  }

  function openEditModal(d) {
    document.getElementById('edit_duration_id').value = d.id;
    document.getElementById('edit_title').value = d.title;
    document.getElementById('edit_days').value = d.days;
    document.getElementById('edit_price').value = d.price;
    document.getElementById('edit_sort_order').value = d.sort_order;
    var chk = document.getElementById('edit_is_free');
    chk.checked = d.is_free == 1;
    var freqEl = document.getElementById('edit_display_frequency');
    if (freqEl) freqEl.value = d.display_frequency || '5_min';
    handleFreeCheckbox(chk, 'edit_price', 'edit_display_frequency');
    document.getElementById('editPackageModal').style.display = 'block';
  }
  </script>

<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
    <div>
      <a class="btn secondary sm <?= $statusFilter === 'all' ? 'active' : '' ?>" href="/admin/ads?status=all">All Ads</a>
      <a class="btn secondary sm <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="/admin/ads?status=pending">Pending</a>
      <a class="btn secondary sm <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="/admin/ads?status=approved">Approved</a>
      <a class="btn secondary sm <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="/admin/ads?status=rejected">Rejected</a>
    </div>
    <?php if (Auth::isSuperAdmin()): ?>
      <a class="btn secondary sm" href="/admin/ads?action=settings">⚙ Manage Settings &amp; Packages</a>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (!$adsList): ?>
      <div class="empty">No advertisements found.</div>
    <?php else: ?>
      <table>
        <tr>
          <th>Ad / Media</th>
          <th>Publisher</th>
          <th>Package / Payment</th>
          <th>Stats</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
        <?php foreach ($adsList as $ad): ?>
          <tr>
            <td>
              <div style="display:flex; gap:12px; align-items:center;">
                <div style="width:50px; height:88px; background:#110e1b; border-radius:4px; overflow:hidden; position:relative; flex-shrink:0; border:1px solid var(--border);">
                  <?php if ($ad['media_type'] === 'image'): ?>
                    <img src="<?= e(uploadUrl($ad['file_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                  <?php else: ?>
                    <?php if (!empty($ad['thumbnail_path'])): ?>
                      <img src="<?= e(uploadUrl($ad['thumbnail_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                      <video src="<?= e(uploadUrl($ad['file_path'])) ?>" style="width:100%; height:100%; object-fit:cover;" muted></video>
                    <?php endif; ?>
                    <span style="position:absolute; bottom:2px; right:2px; background:rgba(0,0,0,0.7); color:#fff; font-size:10px; padding:1px 3px; border-radius:2px;">▶ reel</span>
                  <?php endif; ?>
                </div>
                <div>
                  <strong><?= e($ad['title']) ?></strong><br>
                  <span class="badge info"><?= e(strtoupper($ad['media_type'])) ?></span>
                  <?php if (!empty($ad['destination_url'])): ?>
                    <br><a href="<?= e($ad['destination_url']) ?>" target="_blank" style="font-size:11px; color:var(--gold-soft);">↗ <?= e(mb_substr($ad['destination_url'], 0, 30)) ?>…</a>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <td>
              <strong><?= e($ad['pub_name']) ?></strong><br>
              <span style="font-size:12px; color:var(--ink-faint);"><?= e($ad['pub_email']) ?></span>
              <?php if (!empty($ad['pub_phone'])): ?><br><span style="font-size:12px; color:var(--ink-faint);"><?= e($ad['pub_phone']) ?></span><?php endif; ?>
              <br><a href="<?= e(baseUrl('ad-manager?token=' . rawurlencode($ad['pub_token']))) ?>" target="_blank" style="font-size:11px; color:var(--gold-soft);">🔑 Manager Link</a>
            </td>

            <td>
              <span class="badge ok"><?= e(strtoupper($ad['target_platform'])) ?></span><br>
              <small style="color:var(--ink-dim);"><?= (int) $ad['duration_days'] ?> Days (<?= $ad['is_free'] ? 'FREE' : '₦' . number_format((float) $ad['price'], 2) ?>)</small><br>

              <?php if ($ad['is_free']): ?>
                <span class="badge ok" style="font-size:10px;">Free Package</span>
              <?php else: ?>
                <?php if ($ad['payment_status'] === 'paid'): ?>
                  <span class="badge ok" style="font-size:10px;">Paid (<?= e(ucfirst((string)$ad['payment_method'])) ?>)</span>
                <?php elseif ($ad['payment_status'] === 'pending_review'): ?>
                  <span class="badge warn" style="font-size:10px;">Payment Review</span>
                <?php else: ?>
                  <span class="badge fail" style="font-size:10px;">Unpaid</span>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!empty($ad['payment_proof_path'])): ?>
                <br><a href="<?= e(uploadUrl($ad['payment_proof_path'])) ?>" target="_blank" style="font-size:11px; color:var(--gold-soft);">🖼 View Receipt</a>
              <?php endif; ?>

              <?php if ($ad['start_at'] && $ad['expires_at']): ?>
                <br><small style="font-size:11px; color:var(--ink-faint);">Live: <?= e(date('M j', strtotime($ad['start_at']))) ?> – <?= e(date('M j, Y', strtotime($ad['expires_at']))) ?></small>
              <?php endif; ?>
            </td>

            <td>
              <strong style="font-size:14px;"><?= (int) $ad['views_count'] ?></strong> <small style="color:var(--ink-faint);">views</small><br>
              <strong style="font-size:14px; color:var(--gold-soft);"><?= (int) $ad['clicks_count'] ?></strong> <small style="color:var(--ink-faint);">clicks</small>
            </td>

            <td>
              <?php if ($ad['status'] === 'pending'): ?>
                <span class="badge warn">pending</span>
              <?php elseif ($ad['status'] === 'approved'): ?>
                <?php if (strtotime($ad['expires_at']) <= time()): ?>
                  <span class="badge fail">expired</span>
                <?php else: ?>
                  <span class="badge ok">active</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge fail">rejected</span>
              <?php endif; ?>
            </td>

            <td style="white-space:nowrap;">
              <?php if ($ad['payment_status'] !== 'paid' && !$ad['is_free']): ?>
                <form method="post" action="/admin/ads?action=mark_paid&id=<?= (int) $ad['id'] ?>" style="display:inline;">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn sm secondary" onclick="return confirm('Mark payment as Paid?');">Mark Paid</button>
                </form>
              <?php endif; ?>

              <?php if ($ad['status'] === 'pending'): ?>
                <form method="post" action="/admin/ads?action=approve&id=<?= (int) $ad['id'] ?>" style="display:inline;">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn sm" onclick="return confirm('Approve this advert? It will go live immediately.');">Approve</button>
                </form>
                <form method="post" action="/admin/ads?action=reject&id=<?= (int) $ad['id'] ?>" style="display:inline;">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn sm danger">Reject</button>
                </form>
              <?php endif; ?>
              <form method="post" action="/admin/ads?action=delete&id=<?= (int) $ad['id'] ?>" style="display:inline;" onsubmit="return confirm('Permanently delete this advert?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn danger sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
