<?php
declare(strict_types=1);

/**
 * Sermon series.
 *
 * A series is a named run of messages — the thing that becomes a podcast feed and gives the
 * sermons page something to group by. Before this screen, a series was a text field on each
 * sermon, so it could not be described, illustrated, or renamed.
 *
 * Scoping follows the sermons screen: a church admin sees their own church's series plus any
 * shared (head-office) ones, but may only edit their own. A shared series is shown read-only
 * with a badge saying so, rather than offering buttons that would refuse.
 */

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$isSuper = Auth::isSuperAdmin();
$myUnitId = !empty($user['org_unit_id']) ? (int) $user['org_unit_id'] : 0;
$action = (string) ($_GET['action'] ?? 'list');
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();

// What this admin may see. Empty means "no restriction" for a super admin.
$scopeUnitIds = $isSuper ? [] : ($myUnitId > 0 ? [$myUnitId] : []);

/** Loads a series and reports whether this admin may change it. */
$loadSeries = static function (int $seriesId) use ($isSuper, $myUnitId): ?array {
    $series = Series::find($seriesId);
    if ($series === null) {
        return null;
    }
    $unitId = (int) ($series['org_unit_id'] ?? 0);
    $series['can_manage'] = $isSuper || ($unitId > 0 && $unitId === $myUnitId);
    return $series;
};

/* ============================================================== reassign a series ==
 * The super admin can move a series between churches; a scoped admin cannot.
 */
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);

    if (!$isSuper) {
        flash('error', 'Only the super admin can move a series between churches.');
    } elseif (Series::find($targetId) === null || $unitId <= 0 || !Unit::inAssignableScope($user, $unitId)) {
        flash('error', 'Could not move that series.');
    } else {
        // The series and its sermons belong to the same church, so both move together —
        // otherwise the sermons would drop out of that church's public pages.
        $pdo->prepare('UPDATE sermon_series SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $targetId]);
        Series::adoptUnit($targetId, $unitId);
        flash('success', 'Series moved to ' . Unit::label($unitId) . '. Its sermons went with it.');
    }
    redirect('/admin/series');
}

/* ==================================================================== save == */
if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $existing = $action === 'edit' ? $loadSeries($id) : null;
    if ($action === 'edit' && ($existing === null || empty($existing['can_manage']))) {
        flash('error', 'You can only manage series for your own church.');
        redirect('/admin/series');
    }

    $title = (string) ($_POST['title'] ?? '');
    $unitId = $isSuper ? (int) ($_POST['org_unit_id'] ?? 0) : $myUnitId;

    if ($action === 'create' && !$isSuper && $myUnitId <= 0) {
        $errors[] = 'Your account has no Home Church assigned — ask the super admin to set it (Users → Edit → Home Unit) before adding a series.';
    }
    if ($isSuper && $unitId > 0 && !Unit::inAssignableScope($user, $unitId)) {
        $errors[] = 'That church is outside your scope.';
    }

    $coverPath = $existing['cover_image'] ?? null;
    if (!empty($_FILES['cover_image']['tmp_name']) && is_uploaded_file($_FILES['cover_image']['tmp_name'])) {
        $filename = MediaProcessor::processImage($_FILES['cover_image']['tmp_name'], UPLOADS_WEBP_PATH);
        if ($filename) {
            $coverPath = 'webp/' . $filename;
        } else {
            $errors[] = 'That cover image could not be processed. Try a JPG or PNG.';
        }
    }

    if ($errors === []) {
        $result = Series::save($action === 'edit' ? $id : 0, $title, [
            'description' => (string) ($_POST['description'] ?? ''),
            'cover_image' => $coverPath,
            'org_unit_id' => $unitId > 0 ? $unitId : null,
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ]);

        if (!$result['ok']) {
            $errors[] = (string) $result['error'];
        } else {
            // A series that has just been given a church should take its unassigned sermons
            // with it, or they would vanish from that church's public pages.
            if ($unitId > 0) {
                Series::adoptUnit((int) $result['id'], $unitId);
            }
            flash('success', $result['action'] === 'created' ? 'Series created.' : 'Series updated.');
            redirect('/admin/series');
        }
    }
}

/* ================================================================== delete == */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $target = $loadSeries((int) ($_POST['id'] ?? 0));

    if ($target === null || empty($target['can_manage'])) {
        flash('error', 'You can only manage series for your own church.');
        redirect('/admin/series');
    }

    $result = Series::delete((int) $target['id']);
    if (!$result['ok']) {
        flash('error', 'That series could not be deleted.');
    } elseif ($result['detached'] > 0) {
        flash('success', 'Series deleted. ' . $result['detached'] . ' sermon(s) were kept and are now unassigned — they still appear on your sermons page.');
    } else {
        flash('success', 'Series deleted. It had no sermons.');
    }
    redirect('/admin/series');
}

/* ============================================================== read == */
$editing = null;
if ($action === 'edit') {
    $editing = $loadSeries($id);
    if ($editing === null) {
        flash('error', 'That series could not be found.');
        redirect('/admin/series');
    }
    if (empty($editing['can_manage'])) {
        flash('error', 'That series belongs to another church. Only the super admin can edit it.');
        redirect('/admin/series');
    }
}

$seriesList = Series::all($scopeUnitIds);

// How many sermons each series holds that are still unnumbered, so the admin can see at a
// glance which ones a podcast feed would list in date order rather than episode order.
$unnumbered = [];
try {
    foreach ($pdo->query('SELECT series_id, COUNT(*) AS n FROM sermons WHERE series_id IS NOT NULL AND series_position IS NULL GROUP BY series_id')->fetchAll() as $row) {
        $unnumbered[(int) $row['series_id']] = (int) $row['n'];
    }
} catch (Throwable $e) {
    // Nothing numbered yet.
}

$pageTitle = 'Sermon Series';
$activeNav = 'series';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <div class="card" style="max-width:640px;">
    <h2><?= $action === 'create' ? 'New Series' : 'Edit Series' ?></h2>
    <p class="sub">
      A series groups sermons into a run — and publishes them as a podcast feed, so people can
      subscribe in Spotify or Apple Podcasts instead of remembering to visit the website.
    </p>
    <form method="post" action="/admin/series?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>

      <label for="title">Series name</label>
      <input type="text" id="title" name="title" maxlength="150" required
             value="<?= e((string) ($editing['title'] ?? '')) ?>" placeholder="e.g. Faith Foundations">
      <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
        This name appears as the podcast title, so keep it recognisable.
      </small>

      <label for="description">Description <small style="color:var(--ink-faint);">(optional, but podcast directories show it)</small></label>
      <textarea id="description" name="description" rows="3" maxlength="1200"
                placeholder="What is this series about?"><?= e((string) ($editing['description'] ?? '')) ?></textarea>

      <?php if ($isSuper): ?>
        <label for="org_unit_id">Church</label>
        <select id="org_unit_id" name="org_unit_id">
          <option value="">— shared with every church —</option>
          <?php foreach ($assignableUnits as $unit): ?>
            <option value="<?= (int) $unit['id'] ?>" <?= (int) ($editing['org_unit_id'] ?? 0) === (int) $unit['id'] ? 'selected' : '' ?>>
              <?= e(Unit::optionLabel($unit)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <label>Church</label>
        <input type="text" value="<?= e($unitLabels[$myUnitId] ?? 'Your church') ?>" disabled>
        <small style="color:var(--ink-faint);font-size:12px;">Series belong to your church automatically.</small>
      <?php endif; ?>

      <div class="row two">
        <div>
          <label for="cover_image">Cover image</label>
          <input type="file" id="cover_image" name="cover_image" accept="image/*">
          <small style="color:var(--ink-faint);font-size:12px;">
            Square works best — podcast directories require it.<?= !empty($editing['cover_image']) ? ' Leave blank to keep the current one.' : '' ?>
          </small>
          <?php if (!empty($editing['cover_image'])): ?>
            <img src="<?= e(uploadUrl($editing['cover_image'])) ?>" alt="" style="width:88px;height:88px;object-fit:cover;border-radius:10px;margin-top:8px;border:1px solid var(--border);">
          <?php endif; ?>
        </div>
        <div>
          <label for="sort_order">Order on the series page</label>
          <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
          <small style="color:var(--ink-faint);font-size:12px;">Lowest first. Leave 0 to order by name.</small>
        </div>
      </div>

      <div class="checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" <?= $editing === null || !empty($editing['is_published']) ? 'checked' : '' ?>>
        <label for="is_published" style="margin:0;">Published</label>
      </div>
      <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:14px;">
        An unpublished series is hidden from the website and left out of the podcast feed, but its
        sermons stay visible on the sermons page.
      </small>

      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'create' ? 'Create Series' : 'Save Changes' ?></button>
        <a class="btn secondary" href="/admin/series">Cancel</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn" href="/admin/series?action=create">+ New Series</a>
    <a class="btn secondary" href="/admin/sermons">Sermons</a>
  </div>

  <div class="card">
    <?php if (!$seriesList): ?>
      <div class="empty">
        No series yet. Create one, then attach sermons to it — the number of episodes is worked
        out for you.
      </div>
    <?php else: ?>
      <table>
        <tr><th>Series</th><th>Church</th><th>Episodes</th><th>Status</th><th></th></tr>
        <?php foreach ($seriesList as $row): ?>
          <?php
            $canManage = $isSuper || ((int) ($row['org_unit_id'] ?? 0) > 0 && (int) $row['org_unit_id'] === $myUnitId);
            $count = (int) ($row['sermon_count'] ?? 0);
            $loose = (int) ($unnumbered[(int) $row['id']] ?? 0);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <?php if (!empty($row['cover_image'])): ?>
                  <img src="<?= e(uploadUrl($row['cover_image'])) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--border);">
                <?php endif; ?>
                <div>
                  <strong><?= e((string) $row['title']) ?></strong>
                  <?php if (!empty($row['description'])): ?>
                    <div><small style="color:var(--ink-faint);"><?= e(mb_strimwidth((string) $row['description'], 0, 70, '…')) ?></small></div>
                  <?php endif; ?>
                  <div><small style="color:var(--ink-dim);">/series/<?= e((string) $row['slug']) ?></small></div>
                </div>
              </div>
            </td>
            <td>
              <?php if (!empty($row['org_unit_id'])): ?>
                <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $row['org_unit_id']] ?? '') ?></span>
              <?php else: ?>
                <span class="badge warn">Shared</span>
              <?php endif; ?>
              <?php if (!$canManage): ?>
                <div style="margin-top:6px;"><span class="badge">read-only</span></div>
              <?php elseif (!empty($row['org_unit_id'])): ?>
                <div style="margin-top:6px;">
                  <?php
                    $reassignId = (int) $row['id'];
                    $reassignUnitId = (int) $row['org_unit_id'];
                    $showUnassignedOnly = false;
                    $assignAction = '/admin/series?action=reassign';
                    require __DIR__ . '/partials/unit-assign.php';
                  ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?= number_format($count) ?>
              <?php if ($loose > 0): ?>
                <div><small style="color:var(--warn,#e8b95f);font-size:11.5px;"><?= $loose ?> without an episode number</small></div>
              <?php endif; ?>
            </td>
            <td><?= !empty($row['is_published']) ? '<span class="badge ok">published</span>' : '<span class="badge warn">draft</span>' ?></td>
            <td style="white-space:nowrap;">
              <?php if ($canManage): ?>
                <a class="btn sm" href="/admin/sermons?action=create&series_id=<?= (int) $row['id'] ?>">+ Episode</a>
                <a class="btn secondary sm" href="/admin/series?action=edit&id=<?= (int) $row['id'] ?>">Edit</a>
                <form method="post" action="/admin/series?action=delete" style="display:inline;"
                      onsubmit="return confirm('Delete “<?= e((string) $row['title']) ?>”? Its sermons are kept and simply become unassigned.');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="btn danger sm" type="submit">Delete</button>
                </form>
              <?php else: ?>
                <a class="btn secondary sm" href="/series/<?= e((string) $row['slug']) ?>" target="_blank" rel="noopener">View</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>How this reaches listeners</h2>
    <p class="sub" style="margin-bottom:8px;">
      Every published series appears on the public series page, and the sermon catalogue becomes a
      podcast feed at:
    </p>
    <pre style="background:#0f0d1f;border:1px solid var(--border);border-radius:10px;padding:12px 14px;overflow:auto;font-size:12.5px;"><?= e(baseUrl('/podcast.xml')) ?></pre>
    <p class="sub" style="font-size:12.5px;">
      Give that address to Spotify, Apple Podcasts or any other directory once, and new sermons
      appear in subscribers' apps on their own. A sermon is included when it is <strong>published</strong>
      and has audio — either uploaded or linked.
    </p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
