<?php
declare(strict_types=1);

/**
 * SMS → Campaigns. The history, and the only place a stuck campaign can be fixed.
 *
 * A campaign has two states an admin has to understand:
 *   - "queued" or "sending" — the worker still has rows to get through;
 *   - anything else — it is finished, whether well or badly.
 *
 * "Resend to failures" is deliberately limited to a *partial* campaign. A campaign where
 * nothing went out reads `failed`, and re-sending it is a decision about the gateway, not a
 * retry — so it is offered explicitly rather than being one click away by accident.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$unitLabels = $smsContext['unit_labels'];
$errors = [];
$tabUrl = '/admin/sms?tab=campaigns';

/**
 * Loads a campaign, refusing anything this admin may not see.
 *
 * The unit check matters as much as the list filter does: without it, a church admin could
 * read another church's message and recipient list simply by putting its id in the URL — and
 * the action handlers below all go through here, so they would act on it too.
 */
$loadCampaign = static function (int $id) use ($pdo, $isSuper, $scopeUnitIds): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM sms_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($isSuper || $scopeUnitIds === []) {
        return $row;
    }
    // A campaign with no unit was created church-wide, so it stays visible to everyone
    // who can see the contacts it went to. Matches the list query below.
    $unitId = (int) ($row['org_unit_id'] ?? 0);
    return $unitId === 0 || in_array($unitId, array_map('intval', $scopeUnitIds), true) ? $row : null;
};

/* ============================================================== CSV export ==
 * The per-recipient report, which is what you actually need when someone says
 * "I never got the message".
 */
if (($_GET['action'] ?? '') === 'export') {
    $campaignId = (int) ($_GET['id'] ?? 0);
    $campaign = $loadCampaign($campaignId);
    if ($campaign === null) {
        http_response_code(404);
        exit('No such campaign.');
    }

    $stmt = $pdo->prepare('SELECT msisdn, name, status, units, error_code, error_note, attempts, sent_at FROM sms_campaign_recipients WHERE campaign_id = ? ORDER BY id ASC');
    $stmt->execute([$campaignId]);
    $rows = $stmt->fetchAll();

    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sms-campaign-' . $campaignId . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Number', 'Name', 'Status', 'Units', 'Error code', 'Error', 'Attempts', 'Sent at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            Sms::prettyMsisdn((string) $row['msisdn']),
            (string) ($row['name'] ?? ''),
            (string) $row['status'],
            (int) $row['units'],
            (string) ($row['error_code'] ?? ''),
            (string) ($row['error_note'] ?? ''),
            (int) $row['attempts'],
            (string) ($row['sent_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

/* ============================================================ POST handling */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = (string) ($_POST['do'] ?? '');
    $campaignId = (int) ($_POST['id'] ?? 0);
    $campaign = $loadCampaign($campaignId);

    if ($campaign === null) {
        flash('error', 'That campaign could not be found.');
        redirect($tabUrl);
    }

    if ($do === 'cancel') {
        if (in_array((string) $campaign['status'], ['sent', 'failed', 'cancelled'], true)) {
            flash('error', 'That campaign has already finished, so there is nothing left to cancel.');
        } else {
            SmsCampaign::cancel($campaignId);
            flash('success', 'Campaign cancelled. Recipients that had not been sent to are marked skipped; nothing further will go out.');
        }
        redirect($tabUrl . '&id=' . $campaignId);
    }

    if ($do === 'resume') {
        if (SmsCampaign::resume($campaignId)) {
            flash('success', 'Campaign resumed. The worker will pick it up on its next run.');
        } else {
            flash('error', 'That campaign cannot be resumed — it is finished, cancelled, or has nothing left to send.');
        }
        redirect($tabUrl . '&id=' . $campaignId);
    }

    if ($do === 'resend_failures') {
        $reopened = SmsCampaign::reopenFailures($campaignId);
        if ($reopened > 0) {
            flash('success', $reopened . ' failed recipient(s) put back in the queue. The worker will try them again.');
        } else {
            flash('error', 'There was nothing to resend — that campaign has no failed recipients.');
        }
        redirect($tabUrl . '&id=' . $campaignId);
    }

    if ($do === 'release') {
        // The escape hatch for a campaign whose worker died mid-batch. The rows are only
        // claimed for CLAIM_TIMEOUT_MINUTES, but waiting is not always acceptable.
        $released = SmsCampaign::releaseAllClaims($campaignId);
        SmsCampaign::refreshCounters($campaignId);
        flash('success', $released . ' recipient(s) released back to pending. If a worker is actually still running they may be sent twice — check the process list first.');
        redirect($tabUrl . '&id=' . $campaignId);
    }

    if ($do === 'delete') {
        $pdo->prepare('DELETE FROM sms_campaigns WHERE id = ?')->execute([$campaignId]);
        flash('success', 'Campaign deleted, along with its recipient list. The gateway logs about it are kept.');
        redirect($tabUrl);
    }
}

/* ==================================================================== view */

$statusFilter = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($statusFilter !== '' && in_array($statusFilter, SmsCampaign::STATUSES, true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if (!$isSuper && $scopeUnitIds !== []) {
    $ids = array_map('intval', $scopeUnitIds);
    // A campaign with no unit is one the super admin created across the whole church, so it
    // stays visible to everyone who can see the contacts it went to.
    $where[] = '(org_unit_id IS NULL OR org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
    foreach ($ids as $id) {
        $params[] = $id;
    }
}

$whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM sms_campaigns' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$listStmt = $pdo->prepare('SELECT * FROM sms_campaigns' . $whereSql . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$listStmt->execute($params);
$campaigns = $listStmt->fetchAll();

$openId = (int) ($_GET['id'] ?? 0);
$open = $openId > 0 ? $loadCampaign($openId) : null;
if ($openId > 0 && $open === null) {
    $errors[] = 'That campaign could not be found.';
}

$openCounters = [];
$openRecipients = [];
$recipientFilter = (string) ($_GET['recipients'] ?? '');
if ($open !== null) {
    $openCounters = SmsCampaign::statusCounts((int) $open['id']);
    $sql = 'SELECT * FROM sms_campaign_recipients WHERE campaign_id = ?';
    $bind = [(int) $open['id']];
    if (in_array($recipientFilter, ['pending', 'sent', 'failed', 'skipped'], true)) {
        $sql .= ' AND status = ?';
        $bind[] = $recipientFilter;
    }
    $sql .= ' ORDER BY FIELD(status, "failed", "pending", "sent", "skipped"), id ASC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $openRecipients = $stmt->fetchAll();
}

/** A badge colour for a campaign status. */
$statusBadge = static function (string $status): string {
    // An array rather than a `match`: the codebase supports PHP before 8, where `match` is not a
    // keyword and the file will not parse.
    $badges = [
        'sent' => 'ok',
        'failed' => 'fail',
        'partial' => 'warn',
        'cancelled' => 'warn',
        'queued' => 'info',
        'sending' => 'info',
    ];

    return $badges[$status] ?? '';
};
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($open !== null): ?>
  <?php
    $status = (string) $open['status'];
    $isActive = in_array($status, SmsCampaign::ACTIVE_STATUSES, true);
    $attempted = (int) $open['sent_count'] + (int) $open['failed_count'];
    $rate = $attempted > 0 ? (int) round((int) $open['sent_count'] / $attempted * 100) : null;
    $pendingLeft = (int) ($openCounters['pending'] ?? 0);
  ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
      <div>
        <h2 style="margin-bottom:4px;"><?= e((string) $open['title']) ?></h2>
        <p class="sub" style="margin:0;">
          <span class="badge <?= $statusBadge($status) ?>"><?= e($status) ?></span>
          from <strong style="color:var(--gold-soft);"><?= e((string) ($open['sender_id'] ?: '—')) ?></strong>
          · created <?= e(timeAgo((string) $open['created_at'])) ?>
          <?php if (!empty($open['created_by'])): ?>
            <?php
              $byStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
              $byStmt->execute([(int) $open['created_by']]);
              $byName = (string) ($byStmt->fetchColumn() ?: '');
            ?>
            <?php if ($byName !== ''): ?>by <?= e($byName) ?><?php endif; ?>
          <?php endif; ?>
        </p>
      </div>
      <a class="btn secondary sm" href="<?= e($tabUrl) ?>">← All campaigns</a>
    </div>

    <?php if (!empty($open['paused_reason'])): ?>
      <div class="alert error" style="margin-top:14px;">
        <strong>Paused:</strong> <?= e((string) $open['paused_reason']) ?>
        <?php if ($isActive && $pendingLeft > 0): ?>
          <div style="margin-top:8px;">Fix the cause — usually the wallet balance — then resume.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($open['scheduled_at']) && $status === 'queued' && strtotime((string) $open['scheduled_at']) > time()): ?>
      <div class="alert success" style="background:#6fb3ff18;border-color:#6fb3ff44;color:#cfe4ff;margin-top:14px;">
        Scheduled for <?= e(date('D, M j \a\t g:i A', (int) strtotime((string) $open['scheduled_at']))) ?>.
      </div>
    <?php endif; ?>

    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:16px 0;">
      <div class="stat"><div class="num"><?= number_format((int) $open['total_recipients']) ?></div><div class="label">Recipients</div></div>
      <div class="stat"><div class="num"><?= number_format((int) $open['sent_count']) ?></div><div class="label">Accepted by the gateway</div></div>
      <div class="stat"><div class="num"><?= (int) $open['failed_count'] > 0 ? '<span style="color:var(--danger);">' . number_format((int) $open['failed_count']) . '</span>' : '0' ?></div><div class="label">Failed</div></div>
      <div class="stat"><div class="num"><?= number_format($pendingLeft) ?></div><div class="label">Still to send</div></div>
      <div class="stat"><div class="num"><?= number_format((int) $open['units_charged']) ?></div><div class="label">Units charged</div></div>
      <div class="stat"><div class="num"><?= $rate !== null ? $rate . '%' : '—' ?></div><div class="label">Acceptance rate</div></div>
    </div>

    <?php if ($status === 'partial'): ?>
      <div class="alert error" style="background:#ff6b6b10;border-color:#ff6b6b33;color:#ffc9c9;">
        Some messages were refused. The content of a message that the gateway rejected has arrived
        on nobody's phone, so the most common cause is a blocked word in the text — check a failure's
        code below before re-sending.
      </div>
    <?php elseif ($status === 'failed'): ?>
      <div class="alert error">
        Nothing in this campaign was accepted by the gateway. That usually means the whole run was
        refused for one reason — a wrong sender ID, an empty wallet, or a token issue — rather than
        <?= number_format((int) $open['total_recipients']) ?> separate problems.
      </div>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:18px 0 8px;">The message</h3>
    <div style="background:#0f0d1f;border:1px solid var(--border);border-radius:12px;padding:14px 16px;max-width:520px;">
      <div style="font-size:11px;color:var(--ink-faint);margin-bottom:8px;">From <strong style="color:var(--gold-soft);"><?= e((string) $open['sender_id']) ?></strong></div>
      <div style="white-space:pre-wrap;font-size:13.5px;line-height:1.5;"><?= e((string) $open['message']) ?></div>
    </div>

    <div class="btn-row" style="margin-top:18px;">
      <a class="btn secondary" href="<?= e($tabUrl) ?>&amp;action=export&amp;id=<?= (int) $open['id'] ?>">⬇ Export the full report</a>

      <?php if ($isActive): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this campaign? Everything not yet sent will be skipped.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
          <button class="btn danger" type="submit">Cancel the campaign</button>
        </form>
      <?php endif; ?>

      <?php if (in_array($status, ['failed', 'partial', 'cancelled'], true) && $pendingLeft > 0): ?>
        <form method="post" style="display:inline;">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="resume"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
          <button class="btn" type="submit">Resume — send the remaining <?= number_format($pendingLeft) ?></button>
        </form>
      <?php endif; ?>

      <?php if ((int) $open['failed_count'] > 0): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Put the failed recipients back in the queue? They will be charged again when they go out.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="resend_failures"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
          <button class="btn secondary" type="submit">Resend to the <?= number_format((int) $open['failed_count']) ?> failures</button>
        </form>
      <?php endif; ?>

      <?php if ($isActive && (int) ($openCounters['pending'] ?? 0) > 0): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Release the claims on this campaign back to pending? Only do this if you are certain the worker is not still running.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="release"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
          <button class="btn secondary" type="submit">Release stuck claims</button>
        </form>
      <?php endif; ?>

      <?php if (!$isActive): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this campaign and its recipient list? The gateway logs are kept.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
          <button class="btn danger" type="submit">Delete</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($attempted === 0): ?>
      <div class="alert success" style="background:#e8b95f14;border-color:#e8b95f44;color:#f0d9a4;margin-top:16px;">
        <?php if ($isActive): ?>
          The gateway answers one code for a whole batch, not one per number. Until a batch has gone out,
          this campaign reads zero because nothing has been attempted yet.
        <?php else: ?>
          Nothing in this campaign produced a reply from the gateway at all. Check the Diagnostics panel on
          the dashboard — the reason will be in the log.
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Recipients<?= $recipientFilter !== '' ? ' — ' . e($recipientFilter) : '' ?></h2>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
      <a class="badge info" style="text-decoration:none;" href="<?= e($tabUrl) ?>&amp;id=<?= (int) $open['id'] ?>">All: <?= number_format(array_sum($openCounters)) ?></a>
      <?php foreach (['sent' => 'ok', 'failed' => 'fail', 'pending' => 'warn', 'skipped' => ''] as $key => $badgeKey): ?>
        <?php if (($openCounters[$key] ?? 0) > 0): ?>
          <a class="badge <?= e($badgeKey) ?>" style="text-decoration:none;"
             href="<?= e($tabUrl) ?>&amp;id=<?= (int) $open['id'] ?>&amp;recipients=<?= e($key) ?>"><?= e(ucfirst($key)) ?>: <?= number_format((int) $openCounters[$key]) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php if (!$openRecipients): ?>
      <div class="empty">No recipients in that category.</div>
    <?php else: ?>
      <table class="resp-table">
        <tr><th>Number</th><th>Name</th><th>Status</th><th>Units</th><th>Failure</th><th>Tries</th><th>Sent</th></tr>
        <?php foreach ($openRecipients as $recipient): ?>
          <?php $recipientStatus = (string) $recipient['status']; ?>
          <tr>
            <td><code><?= e(Sms::prettyMsisdn((string) $recipient['msisdn'])) ?></code></td>
            <td><?= e((string) ($recipient['name'] ?: '—')) ?></td>
            <td><span class="badge <?= $statusBadge($recipientStatus === 'sent' ? 'sent' : $recipientStatus) ?>"><?= e($recipientStatus) ?></span></td>
            <td><?= (int) $recipient['units'] ?></td>
            <td style="max-width:260px;">
              <?php if (!empty($recipient['error_code'])): ?>
                <small style="color:var(--danger);">
                  <?= (int) $recipient['error_code'] !== 0 ? '<strong>' . e((string) $recipient['error_code']) . '</strong> — ' : '' ?>
                  <?= e(Sms::errorMessage((string) $recipient['error_code'])) ?>
                  <?php if (!empty($recipient['error_note'])): ?>
                    <br><?= e((string) $recipient['error_note']) ?>
                  <?php endif; ?>
                </small>
              <?php endif; ?>
            </td>
            <td><?= (int) $recipient['attempts'] ?></td>
            <td><small><?= !empty($recipient['sent_at']) ? e(timeAgo((string) $recipient['sent_at'])) : '—' ?></small></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if (array_sum($openCounters) > count($openRecipients)): ?>
        <p class="sub" style="font-size:12px;margin-top:10px;">
          Showing <?= number_format(count($openRecipients)) ?> of <?= number_format(array_sum($openCounters)) ?>.
          Export the report for the complete list.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h2>Campaign history</h2>
      <p class="sub" style="margin:0;"><strong><?= number_format($total) ?></strong> campaign(s)<?= $statusFilter !== '' ? ' with status ' . e($statusFilter) : '' ?>.</p>
    </div>
    <a class="btn" href="<?= e($tabUrl) ?>&amp;tab=compose" onclick="this.href='/admin/sms?tab=compose';return true;">✉️ New campaign</a>
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:8px;margin:14px 0;">
    <a class="badge <?= $statusFilter === '' ? 'info' : '' ?>" style="text-decoration:none;" href="<?= e($tabUrl) ?>">All</a>
    <?php foreach (SmsCampaign::STATUSES as $option): ?>
      <a class="badge <?= $statusFilter === $option ? 'info' : '' ?>" style="text-decoration:none;"
         href="<?= e($tabUrl) ?>&amp;status=<?= e($option) ?>"><?= e(ucfirst($option)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$campaigns): ?>
    <div class="empty">No campaigns yet. Nothing has been sent from this system.</div>
  <?php else: ?>
    <table class="resp-table">
      <tr><th>Title</th><th>Status</th><th>Recipients</th><th>Sent</th><th>Failed</th><th>Units</th><th>When</th><th></th></tr>
      <?php foreach ($campaigns as $campaign): ?>
        <?php $rowStatus = (string) $campaign['status']; ?>
        <tr>
          <td>
            <a href="<?= e($tabUrl) ?>&amp;id=<?= (int) $campaign['id'] ?>" style="color:var(--gold-soft);">
              <?= e(mb_strimwidth((string) $campaign['title'], 0, 44, '…')) ?>
            </a>
            <?php if ((int) $campaign['failed_count'] > 0): ?>
              <div><small style="color:var(--danger);"><?= number_format((int) $campaign['failed_count']) ?> failed</small></div>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $statusBadge($rowStatus) ?>"><?= e($rowStatus) ?></span></td>
          <td><?= number_format((int) $campaign['total_recipients']) ?></td>
          <td><?= number_format((int) $campaign['sent_count']) ?></td>
          <td><?= (int) $campaign['failed_count'] > 0 ? '<span style="color:var(--danger);">' . number_format((int) $campaign['failed_count']) . '</span>' : '0' ?></td>
          <td><?= number_format((int) $campaign['units_charged']) ?></td>
          <td><small><?= e(timeAgo((string) $campaign['created_at'])) ?></small></td>
          <td><a class="btn secondary sm" href="<?= e($tabUrl) ?>&amp;id=<?= (int) $campaign['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <?php if ($pages > 1): ?>
      <div class="btn-row" style="margin-top:14px;">
        <?php if ($page > 1): ?>
          <a class="btn secondary sm" href="<?= e($tabUrl) ?>&amp;status=<?= e($statusFilter) ?>&amp;page=<?= $page - 1 ?>">← Previous</a>
        <?php endif; ?>
        <span style="align-self:center;font-size:12.5px;color:var(--ink-faint);">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?>
          <a class="btn secondary sm" href="<?= e($tabUrl) ?>&amp;status=<?= e($statusFilter) ?>&amp;page=<?= $page + 1 ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
