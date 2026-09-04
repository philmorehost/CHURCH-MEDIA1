<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$scope = Unit::scopeClause($user, 'org_unit_id');
$scopeSql = $scope !== '' ? ' AND ' . $scope : '';
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$audioDir = UPLOADS_PATH . '/audio';
$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();

// Super admin / scoped admin can assign a sermon to a church.
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $reassignId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'sermons', $reassignId, $user) && $unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE sermons SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $reassignId]);
        flash('success', 'Assigned to ' . Unit::label($unitId) . '.');
    } else {
        flash('error', 'Could not reassign that sermon.');
    }
    redirect('/admin/sermons');
}

function sermonSlug(PDO $pdo, string $title, int $ignoreId = 0): string
{
    $base = slugify($title);
    $slug = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sermons WHERE slug = ? AND id != ?');
    while (true) {
        $stmt->execute([$slug, $ignoreId]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . (++$i);
    }
}

if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($action === 'edit' && !Unit::recordInScope($pdo, 'sermons', $id, $user)) {
        flash('error', 'You can only manage sermons for your own church.');
        redirect('/admin/sermons');
    }
    $title = trim($_POST['title'] ?? '');
    $speaker = trim($_POST['speaker'] ?? '');
    $series = trim($_POST['series'] ?? '');
    $scripture = trim($_POST['scripture_ref'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $videoUrl = trim($_POST['video_embed_url'] ?? '');
    $publishedAt = $_POST['published_at'] ?? date('Y-m-d\TH:i');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Auto-assign sermons to the creator's church (block creation if they have none).
    if ($action === 'create' && empty($user['is_super_admin']) && empty($user['org_unit_id'])) {
        $errors[] = 'Your account has no Home Church assigned — ask the super admin to set it (Users → Edit → Home Unit) before adding sermons.';
    }

    if ($title === '') {
        $errors[] = 'Title is required.';
    } else {
        $coverPath = null;
        if (!empty($_FILES['cover_image']['tmp_name']) && is_uploaded_file($_FILES['cover_image']['tmp_name'])) {
            $filename = MediaProcessor::processImage($_FILES['cover_image']['tmp_name'], UPLOADS_WEBP_PATH);
            $coverPath = $filename ? 'webp/' . $filename : null;
        }
        $audioPath = null;
        if (!empty($_FILES['audio']['tmp_name']) && is_uploaded_file($_FILES['audio']['tmp_name'])) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['audio']['tmp_name']);
            if (str_starts_with((string) $mime, 'audio/')) {
                if (!is_dir($audioDir)) {
                    mkdir($audioDir, 0775, true);
                }
                $filename = uniqid('sermon_', true) . '.' . pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
                move_uploaded_file($_FILES['audio']['tmp_name'], $audioDir . '/' . $filename);
                $audioPath = 'audio/' . $filename;
            } else {
                $errors[] = 'Audio file must be an audio format (mp3, m4a, wav).';
            }
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $pdo->prepare('INSERT INTO sermons (title, slug, speaker, series, scripture_ref, description, audio_path, video_embed_url, cover_image, is_published, published_at, org_unit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$title, sermonSlug($pdo, $title), $speaker, $series, $scripture, $description, $audioPath, $videoUrl ?: null, $coverPath, $isPublished, $publishedAt, $user['org_unit_id'] ?? null]);
                if ($isPublished) {
                    try {
                        Pusher::notifyNewSermon($pdo, (int) $pdo->lastInsertId(), $user['org_unit_id'] ?? null, $title);
                    } catch (Throwable $e) {
                        error_log('Push notify failed: ' . $e->getMessage());
                    }
                }
                flash('success', 'Sermon added.');
            } else {
                $sql = 'UPDATE sermons SET title=?, slug=?, speaker=?, series=?, scripture_ref=?, description=?, video_embed_url=?, is_published=?, published_at=?';
                $params = [$title, sermonSlug($pdo, $title, $id), $speaker, $series, $scripture, $description, $videoUrl ?: null, $isPublished, $publishedAt];
                if ($coverPath) { $sql .= ', cover_image=?'; $params[] = $coverPath; }
                if ($audioPath) { $sql .= ', audio_path=?'; $params[] = $audioPath; }
                $sql .= ' WHERE id=?';
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);
                flash('success', 'Sermon updated.');
            }
            redirect('/admin/sermons');
        }
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'sermons', $targetId, $user)) {
        flash('error', 'You can only manage sermons for your own church.');
        redirect('/admin/sermons');
    }
    $pdo->prepare('DELETE FROM sermons WHERE id = ?')->execute([$targetId]);
    flash('success', 'Sermon deleted.');
    redirect('/admin/sermons');
}

$editing = null;
if ($action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM sermons WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing) {
        redirect('/admin/sermons');
    }
    if (!Unit::recordInScope($pdo, 'sermons', $id, $user)) {
        flash('error', 'You can only manage sermons for your own church.');
        redirect('/admin/sermons');
    }
}

$sermons = $action === 'list' ? $pdo->query('SELECT * FROM sermons WHERE 1=1' . $scopeSql . ' ORDER BY published_at DESC LIMIT 100')->fetchAll() : [];

$pageTitle = 'Sermons';
$activeNav = 'sermons';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <div class="card" style="max-width:640px;">
    <h2><?= $action === 'create' ? 'New Sermon' : 'Edit Sermon' ?></h2>
    <form method="post" action="/admin/sermons?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <label for="title">Title</label>
      <input type="text" id="title" name="title" value="<?= e($editing['title'] ?? '') ?>" required>
      <div class="row two">
        <div>
          <label for="speaker">Speaker</label>
          <input type="text" id="speaker" name="speaker" value="<?= e($editing['speaker'] ?? '') ?>">
        </div>
        <div>
          <label for="series">Series</label>
          <input type="text" id="series" name="series" value="<?= e($editing['series'] ?? '') ?>">
        </div>
      </div>
      <label for="scripture_ref">Scripture Reference</label>
      <input type="text" id="scripture_ref" name="scripture_ref" value="<?= e($editing['scripture_ref'] ?? '') ?>" placeholder="John 3:16">
      <label for="description">Description</label>
      <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
      <label for="video_embed_url">Video Embed URL (YouTube/Vimeo, optional)</label>
      <input type="url" id="video_embed_url" name="video_embed_url" value="<?= e($editing['video_embed_url'] ?? '') ?>">
      <div class="row two">
        <div>
          <label for="cover_image">Cover Image</label>
          <input type="file" id="cover_image" name="cover_image" accept="image/*">
        </div>
        <div>
          <label for="audio">Audio File (mp3/m4a/wav)</label>
          <input type="file" id="audio" name="audio" accept="audio/*">
        </div>
      </div>
      <label for="published_at">Published At</label>
      <input type="datetime-local" id="published_at" name="published_at" value="<?= e($editing ? str_replace(' ', 'T', substr((string) $editing['published_at'], 0, 16)) : date('Y-m-d\TH:i')) ?>">
      <div class="checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" <?= $editing === null || !empty($editing['is_published']) ? 'checked' : '' ?>>
        <label for="is_published" style="margin:0;">Published</label>
      </div>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'create' ? 'Add Sermon' : 'Save Changes' ?></button>
        <a class="btn secondary" href="/admin/sermons">Cancel</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;"><a class="btn" href="/admin/sermons?action=create">+ New Sermon</a></div>
  <div class="card">
    <?php if (!$sermons): ?>
      <div class="empty">No sermons yet.</div>
    <?php else: ?>
      <table>
        <tr><th>Title</th><th>Church</th><th>Speaker</th><th>Series</th><th>Published</th><th>Status</th><th></th></tr>
        <?php foreach ($sermons as $s): ?>
        <tr>
          <td><?= e($s['title']) ?></td>
          <td>
            <?php if (!empty($s['org_unit_id'])): ?>
              <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $s['org_unit_id']] ?? '') ?></span>
            <?php else: ?>
              <span class="badge warn">Unassigned</span>
            <?php endif; ?>
            <div style="margin-top:6px;">
              <?php $reassignId = (int) $s['id']; $reassignUnitId = !empty($s['org_unit_id']) ? (int) $s['org_unit_id'] : null; $showUnassignedOnly = false; $assignAction = '/admin/sermons?action=reassign'; require __DIR__ . '/partials/unit-assign.php'; ?>
            </div>
          </td>
          <td><?= e($s['speaker'] ?: '—') ?></td>
          <td><?= e($s['series'] ?: '—') ?></td>
          <td><?= e(date('M j, Y', strtotime($s['published_at']))) ?></td>
          <td><?= $s['is_published'] ? '<span class="badge ok">published</span>' : '<span class="badge warn">draft</span>' ?></td>
          <td>
            <a class="btn secondary sm" href="/admin/sermons?action=edit&id=<?= (int) $s['id'] ?>">Edit</a>
            <form method="post" action="/admin/sermons?action=delete" onsubmit="return confirm('Delete this sermon?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
