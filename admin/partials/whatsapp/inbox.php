<?php
declare(strict_types=1);

/**
 * WhatsApp → Inbox.
 *
 * A list of conversations, and one thread with a reply box.
 *
 * The reply box changes shape depending on the 24-hour window, and that is the whole point of
 * this screen. Inside the window the admin writes whatever they like. Outside it Meta will not
 * accept free text at all, so the screen offers templates instead and says why — rather than
 * presenting a text box whose contents are silently refused.
 */

$where = (string) $waContext['conv_where'];
$params = $waContext['conv_params'];
$user = $waContext['user'];
$userId = (int) $user['id'];

/* ---------------------------------------------------------------- the conversation being read */

$conversationId = (int) ($_GET['conversation'] ?? 0);
$conversation = null;

if ($conversationId > 0) {
    $stmt = $pdo->prepare('SELECT c.* FROM wa_conversations c WHERE c.id = ?' . $where . ' LIMIT 1');
    $stmt->execute(array_merge([$conversationId], $params));
    $conversation = $stmt->fetch() ?: null;
}

/* ------------------------------------------------------------------------ reply handling */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    Csrf::requireValid();

    $targetId = (int) ($_POST['conversation_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT c.* FROM wa_conversations c WHERE c.id = ?' . $where . ' LIMIT 1');
    $stmt->execute(array_merge([$targetId], $params));
    $target = $stmt->fetch() ?: null;

    if (!$target) {
        flash('error', 'That conversation is not available to your account.');
        redirect('/admin/whatsapp?tab=inbox');
    }

    if ((int) $target['is_opted_out'] === 1) {
        // Opting out is a decision the church must honour, not a prompt to try again.
        flash('error', 'That person has opted out of WhatsApp messages, so nothing was sent.');
        redirect('/admin/whatsapp?tab=inbox&conversation=' . $targetId);
    }

    $mode = (string) ($_POST['mode'] ?? 'text');

    if ($mode === 'template') {
        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        $raw = (string) ($_POST['template_params'] ?? '');
        // One parameter per line: templates take a fixed list of positional values, and a single
        // free-text box would make an admin guess where one ends and the next begins.
        $templateParams = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []),
            static fn (string $v): bool => $v !== ''
        ));

        $result = WhatsApp::sendTemplateToConversation($target, $templateName, $templateParams, $userId);
    } else {
        $result = WhatsApp::replyToConversation($target, (string) ($_POST['body'] ?? ''), $userId);
    }

    if (!empty($result['ok'])) {
        flash('success', 'Sent.');
    } else {
        flash('error', 'Not sent: ' . (string) ($result['error'] ?? 'unknown error'));
    }
    redirect('/admin/whatsapp?tab=inbox&conversation=' . $targetId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['conversation_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT c.id FROM wa_conversations c WHERE c.id = ?' . $where . ' LIMIT 1');
    $stmt->execute(array_merge([$targetId], $params));
    if ($stmt->fetchColumn()) {
        $pdo->prepare('UPDATE wa_conversations SET unread_count = 0 WHERE id = ?')->execute([$targetId]);
    }
    redirect('/admin/whatsapp?tab=inbox&conversation=' . $targetId);
}

/* --------------------------------------------------------------------------- the thread */

$messages = [];
if ($conversation !== null) {
    // Opening a conversation clears its badge. Anything else would mean an admin reads a message
    // and it still looks unread, which trains them to ignore the badge.
    if ((int) $conversation['unread_count'] > 0) {
        $pdo->prepare('UPDATE wa_conversations SET unread_count = 0 WHERE id = ?')->execute([$conversationId]);
        $conversation['unread_count'] = 0;
    }

    $stmt = $pdo->prepare('SELECT * FROM wa_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT 300');
    $stmt->execute([$conversationId]);
    $messages = $stmt->fetchAll();
}

$windowOpen = $conversation !== null
    ? WhatsApp::isWindowOpen($conversation['window_expires_at'] !== null ? (string) $conversation['window_expires_at'] : null)
    : false;

// Only approved templates may be sent: drafting one here and sending it would fail at Meta.
$approvedTemplates = $pdo->query(
    "SELECT name, language, body_text FROM wa_templates WHERE status = 'approved' ORDER BY name ASC"
)->fetchAll();

/* ---------------------------------------------------------------------- conversation list */

$list = [];
if ($conversation === null) {
    $search = trim((string) ($_GET['q'] ?? ''));
    $listWhere = $where;
    $listParams = $params;

    if ($search !== '') {
        $digits = preg_replace('/\D/', '', $search) ?? '';
        $listWhere .= ' AND (c.display_name LIKE ? OR c.msisdn LIKE ?)';
        $listParams[] = '%' . $search . '%';
        $listParams[] = '%' . $digits . '%';
    }

    $stmt = $pdo->prepare(
        'SELECT c.*,
                (SELECT m.body FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_body,
                (SELECT m.direction FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_direction
         FROM wa_conversations c
         WHERE 1=1' . $listWhere . '
         ORDER BY c.unread_count DESC, COALESCE(c.last_inbound_at, c.last_outbound_at, c.created_at) DESC
         LIMIT 100'
    );
    $stmt->execute($listParams);
    $list = $stmt->fetchAll();
}
?>

<?php if ($conversation === null): ?>

  <div class="card">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
      <form method="get" action="/admin/whatsapp" style="display:flex;gap:8px;flex:1;min-width:220px;">
        <input type="hidden" name="tab" value="inbox">
        <input type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>"
               placeholder="Search by name or number" style="flex:1;">
        <button class="btn secondary" type="submit">Search</button>
      </form>
      <?php if (!empty($_GET['q'])): ?>
        <a class="btn secondary" href="/admin/whatsapp?tab=inbox">Clear</a>
      <?php endif; ?>
    </div>

    <?php if (!$list): ?>
      <div class="empty-state">
        No conversations yet. They appear here as soon as somebody messages the church's WhatsApp number.
      </div>
    <?php else: ?>
      <table>
        <tr><th>Who</th><th>Last message</th><th>When</th><th>Reply window</th><th></th></tr>
        <?php foreach ($list as $c): ?>
          <?php
            $open = WhatsApp::isWindowOpen($c['window_expires_at'] !== null ? (string) $c['window_expires_at'] : null);
            $activity = $c['last_inbound_at'] ?: ($c['last_outbound_at'] ?: $c['created_at']);
            $preview = (string) ($c['last_body'] ?? '');
          ?>
          <tr>
            <td>
              <?php if ((int) $c['unread_count'] > 0): ?>
                <span class="wa-unread"><?= (int) $c['unread_count'] ?></span>
              <?php endif; ?>
              <?= e($c['display_name'] ?: 'Unknown') ?>
              <?php if ((int) $c['is_opted_out'] === 1): ?>
                <span class="badge warn" style="font-size:11px;">opted out</span>
              <?php endif; ?>
              <div style="font-size:12px;color:var(--ink-dim);"><?= e(Sms::prettyMsisdn((string) $c['msisdn'])) ?></div>
            </td>
            <td style="max-width:280px;">
              <span style="color:var(--ink-dim);font-size:12.5px;">
                <?= $c['last_direction'] === 'out' ? 'You: ' : '' ?><?= e(mb_strimwidth($preview, 0, 80, '…')) ?>
              </span>
            </td>
            <td style="white-space:nowrap;"><?= e(date('M j, g:i a', strtotime((string) $activity))) ?></td>
            <td>
              <span class="<?= $open ? 'wa-window-open' : 'wa-window-closed' ?>"><?= $open ? 'open' : 'closed' ?></span>
            </td>
            <td><a class="btn secondary sm" href="/admin/whatsapp?tab=inbox&conversation=<?= (int) $c['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php else: ?>

  <p style="margin-bottom:14px;"><a href="/admin/whatsapp?tab=inbox" style="color:var(--gold-soft);">← All conversations</a></p>

  <div class="card">
    <h2 style="margin-top:0;">
      <?= e($conversation['display_name'] ?: 'Unknown') ?>
      <span style="font-size:13px;font-weight:400;color:var(--ink-dim);">
        · <?= e(Sms::prettyMsisdn((string) $conversation['msisdn'])) ?>
      </span>
    </h2>

    <p class="<?= $windowOpen ? 'wa-window-open' : 'wa-window-closed' ?>" style="margin-top:-6px;">
      <?= e(WhatsApp::windowLabel($conversation['window_expires_at'] !== null ? (string) $conversation['window_expires_at'] : null)) ?>
    </p>

    <?php if (!empty($conversation['org_unit_id']) && isset($waContext['unit_labels'][(int) $conversation['org_unit_id']])): ?>
      <p style="font-size:12.5px;color:var(--ink-dim);">
        <?= e($waContext['unit_labels'][(int) $conversation['org_unit_id']]) ?>
      </p>
    <?php endif; ?>

    <div class="wa-thread">
      <?php if (!$messages): ?>
        <div class="empty-state">No messages in this conversation yet.</div>
      <?php endif; ?>
      <?php foreach ($messages as $m): ?>
        <?php
          $out = $m['direction'] === 'out';
          $failed = $out && $m['status'] === 'failed';
          $class = $failed ? 'failed' : ($out ? 'out' : 'in');
          $stamp = $m['created_at'] ? date('M j, g:i a', strtotime((string) $m['created_at'])) : '';
        ?>
        <div class="wa-bubble <?= $class ?>">
          <?php if (!empty($m['template_name'])): ?>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--gold-soft);margin-bottom:4px;">
              Template: <?= e((string) $m['template_name']) ?>
            </div>
          <?php endif; ?>

          <?php
            // A voice note or a photo has no text. Saying so is better than an empty bubble that
            // looks like a rendering fault.
            $text = (string) ($m['body'] ?? '');
            if ($text === '') {
                $text = match ($m['type']) {
                    'audio' => '🎤 Voice message',
                    'image' => '🖼 Photo',
                    'video' => '🎬 Video',
                    'document' => '📄 Document',
                    'sticker' => '🌸 Sticker',
                    'location' => '📍 Location',
                    default => '[' . e((string) $m['type']) . ']',
                };
            }
          ?>
          <?= e($text) ?>

          <div class="wa-meta">
            <?= e($stamp) ?>
            <?php if ($out): ?>
              · <?= e((string) $m['status']) ?>
              <?php if ($failed && !empty($m['error_note'])): ?>
                — <?= e((string) $m['error_note']) ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ((int) $conversation['is_opted_out'] === 1): ?>
    <div class="alert error">
      This person has opted out, so nothing can be sent from here. That is deliberate: honouring an
      opt-out is not optional.
    </div>
  <?php elseif (!$windowOpen): ?>
    <div class="card">
      <h2 style="margin-top:0;">The reply window has closed</h2>
      <p style="color:var(--ink-dim);font-size:14px;">
        WhatsApp only allows a free-form reply within 24 hours of the person's last message. It has
        been longer than that here, so the only thing that may be sent is a message template that
        Meta has already approved.
      </p>

      <?php if (!$approvedTemplates): ?>
        <div class="empty-state">
          No approved templates are recorded yet.
          <?php if ($waContext['is_super']): ?>
            Add one under <strong>Templates</strong>, then submit it to Meta for approval.
          <?php else: ?>
            A super admin can add one under <strong>Templates</strong>.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <form method="post" action="/admin/whatsapp?tab=inbox&conversation=<?= (int) $conversation['id'] ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="conversation_id" value="<?= (int) $conversation['id'] ?>">
          <input type="hidden" name="mode" value="template">

          <label for="template_name">Template</label>
          <select id="template_name" name="template_name" required>
            <?php foreach ($approvedTemplates as $t): ?>
              <option value="<?= e((string) $t['name']) ?>">
                <?= e((string) $t['name']) ?> (<?= e((string) $t['language']) ?>)
              </option>
            <?php endforeach; ?>
          </select>

          <label for="template_params">Values for the template's placeholders</label>
          <textarea id="template_params" name="template_params" rows="3"
                    placeholder="One value per line, in order"></textarea>
          <p class="hint" style="margin-top:-6px;font-size:12.5px;color:var(--ink-dim);">
            A template with <code>{{1}}</code> and <code>{{2}}</code> needs two lines here.
          </p>

          <div class="btn-row">
            <button class="btn" type="submit">Send template</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <h2 style="margin-top:0;">Reply</h2>
      <form method="post" action="/admin/whatsapp?tab=inbox&conversation=<?= (int) $conversation['id'] ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="conversation_id" value="<?= (int) $conversation['id'] ?>">
        <input type="hidden" name="mode" value="text">

        <label for="body">Message</label>
        <textarea id="body" name="body" rows="4" required maxlength="<?= WhatsApp::MAX_TEXT_LENGTH ?>"
                  placeholder="Type your reply"></textarea>
        <p class="hint" style="margin-top:-6px;font-size:12.5px;color:var(--ink-dim);">
          Free replies are allowed because this person messaged recently. Once the window closes,
          only a template can be sent.
        </p>

        <div class="btn-row">
          <button class="btn" type="submit">Send</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>
