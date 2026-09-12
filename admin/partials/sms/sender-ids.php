<?php
declare(strict_types=1);

/**
 * SMS → Sender IDs.
 *
 * Any admin, editor or media-team member may submit one for their own church; the gateway
 * reviews it and `cli/sms_sender_check.php` records the outcome, so nobody has to refresh
 * a page to find out. Only the super admin may force a status, and that is flagged so a
 * forced value can never be mistaken for the gateway's own answer.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$myUnitId = (int) $smsContext['my_unit_id'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$errors = [];

$tenantId = Tenant::id();
$senderCap = max(0, (int) setting('sms_sender_cap', 0));

/**
 * Reads one sender row, refusing anything outside this admin's reach.
 *
 * A sender with no unit is shared with the whole church, the same convention the groups
 * and campaigns screens use — so it stays visible either way. Anything with a unit has
 * to fall inside the admin's own subtree, which is what stops church B managing church A's
 * sender IDs by posting their ids.
 */
$loadSender = static function (int $id) use ($pdo, $isSuper, $scopeUnitIds): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM sms_senders WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($isSuper || $scopeUnitIds === []) {
        return $row;
    }
    $unitId = (int) ($row['org_unit_id'] ?? 0);
    return $unitId === 0 || in_array($unitId, array_map('intval', $scopeUnitIds), true) ? $row : null;
};

/* ------------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuper && ($_POST['do'] ?? '') === 'set_default') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $row = $loadSender($id);
    if ($row === null) {
        flash('error', 'That sender ID could not be found.');
    } elseif ((string) $row['status'] !== 'approved') {
        flash('error', 'Only an approved sender ID can be made the default.');
    } else {
        $pdo->prepare('UPDATE sms_senders SET is_default = 0 WHERE tenant_id <=> ?')->execute([$tenantId]);
        $pdo->prepare('UPDATE sms_senders SET is_default = 1 WHERE id = ?')->execute([$id]);
        settingSave(['sms_default_sender_id' => (string) $row['sender_id']]);
        flash('success', $row['sender_id'] . ' is now the default sender ID.');
    }
    redirect('/admin/sms?tab=sender-ids');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'check') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $row = $loadSender($id);
    if ($row === null) {
        flash('error', 'That sender ID could not be found.');
        redirect('/admin/sms?tab=sender-ids');
    }

    if (!Sms::configured()) {
        flash('error', 'No API token is configured, so the status cannot be checked right now.');
        redirect('/admin/sms?tab=sender-ids');
    }

    $result = Sms::senderIdStatus((string) $row['sender_id']);
    if (!$result['ok']) {
        $pdo->prepare('UPDATE sms_senders SET last_checked_at = NOW(), last_check_note = ? WHERE id = ?')
            ->execute([mb_substr('Check failed: ' . (string) ($result['error'] ?? 'unknown error'), 0, 255), $id]);
        flash('error', 'The status could not be checked: ' . (string) ($result['error'] ?? 'unknown error'));
    } else {
        // The same loose matching the cron uses, so a manual check and the poller agree.
        $raw = $result['raw'];
        $status = '';
        foreach (['status', 'senderIDStatus', 'sender_status', 'message', 'data'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $status = strtolower(trim((string) $raw[$key]));
                break;
            }
        }

        if (str_contains($status, 'approve')) {
            $pdo->prepare("UPDATE sms_senders SET status = 'approved', status_source = 'gateway', approved_at = NOW(), last_checked_at = NOW(), last_check_note = NULL WHERE id = ?")->execute([$id]);
            if ((string) setting('sms_default_sender_id', '') === '') {
                settingSave(['sms_default_sender_id' => (string) $row['sender_id']]);
            }
            Notifier::send(
                array_values(array_filter([(int) ($row['org_unit_id'] ?? 0)])),
                'Sender ID ' . $row['sender_id'] . ' approved',
                'Your sender ID ' . $row['sender_id'] . ' has been approved and is ready to use.',
                ['email' => true, 'push' => true, 'roles' => ['admin', 'editor', 'media_team']]
            );
            flash('success', $row['sender_id'] . ' is approved and ready to use.');
        } elseif (str_contains($status, 'reject') || str_contains($status, 'declin') || str_contains($status, 'denied')) {
            $pdo->prepare("UPDATE sms_senders SET status = 'rejected', status_source = 'gateway', rejection_note = ?, last_checked_at = NOW() WHERE id = ?")
                ->execute([mb_substr('The gateway said: ' . $status, 0, 255), $id]);
            flash('error', $row['sender_id'] . ' was rejected by the gateway.');
        } else {
            $pdo->prepare('UPDATE sms_senders SET last_checked_at = NOW(), last_check_note = ? WHERE id = ?')
                ->execute([mb_substr('Still pending at the gateway: ' . ($status !== '' ? $status : 'no status given'), 0, 255), $id]);
            flash('success', $row['sender_id'] . ' is still pending at the gateway.');
        }
    }
    redirect('/admin/sms?tab=sender-ids');
}

// Super-admin override: force a status when the gateway cannot be reached.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuper && ($_POST['do'] ?? '') === 'override') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $row = $loadSender($id);

    if ($row === null) {
        flash('error', 'That sender ID could not be found.');
    } elseif (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        flash('error', 'That is not a valid status.');
    } else {
        $pdo->prepare("UPDATE sms_senders SET status = ?, status_source = 'manual', approved_at = ?, rejection_note = ? WHERE id = ?")
            ->execute([
                $status,
                $status === 'approved' ? date('Y-m-d H:i:s') : null,
                $status === 'rejected' ? 'Set manually by the super admin.' : null,
                $id,
            ]);
        flash('success', $row['sender_id'] . ' was set to ' . $status . ' manually. It is marked as a manual override.');
    }
    redirect('/admin/sms?tab=sender-ids');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    Csrf::requireValid();
    $row = $loadSender((int) ($_POST['id'] ?? 0));
    if ($row === null) {
        flash('error', 'That sender ID could not be found.');
    } else {
        $pdo->prepare('DELETE FROM sms_senders WHERE id = ?')->execute([(int) $row['id']]);
        flash('success', 'Removed ' . $row['sender_id'] . '.');
    }
    redirect('/admin/sms?tab=sender-ids');
}

// Submit a new one.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'submit') {
    Csrf::requireValid();
    $senderId = strtoupper(trim((string) ($_POST['sender_id'] ?? '')));
    $sample = trim((string) ($_POST['sample_message'] ?? ''));
    $unitId = $isSuper ? (int) ($_POST['org_unit_id'] ?? 0) : $myUnitId;

    $problem = Sms::senderIdProblem($senderId);
    if ($problem !== null) {
        $errors[] = $problem;
    }
    if ($sample === '') {
        $errors[] = 'A sample message is required — the gateway validates the sender ID against it.';
    }
    if (!$isSuper && $myUnitId <= 0) {
        $errors[] = 'Your account is not attached to a church, so a sender ID cannot be submitted for one.';
    }
    // A scoped admin may only submit for their own church.
    if (!$isSuper && $unitId > 0 && $scopeUnitIds !== [] && !in_array($unitId, array_map('intval', $scopeUnitIds), true)) {
        $errors[] = 'That church is outside your scope.';
    }

    if ($errors === []) {
        // The per-church cap, so one church cannot flood the gateway with requests.
        $existingCount = 0;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sms_senders WHERE tenant_id <=> ? AND ' . ($unitId > 0 ? 'org_unit_id = ?' : 'org_unit_id IS NULL'));
        $stmt->execute($unitId > 0 ? [$tenantId, $unitId] : [$tenantId]);
        $existingCount = (int) $stmt->fetchColumn();

        if ($senderCap > 0 && $existingCount >= $senderCap) {
            $errors[] = 'This church already has ' . $existingCount . ' sender ID(s), which is the limit you have set.';
        }

        // Already submitted? Say so rather than letting the unique key produce an error page.
        $dupe = $pdo->prepare('SELECT id FROM sms_senders WHERE tenant_id <=> ? AND sender_id = ? LIMIT 1');
        $dupe->execute([$tenantId, $senderId]);
        if ($dupe->fetchColumn()) {
            $errors[] = $senderId . ' has already been submitted. Check its status below.';
        }
    }

    if ($errors === []) {
        $pdo->prepare('INSERT INTO sms_senders (tenant_id, sender_id, status, status_source, sample_message, org_unit_id, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $senderId, 'pending', 'gateway', mb_substr($sample, 0, 160), $unitId > 0 ? $unitId : null, (int) ($smsContext['user']['id'] ?? 0)]);

        $registered = Sms::registerSenderId($senderId, $sample);
        if ($registered['ok']) {
            flash('success', $senderId . ' was submitted to the gateway. Its status updates automatically — check back later, or press "Check now".');
        } else {
            // Keep the row so the church can see what happened, but be honest that the
            // gateway did not accept the submission yet.
            $note = 'Gateway submission failed: ' . (string) ($registered['error'] ?? 'unknown error');
            $pdo->prepare('UPDATE sms_senders SET last_check_note = ? WHERE tenant_id <=> ? AND sender_id = ?')
                ->execute([mb_substr($note, 0, 255), $tenantId, $senderId]);
            flash('error', $senderId . ' was saved, but the gateway rejected the submission: ' . (string) ($registered['error'] ?? 'unknown error'));
        }
        redirect('/admin/sms?tab=sender-ids');
    }
}

/* --------------------------------------------------------------------- read */

$senders = [];
try {
    $clauses = [$tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?'];
    $params = $tenantId === null ? [] : [$tenantId];
    if (!$isSuper && $scopeUnitIds !== []) {
        $ids = array_map('intval', $scopeUnitIds);
        $clauses[] = '(org_unit_id IS NULL OR org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
        foreach ($ids as $id) {
            $params[] = $id;
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM sms_senders WHERE ' . implode(' AND ', $clauses) . ' ORDER BY FIELD(status, "approved", "pending", "rejected"), id ASC');
    $stmt->execute($params);
    $senders = $stmt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'The sender ID table is not available. Run the installer or reload the admin once to apply the database update.';
}

$assignableUnits = Unit::assignableScope($smsContext['user']);
$defaultSender = (string) setting('sms_default_sender_id', '');
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2>Submit a sender ID</h2>
  <p class="sub">
    A sender ID is the name your messages arrive from — up to <strong>11 characters</strong>, letters and
    numbers only, no spaces or symbols. Your provider reviews it, which usually takes a few hours.
    This page updates itself: a background check runs every 15 minutes and the church is told as soon as
    it is approved.
  </p>

  <form method="post" action="/admin/sms?tab=sender-ids">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="submit">
    <div class="row two">
      <div>
        <label for="sender_id">Sender ID</label>
        <input type="text" id="sender_id" name="sender_id" maxlength="11" required
               value="<?= e((string) formOld('sender_id')) ?>" placeholder="RCCGLP63YA" style="text-transform:uppercase;">
        <small style="color:var(--ink-faint);font-size:12px;">Letters and numbers only. It is shown to every recipient, so keep it recognisable.</small>
      </div>
      <div>
        <label for="org_unit_id">Church</label>
        <?php if ($isSuper): ?>
          <select id="org_unit_id" name="org_unit_id">
            <option value="">— none / head office —</option>
            <?php foreach ($assignableUnits as $unitId => $label): ?>
              <option value="<?= (int) $unitId ?>" <?= formOld('org_unit_id') === (string) $unitId ? 'selected' : '' ?>><?= e((string) $label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" value="<?= e($unitLabels[$myUnitId] ?? 'Your church') ?>" disabled>
        <?php endif; ?>
      </div>
    </div>

    <label for="sample_message">Sample message</label>
    <textarea id="sample_message" name="sample_message" rows="2" maxlength="160" required
              placeholder="e.g. Sunday service starts at 9am. See you there!"><?= e((string) formOld('sample_message')) ?></textarea>
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      The gateway uses this to check the sender ID suits your kind of messaging. It is not sent to anyone.
    </small>

    <button class="btn" type="submit">Submit for approval</button>
  </form>

  <?php if ($senderCap > 0): ?>
    <p class="sub" style="font-size:12px;margin-top:12px;">A limit of <?= $senderCap ?> sender ID(s) per church is in force.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Sender IDs</h2>
  <?php if (!$senders): ?>
    <div class="empty">No sender IDs have been submitted yet.</div>
  <?php else: ?>
    <table class="resp-table">
      <tr>
        <th>Sender ID</th><th>Church</th><th>Status</th><th>Last checked</th>
        <th>Note</th><?php if ($isSuper): ?><th>Override</th><?php endif; ?><th></th>
      </tr>
      <?php foreach ($senders as $sender): ?>
        <?php
          $status = (string) $sender['status'];
          $badge = $status === 'approved' ? 'ok' : ($status === 'rejected' ? 'fail' : 'warn');
          $isDefault = $defaultSender !== '' && $defaultSender === (string) $sender['sender_id'];
          $isManual = (string) $sender['status_source'] === 'manual';
        ?>
        <tr>
          <td>
            <strong><?= e((string) $sender['sender_id']) ?></strong>
            <?php if ($isDefault): ?> <span class="badge info">default</span><?php endif; ?>
            <?php if (!empty($sender['is_default']) && !$isDefault): ?> <span class="badge warn">was default</span><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($sender['org_unit_id'])): ?>
              <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $sender['org_unit_id']] ?? ('Unit ' . (int) $sender['org_unit_id'])) ?></span>
            <?php else: ?>
              <small style="color:var(--ink-faint);">Head office</small>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $badge ?>"><?= e($status) ?></span>
            <?php if ($isManual): ?>
              <div><small style="color:var(--ink-faint);">set by hand</small></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($sender['last_checked_at'])): ?>
              <small><?= e(timeAgo((string) $sender['last_checked_at'])) ?></small>
            <?php else: ?>
              <small style="color:var(--ink-faint);">never</small>
            <?php endif; ?>
          </td>
          <td style="max-width:240px;">
            <?php if ($status === 'rejected' && !empty($sender['rejection_note'])): ?>
              <small style="color:var(--danger);"><?= e((string) $sender['rejection_note']) ?></small>
            <?php elseif (!empty($sender['last_check_note'])): ?>
              <small style="color:var(--ink-dim);"><?= e((string) $sender['last_check_note']) ?></small>
            <?php elseif (!empty($sender['sample_message'])): ?>
              <small style="color:var(--ink-faint);">"<?= e(mb_strimwidth((string) $sender['sample_message'], 0, 70, '…')) ?>"</small>
            <?php endif; ?>
          </td>
          <?php if ($isSuper): ?>
            <td>
              <form method="post" class="status-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="do" value="override">
                <input type="hidden" name="id" value="<?= (int) $sender['id'] ?>">
                <select name="status" class="status-select" onchange="this.form.submit()">
                  <?php foreach (['pending', 'approved', 'rejected'] as $option): ?>
                    <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
          <?php endif; ?>
          <td style="white-space:nowrap;">
            <?php if ($status === 'pending'): ?>
              <form method="post" style="display:inline;">
                <?= Csrf::field() ?>
                <input type="hidden" name="do" value="check"><input type="hidden" name="id" value="<?= (int) $sender['id'] ?>">
                <button class="btn secondary sm" type="submit">Check now</button>
              </form>
            <?php endif; ?>
            <?php if ($isSuper && $status === 'approved' && !$isDefault): ?>
              <form method="post" style="display:inline;">
                <?= Csrf::field() ?>
                <input type="hidden" name="do" value="set_default"><input type="hidden" name="id" value="<?= (int) $sender['id'] ?>">
                <button class="btn secondary sm" type="submit">Make default</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Remove <?= e((string) $sender['sender_id']) ?>?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $sender['id'] ?>">
              <button class="btn danger sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
