<?php
declare(strict_types=1);

/**
 * SMS → Guide. The usage guide, written for the person who inherited this system rather
 * than the person who built it.
 *
 * Every step links to the screen it describes, so this page doubles as the table of contents.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$myUnitId = (int) $smsContext['my_unit_id'];
$scopeUnitIds = $smsContext['scope_unit_ids'];

$contactCount = SmsContacts::count(['scope_unit_ids' => $scopeUnitIds]);
$groupCount = count(SmsContacts::groups($scopeUnitIds));
$senderCount = 0;
try {
    $tenantId = Tenant::id();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_senders WHERE status = 'approved' AND tenant_id <=> ?");
    $stmt->execute([$tenantId]);
    $senderCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $senderCount = 0;
}

$balance = null;
try {
    $row = $pdo->query('SELECT balance FROM sms_wallet_log WHERE balance IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
    $balance = $row ? (float) $row['balance'] : null;
} catch (Throwable $e) {
    $balance = null;
}

/**
 * The five steps, in order, with a "done" flag worked out from the real data rather than
 * from a checkbox somebody ticked once. A guide that knows where you actually are is worth
 * more than a list of links.
 */
$steps = [
    [
        'title' => 'Connect the gateway',
        'href' => '/admin/sms?tab=settings',
        'done' => Sms::configured(),
        'super' => true,
        'body' => 'Paste the API token your SMS provider gave you. It is stored encrypted, shown only masked, '
            . 'and never written to the log. Press <strong>Test connection</strong> — if it reports a balance, '
            . 'the token works. While you are there, set the sending window so nobody is texted at 3am, '
            . 'and a daily unit cap if you want one.',
    ],
    [
        'title' => 'Get a sender ID approved',
        'href' => '/admin/sms?tab=sender-ids',
        'done' => $senderCount > 0,
        'super' => false,
        'body' => 'This is the name your messages arrive from — up to 11 letters and numbers, no spaces. '
            . 'Submit it with a sample message; the provider reviews it, usually within a few hours. '
            . 'The status updates itself every 15 minutes and you are notified the moment it is approved. '
            . '<strong>Nothing can be sent before this step is finished.</strong>',
    ],
    [
        'title' => 'Fill the address book',
        'href' => '/admin/sms?tab=contacts',
        'done' => $contactCount > 0,
        'super' => false,
        'body' => 'Three ways, and you can mix them. <strong>Sync from church data</strong> pulls in every phone '
            . 'number the site already holds — the team list, newcomers, event RSVPs, testimonies, registrations, '
            . 'app installs — in one click. <strong>Import a CSV</strong> brings in a list from Excel or a phone '
            . 'export, and shows you exactly what it will do before it does it. Or add people one at a time. '
            . 'Anyone who replies STOP is marked opted out and skipped from then on, whatever you select.',
    ],
    [
        'title' => 'Build a group or a segment',
        'href' => '/admin/sms?tab=groups',
        'done' => $groupCount > 0,
        'super' => false,
        'body' => 'A <strong>group</strong> is a list you curate — the choir, this term\'s workers. '
            . 'A <strong>segment</strong> is a rule that recalculates itself every time you send, so '
            . '“everyone who joined in the last 30 days” is never stale. Groups are optional: you can '
            . 'also pick a unit, or paste numbers, straight from the composer.',
    ],
    [
        'title' => 'Send, or schedule',
        'href' => '/admin/sms?tab=compose',
        'done' => false,
        'super' => false,
        'body' => 'Write the message, pick the audience, and press <strong>Check and continue</strong>. '
            . 'That second screen is the important one: it resolves the audience for real, tells you how many '
            . 'people were left out and why, and works out the exact cost. Only then can you queue it. '
            . 'Send yourself a test first — it costs one unit and saves a lot of embarrassment.',
    ],
];

$doneCount = count(array_filter($steps, static fn(array $step): bool => $step['done']));
?>

<div class="card">
  <h2>How to use this system</h2>
  <p class="sub" style="max-width:720px;">
    You are <?= $doneCount === count($steps) ? 'set up and ready' : 'on step ' . min($doneCount + 1, count($steps)) . ' of ' . count($steps) ?><?php if ($isSuper && $myUnitId === 0): ?> as the church-wide administrator<?php endif; ?>.
    The five steps below go in order — each one depends on the one before it.
  </p>

  <div style="display:grid;gap:12px;margin-top:16px;">
    <?php foreach ($steps as $index => $step): ?>
      <?php if (!empty($step['super']) && !$isSuper) { continue; } ?>
      <div style="display:flex;gap:14px;padding:14px 16px;border:1px solid <?= $step['done'] ? '#6ba36b55' : 'var(--border)' ?>;border-radius:12px;background:<?= $step['done'] ? '#6ba36b0e' : 'transparent' ?>;">
        <div style="flex:0 0 30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;background:<?= $step['done'] ? '#6ba36b' : 'var(--gold-dim,#e8b95f22)' ?>;color:<?= $step['done'] ? '#08120a' : 'var(--gold-soft)' ?>;">
          <?= $step['done'] ? '✓' : (string) ($index + 1) ?>
        </div>
        <div style="flex:1;">
          <div style="font-weight:650;font-size:14.5px;margin-bottom:5px;">
            <?= e($step['title']) ?>
            <?php if ($step['done']): ?><span class="badge ok">done</span><?php endif; ?>
          </div>
          <div style="font-size:13px;color:var(--ink-dim);line-height:1.6;"><?= $step['body'] ?></div>
          <div style="margin-top:9px;">
            <a class="btn <?= $step['done'] ? 'secondary' : '' ?> sm" href="<?= e($step['href']) ?>">
              <?= $step['done'] ? 'Review' : 'Go' ?> →
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>What a message costs</h2>
  <p class="sub">
    One unit is one segment. A segment is 160 characters of plain text, or 70 characters if the
    message contains anything outside the standard alphabet — a smart quote, an accented name, an emoji.
  </p>

  <table class="resp-table">
    <tr><th>Message</th><th>Why</th><th>Units per person</th></tr>
    <tr>
      <td><small>“Service starts at 9am.”</small></td>
      <td><small>All plain characters.</small></td>
      <td>1</td>
    </tr>
    <tr>
      <td><small>A 200-character plain-text message.</small></td>
      <td><small>Longer than 160, so it splits. Each further part carries 153 characters, not 160, because the network has to say "part 2 of 2".</small></td>
      <td>2</td>
    </tr>
    <tr>
      <td><small>One with a curly apostrophe — “Don’t forget”.</small></td>
      <td><small>The ’ is outside the standard alphabet, so the whole message is sent the long way round: 70 characters per unit instead of 160.</small></td>
      <td>1 if under 70, otherwise more</td>
    </tr>
  </table>

  <p class="sub" style="font-size:13px;margin-top:14px;">
    <strong>The counter above the message box tells you which of these you are writing</strong>, and it always
    includes the opt-out footer. The confirmation screen then works the total out person by person, because
    someone whose name is <em>Adébáyọ̀</em> can cost two units where everyone else costs one.
  </p>

  <?php if ($balance !== null): ?>
    <p class="sub" style="font-size:13px;">
      The last recorded wallet balance is <strong><?= number_format($balance) ?> unit(s)</strong>
      (<?= e(number_format($balance)) ?> plain messages, or <?= number_format((int) floor($balance / 2)) ?> two-part ones).
      A campaign that runs out mid-send pauses itself rather than failing silently, and resumes once you top up.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Things worth knowing before you send to 500 people</h2>

  <h3 style="font-size:14px;margin:16px 0 6px;">“Sent” means the gateway accepted it</h3>
  <p class="sub" style="font-size:13px;">
    Not that a handset received it. The gateway answers once per API call, not once per number, so no
    screen here can honestly promise that a particular person's phone buzzed. If someone tells you they
    did not get a message, export the campaign report and check their number is not marked failed —
    beyond that, it is between them and their network.
  </p>

  <h3 style="font-size:14px;margin:16px 0 6px;">The worker only runs if cron is running</h3>
  <p class="sub" style="font-size:13px;">
    Nothing sends by itself. A cron entry picks up the queue once a minute. If a campaign sits at
    <em>queued</em> and does not move, that is the first thing to check — the exact lines to add are on the
    <?php if ($isSuper): ?><a href="/admin/sms?tab=settings" style="color:var(--gold-soft);">Settings tab</a><?php else: ?>Settings tab (ask your super admin)<?php endif; ?>.
  </p>

  <h3 style="font-size:14px;margin:16px 0 6px;">Sending is silent outside the window</h3>
  <p class="sub" style="font-size:13px;">
    Messages queued overnight go out when the window opens. Nothing is lost and nothing needs to be
    re-queued — the queue is a queue, not an attempt.
  </p>

  <h3 style="font-size:14px;margin:16px 0 6px;">A blocked word fails the whole batch</h3>
  <p class="sub" style="font-size:13px;">
    If a campaign reads <em>partial</em>, some batches were refused. The most common cause is a phrase the
    provider's filters dislike — often something that reads like a financial promotion. Each failure lists
    the code and what it means, so check that before re-sending, or you will pay to fail twice.
  </p>

  <h3 style="font-size:14px;margin:16px 0 6px;">Opt-outs are permanent and automatic</h3>
  <p class="sub" style="font-size:13px;">
    A number marked opted out is left out of every campaign, even if you select it directly, and even if it
    is in a group. You can see who they are by filtering the address book. That is deliberate: honouring a
    STOP is not optional, and it protects the church's sender ID from being blocked.
  </p>

  <h3 style="font-size:14px;margin:16px 0 6px;">Test on yourself, every time</h3>
  <p class="sub" style="font-size:13px;">
    The composer has a <strong>Send a test to myself</strong> button. It costs one unit. Use it to check
    the sender ID shows as you expect, and that a placeholder has not been left unfilled — a message that
    reaches 500 people reading “Hi {name}” cannot be recalled.
  </p>
</div>

<div class="card">
  <h2>If something goes wrong</h2>
  <table class="resp-table">
    <tr><th>What you see</th><th>What it means, and what to do</th></tr>
    <tr>
      <td><small>Campaign stuck on <em>queued</em></small></td>
      <td><small>The cron worker is not running, or it is outside the sending window. Check the cron entry, then check the clock.</small></td>
    </tr>
    <tr>
      <td><small>Campaign paused, with a reason</small></td>
      <td><small>Usually a short wallet. Top up, then press <strong>Resume</strong> on the campaign — the recipients are still waiting, nothing was charged.</small></td>
    </tr>
    <tr>
      <td><small>Everything failed with code <strong>401</strong></small></td>
      <td><small>The API token is wrong or has been rotated. A super admin needs to paste the new one under Settings.</small></td>
    </tr>
    <tr>
      <td><small>Everything failed with code <strong>405</strong></small></td>
      <td><small>The sender ID is not approved. Check its status on the Sender IDs tab.</small></td>
    </tr>
    <tr>
      <td><small>Some recipients failed, one error code</small></td>
      <td><small>Their numbers are probably wrong — a landline, or a digit missing. Open the failure list and read the reason for each.</small></td>
    </tr>
    <tr>
      <td><small>“That audience is empty”</small></td>
      <td><small>Everybody in it has opted out, or is outside your scope. The contacts screen will show you who.</small></td>
    </tr>
    <tr>
      <td><small>A campaign half-finished after a server restart</small></td>
      <td><small>Recipients are only claimed for 10 minutes, so the next run picks them up automatically. If it is urgent, open the campaign and press <strong>Release stuck claims</strong> — but only if you are sure the worker is not still running, or someone may be texted twice.</small></td>
    </tr>
  </table>
</div>
