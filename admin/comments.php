<?php
declare(strict_types=1);

/**
 * Comments moderation queue.
 *
 * New comments are screened by CommentModeration on the way in; this screen is
 * where a leader clears the queue — approve, reject, mark spam or delete, in
 * bulk or one at a time — and where the word lists and the overall moderation
 * mode live.
 *
 * Scope follows the comment's church (`media_posts.org_unit_id`), so a church
 * admin only ever sees comments on their own posts. No inline JavaScript here:
 * the site's production CSP allows `script-src 'self'` only.
 */

Auth::requireRole('admin', 'editor', 'media_team');

$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$scope = Unit::scopeClause($user, 'p.org_unit_id');
$scopeSql = $scope !== '' ? ' AND ' . $scope : '';

$tab = ($_GET['action'] ?? '') === 'settings' ? 'settings' : 'queue';
$statusParam = (string) ($_GET['status'] ?? '');
$statusFilter = in_array($statusParam, ['approved', 'pending', 'rejected', 'spam'], true) ? $statusParam : 'pending';
$flaggedOnly = !empty($_GET['flagged']);
$query = trim((string) ($_GET['q'] ?? ''));
$returnTo = '/admin/comments?status=' . urlencode($statusFilter) . ($flaggedOnly ? '&flagged=1' : '');
$errors = [];

$verbs = ['approve', 'reject', 'spam', 'pending', 'delete'];
$verbLabels = [
    'approve' => 'approved',
    'reject' => 'rejected',
    'spam' => 'marked as spam',
    'pending' => 'returned to review',
    'delete' => 'deleted',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $verb = (string) ($_POST['verb'] ?? '');

    if ($verb === 'save_settings') {
        $mode = (string) ($_POST['comments_moderation'] ?? 'off');
        if (!array_key_exists($mode, CommentModeration::MODES)) {
            $mode = 'off';
        }
        $threshold = max(1, min(50, (int) ($_POST['comments_flag_threshold'] ?? 3)));
        try {
            settingSave(['comments_moderation' => $mode, 'comments_flag_threshold' => $threshold]);
            $saved = CommentModeration::saveLists((string) ($_POST['block_words'] ?? ''), (string) ($_POST['spam_words'] ?? ''));
            flash('success', 'Moderation settings saved — ' . $saved . ' word(s) in the lists.');
        } catch (Throwable $e) {
            flash('error', 'Could not save the settings: ' . $e->getMessage());
        }
        redirect('/admin/comments?action=settings');
    }

    if (in_array($verb, $verbs, true)) {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $i): bool => $i > 0));
        if (!$ids) {
            flash('error', 'Select at least one comment first.');
            redirect($returnTo);
        }

        // Only ever act on comments inside this user's scope.
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $allowed = $pdo->prepare("SELECT c.id FROM post_comments c JOIN media_posts p ON p.id = c.media_post_id WHERE c.id IN ({$marks}){$scopeSql}");
        $allowed->execute($ids);
        $ok = array_map('intval', $allowed->fetchAll(PDO::FETCH_COLUMN));

        if (!$ok) {
            flash('error', 'Those comments belong to churches outside your scope.');
            redirect($returnTo);
        }

        $marks = implode(',', array_fill(0, count($ok), '?'));
        if ($verb === 'delete') {
            $pdo->prepare("DELETE FROM post_comments WHERE id IN ({$marks})")->execute($ok);
        } else {
            $note = trim((string) ($_POST['moderator_note'] ?? ''));
            $pdo->prepare("UPDATE post_comments SET status = ?, moderated_by = ?, moderated_at = NOW(), moderator_note = ?, is_flagged = 0, is_published = 1 WHERE id IN ({$marks})")
                ->execute(array_merge([
                    $verb,
                    $user['id'] ?? null,
                    $note !== '' ? mb_substr($note, 0, 255) : null,
                ], $ok));
        }

        flash('success', count($ok) . ' comment(s) ' . ($verbLabels[$verb] ?? 'updated') . '.');
        redirect($returnTo);
    }
}

/* ---- counts for the summary cards ---- */
$counts = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'spam' => 0, 'total' => 0];
foreach ($pdo->query("SELECT c.status, COUNT(*) AS n FROM post_comments c JOIN media_posts p ON p.id = c.media_post_id WHERE 1=1{$scopeSql} GROUP BY c.status")->fetchAll() as $row) {
    $counts[(string) $row['status']] = (int) $row['n'];
    $counts['total'] += (int) $row['n'];
}
$counts['flagged'] = (int) $pdo->query("SELECT COUNT(*) FROM post_comments c JOIN media_posts p ON p.id = c.media_post_id WHERE c.is_flagged = 1{$scopeSql}")->fetchColumn();

/* ---- the queue itself ---- */
$where = ['1=1'];
$params = [];
if (!$flaggedOnly) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}
if ($query !== '') {
    $where[] = '(c.message LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $query . '%';
    $params[] = '%' . $query . '%';
}
$stmt = $pdo->prepare(
    'SELECT c.*, p.caption, p.id AS post_id, u.name AS unit_name
     FROM post_comments c
     JOIN media_posts p ON p.id = c.media_post_id
     LEFT JOIN org_units u ON u.id = p.org_unit_id
     WHERE ' . implode(' AND ', $where) . $scopeSql . '
     ORDER BY c.is_flagged DESC, c.created_at DESC
     LIMIT 200'
);
$stmt->execute($params);
$comments = $stmt->fetchAll();

$mode = CommentModeration::mode();
$threshold = CommentModeration::flagThreshold();
$lists = CommentModeration::lists();

$badgeFor = static function (string $status): string {
    return match ($status) {
        'approved' => 'ok',
        'pending' => 'warn',
        'spam' => 'fail',
        default => 'info',
    };
};

$pageTitle = 'Comments';
$activeNav = 'comments';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($mode === 'off'): ?>
  <div class="alert error" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
    <span><strong>Moderation is off.</strong> Every comment publishes the moment it is written. Turn it on to review comments before they appear.</span>
    <a class="btn sm" href="/admin/comments?action=settings">Turn on moderation</a>
  </div>
<?php endif; ?>

<div class="btn-row" style="margin-bottom:16px;">
  <a class="btn <?= $tab === 'queue' ? '' : 'secondary' ?> sm" href="/admin/comments">Queue</a>
  <a class="btn <?= $tab === 'settings' ? '' : 'secondary' ?> sm" href="/admin/comments?action=settings">Settings &amp; word lists</a>
</div>

<?php if ($tab === 'settings'): ?>

  <div class="card" style="max-width:720px;">
    <h2>Moderation mode</h2>
    <p class="sub">How much should be held back for a leader to review before it appears on the site and in the app?</p>
    <form method="post" action="/admin/comments">
      <?= Csrf::field() ?>
      <input type="hidden" name="verb" value="save_settings">

      <label for="comments_moderation">Mode</label>
      <select id="comments_moderation" name="comments_moderation">
        <?php foreach (CommentModeration::MODES as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $mode === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="sub" style="margin-top:-6px;">
        <strong>Off</strong> is the default and changes nothing about how the site behaves today.
        Start with <strong>Hold comments containing a blocked word</strong> if you only want to catch the obvious cases.
      </p>

      <label for="comments_flag_threshold">Reader reports before a comment is flagged</label>
      <input type="number" id="comments_flag_threshold" name="comments_flag_threshold" min="1" max="50" value="<?= (int) $threshold ?>">
      <p class="sub" style="margin-top:-6px;">A flagged comment is not hidden automatically — it is pushed to the top of the queue so you see it first.</p>

      <label for="block_words">Blocked words — one per line (hold for review)</label>
      <textarea id="block_words" name="block_words" rows="6" placeholder="e.g.&#10;scam&#10;abeg&#10;see my page"><?= e(implode("\n", $lists['block'])) ?></textarea>

      <label for="spam_words">Spam words — one per line (send straight to the spam queue)</label>
      <textarea id="spam_words" name="spam_words" rows="6" placeholder="e.g.&#10;buy now&#10;click here&#10;free money"><?= e(implode("\n", $lists['spam'])) ?></textarea>
      <p class="sub" style="margin-top:-6px;">Matching ignores capital letters and looks anywhere in the comment or the commenter's name.</p>

      <div class="btn-row">
        <button class="btn" type="submit">Save Settings</button>
      </div>
    </form>
  </div>

<?php else: ?>

  <div class="card">
    <h2>Comments queue</h2>
    <p class="sub">
      <?= (int) $counts['total'] ?> comment(s) in your scope.
      Flagged comments are shown first, no matter which filter is active.
    </p>

    <div class="btn-row" style="margin-bottom:14px;flex-wrap:wrap;">
      <?php
      $chips = [
          ['status' => 'pending', 'label' => 'Awaiting review', 'count' => $counts['pending']],
          ['status' => 'approved', 'label' => 'Approved', 'count' => $counts['approved']],
          ['status' => 'spam', 'label' => 'Spam', 'count' => $counts['spam']],
          ['status' => 'rejected', 'label' => 'Rejected', 'count' => $counts['rejected']],
      ];
      foreach ($chips as $chip):
          $isActive = !$flaggedOnly && $statusFilter === $chip['status'];
      ?>
        <a class="btn <?= $isActive ? '' : 'secondary' ?> sm" href="/admin/comments?status=<?= e($chip['status']) ?>">
          <?= e($chip['label']) ?> <strong>(<?= (int) $chip['count'] ?>)</strong>
        </a>
      <?php endforeach; ?>
      <a class="btn <?= $flaggedOnly ? 'danger' : 'secondary' ?> sm" href="/admin/comments?flagged=1">
        🚩 Flagged <strong>(<?= (int) $counts['flagged'] ?>)</strong>
      </a>
    </div>

    <form method="get" action="/admin/comments" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
      <div style="flex:1;min-width:220px;">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($query) ?>" placeholder="Search the comment text or the commenter's name">
      </div>
      <?php if ($flaggedOnly): ?><input type="hidden" name="flagged" value="1"><?php else: ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
      <button class="btn secondary" type="submit">Search</button>
      <?php if ($query !== ''): ?><a class="btn secondary" href="<?= e($returnTo) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$comments): ?>
      <div class="empty">
        Nothing here. <?= $flaggedOnly ? 'No comments have been flagged.' : 'No ' . e($statusFilter) . ' comments right now.' ?>
      </div>
    <?php else: ?>
      <form method="post" action="/admin/comments">
        <?= Csrf::field() ?>
        <table>
          <tr>
            <th style="width:34px;"><input type="checkbox" data-check-all aria-label="Select all"></th>
            <th>Comment</th>
            <th style="width:170px;">On the post</th>
            <th style="width:150px;">Church</th>
            <th style="width:120px;">Received</th>
            <th style="width:130px;">State</th>
            <th style="width:190px;"></th>
          </tr>
          <?php foreach ($comments as $c): ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="Select this comment"></td>
              <td>
                <strong><?= e($c['name'] !== null && $c['name'] !== '' ? $c['name'] : 'Anonymous') ?></strong>
                <?php if (!empty($c['is_flagged'])): ?><span class="badge fail">🚩 flagged</span><?php endif; ?>
                <?php if (!empty($c['report_count'])): ?><span class="badge warn"><?= (int) $c['report_count'] ?> report(s)</span><?php endif; ?>
                <div style="color:var(--ink-dim);margin-top:4px;white-space:pre-wrap;"><?= e(mb_substr((string) $c['message'], 0, 600)) ?><?= mb_strlen((string) $c['message']) > 600 ? '…' : '' ?></div>
                <?php if (!empty($c['image_path']) && ($img = uploadUrl((string) $c['image_path']))): ?>
                  <a href="<?= e($img) ?>" target="_blank" rel="noopener" style="display:inline-block;margin-top:8px;">
                    <img src="<?= e($img) ?>" alt="Attached image" style="max-height:110px;border-radius:8px;border:1px solid var(--border);">
                  </a>
                <?php endif; ?>
                <?php if (!empty($c['held_reason'])): ?>
                  <div style="font-size:11.5px;color:var(--ink-faint);margin-top:6px;">Held because: <?= e($c['held_reason']) ?></div>
                <?php endif; ?>
                <?php if (!empty($c['moderator_note'])): ?>
                  <div style="font-size:11.5px;color:var(--ink-faint);margin-top:4px;">Note: <?= e($c['moderator_note']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <a href="/admin/media?action=edit&id=<?= (int) $c['post_id'] ?>" style="color:var(--gold-soft);">
                  <?= e(mb_substr((string) ($c['caption'] ?? '') !== '' ? (string) $c['caption'] : 'Post #' . (int) $c['post_id'], 0, 60)) ?>
                </a>
              </td>
              <td style="color:var(--ink-dim);"><?= e($c['unit_name'] ?? '—') ?></td>
              <td style="color:var(--ink-faint);font-size:12px;"><?= e(date('M j, Y', strtotime((string) $c['created_at']))) ?><br><?= e(date('g:i A', strtotime((string) $c['created_at']))) ?></td>
              <td>
                <span class="badge <?= $badgeFor((string) $c['status']) ?>"><?= e($c['status']) ?></span>
                <?php if ((int) $c['is_published'] === 0): ?><br><span class="badge fail">hidden</span><?php endif; ?>
              </td>
              <td>
                <div class="btn-row" style="margin:0;gap:4px;flex-wrap:wrap;">
                  <?php if ($c['status'] !== 'approved'): ?>
                    <button class="btn sm" type="submit" name="verb" value="approve">Approve</button>
                  <?php endif; ?>
                  <?php if ($c['status'] !== 'spam'): ?>
                    <button class="btn secondary sm" type="submit" name="verb" value="spam">Spam</button>
                  <?php endif; ?>
                  <?php if ($c['status'] !== 'approved'): ?>
                    <button class="btn secondary sm" type="submit" name="verb" value="reject">Reject</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>

        <div class="card" style="margin:16px 0 0;padding:16px;">
          <h2 style="font-size:14px;">Apply to the selected comments</h2>
          <p class="sub" style="margin-bottom:12px;">Tick the comments above, choose an action, then apply. Deleting cannot be undone.</p>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:220px;">
              <label for="moderator_note">Note (optional — kept with the comment)</label>
              <input type="text" id="moderator_note" name="moderator_note" maxlength="255" placeholder="e.g. off-topic">
            </div>
            <button class="btn" type="submit" name="verb" value="approve">Approve</button>
            <button class="btn secondary" type="submit" name="verb" value="reject">Reject</button>
            <button class="btn secondary" type="submit" name="verb" value="spam">Spam</button>
            <button class="btn danger" type="submit" name="verb" value="delete">Delete</button>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
