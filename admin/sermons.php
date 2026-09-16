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

// Series this admin may file a sermon under. Empty scope means "no restriction", which is what
// a super admin gets; everyone else sees their own church's series plus the shared (unit-less)
// ones, the same convention Series::all() uses.
$isSuper = !empty($user['is_super_admin']);
$myUnitId = (int) ($user['org_unit_id'] ?? 0);
$seriesScopeIds = $isSuper ? [] : ($myUnitId > 0 ? [$myUnitId] : []);
$seriesOptions = Series::all($seriesScopeIds);

// "+ Episode" on the Series screen links here with the series already chosen.
$presetSeriesId = (int) ($_GET['series_id'] ?? 0);

/**
 * True when this admin is allowed to file sermons under the given series.
 *
 * A series with no church is shared, so anyone scoped to a church may use it.
 */
function seriesInScope(?array $series, bool $isSuper, array $scopeIds): bool
{
    if ($series === null) {
        return false;
    }
    if ($isSuper) {
        return true;
    }
    $owner = $series['org_unit_id'] !== null ? (int) $series['org_unit_id'] : null;
    return $owner === null || in_array($owner, $scopeIds, true);
}

// Super admin / scoped admin can assign a sermon to a church.
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $reassignId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'sermons', $reassignId, $user) && $unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE sermons SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $reassignId]);

        // A series belongs to one church's teaching run. Moving a sermon to a different church
        // while it stays filed under the old church's series would leave it in the old church's
        // podcast feed, so it is detached instead. attach() clears the legacy text column too.
        $left = '';
        $lookup = $pdo->prepare('SELECT se.org_unit_id FROM sermons s LEFT JOIN sermon_series se ON se.id = s.series_id WHERE s.id = ?');
        $lookup->execute([$reassignId]);
        $owner = $lookup->fetchColumn();
        if ($owner !== false && $owner !== null && (int) $owner !== $unitId) {
            Series::attach($reassignId, null, null);
            $left = ' Its series was left behind, because it belongs to another church.';
        }

        flash('success', 'Assigned to ' . Unit::label($unitId) . '.' . $left);
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
    $scripture = trim($_POST['scripture_ref'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $videoUrl = trim($_POST['video_embed_url'] ?? '');
    $publishedAt = $_POST['published_at'] ?? date('Y-m-d\TH:i');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Podcast metadata.
    $seriesId = (int) ($_POST['series_id'] ?? 0);
    $positionRaw = trim((string) ($_POST['series_position'] ?? ''));
    $audioUrl = trim((string) ($_POST['audio_url'] ?? ''));
    $durationRaw = trim((string) ($_POST['duration_seconds'] ?? ''));
    $isExplicit = isset($_POST['is_explicit']) ? 1 : 0;

    // Filing a sermon under a series it does not belong to would put it back in the wrong
    // church's podcast feed, so the choice is checked rather than trusted.
    $chosenSeries = null;
    if ($seriesId > 0) {
        $chosenSeries = Series::find($seriesId);
        if (!seriesInScope($chosenSeries, $isSuper, $seriesScopeIds)) {
            $errors[] = 'That series is not available to your account.';
            $seriesId = 0;
            $chosenSeries = null;
        }
    }

    // Blank means "give it the next free number"; anything unreadable is refused rather than
    // silently discarded, because a wrong episode number is hard to notice later.
    $position = null;
    if ($positionRaw !== '') {
        if (ctype_digit($positionRaw) && (int) $positionRaw > 0) {
            $position = (int) $positionRaw;
        } else {
            $errors[] = 'Episode number must be a whole number, or left blank to number it automatically.';
        }
    }

    $duration = null;
    if ($durationRaw !== '') {
        $duration = PodcastFeed::parseDuration($durationRaw);
        if ($duration === null) {
            $errors[] = 'Duration was not understood — use minutes, or h:mm:ss.';
        }
    }

    if ($audioUrl !== '' && !preg_match('~^https?://~i', $audioUrl)) {
        $errors[] = 'The external audio link must start with http:// or https://';
    }

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
        } elseif ($videoUrl) {
            $coverPath = MediaProcessor::fetchVideoUrlThumbnail($videoUrl);
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
                // The series columns are deliberately left out here and written by Series::attach()
                // below. The legacy text column has to stay in step with series_id, and that method
                // is the single place that knows how to do it.
                $stmt = $pdo->prepare('INSERT INTO sermons (title, slug, speaker, series, scripture_ref, description, audio_path, audio_url, video_embed_url, cover_image, duration_seconds, is_explicit, is_published, published_at, org_unit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$title, sermonSlug($pdo, $title), $speaker, null, $scripture, $description, $audioPath, $audioUrl ?: null, $videoUrl ?: null, $coverPath, $duration, $isExplicit, $isPublished, $publishedAt, $user['org_unit_id'] ?? null]);
                $newId = (int) $pdo->lastInsertId();

                // A blank episode number means "next free", which attach() works out.
                Series::attach($newId, $seriesId > 0 ? $seriesId : null, $position);

                // A sermon filed under a church's series belongs to that church, or it would be
                // missing from the church's page and its podcast feed.
                if ($chosenSeries !== null && empty($user['org_unit_id'])) {
                    $owner = $chosenSeries['org_unit_id'] !== null ? (int) $chosenSeries['org_unit_id'] : null;
                    if ($owner !== null) {
                        Series::adoptUnit($seriesId, $owner);
                    }
                }

                if ($isPublished) {
                    try {
                        Pusher::notifyNewSermon($pdo, $newId, $user['org_unit_id'] ?? null, $title);
                    } catch (Throwable $e) {
                        error_log('Push notify failed: ' . $e->getMessage());
                    }
                }
                flash('success', $chosenSeries !== null
                    ? 'Sermon added to ' . $chosenSeries['title'] . '.'
                    : 'Sermon added.');
            } else {
                $sql = 'UPDATE sermons SET title=?, slug=?, speaker=?, scripture_ref=?, description=?, video_embed_url=?, audio_url=?, duration_seconds=?, is_explicit=?, is_published=?, published_at=?';
                $params = [$title, sermonSlug($pdo, $title, $id), $speaker, $scripture, $description, $videoUrl ?: null, $audioUrl ?: null, $duration, $isExplicit, $isPublished, $publishedAt];
                if ($coverPath) { $sql .= ', cover_image=?'; $params[] = $coverPath; }
                if ($audioPath) { $sql .= ', audio_path=?'; $params[] = $audioPath; }
                $sql .= ' WHERE id=?';
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);

                // Attaching an existing sermon to a series also pulls it into that church, so an
                // episode added through the series screen does not stay unassigned.
                Series::attach($id, $seriesId > 0 ? $seriesId : null, $position);
                if ($chosenSeries !== null && empty($user['org_unit_id'])) {
                    $owner = $chosenSeries['org_unit_id'] !== null ? (int) $chosenSeries['org_unit_id'] : null;
                    if ($owner !== null) {
                        Series::adoptUnit($seriesId, $owner);
                    }
                }

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

// The form's opening state. A new sermon started from the Series screen arrives with the series
// already chosen, and its episode number defaults to the next free one in that series.
$formSeriesId = (int) ($editing['series_id'] ?? 0);
if ($formSeriesId === 0 && $action === 'create') {
    $formSeriesId = $presetSeriesId;
    if ($formSeriesId > 0 && !seriesInScope(Series::find($formSeriesId), $isSuper, $seriesScopeIds)) {
        $formSeriesId = 0;
    }
}
$nextPosition = $formSeriesId > 0 ? Series::nextPosition($formSeriesId) : 1;

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
          <label for="series_id">Series</label>
          <select id="series_id" name="series_id">
            <option value="0">— no series —</option>
            <?php foreach ($seriesOptions as $so): ?>
              <option value="<?= (int) $so['id'] ?>" <?= (int) $so['id'] === $formSeriesId ? 'selected' : '' ?>>
                <?= e($so['title']) ?><?= empty($so['org_unit_id']) ? ' (shared)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">
            Grouping sermons into a series is what lets people subscribe to the podcast.
            <a href="/admin/series">Manage series</a>
          </p>
        </div>
      </div>
      <div class="row two">
        <div>
          <label for="series_position">Episode Number</label>
          <input type="number" id="series_position" name="series_position" min="1" step="1"
                 value="<?= $editing !== null && !empty($editing['series_position']) ? (int) $editing['series_position'] : '' ?>"
                 placeholder="auto (<?= $nextPosition ?>)">
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">Leave blank to number it automatically.</p>
        </div>
        <div>
          <label for="duration_seconds">Duration</label>
          <input type="text" id="duration_seconds" name="duration_seconds"
                 value="<?= e($editing !== null && !empty($editing['duration_seconds']) ? PodcastFeed::formatDuration((int) $editing['duration_seconds']) : '') ?>"
                 placeholder="42 min or 42:30">
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">Shown on the podcast player.</p>
        </div>
      </div>
      <label for="scripture_ref">Scripture Reference</label>
      <input type="text" id="scripture_ref" name="scripture_ref" value="<?= e($editing['scripture_ref'] ?? '') ?>" placeholder="John 3:16">
      <label for="description">Description</label>
      <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
      <label for="video_embed_url">Video Embed URL (YouTube/Facebook/Vimeo, optional)</label>
      <input type="url" id="video_embed_url" name="video_embed_url" value="<?= e($editing['video_embed_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=... or https://www.facebook.com/watch/?v=...">
      <p class="hint" style="margin-top:-6px; margin-bottom:12px; font-size:12px; color:var(--ink-dim);">Note for Facebook videos: Ensure the Facebook video/reel privacy is set to <strong>Public</strong> in Facebook settings so the embedded player can display it.</p>
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
      <label for="audio_url">External Audio Link (optional)</label>
      <input type="url" id="audio_url" name="audio_url" value="<?= e($editing['audio_url'] ?? '') ?>" placeholder="https://cdn.example.com/episode-12.mp3">
      <p class="hint" style="margin-top:-6px;margin-bottom:12px;font-size:12px;color:var(--ink-dim);">
        Use this when the audio already lives somewhere else, such as a podcast host or a file on
        another site. It takes priority over an uploaded file, and the episode is skipped from the
        podcast feed if the link stops working.
      </p>
      <?php if ($editing !== null && !empty($editing['audio_path'])): ?>
        <p class="hint" style="margin-top:-6px;margin-bottom:12px;font-size:12px;color:var(--ink-dim);">
          Currently using the uploaded file <code><?= e(basename((string) $editing['audio_path'])) ?></code>.
        </p>
      <?php endif; ?>
      <label for="published_at">Published At</label>
      <input type="datetime-local" id="published_at" name="published_at" value="<?= e($editing ? str_replace(' ', 'T', substr((string) $editing['published_at'], 0, 16)) : date('Y-m-d\TH:i')) ?>">
      <div class="checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" <?= $editing === null || !empty($editing['is_published']) ? 'checked' : '' ?>>
        <label for="is_published" style="margin:0;">Published</label>
      </div>
      <div class="checkbox-row">
        <input type="checkbox" id="is_explicit" name="is_explicit" <?= !empty($editing['is_explicit']) ? 'checked' : '' ?>>
        <label for="is_explicit" style="margin:0;">Explicit content</label>
      </div>
      <p class="hint" style="margin-top:-6px;margin-bottom:12px;font-size:12px;color:var(--ink-dim);">
        Tick this only if the message contains adult themes. Podcast directories require it to be
        declared honestly, and hiding it can get a feed removed.
      </p>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'create' ? 'Add Sermon' : 'Save Changes' ?></button>
        <a class="btn secondary" href="/admin/sermons">Cancel</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn" href="/admin/sermons?action=create">+ New Sermon</a>
    <a class="btn secondary" href="/admin/series">Series &amp; Podcast</a>
  </div>
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
          <td>
            <?php if (!empty($s['series_id']) && !empty($s['series'])): ?>
              <span style="color:var(--gold-soft);"><?= e($s['series']) ?></span>
              <?php if (!empty($s['series_position'])): ?>
                <span style="color:var(--ink-dim);font-size:12px;">#<?= (int) $s['series_position'] ?></span>
              <?php else: ?>
                <span class="badge warn" style="font-size:11px;">unnumbered</span>
              <?php endif; ?>
            <?php elseif (!empty($s['series'])): ?>
              <span style="color:var(--ink-dim);font-size:12px;"><?= e($s['series']) ?></span>
              <span class="badge warn" style="font-size:11px;" title="This sermon still holds a series as plain text from before series existed.">text only</span>
            <?php else: ?>
              <span style="color:var(--ink-dim);">—</span>
            <?php endif; ?>
            <div style="margin-top:4px;font-size:11px;">
              <?php if (!empty($s['audio_url'])): ?>
                <span style="color:var(--gold-soft);">external audio</span>
              <?php elseif (!empty($s['audio_path'])): ?>
                <span style="color:var(--ok, #7ec97e);">audio</span>
              <?php else: ?>
                <span style="color:var(--ink-dim);">no audio</span>
              <?php endif; ?>
            </div>
          </td>
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
