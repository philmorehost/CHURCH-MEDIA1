<?php
declare(strict_types=1);

/**
 * WhatsApp → Broadcast.
 *
 * A broadcast is a template sent to a set of people. Two things shape this screen, and both come
 * from how WhatsApp actually works rather than from how SMS works:
 *
 * **There is no free-text broadcast.** A broadcast goes to people who have not messaged recently,
 * so the 24-hour window is closed for essentially all of them and the only thing Meta will accept
 * is an approved template. So the composer offers templates only, and says so.
 *
 * **Consent is opt-in.** The audience resolvers come from Phase 2 — one audience, two channels —
 * but SMS-opted-out contacts are excluded *and* WhatsApp requires a recorded opt-in. What was
 * skipped, and why, is kept per recipient so the report can say "sent to 120 of 400" and explain
 * the other 280 rather than quietly losing them.
 */

$user = $waContext['user'];
$isSuper = (bool) $waContext['is_super'];
$scopeUnitIds = (array) $waContext['scope_unit_ids'];
$unitLabels = (array) $waContext['unit_labels'];
$userId = (int) $user['id'];
$myUnitId = (int) $waContext['my_unit_id'];

$broadcastAllowed = $isSuper || (int) setting('wa_allow_unit_broadcast', 1) === 1;
$broadcastAllowed = $broadcastAllowed && ($isSuper || $myUnitId > 0);

/** The same audience resolvers the SMS composer uses. One audience, two channels. */
$resolveAudience = static function (array $input) use ($scopeUnitIds): array {
    $kind = (string) ($input['audience'] ?? 'all');
    $recipients = [];
    $label = '';

    $push = static function (array $rows, array &$recipients): void {
        foreach ($rows as $row) {
            $recipients[(string) $row['msisdn']] = [
                'msisdn' => (string) $row['msisdn'],
                'name' => (string) ($row['name'] ?? ''),
                'contact_id' => isset($row['id']) ? (int) $row['id'] : null,
            ];
        }
    };

    if ($kind === 'group') {
        $group = SmsContacts::findGroup((int) ($input['group_id'] ?? 0));
        if ($group === null) {
            return ['recipients' => [], 'label' => 'No such group'];
        }
        $groupUnit = (int) ($group['org_unit_id'] ?? 0);
        if ($scopeUnitIds !== [] && $groupUnit > 0 && !in_array($groupUnit, array_map('intval', $scopeUnitIds), true)) {
            return ['recipients' => [], 'label' => 'No such group'];
        }
        $ids = SmsContacts::groupAudience($group, $scopeUnitIds);
        $rows = $ids === [] ? [] : SmsContacts::filter(['ids' => $ids, 'scope_unit_ids' => $scopeUnitIds, 'opted_out' => false], 20000);
        $push($rows, $recipients);
        $label = 'Group: ' . $group['name'];
    } elseif ($kind === 'unit') {
        $unitId = (int) ($input['unit_id'] ?? 0);
        $rows = SmsContacts::audienceFromUnit($unitId, !empty($input['unit_subtree']), $scopeUnitIds);
        $push($rows, $recipients);
        $label = 'Unit: ' . (Unit::label($unitId) ?: ('unit ' . $unitId))
            . (!empty($input['unit_subtree']) ? ' and everything beneath it' : ' only');
    } else {
        $rows = SmsContacts::filter(['scope_unit_ids' => $scopeUnitIds, 'opted_out' => false], 20000);
        $push($rows, $recipients);
        $label = $scopeUnitIds === [] ? 'Every contact' : 'Every contact in your churches';
    }

    return ['recipients' => array_values($recipients), 'label' => $label];
};

/* --------------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        if (!$broadcastAllowed) {
            flash('error', 'Broadcasting is switched off for your church.');
            redirect('/admin/whatsapp?tab=broadcast');
        }

        $problem = WhatsApp::problem();
        if ($problem !== null) {
            // Allowed to build the campaign while the channel is off — it simply will not send
            // until the setting is fixed. Refusing to create it would lose the work.
            flash('error', 'Saved, but nothing will send yet: ' . $problem);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        $rawParams = (string) ($_POST['params'] ?? '');
        $params = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $rawParams) ?: []),
            static fn (string $v): bool => $v !== ''
        ));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Give the broadcast a name.';
        }
        if ($templateName === '') {
            $errors[] = 'Choose a template.';
        }

        $template = null;
        if ($templateName !== '') {
            $stmt = $pdo->prepare('SELECT * FROM wa_templates WHERE name = ? AND status = "approved" ORDER BY id LIMIT 1');
            $stmt->execute([$templateName]);
            $template = $stmt->fetch() ?: null;
            if ($template === null) {
                // Sending an unapproved template is refused by Meta. Better to stop here.
                $errors[] = 'That template is not recorded as approved, so it cannot be sent.';
            }
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/admin/whatsapp?tab=broadcast');
        }

        $audience = $resolveAudience($_POST);
        if (!$audience['recipients']) {
            flash('error', 'That audience has nobody in it.');
            redirect('/admin/whatsapp?tab=broadcast');
        }

        $pdo->prepare(
            'INSERT INTO wa_campaigns (tenant_id, name, template_name, template_language, org_unit_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, "draft", ?)'
        )->execute([
            class_exists('Tenant') ? Tenant::id() : null,
            mb_substr($name, 0, 150),
            $templateName,
            (string) ($template['language'] ?? WhatsApp::defaultLanguage()),
            $isSuper ? null : $myUnitId,
            $userId,
        ]);
        $campaignId = (int) $pdo->lastInsertId();

        $stats = WaCampaign::queueRecipients($campaignId, $audience['recipients'], $params);

        $summary = sprintf('Queued %d of %d.', $stats['queued'], $stats['total']);
        if ($stats['skipped_optout'] > 0) {
            $summary .= ' ' . $stats['skipped_optout'] . ' had opted out.';
        }
        if ($stats['skipped_invalid'] > 0) {
            $summary .= ' ' . $stats['skipped_invalid'] . ' had never opted in to WhatsApp.';
        }
        if ($stats['already'] > 0) {
            $summary .= ' ' . $stats['already'] . ' were already in it.';
        }

        flash($stats['queued'] > 0 ? 'success' : 'error', $summary . ' ' . $audience['label'] . '.');
        redirect('/admin/whatsapp?tab=broadcast&campaign=' . $campaignId);
    }

    $targetId = (int) ($_POST['campaign_id'] ?? 0);
    $owned = true;
    if (!$isSuper && $targetId > 0) {
        // A scoped admin may only act on their own church's campaigns. Checked before any change.
        $check = $pdo->prepare('SELECT COUNT(*) FROM wa_campaigns WHERE id = ? AND (org_unit_id IS NULL OR org_unit_id = ?)');
        $check->execute([$targetId, $myUnitId]);
        $owned = (int) $check->fetchColumn() > 0;
    }
    if (!$owned) {
        flash('error', 'That broadcast is not available to your account.');
        redirect('/admin/whatsapp?tab=broadcast');
    }

    switch ($action) {
        case 'pause':
            WaCampaign::pause($targetId, 'Paused by ' . (string) $user['name']);
            flash('success', 'Broadcast paused. Anything already sent stays sent.');
            break;
        case 'resume':
            flash(WaCampaign::resume($targetId) ? 'success' : 'error', WaCampaign::resume($targetId) ? 'Broadcast resumed.' : 'That broadcast is not paused.');
            break;
        case 'cancel':
            WaCampaign::cancel($targetId);
            flash('success', 'Broadcast cancelled. Unsent recipients were dropped.');
            break;
        case 'retry':
            // 131047 is "re-engagement message": the window had closed. Retrying that without
            // changing the template just fails again.
            $count = WaCampaign::reopenFailures($targetId, ['131047', '131026']);
            flash($count > 0 ? 'success' : 'error', $count > 0
                ? $count . ' failed recipient(s) put back in the queue.'
                : 'Nothing to retry — the failures there need a different template, not another attempt.');
            break;
    }
    redirect('/admin/whatsapp?tab=broadcast' . ($targetId > 0 ? '&campaign=' . $targetId : ''));
}

/* --------------------------------------------------------------------- loading */

$campaignScope = '';
$campaignParams = [];
if (!$isSuper && $myUnitId > 0) {
    $campaignScope = ' WHERE (org_unit_id IS NULL OR org_unit_id = ?)';
    $campaignParams = [$myUnitId];
}

$campaignId = (int) ($_GET['campaign'] ?? 0);
$campaign = null;
$recipients = [];
if ($campaignId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM wa_campaigns WHERE id = ?' . ($campaignScope !== '' ? ' AND (org_unit_id IS NULL OR org_unit_id = ?)' : ''));
    $stmt->execute($campaignScope !== '' ? [$campaignId, $myUnitId] : [$campaignId]);
    $campaign = $stmt->fetch() ?: null;

    if ($campaign !== null) {
        WaCampaign::refreshCounters($campaignId);
        $stmt = $pdo->prepare(
            'SELECT * FROM wa_campaign_recipients WHERE campaign_id = ?
             ORDER BY FIELD(status, "failed", "queued", "sent", "delivered", "read", "skipped"), id ASC LIMIT 300'
        );
        $stmt->execute([$campaignId]);
        $recipients = $stmt->fetchAll();
        $campaign = WaCampaign::find($campaignId) ?? $campaign;
    }
}

$campaigns = $pdo->prepare('SELECT * FROM wa_campaigns' . $campaignScope . ' ORDER BY id DESC LIMIT 50');
$campaigns->execute($campaignParams);
$campaigns = $campaigns->fetchAll();

$approvedTemplates = $pdo->query("SELECT name, language, body_text FROM wa_templates WHERE status = 'approved' ORDER BY name ASC")->fetchAll();
$groups = SmsContacts::groups($scopeUnitIds);
$assignableUnits = Unit::assignableScope($user);

$remaining = WaCampaign::remainingToday();
$cap = WaCampaign::dailyCap();
?>

<?php if (!$broadcastAllowed): ?>
  <div class="alert error">
    Broadcasting is switched off for your church. A super admin can turn it on under
    <strong>WhatsApp → Settings</strong>.
  </div>
<?php endif; ?>

<?php if (!$approvedTemplates): ?>
  <div class="card">
    <h2 style="margin-top:0;">Nothing can be broadcast yet</h2>
    <p style="color:var(--ink-dim);font-size:14px;">
      A broadcast must use a message template that Meta has already approved, and none are recorded
      as approved.
      <?php if ($isSuper): ?>
        Add one under <strong>Templates</strong>, submit it in Meta's dashboard, then record it as
        approved here.
      <?php else: ?>
        A super admin can add one under <strong>Templates</strong>.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="card" style="max-width:820px;">
    <h2 style="margin-top:0;">New broadcast</h2>
    <p style="color:var(--ink-dim);font-size:13.5px;margin-top:-4px;">
      Sends one approved template to a set of people. Only people who have opted in will receive
      it — anyone else is listed in the report as skipped, with the reason.
    </p>

    <p style="font-size:12.5px;color:var(--ink-dim);">
      Sent today: <strong><?= WaCampaign::sentToday() ?></strong>
      <?php if ($cap > 0): ?>
        of <strong><?= $cap ?></strong>. <?= $remaining > 0
            ? $remaining . ' left today.'
            : 'The cap is reached; sending resumes tomorrow.' ?>
      <?php else: ?>
        (no daily cap set).
      <?php endif; ?>
    </p>

    <form method="post" action="/admin/whatsapp?tab=broadcast">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create">

      <label for="name">Name this broadcast</label>
      <input type="text" id="name" name="name" required maxlength="150" placeholder="Sunday service invitation">

      <label for="template_name">Template</label>
      <select id="template_name" name="template_name" required>
        <?php foreach ($approvedTemplates as $t): ?>
          <option value="<?= e((string) $t['name']) ?>"><?= e((string) $t['name']) ?> (<?= e((string) $t['language']) ?>)</option>
        <?php endforeach; ?>
      </select>

      <label for="params">Values for the template's placeholders</label>
      <textarea id="params" name="params" rows="3" placeholder="One value per line, in order"></textarea>
      <p class="hint" style="margin-top:-6px;font-size:12.5px;color:var(--ink-dim);">
        One line per <code>{{1}}</code>, <code>{{2}}</code> … You can personalise with
        <code>{first_name}</code>, <code>{name}</code> or <code>{church}</code>, which are filled in
        for each person as the message is prepared.
      </p>

      <label for="audience">Who</label>
      <select id="audience" name="audience" onchange="document.getElementById('wa-group').style.display = this.value === 'group' ? '' : 'none'; document.getElementById('wa-unit').style.display = this.value === 'unit' ? '' : 'none';">
        <option value="all">Everyone</option>
        <option value="group">A group</option>
        <option value="unit">A church</option>
      </select>

      <div id="wa-group" style="display:none;">
        <label for="group_id">Group</label>
        <select id="group_id" name="group_id">
          <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group['id'] ?>"><?= e((string) $group['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="wa-unit" style="display:none;">
        <label for="unit_id">Church</label>
        <select id="unit_id" name="unit_id">
          <?php foreach ($assignableUnits as $unit): ?>
            <option value="<?= (int) $unit['id'] ?>"><?= e(Unit::optionLabel($unit)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="checkbox-row">
          <input type="checkbox" id="unit_subtree" name="unit_subtree" checked>
          <label for="unit_subtree" style="margin:0;">Include every church beneath it</label>
        </div>
      </div>

      <div class="btn-row">
        <button class="btn" type="submit" <?= $broadcastAllowed ? '' : 'disabled' ?>>Queue broadcast</button>
      </div>
      <p class="hint" style="font-size:12.5px;color:var(--ink-dim);">
        Queued, not sent. <code>cli/wa_worker.php</code> does the sending, so a mistake is
        reviewable before anything leaves.
      </p>
    </form>
  </div>
<?php endif; ?>

<?php if ($campaign !== null): ?>
  <?php $counters = WaCampaign::refreshCounters((int) $campaign['id']); ?>
  <div class="card">
    <h2 style="margin-top:0;"><?= e((string) $campaign['name']) ?> <span class="badge <?= $campaign['status'] === 'done' ? 'ok' : ($campaign['status'] === 'cancelled' ? 'fail' : 'warn') ?>"><?= e((string) $campaign['status']) ?></span></h2>
    <p style="color:var(--ink-dim);font-size:13px;margin-top:-4px;">
      Template <code><?= e((string) $campaign['template_name']) ?></code> (<?= e((string) $campaign['template_language']) ?>)
    </p>

    <?php if (!empty($campaign['pause_reason'])): ?>
      <div class="alert error">Paused: <?= e((string) $campaign['pause_reason']) ?></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin:14px 0;">
      <?php foreach ([
          'total' => 'Targeted', 'queued' => 'Still queued', 'sent' => 'Accepted',
          'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed', 'skipped' => 'Skipped',
      ] as $key => $label): ?>
        <div style="background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px 12px;">
          <div style="font-size:11px;color:var(--ink-dim);text-transform:uppercase;letter-spacing:.6px;"><?= e($label) ?></div>
          <div style="font-size:20px;font-weight:800;"><?= (int) ($counters[$key] ?? 0) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <p style="font-size:12.5px;color:var(--ink-dim);">
      "Accepted" means Meta took it — not that it arrived. Delivered and read arrive later by
      webhook.
    </p>

    <div class="btn-row" style="flex-wrap:wrap;">
      <?php if (in_array((string) $campaign['status'], ['queued', 'sending'], true)): ?>
        <form method="post" action="/admin/whatsapp?tab=broadcast" style="display:inline;">
          <?= Csrf::field() ?><input type="hidden" name="action" value="pause"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
          <button class="btn secondary sm" type="submit">Pause</button>
        </form>
      <?php endif; ?>
      <?php if ((string) $campaign['status'] === 'paused'): ?>
        <form method="post" action="/admin/whatsapp?tab=broadcast" style="display:inline;">
          <?= Csrf::field() ?><input type="hidden" name="action" value="resume"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
          <button class="btn sm" type="submit">Resume</button>
        </form>
      <?php endif; ?>
      <?php if ((int) $counters['failed'] > 0): ?>
        <form method="post" action="/admin/whatsapp?tab=broadcast" style="display:inline;">
          <?= Csrf::field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
          <button class="btn secondary sm" type="submit">Retry failures</button>
        </form>
      <?php endif; ?>
      <?php if (in_array((string) $campaign['status'], ['draft', 'queued', 'sending', 'paused'], true)): ?>
        <form method="post" action="/admin/whatsapp?tab=broadcast" style="display:inline;"
              onsubmit="return confirm('Cancel this broadcast? Anything not yet sent will be dropped.');">
          <?= Csrf::field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
          <button class="btn danger sm" type="submit">Cancel</button>
        </form>
      <?php endif; ?>
      <a class="btn secondary sm" href="/admin/whatsapp?tab=broadcast">Close</a>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Recipients</h2>
    <?php if (!$recipients): ?>
      <div class="empty-state">Nobody was queued in this broadcast.</div>
    <?php else: ?>
      <table>
        <tr><th>Person</th><th>Number</th><th>Status</th><th>Detail</th></tr>
        <?php foreach ($recipients as $r): ?>
          <?php
            // An array rather than a `match`: the codebase supports PHP before 8, where `match`
            // is not a keyword and the file will not parse.
            $badges = ['read' => 'ok', 'delivered' => 'ok', 'failed' => 'fail', 'skipped' => 'warn'];
            $badge = $badges[(string) $r['status']] ?? '';
          ?>
          <tr>
            <td><?= e((string) ($r['contact_name'] ?: '—')) ?></td>
            <td><?= e(Sms::prettyMsisdn((string) $r['msisdn'])) ?></td>
            <td><span class="badge <?= $badge ?>"><?= e((string) $r['status']) ?></span></td>
            <td style="font-size:12.5px;color:var(--ink-dim);">
              <?php if (!empty($r['skip_reason'])): ?>
                <?= e((string) $r['skip_reason']) ?>
              <?php elseif (!empty($r['error_note'])): ?>
                <?= e((string) $r['error_note']) ?>
                <?php if (!empty($r['error_code'])): ?>(<?= e((string) $r['error_code']) ?>)<?php endif; ?>
              <?php elseif (!empty($r['sent_at'])): ?>
                <?= e(date('M j, g:i a', strtotime((string) $r['sent_at']))) ?>
              <?php else: ?>
                waiting
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Broadcasts</h2>
  <?php if (!$campaigns): ?>
    <div class="empty-state">No broadcasts yet.</div>
  <?php else: ?>
    <table>
      <tr><th>Name</th><th>Template</th><th>Status</th><th>Targeted</th><th>Sent</th><th>Skipped</th><th>Failed</th><th></th></tr>
      <?php foreach ($campaigns as $c): ?>
        <tr>
          <td><?= e((string) $c['name']) ?></td>
          <td><code style="font-size:12px;"><?= e((string) $c['template_name']) ?></code></td>
          <td><span class="badge <?= $c['status'] === 'done' ? 'ok' : ($c['status'] === 'cancelled' ? 'fail' : 'warn') ?>"><?= e((string) $c['status']) ?></span></td>
          <td><?= (int) $c['total_count'] ?></td>
          <td><?= (int) $c['sent_count'] ?></td>
          <td><?= (int) $c['skipped_count'] ?></td>
          <td><?= (int) $c['failed_count'] ?></td>
          <td><a class="btn secondary sm" href="/admin/whatsapp?tab=broadcast&campaign=<?= (int) $c['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
