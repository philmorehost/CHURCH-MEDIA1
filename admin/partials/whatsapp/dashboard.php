<?php
declare(strict_types=1);

/**
 * WhatsApp → Dashboard.
 *
 * Read-only. Answers the two questions an admin actually has: is this working, and is anything
 * waiting for me.
 */
$where = (string) $waContext['conv_where'];
$params = $waContext['conv_params'];

$count = static function (string $sql, array $args) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return (int) $stmt->fetchColumn();
};

$totalConversations = $count('SELECT COUNT(*) FROM wa_conversations c WHERE 1=1' . $where, $params);
$unreadConversations = $count('SELECT COUNT(*) FROM wa_conversations c WHERE c.unread_count > 0' . $where, $params);

// The window is per conversation, so "open" has to be counted in SQL against the stored expiry
// rather than filtered in PHP — otherwise the count would depend on a page size.
$windowOpen = $count(
    'SELECT COUNT(*) FROM wa_conversations c WHERE c.window_expires_at IS NOT NULL AND c.window_expires_at > NOW()' . $where,
    $params
);
$optedOut = $count('SELECT COUNT(*) FROM wa_conversations c WHERE c.is_opted_out = 1' . $where, $params);

$messagesIn = $count(
    'SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE m.direction = "in"' . $where,
    $params
);
$messagesOut = $count(
    'SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE m.direction = "out"' . $where,
    $params
);
$failedOut = $count(
    'SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE m.direction = "out" AND m.status = "failed"' . $where,
    $params
);

$templateRows = $pdo->query('SELECT status, COUNT(*) AS n FROM wa_templates GROUP BY status')->fetchAll();
$templateCounts = [];
foreach ($templateRows as $row) {
    $templateCounts[(string) $row['status']] = (int) $row['n'];
}
$approvedTemplates = $templateCounts['approved'] ?? 0;
$templateTotal = array_sum($templateCounts);

// The most recent activity, so an admin can see whether anything is arriving at all.
$recent = $pdo->prepare(
    'SELECT c.id, c.msisdn, c.display_name, c.unread_count, c.window_expires_at, c.last_inbound_at, c.is_opted_out,
            (SELECT COUNT(*) FROM wa_messages m WHERE m.conversation_id = c.id) AS message_count
     FROM wa_conversations c
     WHERE 1=1' . $where . '
     ORDER BY COALESCE(c.last_inbound_at, c.last_outbound_at, c.created_at) DESC
     LIMIT 8'
);
$recent->execute($params);
$recentConversations = $recent->fetchAll();
?>

<div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:20px;">
  <div class="card" style="margin:0;">
    <div style="font-size:12px;color:var(--ink-dim);text-transform:uppercase;letter-spacing:.6px;">Conversations</div>
    <div style="font-size:28px;font-weight:800;margin-top:4px;"><?= $totalConversations ?></div>
    <div style="font-size:12px;color:var(--ink-dim);"><?= $unreadConversations ?> with unread messages</div>
  </div>
  <div class="card" style="margin:0;">
    <div style="font-size:12px;color:var(--ink-dim);text-transform:uppercase;letter-spacing:.6px;">Reply window open</div>
    <div style="font-size:28px;font-weight:800;margin-top:4px;"><?= $windowOpen ?></div>
    <div style="font-size:12px;color:var(--ink-dim);">free replies allowed right now</div>
  </div>
  <div class="card" style="margin:0;">
    <div style="font-size:12px;color:var(--ink-dim);text-transform:uppercase;letter-spacing:.6px;">Messages</div>
    <div style="font-size:28px;font-weight:800;margin-top:4px;"><?= $messagesIn + $messagesOut ?></div>
    <div style="font-size:12px;color:var(--ink-dim);"><?= $messagesIn ?> in · <?= $messagesOut ?> out</div>
  </div>
  <div class="card" style="margin:0;">
    <div style="font-size:12px;color:var(--ink-dim);text-transform:uppercase;letter-spacing:.6px;">Templates</div>
    <div style="font-size:28px;font-weight:800;margin-top:4px;"><?= $approvedTemplates ?></div>
    <div style="font-size:12px;color:var(--ink-dim);">approved of <?= $templateTotal ?> recorded</div>
  </div>
</div>

<?php if ($failedOut > 0): ?>
  <div class="alert error">
    <strong><?= $failedOut ?></strong> outgoing message<?= $failedOut === 1 ? '' : 's' ?> failed to send.
    <?php if ($isSuper): ?>
      WhatsApp → Inbox shows which ones and why. A common cause is a closed reply window, which
      requires an approved template instead of free text.
    <?php else: ?>
      Ask a super admin to check the WhatsApp setup.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($optedOut > 0): ?>
  <div class="alert" style="background:var(--panel);border:1px solid var(--border);">
    <?= $optedOut ?> conversation<?= $optedOut === 1 ? ' has' : 's have' ?> opted out. They are still listed
    so you can see the history, but they will not be messaged from the broadcast composer.
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Setup</h2>
  <table>
    <tr>
      <th style="width:38%;">Channel</th>
      <td>
        <?= WhatsApp::enabled()
            ? '<span class="badge ok">on</span>'
            : '<span class="badge warn">off</span>' ?>
      </td>
    </tr>
    <tr>
      <th>Phone number ID</th>
      <td><?= WhatsApp::phoneNumberId() !== '' ? '<code>' . e(WhatsApp::phoneNumberId()) . '</code>' : '<span class="badge warn">not set</span>' ?></td>
    </tr>
    <tr>
      <th>Access token</th>
      <td><?= WhatsApp::accessToken() !== '' ? '<code>' . e(WhatsApp::maskedToken()) . '</code>' : '<span class="badge warn">not set</span>' ?></td>
    </tr>
    <tr>
      <th>App secret</th>
      <td>
        <?php if (WhatsApp::appSecret() !== ''): ?>
          <code><?= e(WhatsApp::maskedAppSecret()) ?></code>
        <?php else: ?>
          <span class="badge fail">not set</span>
          <div style="font-size:12.5px;color:var(--ink-dim);margin-top:4px;">
            Without this, every incoming message is rejected. Meta signs each webhook request with it.
          </div>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>Webhook address</th>
      <td>
        <code><?= e(baseUrl('/api/wa-webhook')) ?></code>
        <div style="font-size:12.5px;color:var(--ink-dim);margin-top:4px;">
          Paste this into Meta → WhatsApp → Configuration → Webhook. Meta will ask for the verify
          token you set under Settings.
        </div>
      </td>
    </tr>
    <tr>
      <th>Unofficial bridge</th>
      <td>
        <?= WhatsApp::bridgeEnabled()
            ? '<span class="badge warn">on</span> <span style="font-size:12.5px;color:var(--ink-dim);">group features only — never member messaging</span>'
            : '<span class="badge ok">off</span>' ?>
      </td>
    </tr>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;">Recent activity</h2>
  <?php if (!$recentConversations): ?>
    <div class="empty-state">Nothing yet. Messages will appear here once the webhook is connected.</div>
  <?php else: ?>
    <table>
      <tr><th>Who</th><th>Messages</th><th>Last heard</th><th>Reply window</th><th></th></tr>
      <?php foreach ($recentConversations as $c): ?>
        <tr>
          <td>
            <?= e($c['display_name'] ?: 'Unknown') ?>
            <div style="font-size:12px;color:var(--ink-dim);"><?= e(Sms::prettyMsisdn((string) $c['msisdn'])) ?></div>
          </td>
          <td><?= (int) $c['message_count'] ?></td>
          <td>
            <?= !empty($c['last_inbound_at'])
                ? e(date('M j, Y g:i a', strtotime((string) $c['last_inbound_at'])))
                : '<span style="color:var(--ink-dim);">never</span>' ?>
          </td>
          <td>
            <?php $open = WhatsApp::isWindowOpen($c['window_expires_at'] !== null ? (string) $c['window_expires_at'] : null); ?>
            <span class="<?= $open ? 'wa-window-open' : 'wa-window-closed' ?>">
              <?= $open ? 'open' : 'closed' ?>
            </span>
          </td>
          <td>
            <?php if ((int) $c['unread_count'] > 0): ?>
              <span class="wa-unread"><?= (int) $c['unread_count'] ?></span>
            <?php endif; ?>
            <a class="btn secondary sm" href="/admin/whatsapp?tab=inbox&conversation=<?= (int) $c['id'] ?>">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
