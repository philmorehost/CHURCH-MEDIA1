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

// Recipient targets: every unit in the sender's scope, at *any* level — not just
// the deepest one — so "everyone under Zone A" is a first-class choice. Ordering
// follows the hierarchy, so the picker reads as a tree.
$leafType = Unit::leafType();
$scopeUnits = [];
foreach (Unit::sortByLevel(Unit::all('name ASC')) as $u) {
    if (isset($scopeSet[(int) $u['id']])) {
        $scopeUnits[(int) $u['id']] = $u;
    }
}
$leafCount = 0;
foreach ($scopeUnits as $u) {
    if ($u['type'] === $leafType) {
        $leafCount++;
    }
}

// Group the targets under their top-level unit for the picker.
$rootOf = [];
foreach ($scopeUnits as $uid => $u) {
    $path = Unit::path($uid);
    $rootOf[$uid] = $path ? (int) $path[0]['id'] : $uid;
}
$grouped = [];
foreach ($scopeUnits as $uid => $u) {
    $grouped[$rootOf[$uid]][] = $u;
}
$rootNames = [];
foreach (array_keys($grouped) as $pid) {
    $rootNames[$pid] = Unit::label($pid);
}

/** Names the audience a sent notification went to, for the Sent table. */
$describeTarget = static function (?int $unitId) use ($scopeUnits): string {
    if ($unitId === null || $unitId <= 0) {
        return 'Everyone in scope';
    }
    foreach ($scopeUnits as $u) {
        if ((int) $u['id'] === $unitId) {
            return (string) $u['name'] . ' (' . Unit::labelFor((string) $u['type']) . ')';
        }
    }
    // The unit may have been deleted or moved out of scope since it was sent.
    return Unit::label($unitId) ?: ('Unit ' . $unitId);
};

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
    $selectedUnits = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['unit_ids'] ?? [])))));

    if ($title === '' || $body === '') {
        $errors[] = 'Title and message are required.';
    } else {
        // A picked unit may be any level. Anything outside the sender's scope is
        // dropped rather than honoured, so a hand-crafted post cannot reach past it.
        $pickedUnits = [];
        if ($recipientType === 'all') {
            $pickedUnits = array_keys($scopeUnits);
        } else {
            foreach ($selectedUnits as $uid) {
                if (isset($scopeUnits[$uid])) {
                    $pickedUnits[] = (int) $uid;
                }
            }
        }

        // Expanding each pick to its whole subtree is what makes a mid-level target
        // mean "everyone beneath it".
        $audience = [];
        foreach ($pickedUnits as $uid) {
            foreach (Unit::subtreeIds($uid) as $sub) {
                if (isset($scopeUnits[$sub])) {
                    $audience[(int) $sub] = true;
                }
            }
        }
        $audience = array_map('intval', array_keys($audience));
        sort($audience);

        if (!$audience) {
            $errors[] = $recipientType === 'all'
                ? 'There are no units in your scope to notify yet.'
                : 'No recipient units were selected.';
        } else {
            // Record the unit that was actually picked, for the audit trail: one row
            // when a single unit was chosen, NULL when the whole scope was notified.
            $targetUnitId = $recipientType === 'selected' && count($pickedUnits) === 1 ? $pickedUnits[0] : null;
            $targetLevel = $targetUnitId !== null ? (string) $scopeUnits[$targetUnitId]['type'] : null;

            $pdo->prepare('INSERT INTO notifications (sender_id, title, body, target_unit_id, target_level) VALUES (?, ?, ?, ?, ?)')
                ->execute([$user['id'] ?? null, $title, $body, $targetUnitId, $targetLevel]);
            $nid = (int) $pdo->lastInsertId();

            // One row per unit in the audience, so a mid-level admin can mark it read
            // just as a church can. The unique key keeps this idempotent.
            $ins = $pdo->prepare('INSERT IGNORE INTO notification_recipients (notification_id, org_unit_id) VALUES (?, ?)');
            foreach ($audience as $tid) {
                $ins->execute([$nid, $tid]);
            }

            // Push to the churches rather than to every level: a device subscribes to
            // the topic of the church it is browsing, so the leaves are what actually
            // reaches people. The picked unit is included so its own topic also fires.
            try {
                $pushIds = $targetUnitId !== null ? [$targetUnitId] : [];
                foreach ($audience as $tid) {
                    if ($scopeUnits[$tid]['type'] === $leafType) {
                        $pushIds[] = $tid;
                    }
                }
                foreach (array_unique($pushIds) as $tid) {
                    Pusher::sendToUnit($tid, $title, $body, null, ['type' => 'admin_notice', 'notification_id' => (string) $nid]);
                }
            } catch (Throwable $e) {
                error_log('Push notify failed: ' . $e->getMessage());
            }

            // Email every admin/editor attached to a unit in the audience.
            $emailed = 0;
            $emailedUnits = [];
            $placeholders = implode(',', array_fill(0, count($audience), '?'));
            $stmt = $pdo->prepare("SELECT name, email, org_unit_id FROM users WHERE org_unit_id IN ($placeholders) AND role IN ('admin','editor') AND email IS NOT NULL AND email != ''");
            $stmt->execute($audience);
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

            $reached = $targetUnitId !== null
                ? $describeTarget($targetUnitId)
                : count($scopeUnits) . ' unit(s) in your scope';
            flash('success', 'Sent to ' . $reached . ' — ' . count($audience) . ' unit(s) reached' . ($emailed ? ', ' . $emailed . ' emailed.' : '.'));
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
  <p class="sub">Broadcast to everyone in your scope, or pick any <?= e(mb_strtolower(Unit::labelFor(Unit::rootType()))) ?> and reach every church beneath it. Recipients see it on their dashboard, get a push, and receive an email.</p>
  <form method="post" action="/admin/notifications?action=send">
    <?= Csrf::field() ?>
    <label for="notif_title">Title</label>
    <input type="text" id="notif_title" name="title" required maxlength="255" placeholder="e.g. Provincial Youth Convention — Registration Open">
    <label for="notif_body">Message</label>
    <textarea id="notif_body" name="body" rows="4" required></textarea>

    <label>Recipients</label>
    <div class="checkbox-row">
      <input type="radio" id="rt_all" name="recipient_type" value="all" checked>
      <label for="rt_all" style="margin:0;">Everything in my scope (all <?= e(mb_strtolower(Unit::pluralFor(Unit::rootType()))) ?> and every <?= e(mb_strtolower(Unit::pluralFor($leafType))) ?> beneath them)</label>
    </div>
    <div class="checkbox-row">
      <input type="radio" id="rt_sel" name="recipient_type" value="selected">
      <label for="rt_sel" style="margin:0;">Pick a <?= e(mb_strtolower(Unit::labelFor(Unit::rootType()))) ?>, area or church</label>
    </div>

    <div id="unit-picker" style="display:none;margin-top:10px;max-height:360px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:12px;">
      <?php if (!$grouped): ?>
        <p class="sub">No <?= e(mb_strtolower(Unit::pluralFor($leafType))) ?> in your scope yet.</p>
      <?php else: ?>
        <p class="sub" style="margin:0 0 10px;">Tick any level — everything beneath it is included automatically.</p>
        <?php foreach ($grouped as $pid => $units): ?>
          <h3 style="margin:10px 0 6px;font-size:14px;color:var(--gold-soft);"><?= e($rootNames[$pid] ?? ('Unit ' . $pid)) ?></h3>
          <?php foreach ($units as $p): ?>
            <?php
              // Indent by depth so intermediate levels read as a tree inside a flat list.
              $depth = max(0, (int) (Unit::levelIndex((string) $p['type']) ?? 0));
              $isLeaf = $p['type'] === $leafType;
            ?>
            <div class="checkbox-row" style="margin-left:<?= $depth * 22 ?>px;">
              <input type="checkbox" name="unit_ids[]" value="<?= (int) $p['id'] ?>" id="unit_<?= (int) $p['id'] ?>">
              <label for="unit_<?= (int) $p['id'] ?>" style="margin:0;">
                <?= e($p['name']) ?>
                <small style="color:var(--ink-faint);">· <?= e(Unit::labelFor((string) $p['type'])) ?><?= $isLeaf ? '' : ' — includes every ' . e(mb_strtolower(Unit::pluralFor($leafType))) . ' beneath it' ?></small>
              </label>
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
    <tr><th>Title</th><th>Target</th><th>To</th><th>Sent</th></tr>
    <?php foreach ($sent as $n): ?>
    <tr>
      <td><?= e(mb_strimwidth((string) $n['title'], 0, 60, '…')) ?></td>
      <td><?= e($describeTarget(isset($n['target_unit_id']) ? (int) $n['target_unit_id'] : null)) ?></td>
      <td><?= (int) $n['recipient_count'] ?> unit(s)</td>
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
