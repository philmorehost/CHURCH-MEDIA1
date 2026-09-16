<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();

$myUnitId = !empty($user['org_unit_id']) ? (int) $user['org_unit_id'] : 0;
$isSuper = Auth::isSuperAdmin();

// Only super admins and admins attached to a unit can broadcast.
$canSend = $isSuper || ($user['role'] === 'admin' && $myUnitId > 0);

// Sender scope: super admin = whole tree; unit admin = their own subtree.
$scopeUnitIds = [];
if ($isSuper) {
    foreach (Unit::all('id ASC') as $u) {
        $scopeUnitIds[] = (int) $u['id'];
    }
} elseif ($myUnitId > 0) {
    $scopeUnitIds = Unit::subtreeIds($myUnitId);
}
$scopeSet = array_flip($scopeUnitIds);

// Recipient targets: parishes (churches) within the sender's scope.
$targetUnits = [];
foreach (Unit::all('type ASC, name ASC') as $u) {
    if ($u['type'] === 'parish' && isset($scopeSet[(int) $u['id']])) {
        $targetUnits[(int) $u['id']] = $u;
    }
}

// Group the target parishes by their top-level province for the picker.
$provinceOf = [];
foreach ($targetUnits as $u) {
    $path = Unit::path((int) $u['id']);
    $provinceOf[(int) $u['id']] = $path ? (int) $path[0]['id'] : (int) $u['id'];
}
$grouped = [];
foreach ($targetUnits as $uid => $u) {
    $grouped[$provinceOf[$uid]][] = $u;
}
$provinceNames = [];
foreach (array_keys($grouped) as $pid) {
    $provinceNames[$pid] = Unit::label($pid);
}

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

// Mark a notification read for my unit.
if ($action === 'read' && $id && $myUnitId > 0) {
    $stmt = $pdo->prepare('UPDATE notification_recipients SET read_at = NOW() WHERE notification_id = ? AND org_unit_id = ? AND read_at IS NULL');
    $stmt->execute([$id, $myUnitId]);
    redirect('/admin/notifications');
}

// Mark everything read for my unit.
if ($action === 'mark_all_read' && $myUnitId > 0) {
    $pdo->prepare('UPDATE notification_recipients SET read_at = NOW() WHERE org_unit_id = ? AND read_at IS NULL')->execute([$myUnitId]);
    redirect('/admin/notifications');
}

// Compose + send.
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST' && $canSend) {
    Csrf::requireValid();
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $recipientType = ($_POST['recipient_type'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $selectedUnits = array_filter(array_map('intval', $_POST['unit_ids'] ?? []));

    if ($title === '' || $body === '') {
        $errors[] = 'Title and message are required.';
    } else {
        if ($recipientType === 'all') {
            $targetIds = array_keys($targetUnits);
        } else {
            $targetIds = [];
            foreach ($selectedUnits as $uid) {
                if (isset($scopeSet[$uid]) && isset($targetUnits[$uid])) {
                    $targetIds[] = $uid;
                }
            }
        }
        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
        if (!$targetIds) {
            $errors[] = 'No recipient churches were selected.';
        } else {
            $pdo->prepare('INSERT INTO notifications (sender_id, title, body) VALUES (?, ?, ?)')->execute([$user['id'] ?? null, $title, $body]);
            $nid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT IGNORE INTO notification_recipients (notification_id, org_unit_id) VALUES (?, ?)');
            foreach ($targetIds as $tid) {
                $ins->execute([$nid, $tid]);
            }

            // Push to each targeted church's device topic (best-effort).
            try {
                foreach ($targetIds as $tid) {
                    Pusher::sendToUnit($tid, $title, $body, null, ['type' => 'admin_notice', 'notification_id' => (string) $nid]);
                }
            } catch (Throwable $e) {
                error_log('Push notify failed: ' . $e->getMessage());
            }

            // Email every admin/editor of the targeted churches.
            $in = implode(',', $targetIds);
            $emailed = 0;
            $emailedUnits = [];
            if ($in !== '') {
                $stmt = $pdo->query("SELECT name, email, org_unit_id FROM users WHERE org_unit_id IN ($in) AND role IN ('admin','editor') AND email IS NOT NULL AND email != ''");
                foreach ($stmt->fetchAll() as $u) {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $appUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                    $ok = Mailer::send($u['email'], $title, $body . "\n\nView it in the admin dashboard: " . $appUrl . '/admin/notifications');
                    if ($ok) {
                        $emailed++;
                        $emailedUnits[(int) $u['org_unit_id']] = true;
                    }
                }
                foreach (array_keys($emailedUnits) as $eu) {
                    $pdo->prepare('UPDATE notification_recipients SET delivered_at = NOW() WHERE notification_id = ? AND org_unit_id = ?')->execute([$nid, $eu]);
                }
            }
            flash('success', 'Notification sent to ' . count($targetIds) . ' church(es)' . ($emailed ? ' — emailed ' . $emailed . ' recipient(s).' : '.'));
            redirect('/admin/notifications');
        }
    }
}

// Received by my unit (for the dashboard "notifications" list).
$received = [];
$unreadCount = 0;
if ($myUnitId > 0) {
    $unreadCount = (int) $pdo->query("SELECT COUNT(*) FROM notification_recipients WHERE org_unit_id = {$myUnitId} AND read_at IS NULL")->fetchColumn();
    $received = $pdo->query("SELECT n.id, n.title, n.body, n.created_at, u.name AS sender, nr.read_at, nr.delivered_at
        FROM notifications n
        JOIN notification_recipients nr ON nr.notification_id = n.id AND nr.org_unit_id = {$myUnitId}
        LEFT JOIN users u ON u.id = n.sender_id
        ORDER BY n.created_at DESC LIMIT 100")->fetchAll();
}

// Notifications I sent (with recipient count).
$sent = [];
if ($isSuper) {
    $sent = $pdo->query('SELECT n.*, u.name AS sender, (SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id = n.id) AS recipient_count
        FROM notifications n LEFT JOIN users u ON u.id = n.sender_id ORDER BY n.created_at DESC LIMIT 100')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT n.*, u.name AS sender, (SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id = n.id) AS recipient_count
        FROM notifications n LEFT JOIN users u ON u.id = n.sender_id WHERE n.sender_id = ? ORDER BY n.created_at DESC LIMIT 100');
    $stmt->execute([$user['id'] ?? 0]);
    $sent = $stmt->fetchAll();
}

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($canSend): ?>
<div class="card">
  <h2>Send Notification</h2>
  <p class="sub">Broadcast an announcement to all churches, one church, or selected churches. Recipients see it on their dashboard and receive an email.</p>
  <form method="post" action="/admin/notifications?action=send">
    <?= Csrf::field() ?>
    <label for="notif_title">Title</label>
    <input type="text" id="notif_title" name="title" required maxlength="255" placeholder="e.g. Provincial Youth Convention — Registration Open">
    <label for="notif_body">Message</label>
    <textarea id="notif_body" name="body" rows="4" required></textarea>

    <label>Recipients</label>
    <div class="checkbox-row">
      <input type="radio" id="rt_all" name="recipient_type" value="all" checked>
      <label for="rt_all" style="margin:0;">All churches (<?= count($targetUnits) ?> parishes in your scope)</label>
    </div>
    <div class="checkbox-row">
      <input type="radio" id="rt_sel" name="recipient_type" value="selected">
      <label for="rt_sel" style="margin:0;">Select specific churches</label>
    </div>

    <div id="unit-picker" style="display:none;margin-top:10px;max-height:320px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:12px;">
      <?php if (!$grouped): ?>
        <p class="sub">No parishes in your scope yet.</p>
      <?php else: ?>
        <?php foreach ($grouped as $pid => $parishes): ?>
          <h3 style="margin:8px 0 6px;font-size:14px;color:var(--gold-soft);"><?= e($provinceNames[$pid] ?? ('Unit ' . $pid)) ?></h3>
          <?php foreach ($parishes as $p): ?>
            <div class="checkbox-row">
              <input type="checkbox" name="unit_ids[]" value="<?= (int) $p['id'] ?>" id="unit_<?= (int) $p['id'] ?>">
              <label for="unit_<?= (int) $p['id'] ?>" style="margin:0;"><?= e($p['name']) ?></label>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <button class="btn" type="submit" style="margin-top:12px;">Send Notification</button>
  </form>
</div>
<?php endif; ?>

<?php if ($myUnitId > 0): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;">
    <h2>Received <?= $unreadCount ? '<span class="badge danger">' . $unreadCount . ' new</span>' : '' ?></h2>
    <?php if ($unreadCount): ?><a href="/admin/notifications?action=mark_all_read" style="color:var(--gold-soft);">Mark all read</a><?php endif; ?>
  </div>
  <?php if (!$received): ?>
    <div class="empty">No notifications for your church yet.</div>
  <?php else: ?>
    <?php foreach ($received as $n): ?>
    <div style="padding:12px 0;border-bottom:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;gap:10px;">
        <strong><?= e($n['title']) ?><?= $n['read_at'] ? '' : ' <span class="badge info">new</span>' ?></strong>
        <span style="white-space:nowrap;color:var(--ink-faint);font-size:12px;"><?= e(date('M j, g:i A', strtotime((string) $n['created_at']))) ?></span>
      </div>
      <p style="margin:6px 0 0;color:var(--ink-dim);white-space:pre-wrap;"><?= e($n['body']) ?></p>
      <div style="margin-top:6px;font-size:12px;color:var(--ink-faint);">
        From: <?= e($n['sender'] ?: 'Headquarters') ?>
        <?php if ($n['read_at']): ?>
          · Read <?= e(date('M j, g:i A', strtotime((string) $n['read_at']))) ?>
        <?php else: ?>
          · <a href="/admin/notifications?action=read&id=<?= (int) $n['id'] ?>" style="color:var(--gold-soft);">Mark read</a>
        <?php endif; ?>
        <?php if ($n['delivered_at']): ?> · <span style="color:var(--success);">Emailed</span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sent): ?>
<div class="card">
  <h2>Sent</h2>
  <table>
    <tr><th>Title</th><th>To</th><th>Sent</th></tr>
    <?php foreach ($sent as $n): ?>
    <tr>
      <td><?= e(mb_strimwidth((string) $n['title'], 0, 60, '…')) ?></td>
      <td><?= (int) $n['recipient_count'] ?> church(es)</td>
      <td><?= e(date('M j, g:i A', strtotime((string) $n['created_at']))) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<script>
(function () {
  const all = document.getElementById('rt_all');
  const sel = document.getElementById('rt_sel');
  const picker = document.getElementById('unit-picker');
  if (!all || !sel || !picker) return;
  const toggle = () => { picker.style.display = sel.checked ? '' : 'none'; };
  all.addEventListener('change', toggle);
  sel.addEventListener('change', toggle);
  toggle();
})();
</script>
