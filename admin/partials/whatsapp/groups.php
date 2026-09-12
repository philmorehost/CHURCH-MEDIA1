<?php
declare(strict_types=1);

/**
 * The unofficial bridge: status, the group list, member import, and group posting.
 *
 * This screen exists because the bridge is the only way to see inside a WhatsApp group. It is off
 * unless a super admin has switched it on, and every state it can be in is stated rather than
 * implied — an admin who cannot tell whether the Node service is running will assume the feature
 * is broken.
 *
 * Importing members is the safe half: it writes contacts, it messages nobody. Posting into a group
 * is the dangerous half, so it is super-admin only and sits behind a confirm in the guide's terms.
 *
 * @var array<string, mixed> $waContext
 */

$isSuper = !empty($waContext['is_super']);

/** Longest group name we will echo back, matching the notes column. */
const WA_BRIDGE_NOTE_LIMIT = 120;

$action = (string) ($_POST['action'] ?? '');
$bridgeEnabled = WaBridge::enabled();
$bridgeProblem = WaBridge::problem();

/** Health is only worth asking for when the bridge is actually configured. */
$health = ($bridgeEnabled && $bridgeProblem === null) ? WaBridge::health() : null;
$groups = [];
if ($health !== null && $health['ok']) {
    $groupResult = WaBridge::groups();
    $groups = $groupResult['groups'];
    if (!$groupResult['ok']) {
        $bridgeProblem = $groupResult['error'];
    }
}

/* ------------------------------------------------------------------ *
 * Import group members as contacts
 * ------------------------------------------------------------------ */

if ($action === 'import_members' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $jid = (string) ($_POST['jid'] ?? '');
    $result = WaBridge::members($jid);

    if (!$result['ok']) {
        flash('error', (string) $result['error']);
        redirect('/admin/whatsapp?tab=groups');
    }

    // The church's own number sits in the group as a member. Importing it would mean the church
    // messaging itself.
    $ownNumber = $health['number'] ?? null;
    $country = Sms::defaultCountry();

    $added = 0;
    $known = 0;
    $invalid = 0;

    foreach ($result['members'] as $member) {
        $msisdn = Sms::normaliseMsisdn($member['number'], $country);
        if ($msisdn === null || ($ownNumber !== null && $msisdn === $ownNumber)) {
            $invalid++;
            continue;
        }

        $existing = SmsContacts::findByNumber($msisdn);
        if ($existing) {
            /*
             * Already on the list. Deliberately left completely alone: someone who is opted in
             * stays opted in, and someone who opted out of church messaging does not get pulled
             * back in because they happen to share a WhatsApp group with the youth leader.
             */
            $known++;
            continue;
        }

        $saved = SmsContacts::save(0, $msisdn, [
            'source' => 'group',
            'tags' => 'whatsapp-group',
            'notes' => 'WhatsApp group: ' . mb_substr((string) ($_POST['group_name'] ?? ''), 0, WA_BRIDGE_NOTE_LIMIT),
        ]);

        if (empty($saved['ok'])) {
            $invalid++;
            continue;
        }

        // Contacts default to opted in, which is right for a form somebody filled in and wrong
        // for a group roster. Being in a group is not consent to be messaged.
        SmsContacts::setOptOut([(int) $saved['id']], true);
        $added++;
    }

    $message = $added . ' added (opted out) · ' . $known . ' already known';
    if ($invalid > 0) {
        $message .= ' · ' . $invalid . ' skipped';
    }
    $message .= '. Nothing was sent to anyone.';

    flash($added > 0 || $known > 0 ? 'success' : 'error', $message);
    redirect('/admin/whatsapp?tab=groups');
}

/* ------------------------------------------------------------------ *
 * Post into a group
 * ------------------------------------------------------------------ */

if ($action === 'post_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    if (!$isSuper) {
        http_response_code(403);
        exit('Only a super admin may post into a group.');
    }

    $result = WaBridge::sendGroup((string) ($_POST['jid'] ?? ''), (string) ($_POST['text'] ?? ''));
    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? 'Posted to the group.'
        : 'Not posted: ' . (string) $result['error']);

    redirect('/admin/whatsapp?tab=groups');
}
?>

<div class="card" style="margin-bottom:18px;">
  <h2 style="margin-top:0;">Bridge status</h2>

  <?php if (!$bridgeEnabled): ?>
    <div class="alert error">
      The bridge is switched off, so no group can be read and nothing can be posted.
      <?= $isSuper ? 'Turn it on under <strong>Settings</strong>.' : 'A super admin can turn it on under <strong>Settings</strong>.' ?>
    </div>
  <?php elseif ($bridgeProblem !== null): ?>
    <div class="alert error"><?= e($bridgeProblem) ?></div>
  <?php elseif ($health !== null && !$health['ok']): ?>
    <div class="alert error"><?= e((string) $health['error']) ?></div>
  <?php endif; ?>

  <?php if ($health !== null && $health['ok'] && $health['mode'] === 'dry'): ?>
    <div class="alert error">
      <strong>This bridge is in dry-run mode.</strong> It has no WhatsApp number paired and will
      refuse to send anything. It is for testing the wiring only — start the service without
      <code>WA_BRIDGE_DRY=1</code> to pair a real number.
    </div>
  <?php endif; ?>

  <table>
    <tr><th style="width:200px;">Address</th><td><code><?= e(WhatsApp::bridgeUrl()) ?></code></td></tr>
    <tr><th>Enabled</th><td><?= $bridgeEnabled ? '<span class="badge ok">yes</span>' : '<span class="badge warn">no</span>' ?></td></tr>
    <tr><th>Token</th><td><?= WaBridge::tokenSet() ? '<span class="badge ok">configured</span>' : '<span class="badge warn">missing</span>' ?></td></tr>
    <tr>
      <th>Service</th>
      <td>
        <?php if ($health === null): ?>
          <span class="badge warn">not checked</span>
        <?php elseif ($health['ok']): ?>
          <span class="badge ok">reachable</span>
          <?php if ($health['mode'] !== null): ?> &middot; mode <code><?= e($health['mode']) ?></code><?php endif; ?>
        <?php else: ?>
          <span class="badge warn">unreachable</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>Paired number</th>
      <td>
        <?php if ($health !== null && $health['number'] !== null): ?>
          <code><?= e($health['number']) ?></code>
        <?php else: ?>
          <span style="color:var(--ink-dim);">not paired</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>Connected</th>
      <td><?= $health !== null && $health['connected'] ? '<span class="badge ok">yes</span>' : '<span class="badge warn">no</span>' ?></td>
    </tr>
    <?php if ($health !== null && !empty($health['data']['last_error'])): ?>
      <tr><th>Last error</th><td style="color:var(--danger);"><?= e((string) $health['data']['last_error']) ?></td></tr>
    <?php endif; ?>
  </table>

  <p class="hint" style="margin:14px 0 0;font-size:12px;color:var(--ink-dim);">
    The service must already be running — the admin never starts it. On the server:
    <code>cd bridge &amp;&amp; WA_BRIDGE_TOKEN=… node server.js --show-qr</code>. See
    <code>bridge/README.md</code> for the risks and the pairing steps.
  </p>
</div>

<?php if ($groups): ?>
  <div class="card" style="margin-bottom:18px;">
    <h2 style="margin-top:0;">Groups</h2>
    <p class="sub" style="margin:-6px 0 14px;">
      Importing members adds them as contacts marked <strong>opted out</strong>. It messages nobody.
    </p>
    <table>
      <tr><th>Group</th><th style="width:110px;">Members</th><th style="width:260px;"></th></tr>
      <?php foreach ($groups as $group): ?>
        <tr>
          <td><strong><?= e($group['name'] !== '' ? $group['name'] : $group['jid']) ?></strong>
            <div style="font-size:11px;color:var(--ink-faint);"><code><?= e($group['jid']) ?></code></div>
          </td>
          <td><?= (int) $group['participants'] ?></td>
          <td>
            <form method="post" action="/admin/whatsapp?tab=groups" style="display:inline;">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="import_members">
              <input type="hidden" name="jid" value="<?= e($group['jid']) ?>">
              <input type="hidden" name="group_name" value="<?= e($group['name']) ?>">
              <button class="btn sm secondary" type="submit">Import members</button>
            </form>
            <?php if ($isSuper): ?>
              <details style="display:inline-block;margin-left:8px;">
                <summary style="cursor:pointer;color:var(--ink-dim);font-size:12px;">Post…</summary>
                <form method="post" action="/admin/whatsapp?tab=groups" style="margin-top:8px;min-width:320px;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="post_group">
                  <input type="hidden" name="jid" value="<?= e($group['jid']) ?>">
                  <textarea name="text" rows="3" maxlength="4096" required
                            placeholder="Message to post into this group"></textarea>
                  <button class="btn sm danger" type="submit" style="margin-top:6px;">Post to group</button>
                  <p class="hint" style="margin:6px 0 0;font-size:11px;color:var(--ink-dim);">
                    This goes to every member of the group immediately, exactly as typed.
                  </p>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php elseif ($health !== null && $health['ok'] && $health['connected']): ?>
  <div class="card"><div class="empty">
    The paired number is not in any WhatsApp group, so there is nothing to read yet.
    Join a group with the disposable phone, then reload this page.
  </div></div>
<?php endif; ?>
