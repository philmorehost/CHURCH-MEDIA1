<?php
declare(strict_types=1);

Auth::requireRole('admin');
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can review church registrations.');
}

$pdo = Database::getInstance()->getConnection();
$admin = Auth::user();
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];
// Capture first, then validate — reading $_GET['status'] inside the ternary's
// true-branch when the key is absent (no ?status= in the URL) triggered an
// "Undefined array key" warning.
$statusParam = $_GET['status'] ?? 'pending';
$statusFilter = in_array($statusParam, ['pending', 'approved', 'rejected'], true) ? $statusParam : 'pending';

function regChurchLabel(array $reg): string
{
    $parts = [];
    foreach (['province_id', 'zone_id', 'area_id', 'parish_id'] as $col) {
        $uid = (int) ($reg[$col] ?? 0);
        if ($uid > 0) {
            $unit = Unit::find($uid);
            if ($unit) {
                $parts[] = $unit['name'];
            }
        }
    }
    if (!$parts && !empty($reg['parish_name'])) {
        $parts[] = $reg['parish_name'];
    }
    return $parts ? implode(' > ', $parts) : '—';
}

/** Creates the corporate mailbox (+ optional forwarder) for a registration. */
function createCorporateEmail(array $reg, ?string $localPart = null): array
{
    $local = $localPart !== null && $localPart !== '' ? $localPart : (string) ($reg['username'] ?? '');
    if (!(int) setting('email_cpanel_enabled') || (string) setting('email_domain') === '') {
        return ['email' => null, 'note' => 'corporate email disabled or no email domain configured'];
    }
    $api = new CpanelApi([
        'host' => (string) setting('email_cpanel_host'),
        'user' => (string) setting('email_cpanel_user'),
        'token' => (string) setting('email_cpanel_token'),
    ]);
    if (!$api->configured()) {
        return ['email' => null, 'note' => 'cPanel not configured'];
    }
    $plain = decryptSecret((string) ($reg['password_enc'] ?? ''));
    if ($plain === null || $plain === '') {
        return ['email' => null, 'note' => 'registrant password unavailable'];
    }
    $domain = (string) setting('email_domain');
    $res = $api->createEmail($domain, $local, $plain, (int) (setting('email_default_quota') ?: 500));
    if (!$res['ok']) {
        return ['email' => null, 'note' => 'email creation failed: ' . ($res['error'] ?? 'unknown')];
    }
    $email = $local . '@' . $domain;
    $note = 'email ' . $email . ' created';
    $alt = trim((string) ($reg['alt_email'] ?? ''));
    if ($alt !== '' && filter_var($alt, FILTER_VALIDATE_EMAIL)) {
        $fr = $api->createForwarder($domain, $local, $alt);
        $note .= $fr['ok'] ? ' + forwarder → ' . $alt : ' (forwarder failed: ' . ($fr['error'] ?? 'unknown') . ')';
    }
    return ['email' => $email, 'note' => $note];
}

// Approve: create the admin account (parish auto-created under the area if new).
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stmt = $pdo->prepare('SELECT * FROM pending_registrations WHERE id = ?');
    $stmt->execute([$id]);
    $reg = $stmt->fetch();
    if (!$reg || $reg['status'] !== 'pending') {
        redirect('/admin/registrations');
    }

    $name = trim($_POST['name'] ?? $reg['name']);
    $email = trim($_POST['email'] ?? $reg['email']);
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? $reg['username']);
    $role = in_array($_POST['role'] ?? '', ['admin', 'editor', 'media_team'], true) ? $_POST['role'] : ($reg['role'] ?? 'admin');
    $areaId = (int) ($_POST['area_id'] ?? $reg['area_id'] ?? 0);
    $parishId = (int) ($_POST['parish_id'] ?? $reg['parish_id'] ?? 0);
    $parishName = Unit::nameFor((string) ($_POST['parish_name'] ?? $reg['parish_name'] ?? ''));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid name and email.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username === '') {
        $errors[] = 'Invalid username.';
    } elseif ($areaId <= 0 || !$area = Unit::find($areaId)) {
        $errors[] = 'Select the church Area for this registration.';
    } else {
        // Resolve the parish: existing id, existing name under the area, or create.
        $parish = $parishId > 0 ? Unit::find($parishId) : null;
        if ($parish && (int) ($parish['parent_id'] ?? 0) !== $areaId) {
            $parish = null;
        }
        if (!$parish && $parishName !== '') {
            $parish = Unit::findByName('parish', $parishName, $areaId);
        }
        if (!$parish && $parishName !== '') {
            $res = Unit::findOrCreate('parish', $areaId, $parishName);
            if (isset($res['errors'])) {
                $errors[] = 'Could not create the parish: ' . implode(' ', $res['errors']);
            } else {
                $parish = Unit::find((int) $res['id']);
            }
        }
        if (!$errors && !$parish) {
            $errors[] = 'A parish name is required to approve this registration.';
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                $errors[] = 'That username or email is already in use — edit it before approving.';
            } else {
                $pdo->prepare('INSERT INTO users (name, username, email, password, role, org_unit_id, notify_on_login) VALUES (?, ?, ?, ?, ?, ?, 1)')
                    ->execute([mb_substr($name, 0, 150), mb_substr($username, 0, 100), mb_substr($email, 0, 150), $reg['password_hash'], $role, (int) $parish['id']]);

                // Auto-create the corporate email (+ optional forwarder) via cPanel.
                $emailResult = createCorporateEmail($reg, $username);
                $emailCreated = $emailResult['email'] ? 1 : 0;
                $createdEmail = $emailResult['email'];
                $emailNote = $emailResult['note'];
                $pdo->prepare('UPDATE pending_registrations SET status = "approved", reviewed_by = ?, reviewed_at = NOW(), parish_id = ?, parish_name = ?, role = ?, email_created = ?, created_email = ? WHERE id = ?')
                    ->execute([$admin['id'] ?? null, (int) $parish['id'], (string) $parish['name'], $role, $emailCreated, $createdEmail, $id]);
                try {
                    $mailExtra = $createdEmail ? "\n\nCorporate email: {$createdEmail}" : '';
                    Mailer::send($email, 'Your ' . setting('site_title') . ' admin account is approved', "Hi {$name},\n\nYour church admin account has been approved and activated.\n\nLogin: " . baseUrl('admin/login') . "\nUsername: {$username}{$mailExtra}\n\nBlessings.");
                } catch (Throwable) {
                    // SMTP may be unconfigured — the account is still created.
                }
                flash('success', 'Registration approved — admin account created for ' . $name . ' (' . (string) $parish['name'] . '); ' . $emailNote . '.');
                redirect('/admin/registrations');
            }
        }
    }
}

// Reject: mark rejected (optional reason), no account is created.
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $reason = trim($_POST['reason'] ?? '');
    $pdo->prepare('UPDATE pending_registrations SET status = "rejected", reviewed_by = ?, reviewed_at = NOW(), reject_reason = ? WHERE id = ? AND status = "pending"')
        ->execute([$admin['id'] ?? null, $reason !== '' ? mb_substr($reason, 0, 500) : null, $id]);
    $stmt = $pdo->prepare('SELECT name, email FROM pending_registrations WHERE id = ?');
    $stmt->execute([$id]);
    $reg = $stmt->fetch();
    if ($reg) {
        try {
            Mailer::send((string) $reg['email'], 'Your ' . setting('site_title') . ' registration', "Hi {$reg['name']},\n\nYour church admin registration was not approved." . ($reason !== '' ? "\n\nReason: {$reason}" : '') . "\n\nYou can contact the site admin for help.");
        } catch (Throwable) {
        }
    }
    flash('success', 'Registration rejected.');
    redirect('/admin/registrations');
}

// Retry / create the corporate email for an already-approved registration.
if ($action === 'create_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stmt = $pdo->prepare('SELECT * FROM pending_registrations WHERE id = ?');
    $stmt->execute([$id]);
    $reg = $stmt->fetch();
    if (!$reg || $reg['status'] !== 'approved') {
        redirect('/admin/registrations?status=approved');
    }
    $result = createCorporateEmail($reg);
    $pdo->prepare('UPDATE pending_registrations SET email_created = ?, created_email = ? WHERE id = ?')
        ->execute([$result['email'] ? 1 : 0, $result['email'], $id]);
    flash($result['email'] ? 'success' : 'error', $result['email'] ? 'Corporate email ' . $result['email'] . ' created for ' . $reg['name'] . '.' : 'Could not create the email: ' . $result['note']);
    redirect('/admin/registrations?status=approved');
}

// Load the review target.
$reviewing = null;
if ($action === 'review') {
    $stmt = $pdo->prepare('SELECT * FROM pending_registrations WHERE id = ?');
    $stmt->execute([$id]);
    $reviewing = $stmt->fetch();
    if (!$reviewing) {
        redirect('/admin/registrations');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
        $reviewing['name'] = trim($_POST['name'] ?? $reviewing['name']);
        $reviewing['email'] = trim($_POST['email'] ?? $reviewing['email']);
        $reviewing['username'] = trim($_POST['username'] ?? $reviewing['username']);
        $reviewing['phone'] = trim($_POST['phone'] ?? '');
    }
}

// Load the list + counts.
$where = ' WHERE status = ?';
$params = [$statusFilter];
$stmt = $pdo->prepare('SELECT * FROM pending_registrations ' . $where . ' ORDER BY created_at DESC LIMIT 200');
$stmt->execute($params);
$registrations = $stmt->fetchAll();
$counts = $pdo->query('SELECT status, COUNT(*) AS c FROM pending_registrations GROUP BY status')->fetchAll();
$statusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($counts as $r) {
    $statusCounts[$r['status']] = (int) $r['c'];
}

$pageTitle = 'Registrations';
$activeNav = 'registrations';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'review' && $reviewing): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/registrations">← Back to registrations</a>
  </div>
  <div class="card" style="max-width:720px;">
    <h2>Review Registration</h2>
    <p class="sub">Submitted <?= e(date('M j, Y g:i A', strtotime($reviewing['created_at']))) ?> — edit anything, then approve (creates the admin account) or reject.</p>

    <form method="post" action="/admin/registrations?action=approve&id=<?= (int) $reviewing['id'] ?>" id="reviewForm"
          data-units='<?= e(json_encode(Unit::treeLight(), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
          data-old='<?= e(json_encode([
              'province_id' => (int) ($reviewing['province_id'] ?? 0),
              'zone_id' => (int) ($reviewing['zone_id'] ?? 0),
              'area_id' => (int) ($reviewing['area_id'] ?? 0),
              'parish_id' => (int) ($reviewing['parish_id'] ?? 0),
              'parish_name' => (string) ($reviewing['parish_name'] ?? ''),
          ], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
      <?= Csrf::field() ?>

      <div class="row two">
        <div>
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name" value="<?= e($reviewing['name']) ?>" required>
        </div>
        <div>
          <label for="role">Role</label>
          <select id="role" name="role" data-role>
            <option value="admin" <?= ($reviewing['role'] ?? 'admin') === 'admin' ? 'selected' : '' ?>>Church Admin</option>
            <option value="editor" <?= ($reviewing['role'] ?? '') === 'editor' ? 'selected' : '' ?>>Editor</option>
            <option value="media_team" <?= ($reviewing['role'] ?? '') === 'media_team' ? 'selected' : '' ?>>Media Team</option>
          </select>
        </div>
      </div>
      <div class="row two">
        <div>
          <label for="username">Username / Email</label>
          <input type="text" id="username" name="username" value="<?= e($reviewing['username']) ?>" required data-username>
          <div data-suggestions style="display:none;margin-top:8px;">
            <span style="font-size:11px;color:var(--ink-faint);font-weight:700;">Suggested:</span>
            <button type="button" class="btn secondary sm" data-suggestion style="margin-left:4px;"></button>
            <button type="button" class="btn secondary sm" data-suggestion style="margin-left:4px;"></button>
          </div>
          <div style="font-size:12px;color:var(--ink-faint);margin-top:6px;"><?= setting('email_domain') ? 'Corporate email will be <strong>@' . e((string) setting('email_domain')) . '</strong>.' : '' ?></div>
        </div>
        <div>
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="<?= e($reviewing['email']) ?>" required>
        </div>
      </div>
      <div class="row two">
        <div>
          <label for="phone">WhatsApp Phone</label>
          <input type="tel" id="phone" name="phone" value="<?= e($reviewing['phone'] ?? '') ?>">
        </div>
        <div>
          <label>Alternative Email (forwarder)</label>
          <input type="text" value="<?= e((string) ($reviewing['alt_email'] ?? '')) ?>" readonly style="opacity:.7;">
        </div>
      </div>

      <h2 style="margin-top:26px;">Church</h2>
      <div class="cascade-selects">
        <div>
          <label for="province">Province</label>
          <select id="province" data-province required><option value="">Select Province…</option></select>
        </div>
        <div>
          <label for="zone">Zone</label>
          <select id="zone" data-zone required><option value="">Select Zone…</option></select>
        </div>
        <div>
          <label for="area">Area</label>
          <select id="area" data-area required><option value="">Select Area…</option></select>
        </div>
        <div>
          <label for="parish">Parish church</label>
          <input type="text" id="parish" data-parish required placeholder="Type parish name (CAPS)">
          <datalist id="parishOptions" data-parish-list></datalist>
        </div>
        <input type="hidden" name="province_id" data-province-id>
        <input type="hidden" name="zone_id" data-zone-id>
        <input type="hidden" name="area_id" data-area-id>
        <input type="hidden" name="parish_id" data-parish-id>
        <input type="hidden" name="parish_name" data-parish-name>
      </div>
      <p class="sub" style="margin-top:10px;">Existing parishes under the selected Area appear as suggestions. A new parish name is created automatically on approval.</p>

      <div class="btn-row">
        <button class="btn" type="submit">✔ Approve &amp; Create Admin Account</button>
        <a class="btn secondary" href="/admin/registrations">Cancel</a>
      </div>
    </form>

    <form method="post" action="/admin/registrations?action=reject&id=<?= (int) $reviewing['id'] ?>" style="margin-top:26px;border-top:1px solid var(--border);padding-top:18px;">
      <?= Csrf::field() ?>
      <label for="reason">Reject reason (optional — emailed to the registrant)</label>
      <textarea id="reason" name="reason" rows="2" placeholder="e.g. Please correct your church name and resubmit"></textarea>
      <button type="submit" class="btn danger" onclick="return confirm('Reject this registration? No account will be created.');">✖ Reject Registration</button>
    </form>
  </div>

  <script src="<?= asset('js/register.js') ?>"></script>

<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn secondary sm <?= $statusFilter === 'pending' ? '' : 'secondary' ?>" href="/admin/registrations">Pending (<?= (int) $statusCounts['pending'] ?>)</a>
    <a class="btn secondary sm <?= $statusFilter === 'approved' ? '' : 'secondary' ?>" href="/admin/registrations?status=approved">Approved (<?= (int) $statusCounts['approved'] ?>)</a>
    <a class="btn secondary sm <?= $statusFilter === 'rejected' ? '' : 'secondary' ?>" href="/admin/registrations?status=rejected">Rejected (<?= (int) $statusCounts['rejected'] ?>)</a>
  </div>

  <div class="card">
    <?php if (!$registrations): ?>
      <div class="empty">No <?= e($statusFilter) ?> registrations. Churches register at <a href="<?= e(baseUrl('register')) ?>" target="_blank" style="color:var(--gold-soft);">/register</a>.</div>
    <?php else: ?>
      <table>
        <tr><th>Applicant</th><th>Church</th><th>Submitted</th><th>Status</th><th></th></tr>
        <?php foreach ($registrations as $reg): ?>
        <tr>
          <td>
            <strong><?= e($reg['name']) ?></strong>
            <div style="color:var(--ink-faint);font-size:12px;"><?= e($reg['email']) ?><?= $reg['phone'] ? ' · ' . e($reg['phone']) : '' ?><br>@<?= e($reg['username']) ?></div>
            <?php if ($reg['created_email']): ?><div style="color:var(--success);font-size:12px;margin-top:2px;">✉ <?= e($reg['created_email']) ?></div><?php endif; ?>
          </td>
          <td style="color:var(--gold-soft);font-size:13px;"><?= e(regChurchLabel($reg)) ?></td>
          <td><?= e(date('M j, Y g:i A', strtotime($reg['created_at']))) ?></td>
          <td>
            <?php if ($reg['status'] === 'pending'): ?><span class="badge warn">pending</span>
            <?php elseif ($reg['status'] === 'approved'): ?><span class="badge ok">approved</span>
            <?php else: ?><span class="badge fail">rejected</span><?php endif; ?>
            <?php if ($reg['reject_reason']): ?><div style="font-size:11px;color:var(--ink-faint);margin-top:3px;"><?= e($reg['reject_reason']) ?></div><?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <a class="btn secondary sm" href="/admin/registrations?action=review&id=<?= (int) $reg['id'] ?>">Review</a>
            <?php if ($reg['status'] === 'approved' && empty($reg['email_created'])): ?>
              <form method="post" action="/admin/registrations?action=create_email&id=<?= (int) $reg['id'] ?>" style="display:inline;">
                <?= Csrf::field() ?>
                <button type="submit" class="btn sm">✉ Create email</button>
              </form>
            <?php endif; ?>
            <?php if ($reg['status'] === 'pending'): ?>
              <form method="post" action="/admin/registrations?action=approve&id=<?= (int) $reg['id'] ?>" style="display:inline;">
                <?= Csrf::field() ?>
                <button type="submit" class="btn sm" onclick="return confirm('Approve <?= e($reg['name']) ?> and create their admin account?');">Approve</button>
              </form>
              <form method="post" action="/admin/registrations?action=reject&id=<?= (int) $reg['id'] ?>" style="display:inline;">
                <?= Csrf::field() ?><input type="hidden" name="reason" value="">
                <button type="submit" class="btn sm danger" onclick="return confirm('Reject this registration?');">Reject</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
