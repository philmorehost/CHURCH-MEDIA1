<?php
declare(strict_types=1);

/**
 * SMS → Dashboard.
 *
 * Deliberately reads the *last recorded* wallet balance rather than calling the gateway on
 * every page load: a balance check is a network round trip, and a dashboard that stalls for
 * two seconds every time it is opened is a dashboard nobody opens. The button refreshes it.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$tenantId = Tenant::id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'refresh_balance') {
    Csrf::requireValid();
    if (!Sms::configured()) {
        flash('error', 'No API token is configured yet, so the balance cannot be read.');
    } else {
        $result = Sms::balance();
        if ($result['ok'] && $result['balance'] !== null) {
            flash('success', 'Wallet balance: ' . number_format((float) $result['balance']) . ' unit(s).');
        } elseif ($result['ok']) {
            flash('error', 'The gateway answered but did not report a balance.');
        } else {
            flash('error', 'Could not read the balance: ' . (string) ($result['error'] ?? 'unknown error'));
        }
    }
    redirect('/admin/sms?tab=dashboard');
}

/* ------------------------------------------------------------------ the numbers */

$contacts = SmsContacts::count(['scope_unit_ids' => $scopeUnitIds]);
$optedOut = SmsContacts::count(['scope_unit_ids' => $scopeUnitIds, 'opted_out' => true]);
$sourceCounts = SmsContacts::countsBySource($scopeUnitIds);

// Sent and failed over the last 30 days, from the queue rather than a counter.
$sent30 = 0;
$failed30 = 0;
$units30 = 0;
$unitsMonth = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT
            SUM(status = 'sent') AS sent,
            SUM(status = 'failed') AS failed,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN units ELSE 0 END), 0) AS units
         FROM sms_campaign_recipients
         WHERE created_at >= (NOW() - INTERVAL 30 DAY)"
    );
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    $sent30 = (int) ($row['sent'] ?? 0);
    $failed30 = (int) ($row['failed'] ?? 0);
    $units30 = (int) ($row['units'] ?? 0);

    // This calendar month, for a "what have we spent" figure.
    $month = $pdo->query("SELECT COALESCE(SUM(units), 0) FROM sms_campaign_recipients WHERE status = 'sent' AND sent_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
    $unitsMonth = (int) $month;
} catch (Throwable $e) {
    // The queue table is missing; the tiles read zero.
}

$attempts30 = $sent30 + $failed30;
$successRate = $attempts30 > 0 ? (int) round($sent30 / $attempts30 * 100) : null;

$balance = null;
$balanceAt = null;
try {
    $row = $pdo->query('SELECT balance, created_at FROM sms_wallet_log WHERE balance IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
    if ($row) {
        $balance = (float) $row['balance'];
        $balanceAt = (string) $row['created_at'];
    }
} catch (Throwable $e) {
    // No wallet log yet.
}

$recent = [];
try {
    $recent = $pdo->query(
        'SELECT id, title, status, total_recipients, sent_count, failed_count, units_charged, created_at, paused_reason
         FROM sms_campaigns ORDER BY created_at DESC LIMIT 6'
    )->fetchAll();
} catch (Throwable $e) {
    // No campaigns yet.
}

$queuedCount = 0;
$pendingRecipients = 0;
try {
    $queuedCount = count(SmsCampaign::active(50));
    foreach (SmsCampaign::active(50) as $activeCampaign) {
        $pendingRecipients += SmsCampaign::pendingCount((int) $activeCampaign['id']);
    }
} catch (Throwable $e) {
    // Nothing queued.
}

$lastError = null;
try {
    $lastError = $pdo->query("SELECT endpoint, error_code, error_note, created_at FROM sms_messages_log WHERE error_code IS NOT NULL AND error_code != '000' ORDER BY id DESC LIMIT 1")->fetch();
} catch (Throwable $e) {
    // No log table.
}

$sendersPending = 0;
$sendersApproved = 0;
try {
    $stmt = $pdo->prepare("SELECT SUM(status = 'pending') AS pending, SUM(status = 'approved') AS approved FROM sms_senders WHERE tenant_id <=> ?");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch() ?: [];
    $sendersPending = (int) ($row['pending'] ?? 0);
    $sendersApproved = (int) ($row['approved'] ?? 0);
} catch (Throwable $e) {
    // No sender table.
}

$dailyCap = SmsCampaign::dailyCap();
$sentToday = SmsCampaign::unitsSentToday();
$withinHours = SmsCampaign::withinQuietHours();
?>

<?php if (!$withinHours): ?>
  <div class="alert success" style="background:#6fb3ff18;border-color:#6fb3ff44;color:#cfe4ff;">
    Outside the sending window (<?= e(SmsCampaign::quietHoursLabel()) ?>). Campaigns are queued and will
    resume on their own — nothing is lost.
  </div>
<?php endif; ?>

<?php if ($dailyCap > 0 && $sentToday >= $dailyCap): ?>
  <div class="alert error">
    Today's cap of <?= number_format($dailyCap) ?> unit(s) has been reached. Sending resumes tomorrow.
  </div>
<?php endif; ?>

<?php if ($balance !== null && $balance <= 50): ?>
  <div class="alert error">
    The last recorded wallet balance is only <strong><?= number_format($balance) ?> unit(s)</strong>.
    Top up before scheduling anything large — a campaign that runs out mid-send pauses itself, but it
    still stops partway.
  </div>
<?php endif; ?>

<div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px;">
  <div class="stat">
    <div class="num"><?= $balance !== null ? number_format($balance) : '—' ?></div>
    <div class="label">
      Wallet balance
      <?php if ($balanceAt !== null): ?><br><small style="color:var(--ink-faint);">as of <?= e(timeAgo($balanceAt)) ?></small><?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="num"><?= number_format($contacts) ?></div>
    <div class="label">Contacts<?= $optedOut > 0 ? '<br><small style="color:var(--ink-faint);">' . number_format($optedOut) . ' opted out</small>' : '' ?></div>
  </div>
  <div class="stat">
    <div class="num"><?= number_format($unitsMonth) ?></div>
    <div class="label">Units this month</div>
  </div>
  <div class="stat">
    <div class="num"><?= $successRate !== null ? $successRate . '%' : '—' ?></div>
    <div class="label">
      Accepted by the gateway (30 days)
      <?php if ($attempts30 > 0): ?><br><small style="color:var(--ink-faint);"><?= number_format($sent30) ?> of <?= number_format($attempts30) ?></small><?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="num"><?= number_format($sentToday) ?></div>
    <div class="label">
      Units sent today
      <?php if ($dailyCap > 0): ?><br><small style="color:var(--ink-faint);">of <?= number_format($dailyCap) ?> allowed</small><?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h2>Wallet</h2>
      <p class="sub" style="max-width:620px;">
        A balance is read from your provider on demand. One unit covers one segment — a message
        over 160 characters, or one containing a character outside the standard alphabet, costs more
        than one. The composer shows the true count before you send.
      </p>
    </div>
    <form method="post" style="margin:0;">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="refresh_balance">
      <button class="btn" type="submit">Check balance now</button>
    </form>
  </div>

  <?php if ($units30 > 0): ?>
    <p class="sub" style="font-size:13px;margin-top:14px;">
      In the last 30 days this account sent <strong><?= number_format($units30) ?></strong> unit(s) to
      <strong><?= number_format($sent30) ?></strong> recipient(s)<?= $failed30 > 0 ? ', with ' . number_format($failed30) . ' the gateway refused' : '' ?>.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Queue</h2>
  <?php if ($queuedCount === 0): ?>
    <div class="empty">Nothing is waiting to send.</div>
  <?php else: ?>
    <p class="sub">
      <strong><?= number_format($queuedCount) ?></strong> campaign(s) in progress with
      <strong><?= number_format($pendingRecipients) ?></strong> recipient(s) still to send.
      <?php if (!$withinHours): ?>They will resume when the sending window opens.<?php endif; ?>
    </p>
    <p class="sub" style="font-size:12px;">
      The worker runs from cron. If this number is not moving, check that the cron entry on the
      Settings tab is actually in place.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Sender IDs</h2>
  <p class="sub">
    <?php if ($sendersApproved === 0 && $sendersPending === 0): ?>
      No sender ID has been submitted yet, so nothing can be sent.
      <a href="/admin/sms?tab=sender-ids" style="color:var(--gold-soft);">Submit one now</a>.
    <?php else: ?>
      <?= number_format($sendersApproved) ?> approved<?= $sendersPending > 0 ? ', ' . number_format($sendersPending) . ' awaiting the gateway' : '' ?>.
      <a href="/admin/sms?tab=sender-ids" style="color:var(--gold-soft);">Manage sender IDs</a>
    <?php endif; ?>
  </p>
</div>

<?php if ($recent): ?>
<div class="card">
  <h2>Recent campaigns</h2>
  <table class="resp-table">
    <tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Failed</th><th>Units</th><th>Created</th></tr>
    <?php foreach ($recent as $campaignRow): ?>
      <?php
        $status = (string) $campaignRow['status'];
        $badge = in_array($status, ['sent'], true) ? 'ok' : (in_array($status, ['failed'], true) ? 'fail' : (in_array($status, ['partial', 'cancelled'], true) ? 'warn' : 'info'));
      ?>
      <tr>
        <td><a href="/admin/sms?tab=campaigns&amp;id=<?= (int) $campaignRow['id'] ?>" style="color:var(--gold-soft);"><?= e(mb_strimwidth((string) $campaignRow['title'], 0, 46, '…')) ?></a></td>
        <td><span class="badge <?= $badge ?>"><?= e($status) ?></span></td>
        <td><?= number_format((int) $campaignRow['sent_count']) ?></td>
        <td><?= (int) $campaignRow['failed_count'] > 0 ? '<span style="color:var(--danger);">' . number_format((int) $campaignRow['failed_count']) . '</span>' : '0' ?></td>
        <td><?= number_format((int) $campaignRow['units_charged']) ?></td>
        <td><small><?= e(timeAgo((string) $campaignRow['created_at'])) ?></small></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($sourceCounts): ?>
<div class="card">
  <h2>Where your contacts came from</h2>
  <div style="display:flex;flex-wrap:wrap;gap:8px;">
    <?php foreach ($sourceCounts as $source => $count): ?>
      <span class="badge info"><?= e(SmsContacts::sourceLabel((string) $source)) ?>: <?= number_format((int) $count) ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Diagnostics</h2>
  <?php if (!$lastError): ?>
    <p class="sub">No gateway errors have been recorded. Nothing to fix.</p>
  <?php else: ?>
    <table>
      <tr><th>When</th><th>Call</th><th>Code</th><th>What it means</th></tr>
      <tr>
        <td><?= e(timeAgo((string) $lastError['created_at'])) ?></td>
        <td><code><?= e((string) $lastError['endpoint']) ?></code></td>
        <td><span class="badge fail"><?= e((string) ($lastError['error_code'] ?? '')) ?></span></td>
        <td><?= e(Sms::errorMessage((string) $lastError['error_code'])) ?></td>
      </tr>
    </table>
  <?php endif; ?>
</div>
