<?php
declare(strict_types=1);

/**
 * Daily devotionals — a month at a glance, and an editor.
 *
 * Built around the calendar rather than a list, because the question this screen has to answer
 * is "what is going out on Sunday?", and a list ordered by title cannot answer it.
 *
 * Scoping follows the sermons screen: an admin sees their own church's entries plus the
 * church-wide ones, and may only change their own. A church-wide entry is shown read-only to a
 * scoped admin, marked "head office", rather than offering buttons that would refuse.
 *
 * One entry per day is shown even when a church and head office both wrote one. `Devotional::month()`
 * returns the winner per day — and the winner is the church's own, which is what will actually be
 * read that day. For a super admin that means a shadowed church-wide entry is not listed here.
 *
 * The daily notification is scheduled from here too: whether it is switched on, when today's went
 * out, and the exact cron line. The send itself lives in cli/devotional_worker.php, because cron
 * is the only thing running when nobody is looking at a screen.
 *
 * A new entry can be started from a sermon, which copies its title, reference and notes in and
 * remembers the link in `sermon_id` — most devotionals come from a message someone already
 * preached, so typing it out again is the part worth removing.
 */

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$isSuper = Auth::isSuperAdmin();
$myUnitId = !empty($user['org_unit_id']) ? (int) $user['org_unit_id'] : 0;
$action = (string) ($_GET['action'] ?? 'month');
$id = (int) ($_GET['id'] ?? 0);
$errors = array();

$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();
// The same scoping the Sermons screen uses, so a scoped admin is never offered another church's
// titles as a source to copy from.
$sermonScope = Unit::scopeClause($user, 'org_unit_id');

// Which month the grid is showing. Anything unparseable falls back to this month rather than
// erroring — a hand-edited URL should not produce a blank screen.
$requestedMonth = (string) ($_GET['month'] ?? '');
$monthStart = preg_match('/^\d{4}-\d{2}$/', $requestedMonth) ? $requestedMonth . '-01' : date('Y-m-01');
$gridYear = (int) date('Y', strtotime($monthStart));
$gridMonth = (int) date('n', strtotime($monthStart));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

/** Loads an entry and reports whether this admin may change it. */
$loadEntry = static function (int $entryId) use ($isSuper, $myUnitId): ?array {
    $entry = Devotional::find($entryId);
    if ($entry === null) {
        return null;
    }
    $unitId = (int) ($entry['org_unit_id'] ?? 0);
    // 0 is church-wide, which belongs to head office, so only the super admin may touch it.
    $entry['can_manage'] = $isSuper || ($unitId > 0 && $unitId === $myUnitId);
    return $entry;
};

/** The month to come back to after a write, taken from the date that was submitted. */
$monthOf = static function (string $date): string {
    return preg_match('/^\d{4}-\d{2}/', $date) ? substr($date, 0, 7) : date('Y-m');
};

/* ======================================================================== save == */
if (in_array($action, array('create', 'edit'), true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $existing = $action === 'edit' ? $loadEntry($id) : null;
    if ($action === 'edit' && ($existing === null || empty($existing['can_manage']))) {
        flash('error', 'You can only edit your own church\'s devotionals.');
        redirect('/admin/devotionals');
    }

    // A scoped admin always writes their own church's rows. A super admin chooses, and an
    // out-of-scope choice falls back to church-wide rather than writing another church's row.
    $unitId = $isSuper ? (int) ($_POST['org_unit_id'] ?? 0) : $myUnitId;
    if ($isSuper && $unitId > 0 && !Unit::inAssignableScope($user, $unitId)) {
        $unitId = 0;
    }

    $result = Devotional::save($action === 'edit' ? $id : 0, array(
        'org_unit_id' => $unitId,
        'publish_on' => (string) ($_POST['publish_on'] ?? ''),
        'title' => (string) ($_POST['title'] ?? ''),
        'scripture_reference' => (string) ($_POST['scripture_reference'] ?? ''),
        'scripture_text' => (string) ($_POST['scripture_text'] ?? ''),
        'body' => (string) ($_POST['body'] ?? ''),
        'is_published' => !empty($_POST['is_published']),
        'sermon_id' => (int) ($_POST['sermon_id'] ?? 0),
        'created_by' => (int) ($user['id'] ?? 0),
    ));

    if (!$result['ok']) {
        $errors = $result['errors'] ?? array('Could not save that devotional.');
    } else {
        flash('success', !empty($result['updated'])
            ? 'That day already had a devotional, so it was updated rather than duplicated.'
            : 'Devotional saved.');
        redirect('/admin/devotionals?month=' . $monthOf((string) ($_POST['publish_on'] ?? '')));
    }
}

/* ====================================================================== delete == */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $entry = $loadEntry($id);
    if ($entry === null || empty($entry['can_manage'])) {
        flash('error', 'You can only delete your own church\'s devotionals.');
        redirect('/admin/devotionals');
    }
    Devotional::delete($id);
    flash('success', 'Devotional deleted.');
    redirect('/admin/devotionals?month=' . $monthOf((string) ($entry['publish_on'] ?? '')));
}

/* ========================================================= notification switch == */
if ($action === 'notify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    // One switch for the whole install, like the SMS sending window, so it belongs to head office
    // rather than to whichever church an admin happens to be scoped to.
    if (!$isSuper) {
        flash('error', 'Only a head-office admin can change the daily notification.');
        redirect('/admin/devotionals');
    }

    $enabled = !empty($_POST['enabled']);
    settingSave(array('devotional_push_enabled' => $enabled ? 1 : 0));
    flash('success', $enabled
        ? 'The daily devotional notification is on.'
        : 'The daily devotional notification is off. Devotionals still appear on the website.');
    redirect('/admin/devotionals?month=' . $monthOf((string) ($_POST['month'] ?? '')));
}

/* =================================================================== load view == */
$editing = null;
if ($action === 'edit') {
    $editing = $loadEntry($id);
    if ($editing === null) {
        flash('error', 'That devotional no longer exists.');
        redirect('/admin/devotionals');
    }
} elseif ($action === 'create') {
    $editing = array(
        'id' => 0,
        'publish_on' => (string) ($_GET['date'] ?? date('Y-m-d')),
        'title' => '',
        'scripture_reference' => '',
        'scripture_text' => '',
        'body' => '',
        'org_unit_id' => $myUnitId,
        'is_published' => 1,
        'can_manage' => true,
        'sermon_id' => 0,
    );

    // "Start from a sermon": seed the draft from a message the church already has, which is where
    // most devotionals come from anyway. The id is carried through to the save so the two stay
    // linked, and the admin edits whatever the copy does not fit.
    $fromSermon = (int) ($_GET['from_sermon'] ?? 0);
    if ($fromSermon > 0) {
        $sql = 'SELECT id, title, scripture_ref, description FROM sermons WHERE id = ?';
        if ($sermonScope !== '') {
            $sql .= ' AND ' . $sermonScope;
        }
        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute(array($fromSermon));
        $sermon = $statement->fetch();
        if ($sermon) {
            $editing['title'] = (string) $sermon['title'];
            $editing['scripture_reference'] = (string) ($sermon['scripture_ref'] ?? '');
            $editing['body'] = trim((string) ($sermon['description'] ?? ''));
            $editing['sermon_id'] = (int) $sermon['id'];
        } else {
            // Says so rather than silently opening blank, which would read as the button not working.
            flash('error', 'That sermon was not found, so this devotional starts empty.');
        }
    }
}

// `false` for published-only, so drafts appear in the grid: an unpublished entry the admin
// cannot see is one they cannot publish, and hunting for it by URL is not a workflow.
$entries = Devotional::month($gridYear, $gridMonth, $isSuper ? Devotional::ALL_UNITS : $myUnitId, false);
$daysInMonth = (int) date('t', strtotime($monthStart));
$today = date('Y-m-d');

// Messages a devotional can be started from. Only needed on the create form, so it is not queried
// for the month grid.
$sermonOptions = array();
if ($action === 'create') {
    $sql = 'SELECT id, title, published_at FROM sermons WHERE is_published = 1';
    if ($sermonScope !== '') {
        $sql .= ' AND ' . $sermonScope;
    }
    $sermonOptions = $pdo->query($sql . ' ORDER BY published_at DESC LIMIT 60')->fetchAll();
}

$pageTitle = 'Daily Devotionals';
$activeNav = 'devotionals';
require __DIR__ . '/partials/layout-open.php';
?>

<div class="btn-row" style="flex-wrap:wrap;align-items:center;margin-bottom:16px;">
  <a class="btn sm secondary" href="/admin/devotionals?month=<?= e($prevMonth) ?>">&larr; <?= e(date('M Y', strtotime($prevMonth . '-01'))) ?></a>
  <strong style="margin:0 10px;font-size:15px;"><?= e(date('F Y', strtotime($monthStart))) ?></strong>
  <a class="btn sm secondary" href="/admin/devotionals?month=<?= e($nextMonth) ?>"><?= e(date('M Y', strtotime($nextMonth . '-01'))) ?> &rarr;</a>
  <span style="flex:1;"></span>
  <a class="btn sm" href="/admin/devotionals?action=create&amp;date=<?= e($today) ?>&amp;month=<?= e($monthStart) ?>">+ New devotional</a>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <?php foreach ($errors as $error): ?><?= e($error) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($editing !== null): ?>
  <?php $isNew = (int) $editing['id'] === 0; ?>
  <div style="border:1px solid rgba(0,0,0,0.12);border-radius:12px;padding:18px 20px;margin-bottom:24px;">
    <h2 style="margin:0 0 14px 0;font-size:16px;">
      <?= $isNew ? 'New devotional' : 'Edit devotional' ?>
      <?php if (!$isNew && empty($editing['can_manage'])): ?>
        <span style="font-size:12px;font-weight:400;opacity:0.7;">— read-only, this belongs to head office</span>
      <?php endif; ?>
    </h2>

    <?php if ($isNew && $sermonOptions): ?>
      <form method="get" action="/admin/devotionals" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0 0 16px 0;padding:12px 14px;border:1px dashed rgba(0,0,0,0.18);border-radius:10px;">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="month" value="<?= e($monthStart) ?>">
        <input type="hidden" name="date" value="<?= e((string) $editing['publish_on']) ?>">
        <label style="display:block;flex:1;min-width:260px;">
          <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Start from a sermon — copies its title, reference and notes in as a first draft</span>
          <select name="from_sermon" style="width:100%;">
            <option value="">Choose a sermon…</option>
            <?php foreach ($sermonOptions as $option): ?>
              <option value="<?= (int) $option['id'] ?>" <?= (int) ($editing['sermon_id'] ?? 0) === (int) $option['id'] ? 'selected' : '' ?>><?= e((string) $option['title']) ?> · <?= e(date('j M Y', strtotime((string) $option['published_at']))) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn sm secondary" type="submit">Use this sermon</button>
      </form>
    <?php endif; ?>

    <form method="post" action="/admin/devotionals?action=<?= $isNew ? 'create' : 'edit' ?><?= $isNew ? '' : '&amp;id=' . (int) $editing['id'] ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="sermon_id" value="<?= (int) ($editing['sermon_id'] ?? 0) ?>">

      <?php if ((int) ($editing['sermon_id'] ?? 0) > 0): ?>
        <p style="margin:0 0 12px 0;font-size:12.5px;opacity:0.75;">Started from a sermon — the link is kept on the entry.</p>
      <?php endif; ?>

      <div class="btn-row" style="flex-wrap:wrap;gap:16px;align-items:flex-end;">
        <label style="display:block;">
          <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Day</span>
          <input type="date" name="publish_on" value="<?= e((string) $editing['publish_on']) ?>" required>
        </label>

        <?php if ($isSuper): ?>
          <label style="display:block;">
            <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Belongs to</span>
            <select name="org_unit_id">
              <option value="0">Church-wide (everyone)</option>
              <?php foreach ($assignableUnits as $unit): ?>
                <option value="<?= (int) $unit['id'] ?>" <?= (int) $editing['org_unit_id'] === (int) $unit['id'] ? 'selected' : '' ?>><?= e(Unit::optionLabel($unit)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>

        <label style="display:block;flex:1;min-width:240px;">
          <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Title</span>
          <input type="text" name="title" maxlength="<?= (int) Devotional::MAX_TITLE ?>" value="<?= e((string) $editing['title']) ?>" required placeholder="e.g. He Restores My Soul">
        </label>
      </div>

      <label style="display:block;margin-top:14px;">
        <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Scripture reference</span>
        <input type="text" name="scripture_reference" style="width:100%;" value="<?= e((string) ($editing['scripture_reference'] ?? '')) ?>" placeholder="e.g. Psalm 23:1-6">
      </label>

      <label style="display:block;margin-top:14px;">
        <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Scripture text <span style="font-weight:400;">— optional, shown above the devotional so the reader need not go looking</span></span>
        <textarea name="scripture_text" rows="3" style="width:100%;"><?= e((string) ($editing['scripture_text'] ?? '')) ?></textarea>
      </label>

      <label style="display:block;margin-top:14px;">
        <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Devotional</span>
        <textarea name="body" rows="10" style="width:100%;"><?= e((string) ($editing['body'] ?? '')) ?></textarea>
      </label>

      <label style="display:block;margin-top:14px;font-size:13px;">
        <input type="checkbox" name="is_published" value="1" <?= !empty($editing['is_published']) ? 'checked' : '' ?>>
        Published — unchecking hides it from the website without deleting it
      </label>

      <div class="btn-row" style="margin-top:18px;">
        <button class="btn" type="submit">Save devotional</button>
        <a class="btn sm secondary" href="/admin/devotionals?month=<?= e($monthStart) ?>">Cancel</a>
      </div>
    </form>

    <?php if (!$isNew && !empty($editing['can_manage'])): ?>
      <!-- A separate form, not a button inside the one above: nested forms are invalid and
           browsers disagree about which one a nested submit belongs to. -->
      <form method="post" action="/admin/devotionals?action=delete&amp;id=<?= (int) $editing['id'] ?>" style="margin-top:12px;"
            onsubmit="return confirm('Delete the devotional for <?= e((string) $editing['publish_on']) ?>? This cannot be undone.');">
        <?= Csrf::field() ?>
        <button class="btn sm secondary" type="submit" style="color:var(--danger,#c0392b);">Delete this day</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<h2 style="font-size:16px;margin:0 0 10px 0;"><?= e(date('F Y', strtotime($monthStart))) ?> at a glance</h2>

<div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;">
        <th style="padding:6px 8px 6px 0;width:84px;">Day</th>
        <th style="padding:6px 8px;">Devotional</th>
        <th style="padding:6px 8px;width:150px;">Belongs to</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
        <?php
          $date = sprintf('%04d-%02d-%02d', $gridYear, $gridMonth, $day);
          $entry = $entries[$date] ?? null;
          $isToday = $date === $today;
        ?>
        <tr style="border-top:1px solid rgba(0,0,0,0.08);<?= $isToday ? 'background:rgba(212,175,55,0.10);' : '' ?>">
          <td style="padding:8px 8px 8px 0;white-space:nowrap;vertical-align:top;">
            <?= e(date('D j', strtotime($date))) ?>
            <?php if ($isToday): ?><strong style="display:block;font-size:11px;opacity:0.8;">today</strong><?php endif; ?>
          </td>
          <td style="padding:8px;vertical-align:top;">
            <?php if ($entry): ?>
              <a href="/admin/devotionals?action=edit&amp;id=<?= (int) $entry['id'] ?>&amp;month=<?= e($monthStart) ?>" style="font-weight:600;"><?= e((string) $entry['title']) ?></a>
              <?php if (!empty($entry['scripture_reference'])): ?>
                <span style="opacity:0.7;">— <?= e((string) $entry['scripture_reference']) ?></span>
              <?php endif; ?>
              <?php if (empty($entry['is_published'])): ?>
                <span style="font-size:11px;opacity:0.7;">· hidden</span>
              <?php endif; ?>
            <?php else: ?>
              <a href="/admin/devotionals?action=create&amp;date=<?= e($date) ?>&amp;month=<?= e($monthStart) ?>" style="opacity:0.55;">+ write one</a>
            <?php endif; ?>
          </td>
          <td style="padding:8px;vertical-align:top;">
            <?php if ($entry): ?>
              <?php if ((int) $entry['org_unit_id'] === 0): ?>
                Church-wide
              <?php else: ?>
                <?= e($unitLabels[(int) $entry['org_unit_id']] ?? 'Church') ?>
              <?php endif; ?>
              <?php if (empty($entry['can_manage'])): ?>
                <span style="font-size:11px;opacity:0.6;display:block;">head office</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>

<h2 style="font-size:16px;margin:26px 0 10px 0;">Daily notification</h2>

<?php
// Today's notification state, so an admin can answer "did it go out this morning?" without
// opening the database.
$notifyEnabled = (int) setting('devotional_push_enabled', 1) === 1;
$deviceCount = DevotionalPush::audienceSize();
$todayStatement = $pdo->prepare('SELECT title, org_unit_id, is_published, push_sent_at FROM devotionals WHERE publish_on = ? ORDER BY org_unit_id ASC');
$todayStatement->execute(array($today));
$todayEntries = $todayStatement->fetchAll();
$cronPath = ROOT_PATH . '/cli/devotional_worker.php';
?>

<div style="border:1px solid rgba(0,0,0,0.12);border-radius:12px;padding:16px;max-width:780px;">
  <p style="margin:0 0 10px 0;">
    <?php if ($notifyEnabled): ?>
      <strong style="color:var(--success,#1e8e3e);">On</strong> — today's entry is pushed to the app the next time the worker runs inside the sending window.
    <?php else: ?>
      <strong style="color:var(--danger,#c0392b);">Off</strong> — nothing is pushed. Members still see the devotional on the website.
    <?php endif; ?>
  </p>

  <p style="margin:0 0 4px 0;font-size:13px;opacity:0.85;">
    <?= (int) $deviceCount ?> device<?= $deviceCount === 1 ? '' : 's' ?> registered ·
    sending window <?= e(SmsCampaign::quietHoursLabel()) ?>
  </p>
  <p style="margin:0 0 12px 0;font-size:12.5px;opacity:0.7;">
    That window is the same one SMS and WhatsApp use, so a mis-set cron cannot wake anyone at 3am.
    A member who switched devotionals off on their own dashboard is skipped.
  </p>

  <?php if (!$todayEntries): ?>
    <p style="margin:0 0 12px 0;font-size:13px;">Nothing has been written for today, so there is nothing to send.</p>
  <?php else: ?>
    <ul style="margin:0 0 12px 0;padding-left:18px;font-size:13px;">
      <?php foreach ($todayEntries as $row): ?>
        <li>
          <?= e((string) $row['title']) ?>
          <span style="opacity:0.7;">(<?= ((int) $row['org_unit_id'] === 0) ? 'church-wide' : e($unitLabels[(int) $row['org_unit_id']] ?? 'church') ?>)</span>
          —
          <?php if (empty($row['is_published'])): ?>
            hidden, so it will not be sent
          <?php elseif (!empty($row['push_sent_at'])): ?>
            sent <?= e(date('g:ia', strtotime((string) $row['push_sent_at']))) ?>
          <?php else: ?>
            not sent yet
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p style="margin:0 0 6px 0;font-size:13px;">Add this to cron to send it each morning:</p>
  <pre style="background:#0f0d1f;border:1px solid var(--border);border-radius:10px;padding:12px;overflow:auto;font-size:12.5px;">30 6 * * * php <?= e($cronPath) ?></pre>
  <p style="margin:6px 0 0 0;font-size:12.5px;opacity:0.7;">
    Change the time to suit you. Running it twice is harmless — the day is claimed before the first
    notification goes out, so a duplicate cron entry cannot notify anyone twice.
  </p>

  <?php if ($isSuper): ?>
    <form method="post" action="/admin/devotionals?action=notify" style="margin-top:14px;">
      <?= Csrf::field() ?>
      <input type="hidden" name="month" value="<?= e($monthStart) ?>">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
        <input type="checkbox" name="enabled" value="1" <?= $notifyEnabled ? 'checked' : '' ?>>
        Send the daily devotional notification
      </label>
      <button class="btn sm" type="submit" style="margin-top:10px;">Save</button>
    </form>
  <?php else: ?>
    <p style="margin:14px 0 0 0;font-size:12.5px;opacity:0.7;">Head office controls this setting.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
