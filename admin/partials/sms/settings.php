<?php
declare(strict_types=1);

/**
 * SMS → Settings. Super admin only.
 *
 * The API token is handled carefully: it is stored encrypted, shown only masked, and the
 * form will not overwrite it with a blank value — so an admin can change the quiet hours
 * without having to re-paste a secret they cannot read back.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
if (!$isSuper) {
    http_response_code(403);
    exit('Only the super admin can change SMS settings.');
}

$action = (string) ($_GET['action'] ?? '');
$errors = [];

// --- Clear the stored token -----------------------------------------------------------------
if ($action === 'clear_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    settingSave(['sms_token' => '']);
    flash('success', 'The API token has been removed. Nothing can be sent until a new one is added.');
    redirect('/admin/sms?tab=settings');
}

// --- Test the connection --------------------------------------------------------------------
if ($action === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    // Test against the value in the box if one was typed, so an admin can check a new token
    // before committing to it.
    $typed = trim((string) ($_POST['sms_token'] ?? ''));
    $usingTyped = false;
    if ($typed !== '') {
        settingSave(['sms_token' => encryptSecret($typed)]);
        $usingTyped = true;
    }

    $result = Sms::balance();
    if ($result['ok']) {
        $balance = $result['balance'];
        flash('success', 'Connection OK — the wallet balance is '
            . ($balance !== null ? number_format($balance) . ' unit(s)' : 'reported, but the gateway gave no number')
            . ($usingTyped ? '. The token you typed has been saved.' : '.'));
    } else {
        flash('error', 'Connection failed: ' . (string) ($result['error'] ?? 'unknown error'));
    }
    redirect('/admin/sms?tab=settings');
}

// --- Save -----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
    Csrf::requireValid();

    $values = [
        'sms_default_country' => preg_replace('/\D/', '', (string) ($_POST['sms_default_country'] ?? '234')) ?: '234',
        'sms_default_sender_id' => strtoupper(trim((string) ($_POST['sms_default_sender_id'] ?? ''))),
        'sms_sender_display_name' => trim((string) ($_POST['sms_sender_display_name'] ?? '')),
        'sms_quiet_start' => max(0, min(23, (int) ($_POST['sms_quiet_start'] ?? 7))),
        'sms_quiet_end' => max(0, min(23, (int) ($_POST['sms_quiet_end'] ?? 20))),
        'sms_daily_unit_cap' => max(0, (int) ($_POST['sms_daily_unit_cap'] ?? 0)),
        'sms_sender_cap' => max(0, (int) ($_POST['sms_sender_cap'] ?? 0)),
        'sms_batch_size' => max(1, min(1000, (int) ($_POST['sms_batch_size'] ?? 100))),
        'sms_allow_unit_sending' => isset($_POST['sms_allow_unit_sending']) ? 1 : 0,
        'sms_optout_footer' => trim((string) ($_POST['sms_optout_footer'] ?? '')),
        'sms_log_retention_days' => max(1, min(3650, (int) ($_POST['sms_log_retention_days'] ?? 30))),
    ];

    if ($values['sms_default_sender_id'] !== '' && Sms::senderIdProblem($values['sms_default_sender_id']) !== null) {
        $errors[] = 'The default sender ID is not usable: ' . Sms::senderIdProblem($values['sms_default_sender_id']);
    }
    if ($values['sms_default_country'] !== '' && !isset(Sms::countries()[$values['sms_default_country']])) {
        $errors[] = 'That country is not in the country list.';
    }

    // Only replace the token when something was actually typed.
    $typed = trim((string) ($_POST['sms_token'] ?? ''));
    if ($typed !== '') {
        $values['sms_token'] = encryptSecret($typed);
    }

    if ($errors === []) {
        settingSave($values);
        flash('success', 'SMS settings saved.');
        redirect('/admin/sms?tab=settings');
    }
}

// --- Read -----------------------------------------------------------------------------------
$tokenSet = Sms::configured();
$countries = Sms::countries();
ksort($countries, SORT_NUMERIC);

$approvedSenders = [];
try {
    $tenantId = Tenant::id();
    if ($tenantId === null) {
        $stmt = $pdo->query("SELECT sender_id FROM sms_senders WHERE status = 'approved' AND tenant_id IS NULL ORDER BY is_default DESC, sender_id ASC");
    } else {
        $stmt = $pdo->prepare("SELECT sender_id FROM sms_senders WHERE status = 'approved' AND tenant_id = ? ORDER BY is_default DESC, sender_id ASC");
        $stmt->execute([$tenantId]);
    }
    $approvedSenders = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // The Sender IDs tab will explain if the table is missing.
}

$lastError = null;
try {
    $lastError = $pdo->query("SELECT endpoint, error_code, error_note, created_at FROM sms_messages_log WHERE error_code IS NOT NULL AND error_code != '000' ORDER BY id DESC LIMIT 1")->fetch();
} catch (Throwable $e) {
    // No log table yet.
}
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2>Gateway connection</h2>
  <p class="sub">
    Your provider's API token. It is stored encrypted and never shown again in full — the masked
    value below is the only thing this screen will ever display, and it is never written to the logs.
  </p>

  <form method="post" action="/admin/sms?tab=settings">
    <?= Csrf::field() ?>
    <label for="sms_token">API token <?= $tokenSet ? '<span class="badge ok">configured</span>' : '<span class="badge fail">not set</span>' ?></label>
    <input type="password" id="sms_token" name="sms_token" autocomplete="new-password"
           placeholder="<?= $tokenSet ? e(Sms::maskedToken()) : 'Paste the API token from your SMS provider' ?>">
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      Leave this blank to keep the token that is already stored.
    </small>

    <div class="row two">
      <div>
        <label for="sms_default_country">Default country</label>
        <select id="sms_default_country" name="sms_default_country">
          <?php foreach ($countries as $code => $country): ?>
            <option value="<?= e((string) $code) ?>" <?= (string) setting('sms_default_country', '234') === (string) $code ? 'selected' : '' ?>>
              <?= e((string) $country['name']) ?> (+<?= e((string) $code) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--ink-faint);font-size:12px;">Used when a number is written in local form, e.g. 0803…</small>
      </div>
      <div>
        <label for="sms_default_sender_id">Default sender ID</label>
        <select id="sms_default_sender_id" name="sms_default_sender_id">
          <option value="">— none —</option>
          <?php foreach ($approvedSenders as $sender): ?>
            <option value="<?= e((string) $sender) ?>" <?= setting('sms_default_sender_id') === $sender ? 'selected' : '' ?>><?= e((string) $sender) ?></option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--ink-faint);font-size:12px;">
          Only approved sender IDs appear here.
          <a href="/admin/sms?tab=sender-ids" style="color:var(--gold-soft);">Manage sender IDs</a>
        </small>
      </div>
    </div>

    <label for="sms_sender_display_name">Sender display name <small style="color:var(--ink-faint);">(optional)</small></label>
    <input type="text" id="sms_sender_display_name" name="sms_sender_display_name" maxlength="60"
           value="<?= e((string) setting('sms_sender_display_name', '')) ?>" placeholder="e.g. RCCG LP63 YAYA">
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      Shown on the handset where the network supports it. Some networks ignore it.
    </small>

    <div class="btn-row">
      <button class="btn" type="submit">Save settings</button>
      <button class="btn secondary" type="submit" formaction="/admin/sms?action=test&amp;tab=settings">🔌 Test connection</button>
    </div>
  </form>

  <?php if ($tokenSet): ?>
    <form method="post" action="/admin/sms?action=clear_token&amp;tab=settings" onsubmit="return confirm('Remove the stored API token? Nothing will be able to send until a new one is added.');" style="margin-top:12px;">
      <?= Csrf::field() ?>
      <button class="btn danger sm" type="submit">Remove the stored token</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Sending rules</h2>
  <form method="post" action="/admin/sms?tab=settings">
    <?= Csrf::field() ?>

    <div class="row two">
      <div>
        <label for="sms_quiet_start">Send between</label>
        <select id="sms_quiet_start" name="sms_quiet_start">
          <?php for ($h = 0; $h <= 23; $h++): ?>
            <option value="<?= $h ?>" <?= (int) setting('sms_quiet_start', 7) === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label for="sms_quiet_end">and</label>
        <select id="sms_quiet_end" name="sms_quiet_end">
          <?php for ($h = 0; $h <= 23; $h++): ?>
            <option value="<?= $h ?>" <?= (int) setting('sms_quiet_end', 20) === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      The worker will not send outside this window; campaigns stay queued and resume on their own.
      A window that wraps past midnight works, e.g. 20:00 to 07:00. Setting both to the same hour removes the restriction.
    </small>

    <div class="row two">
      <div>
        <label for="sms_daily_unit_cap">Daily unit cap</label>
        <input type="number" id="sms_daily_unit_cap" name="sms_daily_unit_cap" min="0" max="1000000"
               value="<?= (int) setting('sms_daily_unit_cap', 0) ?>">
        <small style="color:var(--ink-faint);font-size:12px;">A ceiling on units per day. <strong>0</strong> means no cap.</small>
      </div>
      <div>
        <label for="sms_sender_cap">Sender IDs per church</label>
        <input type="number" id="sms_sender_cap" name="sms_sender_cap" min="0" max="100"
               value="<?= (int) setting('sms_sender_cap', 0) ?>">
        <small style="color:var(--ink-faint);font-size:12px;">How many a church may register. <strong>0</strong> means no limit.</small>
      </div>
    </div>

    <div class="row two">
      <div>
        <label for="sms_batch_size">Recipients per gateway call</label>
        <input type="number" id="sms_batch_size" name="sms_batch_size" min="1" max="1000"
               value="<?= (int) setting('sms_batch_size', 100) ?>">
        <small style="color:var(--ink-faint);font-size:12px;">Higher is faster; lower is gentler on a slow host. 100 is a good default.</small>
      </div>
      <div>
        <label for="sms_log_retention_days">Keep gateway logs for</label>
        <input type="number" id="sms_log_retention_days" name="sms_log_retention_days" min="1" max="3650"
               value="<?= (int) setting('sms_log_retention_days', 30) ?>">
        <small style="color:var(--ink-faint);font-size:12px;">Days. Deleted by the daily housekeeping job below — until that job is on your cron, nothing is pruned. The logs hold no token, only what was sent and what came back.</small>
      </div>
    </div>

    <label for="sms_optout_footer">Opt-out footer</label>
    <input type="text" id="sms_optout_footer" name="sms_optout_footer" maxlength="160"
           value="<?= e((string) setting('sms_optout_footer', 'Reply STOP to opt out.')) ?>">
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      Appended to every campaign message. It is added to the segment count, so it can push a message
      over into a second unit — the composer shows the true total.
    </small>

    <div class="checkbox-row">
      <input type="checkbox" id="sms_allow_unit_sending" name="sms_allow_unit_sending" value="1"
             <?= (int) setting('sms_allow_unit_sending', 1) === 1 ? 'checked' : '' ?>>
      <label for="sms_allow_unit_sending" style="margin:0;">Let churches send their own messages</label>
    </div>
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:14px;">
      Turn this off to centralise sending at head office. Churches keep their address books either way —
      only the send button is withdrawn.
    </small>

    <button class="btn" type="submit">Save sending rules</button>
  </form>
</div>

<div class="card">
  <h2>Gateway diagnostics</h2>
  <?php if ($lastError === false || $lastError === null): ?>
    <div class="empty">No errors have been recorded. Good.</div>
  <?php else: ?>
    <p class="sub">The most recent failure, so you can tell a wrong token from a blocked word.</p>
    <table>
      <tr><th>When</th><th>Call</th><th>Code</th><th>What it means</th></tr>
      <tr>
        <td><?= e(date('M j, Y g:i A', (int) strtotime((string) $lastError['created_at']))) ?></td>
        <td><code><?= e((string) $lastError['endpoint']) ?></code></td>
        <td><span class="badge fail"><?= e((string) ($lastError['error_code'] ?? '')) ?></span></td>
        <td><?= e(Sms::errorMessage((string) $lastError['error_code'])) ?></td>
      </tr>
    </table>
  <?php endif; ?>

  <h3 style="font-size:14px;margin:18px 0 8px;">Scheduling the worker</h3>
  <p class="sub" style="font-size:13px;">
    Nothing sends without the queue worker running. In cPanel → Cron Jobs add these entries.
    Replace the PHP path with the one shown on the main Settings page for your media worker.
  </p>
  <pre style="background:#0f0d1f;border:1px solid var(--border);border-radius:10px;padding:14px;overflow:auto;font-size:12.5px;">* * * * * php <?= e(ROOT_PATH) ?>/cli/sms_worker.php --quiet
*/15 * * * * php <?= e(ROOT_PATH) ?>/cli/sms_sender_check.php
15 3 * * * php <?= e(ROOT_PATH) ?>/cli/sms_maintenance.php --quiet</pre>
  <p class="sub" style="font-size:12.5px;">
    The third one is what actually enforces the log retention below — without it, gateway and
    wallet log rows accumulate forever. It also releases claims abandoned by a worker that
    stopped mid-batch. It never deletes a campaign or a recipient, so your send history is safe.
  </p>
</div>
