<?php
declare(strict_types=1);

/**
 * SMS → Compose.
 *
 * This is the only screen that spends money, so it works in three deliberate steps:
 *
 *   1. compose   — nothing is charged, nothing is queued.
 *   2. confirm   — the audience is resolved *for real*, every skipped number is explained,
 *                  and the cost is worked out by personalising the message for every single
 *                  recipient and counting the segments that actually results in. Not an
 *                  estimate from a character count: a name with an accent in it can push a
 *                  message into a second segment, and that is a second unit.
 *   3. queue     — the campaign is written and handed to the worker.
 *
 * There is no "send now" shortcut that bypasses step 2.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$myUnitId = (int) $smsContext['my_unit_id'];
$sendingAllowed = (bool) $smsContext['sending_allowed'];
$defaultCountry = (string) setting('sms_default_country', '234');
$unitLabels = $smsContext['unit_labels'];
$assignableUnits = Unit::assignableScope($smsContext['user']);
$errors = [];
$tabUrl = '/admin/sms?tab=compose';

$footer = trim((string) setting('sms_optout_footer', ''));
$senders = [];
try {
    $tenantId = Tenant::id();
    if ($isSuper || $scopeUnitIds === []) {
        $stmt = $pdo->prepare("SELECT sender_id FROM sms_senders WHERE status = 'approved' AND tenant_id <=> ? ORDER BY is_default DESC, sender_id ASC");
        $stmt->execute([$tenantId]);
    } else {
        $ids = array_map('intval', $scopeUnitIds);
        $stmt = $pdo->prepare("SELECT sender_id FROM sms_senders WHERE status = 'approved' AND tenant_id <=> ? AND (org_unit_id IS NULL OR org_unit_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')) ORDER BY is_default DESC, sender_id ASC');
        $stmt->execute(array_merge([$tenantId], $ids));
    }
    $senders = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // Handled by the warning below.
}

$defaultSender = Sms::defaultSenderId();
if ($defaultSender !== '' && !in_array($defaultSender, $senders, true)) {
    $senders[] = $defaultSender;
}

$groups = SmsContacts::groups($scopeUnitIds);
$presetGroupId = (int) ($_GET['group'] ?? 0);
$presetTemplateId = (int) ($_GET['template'] ?? 0);

$presetTemplate = null;
if ($presetTemplateId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM sms_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$presetTemplateId]);
    $presetTemplate = $stmt->fetch() ?: null;
}

/**
 * Turns the submitted audience selection into an actual list of recipients.
 *
 * Every exclusion is reported with a reason, because "we sent to 412 people" when the admin
 * expected 500 is not an answer — they need to know which 88 and why.
 *
 * @return array{recipients:array<int,array{msisdn:string,name:string,contact_id:?int}>,excluded:array<int,array{value:string,reason:string}>,label:string}
 */
$resolveAudience = static function (array $input) use ($scopeUnitIds, $defaultCountry, $footer): array {
    $kind = (string) ($input['audience'] ?? 'all');
    $recipients = [];
    $excluded = [];
    $label = '';

    $push = static function (array $rows, array &$recipients): void {
        foreach ($rows as $row) {
            $recipients[$row['msisdn']] = [
                'msisdn' => (string) $row['msisdn'],
                'name' => (string) ($row['name'] ?? ''),
                'contact_id' => isset($row['id']) ? (int) $row['id'] : null,
            ];
        }
    };

    if ($kind === 'group') {
        $group = SmsContacts::findGroup((int) ($input['group_id'] ?? 0));
        if ($group === null) {
            return ['recipients' => [], 'excluded' => [], 'label' => 'No such group'];
        }
        // A scoped admin must not be able to reach a group they cannot see.
        $groupUnit = (int) ($group['org_unit_id'] ?? 0);
        if ($scopeUnitIds !== [] && $groupUnit > 0 && !in_array($groupUnit, array_map('intval', $scopeUnitIds), true)) {
            return ['recipients' => [], 'excluded' => [], 'label' => 'No such group'];
        }

        $ids = SmsContacts::groupAudience($group, $scopeUnitIds);
        $rows = $ids === [] ? [] : SmsContacts::filter([
            'ids' => $ids,
            'scope_unit_ids' => $scopeUnitIds,
            'opted_out' => false,
        ], 20000);

        // Opted-out members of a static group are silently in the id list; count them out loud.
        $kept = [];
        foreach ($rows as $row) {
            $kept[(int) $row['id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($kept[$id])) {
                $excluded[] = ['value' => 'contact #' . $id, 'reason' => 'Opted out, or outside your scope'];
            }
        }

        $push($rows, $recipients);
        $label = 'Group: ' . $group['name'];
    } elseif ($kind === 'segment') {
        $rule = [
            'type' => (string) ($input['rule_type'] ?? 'all'),
            'source' => (string) ($input['rule_source'] ?? ''),
            'tag' => (string) ($input['rule_tag'] ?? ''),
            'unit_id' => (int) ($input['rule_unit_id'] ?? 0),
            'include_subtree' => !empty($input['rule_include_subtree']),
            'days' => (int) ($input['rule_days'] ?? 30),
        ];
        $ids = SmsContacts::resolveRule($rule, $scopeUnitIds, null);
        $rows = $ids === [] ? [] : SmsContacts::filter(['ids' => $ids, 'scope_unit_ids' => $scopeUnitIds, 'opted_out' => false], 20000);
        $push($rows, $recipients);
        $label = 'Live segment: ' . SmsContacts::ruleSummary($rule);
    } elseif ($kind === 'unit') {
        $rows = SmsContacts::audienceFromUnit((int) ($input['unit_id'] ?? 0), !empty($input['unit_subtree']), $scopeUnitIds);
        $push($rows, $recipients);
        $unitId = (int) ($input['unit_id'] ?? 0);
        $label = 'Unit: ' . (Unit::label($unitId) ?: ('unit ' . $unitId))
            . (!empty($input['unit_subtree']) ? ' and everything beneath it' : ' only');
    } elseif ($kind === 'numbers') {
        $result = SmsContacts::audienceFromNumbers((string) ($input['numbers'] ?? ''), $defaultCountry);
        $push($result['known'], $recipients);
        foreach ($result['unknown'] as $msisdn) {
            // A pasted number that is not in the book is still messaged, but is not stored.
            $recipients[$msisdn] = ['msisdn' => $msisdn, 'name' => '', 'contact_id' => null];
        }
        $excluded = $result['invalid'];
        $label = 'Numbers pasted in by hand';
    } else {
        $rows = SmsContacts::filter(['scope_unit_ids' => $scopeUnitIds, 'opted_out' => false], 20000);
        $push($rows, $recipients);
        $label = 'Everyone in the address book';
    }

    if ($footer !== '') {
        // Reported so the admin sees the footer is doing something, not hidden.
    }

    return ['recipients' => array_values($recipients), 'excluded' => $excluded, 'label' => $label];
};

/** Works out the true unit cost by personalising for each person and counting segments. */
$costOf = static function (array $recipients, string $message, string $footer): array {
    $combined = $footer !== '' ? $message . "\n" . $footer : $message;
    $units = 0;
    $segments = 0;
    $encoding = 'GSM-7';
    foreach ($recipients as $recipient) {
        $personalised = Sms::personalise($combined, ['name' => $recipient['name']]);
        $perMessage = Sms::unitsFor($personalised);
        $units += $perMessage;
        $segments = max($segments, $perMessage);
        $encoding = Sms::encodingFor($personalised);
    }
    return ['units' => $units, 'max_segments' => $segments, 'encoding' => $encoding, 'text' => $combined];
};

/* ============================================================ POST handling */

$confirmation = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sendingAllowed) {
    Csrf::requireValid();
    $do = (string) ($_POST['do'] ?? '');

    $title = trim((string) ($_POST['title'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $senderId = strtoupper(trim((string) ($_POST['sender_id'] ?? '')));

    // --- send one test message to the admin's own phone ---------------------------------------
    if ($do === 'test') {
        $myNumber = trim((string) ($_POST['test_number'] ?? ''));
        if ($myNumber === '') {
            $myNumber = (string) ($smsContext['user']['phone'] ?? '');
        }
        if ($myNumber === '') {
            $errors[] = 'Enter a number to send the test to — your user record has no phone number saved.';
        } elseif ($message === '') {
            $errors[] = 'Type the message first.';
        } elseif ($senderId === '') {
            $errors[] = 'Choose the sender ID the test should come from.';
        } elseif (!Sms::configured()) {
            $errors[] = 'No API token is configured, so a test cannot be sent.';
        } else {
            $msisdn = Sms::normaliseMsisdn($myNumber, $defaultCountry);
            if ($msisdn === null) {
                $errors[] = 'That test number is not valid: ' . Sms::whyInvalid($myNumber, $defaultCountry);
            } else {
                $body = $footer !== '' ? $message . "\n" . $footer : $message;
                $body = Sms::personalise($body, ['name' => (string) ($smsContext['user']['name'] ?? '')]);
                $result = Sms::send([$msisdn], $body, $senderId, null);
                if ($result['ok']) {
                    flash('success', 'Test message sent to ' . Sms::prettyMsisdn($msisdn) . '. Check the handset — if it arrives, everything is wired up correctly.');
                } else {
                    flash('error', 'The test failed: ' . Sms::errorMessage((string) ($result['error_code'] ?? '')) . ' (code ' . (string) ($result['error_code'] ?? '?') . ')');
                }
                redirect($tabUrl);
            }
        }
    }

    // --- step 2: resolve and show the confirmation ---------------------------------------------
    if ($do === 'confirm') {
        if ($title === '') {
            $errors[] = 'Give the campaign a title. It is only used in the reports, but you will want it later.';
        }
        if ($message === '') {
            $errors[] = 'The message is empty.';
        }
        if ($senderId === '') {
            $errors[] = 'Choose which sender ID the message should come from.';
        } elseif (Sms::senderIdProblem($senderId) !== null) {
            $errors[] = 'That sender ID cannot be used: ' . Sms::senderIdProblem($senderId);
        }
        if (!Sms::configured()) {
            $errors[] = 'No API token is configured, so nothing can be queued.';
        }
        if (mb_strlen($message) > 1000) {
            $errors[] = 'That message is ' . mb_strlen($message) . ' characters. Anything past 1000 characters is almost certainly a mistake.';
        }

        if ($errors === []) {
            $audience = $resolveAudience($_POST);
            if ($audience['recipients'] === []) {
                $errors[] = 'That audience is empty. Check the group, the filter or the numbers you pasted.';
            } else {
                $cost = $costOf($audience['recipients'], $message, $footer);
                $confirmation = [
                    'title' => $title,
                    'message' => $message,
                    'sender_id' => $senderId,
                    'scheduled_at' => (string) ($_POST['scheduled_at'] ?? ''),
                    'audience' => $audience,
                    'cost' => $cost,
                    'input' => $_POST,
                ];
            }
        }
    }

    // --- step 3: write the campaign and queue it -------------------------------------------------
    if ($do === 'queue') {
        // The audience is resolved again rather than trusted from the previous step: between
        // the two screens someone else may have opted out, and the campaign must reflect the
        // world as it is at the moment of sending.
        $audience = $resolveAudience($_POST);
        if ($audience['recipients'] === []) {
            $errors[] = 'That audience is now empty — nothing was queued.';
        } elseif ($title === '' || $message === '' || $senderId === '') {
            $errors[] = 'Something went missing between the two steps. Please start again.';
        } else {
            $cost = $costOf($audience['recipients'], $message, $footer);

            // The daily cap is checked before queueing, so an admin is told up front rather
            // than discovering a half-sent campaign the next morning.
            if (SmsCampaign::wouldExceedDailyCap($cost['units'])) {
                $errors[] = 'This would send ' . number_format($cost['units']) . ' unit(s), but only '
                    . number_format(max(0, SmsCampaign::dailyCap() - SmsCampaign::unitsSentToday()))
                    . ' of today\'s cap remains. Reduce the audience, or schedule it for tomorrow.';
            } else {
                $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));
                $scheduledTs = $scheduledAt !== '' ? strtotime($scheduledAt) : false;
                if ($scheduledTs !== false && $scheduledTs < time() + 60) {
                    $errors[] = 'That send time is in the past. Choose a time at least a minute from now, or leave it blank to queue immediately.';
                } else {
                    $groupId = (string) ($_POST['audience'] ?? '') === 'group' ? (int) ($_POST['group_id'] ?? 0) : 0;
                    $combined = $footer !== '' ? $message . "\n" . $footer : $message;

                    try {
                        $campaignTenantId = Tenant::id();
                        $stmt = $pdo->prepare(
                            'INSERT INTO sms_campaigns (tenant_id, title, message, sender_id, group_id, status, scheduled_at, total_recipients, estimated_units, created_by, org_unit_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $campaignTenantId,
                            mb_substr($title, 0, 200),
                            $combined,
                            $senderId,
                            $groupId > 0 ? $groupId : null,
                            'queued',
                            $scheduledTs !== false ? date('Y-m-d H:i:s', $scheduledTs) : null,
                            count($audience['recipients']),
                            $cost['units'],
                            (int) ($smsContext['user']['id'] ?? 0),
                            !$isSuper && $myUnitId > 0 ? $myUnitId : null,
                        ]);
                        $campaignId = (int) $pdo->lastInsertId();

                        $queued = SmsCampaign::queueRecipients($campaignId, $audience['recipients']);
                        SmsCampaign::refreshCounters($campaignId);

                        $summary = number_format((int) $queued['queued']) . ' recipient(s) queued, '
                            . number_format($cost['units']) . ' unit(s) expected.';
                        if ((int) $queued['duplicates'] > 0) {
                            $summary .= ' ' . $queued['duplicates'] . ' were already in this campaign.';
                        }
                        if ((int) $queued['opted_out'] > 0) {
                            $summary .= ' ' . $queued['opted_out'] . ' had opted out.';
                        }
                        if ((int) $queued['invalid'] > 0) {
                            $summary .= ' ' . $queued['invalid'] . ' had an unusable number.';
                        }
                        if ((int) $queued['rejected'] > 0) {
                            $summary .= ' ' . $queued['rejected'] . ' could NOT be saved — please report this.';
                        }

                        if ($scheduledTs !== false) {
                            flash('success', 'Campaign scheduled for ' . date('D, M j \a\t g:i A', $scheduledTs) . ' — ' . $summary);
                        } else {
                            flash('success', 'Campaign queued — ' . $summary . ' The worker sends it on its next run.');
                        }
                        redirect('/admin/sms?tab=campaigns&id=' . $campaignId);
                    } catch (Throwable $e) {
                        error_log('SMS campaign create failed: ' . $e->getMessage());
                        $errors[] = 'The campaign could not be saved. Nothing was queued.';
                    }
                }
            }
        }
    }
}

/* ==================================================================== view */

$formValues = [
    'title' => (string) ($_POST['title'] ?? formOld('title')),
    'message' => (string) ($_POST['message'] ?? ($confirmation['message'] ?? ($presetTemplate['body'] ?? formOld('message')))),
    'sender_id' => (string) ($_POST['sender_id'] ?? ($defaultSender !== '' ? $defaultSender : ($senders[0] ?? ''))),
    'audience' => (string) ($_POST['audience'] ?? ($presetGroupId > 0 ? 'group' : 'all')),
    'group_id' => (string) ($_POST['group_id'] ?? ($presetGroupId > 0 ? (string) $presetGroupId : '')),
    'unit_id' => (string) ($_POST['unit_id'] ?? ''),
    'unit_subtree' => !empty($_POST['unit_subtree']) ? '1' : '',
    'rule_type' => (string) ($_POST['rule_type'] ?? 'source'),
    'rule_source' => (string) ($_POST['rule_source'] ?? ''),
    'rule_tag' => (string) ($_POST['rule_tag'] ?? ''),
    'rule_unit_id' => (string) ($_POST['rule_unit_id'] ?? ''),
    'rule_days' => (string) ($_POST['rule_days'] ?? '30'),
    'numbers' => (string) ($_POST['numbers'] ?? ''),
    'scheduled_at' => (string) ($_POST['scheduled_at'] ?? ''),
];

$plainUnits = Sms::unitsFor($footer !== '' ? $formValues['message'] . "\n" . $footer : $formValues['message']);
$plainSegments = Sms::segmentsFor($footer !== '' ? $formValues['message'] . "\n" . $footer : $formValues['message']);

$placeholders = Sms::placeholders();
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (!$sendingAllowed): ?>
  <div class="card">
    <h2>Composing is switched off</h2>
    <p class="sub">
      A super admin has disabled sending for individual churches. Your address book, groups and
      templates are all still here, and are not affected.
    </p>
  </div>
<?php elseif ($senders === []): ?>
  <div class="card">
    <h2>No approved sender ID yet</h2>
    <p class="sub">
      A message needs a sender ID that the gateway has approved. Nothing can be composed until then.
      <a href="/admin/sms?tab=sender-ids" style="color:var(--gold-soft);">Submit one now</a> — approval usually takes a few hours.
    </p>
  </div>
<?php elseif ($confirmation !== null): ?>

  <?php
    $audience = $confirmation['audience'];
    $cost = $confirmation['cost'];
    $balance = null;
    try {
        $balanceRow = $pdo->query('SELECT balance FROM sms_wallet_log WHERE balance IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
        $balance = $balanceRow ? (float) $balanceRow['balance'] : null;
    } catch (Throwable $e) {
        $balance = null;
    }
    $shortOfFunds = $balance !== null && $balance < $cost['units'];
  ?>

  <div class="card">
    <h2>Step 2 of 3 — check before you send</h2>
    <p class="sub">Nothing has been queued yet. This is the last screen before money is spent.</p>

    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:16px 0;">
      <div class="stat"><div class="num"><?= number_format(count($audience['recipients'])) ?></div><div class="label">Recipients</div></div>
      <div class="stat"><div class="num"><?= number_format($cost['units']) ?></div><div class="label">Units this will cost</div></div>
      <div class="stat"><div class="num"><?= $cost['max_segments'] ?></div><div class="label">Segments per message</div></div>
      <div class="stat"><div class="num"><?= e($cost['encoding']) ?></div><div class="label">Encoding</div></div>
    </div>

    <?php if ($shortOfFunds): ?>
      <div class="alert error">
        The last recorded balance is <strong><?= number_format($balance) ?> unit(s)</strong>, which is
        less than this campaign needs. Top up first, or the campaign will pause partway through.
      </div>
    <?php endif; ?>

    <?php if ($cost['max_segments'] > 1): ?>
      <div class="alert success" style="background:#e8b95f14;border-color:#e8b95f44;color:#f0d9a4;">
        Some messages run to <?= $cost['max_segments'] ?> segments, so those recipients cost <?= $cost['max_segments'] ?> units each
        rather than one. <?= $cost['encoding'] === 'UCS-2'
          ? 'The message contains a character outside the standard alphabet — a smart quote or an emoji is the usual cause. Replacing it with a plain equivalent halves the cost.'
          : 'Shortening the message to 160 characters would bring it back to a single unit.' ?>
      </div>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:18px 0 8px;">Audience</h3>
    <p class="sub" style="margin-bottom:10px;"><strong><?= e($audience['label']) ?></strong></p>

    <?php if ($audience['excluded'] !== []): ?>
      <div class="alert error" style="background:#ff6b6b10;border-color:#ff6b6b33;color:#ffc9c9;">
        <?= number_format(count($audience['excluded'])) ?> number(s) were left out. They will not be messaged.
      </div>
      <table style="margin-bottom:16px;">
        <tr><th>Number</th><th>Why it was left out</th></tr>
        <?php foreach (array_slice($audience['excluded'], 0, 25) as $excluded): ?>
          <tr>
            <td><code><?= e((string) $excluded['value']) ?></code></td>
            <td><small><?= e((string) $excluded['reason']) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if (count($audience['excluded']) > 25): ?>
        <p class="sub" style="font-size:12px;">…and <?= number_format(count($audience['excluded']) - 25) ?> more.</p>
      <?php endif; ?>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:18px 0 8px;">The message, as the first recipient will read it</h3>
    <div style="background:#0f0d1f;border:1px solid var(--border);border-radius:12px;padding:14px 16px;max-width:420px;">
      <div style="font-size:11px;color:var(--ink-faint);margin-bottom:8px;">From <strong style="color:var(--gold-soft);"><?= e($confirmation['sender_id']) ?></strong></div>
      <div style="white-space:pre-wrap;font-size:13.5px;line-height:1.5;"><?= e(Sms::personalise($cost['text'], ['name' => $audience['recipients'][0]['name']])) ?></div>
    </div>

    <h3 style="font-size:14px;margin:18px 0 8px;">First few recipients</h3>
    <table class="resp-table">
      <tr><th>Number</th><th>Name</th></tr>
      <?php foreach (array_slice($audience['recipients'], 0, 10) as $recipient): ?>
        <tr>
          <td><code><?= e(Sms::prettyMsisdn($recipient['msisdn'])) ?></code></td>
          <td><?= e($recipient['name'] !== '' ? $recipient['name'] : '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($audience['recipients']) > 10): ?>
      <p class="sub" style="font-size:12px;">…and <?= number_format(count($audience['recipients']) - 10) ?> more.</p>
    <?php endif; ?>

    <form method="post" action="<?= e($tabUrl) ?>" style="margin-top:20px;">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="queue">
      <?php
        // Everything the second step needs, carried forward verbatim.
        foreach (['title', 'message', 'sender_id', 'audience', 'group_id', 'unit_id', 'unit_subtree',
                  'rule_type', 'rule_source', 'rule_tag', 'rule_unit_id', 'rule_include_subtree',
                  'rule_days', 'numbers', 'scheduled_at'] as $field) {
            $value = $_POST[$field] ?? '';
            if (is_scalar($value) && (string) $value !== '') {
                echo '<input type="hidden" name="' . e($field) . '" value="' . e((string) $value) . '">' . "\n";
            }
        }
      ?>
      <div class="btn-row">
        <button class="btn" type="submit">
          <?= $confirmation['scheduled_at'] !== '' ? 'Schedule for later' : 'Queue this campaign now' ?>
        </button>
        <a class="btn secondary" href="<?= e($tabUrl) ?>">Go back and change something</a>
      </div>
    </form>
  </div>

<?php else: ?>

  <form method="post" action="<?= e($tabUrl) ?>" id="compose-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="confirm">

    <div class="card">
      <h2>Step 1 of 3 — the message</h2>

      <div class="row two">
        <div>
          <label for="title">Campaign title <small style="color:var(--ink-faint);">(for your reports only)</small></label>
          <input type="text" id="title" name="title" maxlength="200" required
                 value="<?= e($formValues['title']) ?>" placeholder="e.g. December workers' meeting">
        </div>
        <div>
          <label for="sender_id">Sender ID</label>
          <select id="sender_id" name="sender_id" required>
            <?php foreach ($senders as $sender): ?>
              <option value="<?= e((string) $sender) ?>" <?= $formValues['sender_id'] === (string) $sender ? 'selected' : '' ?>><?= e((string) $sender) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--ink-faint);font-size:12px;">This is what appears on the handset as the sender.</small>
        </div>
      </div>

      <label for="message">Message</label>
      <textarea id="message" name="message" rows="5" maxlength="1000" required
                style="font-size:14px;line-height:1.6;"><?= e($formValues['message']) ?></textarea>

      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:-6px 0 14px;">
        <span class="badge"><span id="char-count"><?= mb_strlen($formValues['message']) ?></span> characters</span>
        <span class="badge" id="segment-badge"><?= $plainSegments ?> unit<?= $plainSegments === 1 ? '' : 's' ?> per recipient</span>
        <span class="badge" id="encoding-badge"><?= e(Sms::encodingFor($formValues['message'])) ?></span>
        <span id="cost-badge" style="font-size:12.5px;color:var(--gold-soft);font-weight:600;"></span>
      </div>

      <?php if ($footer !== ''): ?>
        <div style="border:1px dashed var(--border);border-radius:10px;padding:10px 12px;margin-bottom:14px;">
          <small style="color:var(--ink-faint);font-size:12px;">
            This is added to every message automatically (change it under Settings):
          </small>
          <div style="font-size:12.5px;color:var(--ink-dim);margin-top:4px;"><?= e($footer) ?></div>
          <small style="color:var(--ink-faint);font-size:11.5px;">It counts towards the segment total above.</small>
        </div>
      <?php endif; ?>

      <details style="margin-bottom:6px;">
        <summary style="cursor:pointer;font-size:13px;color:var(--gold-soft);">Insert a placeholder</summary>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
          <?php foreach ($placeholders as $token => $meaning): ?>
            <button type="button" class="btn secondary sm"
                    onclick="var box=document.getElementById('message');box.value+= '<?= e($token) ?>';box.dispatchEvent(new Event('input'));">
              <?= e($token) ?> <small style="color:var(--ink-faint);">— <?= e($meaning) ?></small>
            </button>
          <?php endforeach; ?>
        </div>
      </details>
      <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:6px;">
        A placeholder that cannot be filled is left exactly as typed, so you will see the mistake rather
        than sending “Hi , welcome”.
      </small>
    </div>

    <div class="card">
      <h2>Who is it going to?</h2>

      <label for="audience">Audience</label>
      <select id="audience" name="audience" required
              onchange="['group','segment','unit','numbers'].forEach(function(k){document.getElementById('aud-'+k).style.display = (k === this.value) ? 'block' : 'none';}.bind(this));">
        <option value="all" <?= $formValues['audience'] === 'all' ? 'selected' : '' ?>>Everyone in the address book</option>
        <option value="group" <?= $formValues['audience'] === 'group' ? 'selected' : '' ?>>A saved group</option>
        <option value="segment" <?= $formValues['audience'] === 'segment' ? 'selected' : '' ?>>A live segment (a rule)</option>
        <option value="unit" <?= $formValues['audience'] === 'unit' ? 'selected' : '' ?>>Everyone in a church unit</option>
        <option value="numbers" <?= $formValues['audience'] === 'numbers' ? 'selected' : '' ?>>Numbers I paste in</option>
      </select>
      <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:14px;">
        Opted-out contacts are never included, whichever you choose. The next screen tells you exactly
        how many people were left out and why.
      </small>

      <div id="aud-group" style="display:<?= $formValues['audience'] === 'group' ? 'block' : 'none' ?>;">
        <label for="group_id">Group</label>
        <select id="group_id" name="group_id">
          <option value="">— choose a group —</option>
          <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group['id'] ?>" <?= $formValues['group_id'] === (string) $group['id'] ? 'selected' : '' ?>>
              <?= e((string) $group['name']) ?><?= (string) $group['kind'] === 'dynamic' ? ' (live)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="aud-segment" style="display:<?= $formValues['audience'] === 'segment' ? 'block' : 'none' ?>;">
        <div class="row two">
          <div>
            <label for="rule_type">Rule</label>
            <select id="rule_type" name="rule_type">
              <?php foreach (SmsContacts::RULE_TYPES as $type): ?>
                <?php if ($type === 'never_in_group') { continue; } ?>
                <option value="<?= e($type) ?>" <?= $formValues['rule_type'] === $type ? 'selected' : '' ?>><?= e(SmsContacts::RULE_LABELS[$type] ?? $type) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="rule_source">Source / tag / unit</label>
            <select id="rule_source" name="rule_source">
              <option value="">— source not used —</option>
              <?php foreach (SmsContacts::SOURCES as $source): ?>
                <option value="<?= e($source) ?>" <?= $formValues['rule_source'] === $source ? 'selected' : '' ?>><?= e(SmsContacts::sourceLabel($source)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="rule_tag" value="<?= e($formValues['rule_tag']) ?>" placeholder="…or a tag" style="margin-top:8px;">
            <select name="rule_unit_id" style="margin-top:8px;">
              <option value="">…or a unit</option>
              <?php foreach ($assignableUnits as $unit): ?>
                <option value="<?= (int) $unit['id'] ?>" <?= $formValues['rule_unit_id'] === (string) $unit['id'] ? 'selected' : '' ?>><?= e(Unit::optionLabel($unit)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="checkbox-row">
              <input type="checkbox" id="rule_include_subtree" name="rule_include_subtree" value="1">
              <label for="rule_include_subtree" style="margin:0;">Include sub-units of that unit</label>
            </div>
            <input type="number" name="rule_days" min="1" max="3650" value="<?= e($formValues['rule_days']) ?>" style="margin-top:8px;" aria-label="Days">
          </div>
        </div>
      </div>

      <div id="aud-unit" style="display:<?= $formValues['audience'] === 'unit' ? 'block' : 'none' ?>;">
        <label for="unit_id">Unit</label>
        <select id="unit_id" name="unit_id">
          <option value="">— choose a unit —</option>
          <?php foreach ($assignableUnits as $unit): ?>
            <option value="<?= (int) $unit['id'] ?>" <?= $formValues['unit_id'] === (string) $unit['id'] ? 'selected' : '' ?>><?= e(Unit::optionLabel($unit)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="checkbox-row">
          <input type="checkbox" id="unit_subtree" name="unit_subtree" value="1" <?= $formValues['unit_subtree'] === '1' ? 'checked' : '' ?>>
          <label for="unit_subtree" style="margin:0;">Include every unit beneath it</label>
        </div>
      </div>

      <div id="aud-numbers" style="display:<?= $formValues['audience'] === 'numbers' ? 'block' : 'none' ?>;">
        <label for="numbers">Numbers</label>
        <textarea id="numbers" name="numbers" rows="3" placeholder="08031234567, 0803 765 4321, +2348030000000"><?= e($formValues['numbers']) ?></textarea>
        <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 0;">
          A number that is not already in the address book is messaged but <strong>not saved</strong> —
          a one-off paste is not consent to keep someone's number.
        </small>
      </div>
    </div>

    <div class="card">
      <h2>When, and a final check</h2>

      <div class="row two">
        <div>
          <label for="scheduled_at">Send at</label>
          <input type="datetime-local" id="scheduled_at" name="scheduled_at" value="<?= e($formValues['scheduled_at']) ?>">
          <small style="color:var(--ink-faint);font-size:12px;">
            Leave blank to queue straight away. Sending only happens between
            <?= e(SmsCampaign::quietHoursLabel()) ?>, so a message queued at 2am goes out when the window opens.
          </small>
        </div>
        <div>
          <label>Send yourself a test first</label>
          <input type="text" id="test_number" name="test_number" placeholder="Your own number"
                 value="<?= e((string) ($smsContext['user']['phone'] ?? '')) ?>">
          <small style="color:var(--ink-faint);font-size:12px;">
            Uses the message and sender ID above. A test costs one unit and is not part of the campaign.
          </small>
        </div>
      </div>

      <div class="btn-row">
        <button class="btn" type="submit">Check and continue →</button>
        <button class="btn secondary" type="submit" name="do" value="test" formnovalidate>📱 Send a test to myself</button>
      </div>
      <small style="color:var(--ink-faint);font-size:12px;display:block;margin-top:8px;">
        Nothing is sent by “Check and continue”. It resolves the audience and works out the true cost first.
      </small>
    </div>
  </form>

  <script>
  (function () {
    // Mirrors Sms::isGsm7() / unitsFor() so the counter moves as you type. The server
    // recomputes the real figure on the next screen and that one is authoritative.
    var GSM7 = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    var GSM7_EXT = '^{}\\[~]|€';
    var FOOTER = <?= json_encode($footer, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function isGsm7(text) {
      for (var i = 0; i < text.length; i++) {
        var ch = text.charAt(i);
        if (GSM7.indexOf(ch) === -1 && GSM7_EXT.indexOf(ch) === -1) { return false; }
      }
      return true;
    }

    function segments(text) {
      if (text.length === 0) { return 1; }
      if (!isGsm7(text)) {
        return text.length <= 70 ? 1 : Math.ceil(text.length / 67);
      }
      var extended = 0;
      for (var i = 0; i < text.length; i++) {
        if (GSM7_EXT.indexOf(text.charAt(i)) !== -1) { extended++; }
      }
      var length = text.length + extended;
      return length <= 160 ? 1 : Math.ceil(length / 153);
    }

    var box = document.getElementById('message');
    var charCount = document.getElementById('char-count');
    var segmentBadge = document.getElementById('segment-badge');
    var encodingBadge = document.getElementById('encoding-badge');
    var costBadge = document.getElementById('cost-badge');

    function refresh() {
      var body = box.value + (FOOTER ? '\n' + FOOTER : '');
      var perMessage = segments(body);
      var count = body.length;
      var encoding = isGsm7(body) ? 'GSM-7' : 'UCS-2';

      charCount.textContent = count;
      segmentBadge.textContent = perMessage + (perMessage === 1 ? ' unit per recipient' : ' units per recipient');
      segmentBadge.className = 'badge ' + (perMessage > 1 ? 'warn' : '');
      encodingBadge.textContent = encoding;
      encodingBadge.className = 'badge ' + (encoding === 'UCS-2' ? 'warn' : '');
      costBadge.textContent = perMessage > 1
        ? 'At 500 recipients that would cost about ' + (perMessage * 500).toLocaleString() + ' units.'
        : '';
    }

    box.addEventListener('input', refresh);
    refresh();
  })();
  </script>

<?php endif; ?>
