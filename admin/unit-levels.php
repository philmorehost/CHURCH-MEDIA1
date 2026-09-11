<?php
declare(strict_types=1);

/**
 * Unit Levels — the super admin's control over the church hierarchy.
 *
 * Each row in `unit_levels` is one depth of the tree (the classic RCCG setup is
 * Province → Zone → Area → Parish). Renaming a level changes it everywhere the
 * site shows it — units admin, CSV import/export, the registration cascade and
 * the public units pages — because those all read Unit::labelFor()/pluralFor().
 */

Auth::requireRole('admin');

// Reshaping the hierarchy is a super-admin-only operation.
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can manage unit levels.');
}

$pdo = Database::getInstance()->getConnection();
$action = (string) ($_GET['action'] ?? 'list');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $type = (string) ($_POST['type'] ?? '');
    $label = (string) ($_POST['label'] ?? '');
    $plural = (string) ($_POST['plural'] ?? '');

    if ($action === 'create') {
        $result = Unit::levelCreate($label, $plural);
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        } else {
            flash('success', '“' . $label . '” added as the deepest level.');
            redirect('/admin/unit-levels');
        }
    }

    if ($action === 'update') {
        $result = Unit::levelUpdate($type, $label, $plural);
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        } else {
            flash('success', 'Level renamed to “' . $label . '”.');
            redirect('/admin/unit-levels');
        }
    }

    if ($action === 'move') {
        $result = Unit::levelMove($type, ($_POST['direction'] ?? 'up') === 'down' ? 'down' : 'up');
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        } else {
            flash('success', 'Level order updated.');
            redirect('/admin/unit-levels');
        }
    }

    if ($action === 'delete') {
        $result = Unit::levelDelete($type);
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        } else {
            flash('success', 'Level removed.');
            redirect('/admin/unit-levels');
        }
    }
}

$levels = Unit::levelsWithCounts();
$unitTotal = (int) $pdo->query('SELECT COUNT(*) FROM org_units')->fetchColumn();
$maxDepth = count($levels);

// A sample CSV header built from the live level names, so it always matches.
$csvHeader = implode(',', array_map(static fn (array $l): string => $l['plural'], $levels));

$editing = null;
if ($action === 'edit') {
    $want = (string) ($_GET['type'] ?? '');
    foreach ($levels as $level) {
        if ($level['type'] === $want) {
            $editing = $level;
            break;
        }
    }
    if ($editing === null) {
        redirect('/admin/unit-levels');
    }
}

$pageTitle = 'Unit Levels';
$activeNav = 'unit-levels';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2>Your church hierarchy</h2>
  <p class="sub">
    These are the levels every church is organised into, from the top down. Rename them to suit your structure
    (for example <em>Province</em> → <em>Region</em>, or <em>Parish</em> → <em>Branch</em>) — the new names appear
    everywhere: the Units admin, CSV import/export, the registration form and the public pages.
    You can add as many levels as you need, or remove one that is no longer used.
  </p>

  <table>
    <thead>
      <tr>
        <th style="width:70px;">Depth</th>
        <th>Level</th>
        <th>Plural</th>
        <th>Key</th>
        <th style="width:90px;">Units</th>
        <th style="width:250px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($levels as $i => $level): ?>
        <tr>
          <td><span class="badge info"><?= (int) $level['position'] ?></span></td>
          <td><strong><?= e($level['label']) ?></strong></td>
          <td style="color:var(--ink-dim);"><?= e($level['plural']) ?></td>
          <td><code style="font-size:11.5px;"><?= e($level['type']) ?></code></td>
          <td><?= (int) $level['unit_count'] ?></td>
          <td>
            <div class="btn-row" style="margin:0; gap:6px;">
              <?php if ($i > 0): ?>
                <form method="post" action="/admin/unit-levels?action=move" style="display:inline;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="type" value="<?= e($level['type']) ?>">
                  <input type="hidden" name="direction" value="up">
                  <button class="btn secondary sm" type="submit" title="Move up">↑</button>
                </form>
              <?php endif; ?>
              <?php if ($i < $maxDepth - 1): ?>
                <form method="post" action="/admin/unit-levels?action=move" style="display:inline;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="type" value="<?= e($level['type']) ?>">
                  <input type="hidden" name="direction" value="down">
                  <button class="btn secondary sm" type="submit" title="Move down">↓</button>
                </form>
              <?php endif; ?>
              <a class="btn secondary sm" href="/admin/unit-levels?action=edit&type=<?= urlencode($level['type']) ?>">Rename</a>
              <form method="post" action="/admin/unit-levels?action=delete" style="display:inline;"
                    onsubmit="return confirm('Remove the <?= e($level['label']) ?> level? Only possible when no unit uses it.');">
                <?= Csrf::field() ?>
                <input type="hidden" name="type" value="<?= e($level['type']) ?>">
                <button class="btn secondary sm" type="submit" style="color:var(--danger);">Remove</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="sub" style="margin:16px 0 0;">
    <strong>Depth 1</strong> is your top level (e.g. <?= e($levels[0]['label'] ?? 'Province') ?>) and
    <strong>depth <?= (int) $maxDepth ?></strong> is where the churches themselves live
    (e.g. <?= e($levels[$maxDepth - 1]['label'] ?? 'Parish') ?>). <?= number_format($unitTotal) ?> unit(s) configured.
  </p>
</div>

<div class="card" style="max-width:560px;">
  <h2><?= $editing ? 'Rename level' : 'Add a level' ?></h2>
  <p class="sub">
    <?php if ($editing): ?>
      Changing the name updates it everywhere. Existing units keep their data — only the label changes.
    <?php else: ?>
      The new level is added at the <strong>bottom</strong> of the hierarchy. Reorder it afterwards with the ↑ ↓ buttons.
    <?php endif; ?>
  </p>

  <form method="post" action="/admin/unit-levels?action=<?= $editing ? 'update' : 'create' ?>">
    <?= Csrf::field() ?>
    <?php if ($editing): ?>
      <input type="hidden" name="type" value="<?= e($editing['type']) ?>">
    <?php endif; ?>

    <label for="label">Level name (singular)</label>
    <input type="text" id="label" name="label" required maxlength="60"
           value="<?= e($editing['label'] ?? '') ?>" placeholder="e.g. District">

    <label for="plural">Level name (plural)</label>
    <input type="text" id="plural" name="plural" maxlength="60"
           value="<?= e($editing['plural'] ?? '') ?>" placeholder="e.g. Districts">
    <p class="sub" style="margin:-8px 0 14px;">Used for column headings and headings like “All Districts”. Left blank, we add an “s”.</p>

    <div class="btn-row">
      <button class="btn" type="submit"><?= $editing ? 'Save Name' : 'Add Level' ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/admin/unit-levels">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>CSV import &amp; export</h2>
  <p class="sub">
    The church CSV now uses your level names, one column per level, from top to bottom:
  </p>
  <p style="margin:0 0 12px;"><code><?= e($csvHeader) ?></code></p>
  <p class="sub" style="margin:0;">
    Download a ready-to-fill template from <a href="/admin/units?action=sample_csv" style="color:var(--gold-soft);">Units → Import Churches (CSV)</a>.
    Import also accepts the original keys (<code>province</code>, <code>zone</code>, …), so older spreadsheets keep working.
  </p>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
