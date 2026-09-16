<?php
declare(strict_types=1);

/**
 * Bible reading plans — a list, and an editor.
 *
 * The editor is a text box with one line per day, because that is how a reading plan is written on
 * paper and it is the only form that stays readable for a 365-day plan. Each line is parsed into
 * book, chapters and verses (core/ReadingPlan.php), so the app can open the passage offline rather
 * than having to understand prose. Blank lines are rest days.
 *
 * **Plans are church-wide, not per-church.** A reading plan is a discipline the whole church takes
 * on together, and there is no `org_unit_id` on `reading_plans`. That is why creating, editing and
 * deleting are super-admin only: a scoped editor writing one would be writing for every church in
 * the install, which is exactly the scope leak the rest of the admin screens are built to avoid.
 * A scoped admin can still read the list, so they know what their people have been offered.
 */

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$isSuper = Auth::isSuperAdmin();
$action = (string) ($_GET['action'] ?? 'list');
$id = (int) ($_GET['id'] ?? 0);
$errors = array();

// Writing is head office only, for the reason in the docblock.
if (in_array($action, array('create', 'edit', 'delete'), true) && !$isSuper) {
    flash('error', 'Only a head-office admin can change reading plans, because a plan is shared by every church.');
    redirect('/admin/reading-plans');
}

/* ======================================================================== save == */
if (in_array($action, array('create', 'edit'), true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    if ($action === 'edit' && ReadingPlan::find($id) === null) {
        flash('error', 'That plan no longer exists.');
        redirect('/admin/reading-plans');
    }

    $result = ReadingPlan::savePlan($action === 'edit' ? $id : 0, array(
        'name' => (string) ($_POST['name'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'days' => (string) ($_POST['days'] ?? ''),
        'is_published' => !empty($_POST['is_published']),
        'created_by' => (int) ($user['id'] ?? 0),
    ));

    if (empty($result['ok'])) {
        $errors = $result['errors'] ?? array('Could not save that plan.');
    } else {
        flash('success', (!empty($result['updated']) ? 'Plan updated' : 'Plan created')
            . ' — ' . (int) $result['days'] . ' day' . ((int) $result['days'] === 1 ? '' : 's') . '.');
        redirect('/admin/reading-plans');
    }
}

/* ====================================================================== delete == */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $plan = ReadingPlan::find($id);
    if ($plan === null) {
        flash('error', 'That plan no longer exists.');
        redirect('/admin/reading-plans');
    }
    ReadingPlan::delete($id);
    flash('success', 'Plan deleted, along with everyone\'s progress in it.');
    redirect('/admin/reading-plans');
}

/* =================================================================== load view == */
$editing = null;
if ($action === 'edit') {
    $editing = ReadingPlan::find($id);
    if ($editing === null) {
        flash('error', 'That plan no longer exists.');
        redirect('/admin/reading-plans');
    }
    $editing['days_text'] = ReadingPlan::editorText($id);
} elseif ($action === 'create') {
    $editing = array(
        'id' => 0,
        'name' => '',
        'description' => '',
        'days_count' => 0,
        'is_published' => 0,
        'days_text' => '',
    );
}

// On a failed save the box keeps what was typed. Losing a plan that took an evening to type because
// of one bad line on day 200 would be worse than the error itself.
$daysText = $editing !== null ? (string) $editing['days_text'] : '';
if ($errors !== array() && isset($_POST['days'])) {
    $daysText = (string) $_POST['days'];
    $editing['name'] = (string) ($_POST['name'] ?? $editing['name']);
    $editing['description'] = (string) ($_POST['description'] ?? $editing['description']);
    $editing['is_published'] = !empty($_POST['is_published']) ? 1 : 0;
}

$plans = ReadingPlan::all();

// Who is following what, so a plan nobody has picked is visible as such rather than looking equal
// to one the whole church is on.
$followers = array();
foreach ($pdo->query('SELECT reading_plan_id, COUNT(*) AS total FROM members WHERE reading_plan_id IS NOT NULL GROUP BY reading_plan_id')->fetchAll() as $row) {
    $followers[(int) $row['reading_plan_id']] = (int) $row['total'];
}

$pageTitle = 'Reading Plans';
$activeNav = 'reading-plans';
require __DIR__ . '/partials/layout-open.php';
?>

<div class="btn-row" style="flex-wrap:wrap;align-items:center;margin-bottom:16px;">
  <strong style="font-size:15px;">Bible Reading Plans</strong>
  <span style="flex:1;"></span>
  <?php if ($isSuper): ?>
    <a class="btn sm" href="/admin/reading-plans?action=create">+ New plan</a>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
  <div class="alert error">
    <?php foreach ($errors as $error): ?><?= e($error) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($editing !== null): ?>
  <?php $isNew = (int) $editing['id'] === 0; ?>
  <div style="border:1px solid rgba(0,0,0,0.12);border-radius:12px;padding:18px 20px;margin-bottom:24px;">
    <h2 style="margin:0 0 14px 0;font-size:16px;"><?= $isNew ? 'New plan' : 'Edit plan' ?></h2>

    <form method="post" action="/admin/reading-plans?action=<?= $isNew ? 'create' : 'edit' ?><?= $isNew ? '' : '&amp;id=' . (int) $editing['id'] ?>">
      <?= Csrf::field() ?>

      <div class="btn-row" style="flex-wrap:wrap;gap:16px;align-items:flex-end;">
        <label style="display:block;flex:1;min-width:240px;">
          <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Name</span>
          <input type="text" name="name" maxlength="<?= (int) ReadingPlan::MAX_NAME ?>" value="<?= e((string) $editing['name']) ?>" required placeholder="e.g. Read the Bible in a Year">
        </label>
      </div>

      <label style="display:block;margin-top:14px;">
        <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">Description <span style="font-weight:400;">— optional, shown above the plan in the app</span></span>
        <textarea name="description" rows="2" style="width:100%;"><?= e((string) ($editing['description'] ?? '')) ?></textarea>
      </label>

      <label style="display:block;margin-top:16px;">
        <span style="display:block;font-size:12px;opacity:0.7;margin-bottom:3px;">The plan <span style="font-weight:400;">— one line per day, starting at day 1</span></span>
        <textarea name="days" rows="18" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.6;" spellcheck="false" placeholder="Genesis 1&#10;Genesis 2&#10;Genesis 3; Psalm 1"><?= e($daysText) ?></textarea>
      </label>

      <div style="margin-top:10px;font-size:12.5px;opacity:0.75;line-height:1.7;">
        <strong>Line 1 is day 1.</strong> Write a passage as <code>Genesis 1</code>, <code>Genesis 1-3</code>,
        <code>Genesis 1:1-5</code> or <code>John 3:16</code>.<br>
        Put more than one reading on a day by separating them with a semicolon: <code>Psalm 23; John 10</code>.<br>
        <strong>Leave a line blank for a rest day</strong> — the days after it keep their numbers.<br>
        Book names are checked against the Bible the app carries, and a chapter that does not exist is refused
        before anyone reads it.
      </div>

      <label style="display:block;margin-top:14px;font-size:13px;">
        <input type="checkbox" name="is_published" value="1" <?= !empty($editing['is_published']) ? 'checked' : '' ?>>
        Published — members can choose this plan. Unchecking hides it without deleting anyone's progress.
      </label>

      <div class="btn-row" style="margin-top:16px;">
        <button class="btn" type="submit"><?= $isNew ? 'Create plan' : 'Save changes' ?></button>
        <a class="btn sm secondary" href="/admin/reading-plans">Cancel</a>
        <?php if (!$isNew): ?>
          <span style="font-size:12.5px;opacity:0.7;margin-left:8px;"><?= (int) $editing['days_count'] ?> days</span>
        <?php endif; ?>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if (!$plans): ?>
  <p style="font-size:14px;opacity:0.8;">No reading plans yet.
    <?php if ($isSuper): ?>Create one and members can choose it from their dashboard.<?php endif; ?>
  </p>
<?php else: ?>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="text-align:left;">
          <th style="padding:6px 8px 6px 0;">Plan</th>
          <th style="padding:6px 8px;width:80px;">Days</th>
          <th style="padding:6px 8px;width:110px;">Members</th>
          <th style="padding:6px 8px;width:110px;">Status</th>
          <th style="padding:6px 8px;width:150px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $plan): ?>
          <?php $planId = (int) $plan['id']; ?>
          <tr style="border-top:1px solid rgba(0,0,0,0.08);">
            <td style="padding:8px 8px 8px 0;vertical-align:top;">
              <strong><?= e((string) $plan['name']) ?></strong>
              <?php if (!empty($plan['description'])): ?>
                <span style="display:block;font-size:12.5px;opacity:0.7;"><?= e(mb_strimwidth((string) $plan['description'], 0, 110, '…')) ?></span>
              <?php endif; ?>
            </td>
            <td style="padding:8px;vertical-align:top;"><?= (int) $plan['days_count'] ?></td>
            <td style="padding:8px;vertical-align:top;"><?= (int) ($followers[$planId] ?? 0) ?></td>
            <td style="padding:8px;vertical-align:top;">
              <?php if (!empty($plan['is_published'])): ?>
                <span style="color:var(--success,#1e8e3e);">Published</span>
              <?php else: ?>
                <span style="opacity:0.7;">Draft</span>
              <?php endif; ?>
            </td>
            <td style="padding:8px;vertical-align:top;white-space:nowrap;">
              <?php if ($isSuper): ?>
                <a class="btn sm secondary" href="/admin/reading-plans?action=edit&amp;id=<?= $planId ?>">Edit</a>
                <form method="post" action="/admin/reading-plans?action=delete&amp;id=<?= $planId ?>" style="display:inline;"
                      onsubmit="return confirm('Delete &quot;<?= e((string) $plan['name']) ?>&quot;? Everyone\'s progress in it goes too. This cannot be undone.');">
                  <?= Csrf::field() ?>
                  <button class="btn sm secondary" type="submit" style="color:var(--danger,#c0392b);">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
