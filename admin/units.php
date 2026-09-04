<?php
declare(strict_types=1);

Auth::requireRole('admin');
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can manage units.');
}

$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

// Bulk-import churches from CSV: Province,Zone,Area,Parish (Parish optional).
if ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $csvText = '';
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $csvText = (string) file_get_contents($_FILES['csv_file']['tmp_name']);
    } else {
        $csvText = (string) ($_POST['csv_text'] ?? '');
    }
    $csvText = trim($csvText);
    if ($csvText === '') {
        $errors[] = 'Paste CSV text or upload a .csv file.';
    } else {
        $stats = ['province' => 0, 'zone' => 0, 'area' => 0, 'parish' => 0, 'skipped' => 0];
        $ensure = function (string $type, ?int $parentId, string $name) use (&$stats): ?int {
            if ($name === '') {
                return null;
            }
            $existing = Unit::findByName($type, $name, $parentId);
            if ($existing) {
                return (int) $existing['id'];
            }
            $res = Unit::findOrCreate($type, $parentId, $name);
            if (isset($res['errors'])) {
                $stats['skipped']++;
                return null;
            }
            $stats[$type]++;
            return (int) $res['id'];
        };
        $pdo->beginTransaction();
        try {
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, $csvText);
            rewind($fh);
            $first = true;
            while (($row = fgetcsv($fh)) !== false) {
                if ($first) {
                    $first = false;
                    continue; // skip header row
                }
                $provinceId = $ensure('province', null, Unit::nameFor((string) ($row[0] ?? '')));
                if ($provinceId === null) {
                    $stats['skipped']++;
                    continue;
                }
                $zoneId = $ensure('zone', $provinceId, Unit::nameFor((string) ($row[1] ?? '')));
                if ($zoneId === null) {
                    continue;
                }
                $areaId = $ensure('area', $zoneId, Unit::nameFor((string) ($row[2] ?? '')));
                if ($areaId === null) {
                    continue;
                }
                $ensure('parish', $areaId, Unit::nameFor((string) ($row[3] ?? '')));
            }
            fclose($fh);
            $pdo->commit();
            flash('success', 'CSV import done — added ' . $stats['province'] . ' province(s), ' . $stats['zone'] . ' zone(s), ' . $stats['area'] . ' area(s), ' . $stats['parish'] . ' parish(es). ' . $stats['skipped'] . ' row(s) skipped.');
            redirect('/admin/units?action=import_csv');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'CSV import failed: ' . $e->getMessage();
        }
    }
}

// Download a ready-to-fill CSV sample so admins get the column format exactly right.
if ($action === 'sample_csv') {
    csvDownload('church-import-sample.csv', ['Province', 'Zone', 'Area', 'Parish'], [
        ['LAGOS PROVINCE', 'LAGOS ZONE', 'SOMOLU AREA', ''],
        ['LAGOS PROVINCE', 'LAGOS ZONE', 'YABA AREA', 'ST JAMES PARISH'],
        ['OGUN PROVINCE', 'ABEOKUTA ZONE', 'IDI-ABA AREA', ''],
        ['OGUN PROVINCE', 'ABEOKUTA ZONE', 'IJAYE AREA', 'GRACE PARISH'],
    ]);
}

// Approve a church-name correction: rename the unit to the suggested spelling.
if ($action === 'flag_approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $flagId = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM church_name_flags WHERE id = ?');
    $stmt->execute([$flagId]);
    $flag = $stmt->fetch();
    if ($flag && $flag['status'] === 'pending') {
        $suggested = Unit::nameFor((string) $flag['suggested_name']);
        $targetId = !empty($flag['org_unit_id']) ? (int) $flag['org_unit_id'] : null;
        if ($targetId === null) {
            $found = Unit::findByNameAnywhere((string) $flag['current_name']);
            $targetId = $found ? (int) $found['id'] : null;
        }
        if ($targetId !== null && $suggested !== '') {
            $pdo->prepare('UPDATE org_units SET name = ? WHERE id = ?')->execute([$suggested, $targetId]);
            flash('success', 'Church name corrected to "' . $suggested . '".');
        } else {
            flash('error', 'Could not resolve the church to rename — no changes made.');
        }
        $pdo->prepare('UPDATE church_name_flags SET status = "approved", reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$user['id'] ?? null, $flagId]);
    }
    redirect('/admin/units?action=flags');
}

// Reject a church-name correction: no changes.
if ($action === 'flag_reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $pdo->prepare('UPDATE church_name_flags SET status = "rejected", reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = "pending"')
        ->execute([$user['id'] ?? null, (int) ($_POST['id'] ?? 0)]);
    flash('success', 'Correction rejected — no changes made.');
    redirect('/admin/units?action=flags');
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $type = (string) ($_POST['type'] ?? 'province');
    $parentId = (string) ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $result = Unit::create($type, $parentId, $name);
    if (!empty($result['errors'])) {
        $errors = $result['errors'];
    } else {
        flash('success', 'Unit added.');
        redirect('/admin/units');
    }
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? $id);
    $type = (string) ($_POST['type'] ?? '');
    $parentId = (string) ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $result = Unit::update($id, $type, $parentId, $name);
    if (!empty($result['errors'])) {
        $errors = $result['errors'];
    } else {
        flash('success', 'Unit updated.');
        redirect('/admin/units');
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    Unit::delete((int) ($_POST['id'] ?? 0));
    flash('success', 'Unit removed.');
    redirect('/admin/units');
}

$editUnit = null;
if ($action === 'edit') {
    $editUnit = Unit::find($id);
    if (!$editUnit) {
        redirect('/admin/units');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
        $editUnit['type'] = (string) ($_POST['type'] ?? $editUnit['type']);
        $editUnit['parent_id'] = (string) ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
        $editUnit['name'] = trim((string) ($_POST['name'] ?? $editUnit['name']));
    }
}

$tree = Unit::tree();
$unitOptions = array_map(fn (array $u): array => ['id' => (int) $u['id'], 'type' => $u['type'], 'label' => Unit::label((int) $u['id'])], Unit::all());
$nameFlags = [];
if ($action === 'flags') {
    $nameFlags = $pdo->query("SELECT * FROM church_name_flags ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, created_at DESC LIMIT 100")->fetchAll();
}

$pageTitle = 'Units';
$activeNav = 'units';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'create' || $action === 'edit'): ?>
  <div class="card" style="max-width:560px;">
    <h2><?= $action === 'edit' ? 'Edit Unit' : 'Add Unit' ?></h2>
    <form method="post" action="/admin/units?action=<?= $action ?><?= $action === 'edit' ? '&id=' . (int) $id : '' ?>">
      <?= Csrf::field() ?>
      <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
      <label for="type">Type</label>
      <select id="type" name="type">
        <?php foreach (Unit::types() as $t): ?>
          <option value="<?= e($t) ?>" <?= ($editUnit['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst(e($t)) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="parent_id">Parent unit</label>
      <select id="parent_id" name="parent_id">
        <option value="">— none (top level) —</option>
      </select>
      <label for="name">Name</label>
      <input type="text" id="name" name="name" value="<?= e($editUnit['name'] ?? '') ?>" required>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'edit' ? 'Save Changes' : 'Add Unit' ?></button>
        <a class="btn secondary" href="/admin/units">Cancel</a>
      </div>
    </form>
  </div>
  <script>
    var unitOptions = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>;
    var parentTypes = { 'zone': 'province', 'area': 'zone', 'parish': 'area' };
    function updateParentPicker() {
      var type = document.getElementById('type').value;
      var want = parentTypes[type] || '';
      var sel = document.getElementById('parent_id');
      var current = sel.value;
      sel.innerHTML = '<option value="">— none (top level) —</option>';
      unitOptions.forEach(function (u) {
        if (want === '' || u.type === want) {
          var o = document.createElement('option');
          o.value = String(u.id);
          o.textContent = u.label;
          sel.appendChild(o);
        }
      });
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === current) { sel.value = current; break; }
      }
    }
    document.getElementById('type').addEventListener('change', updateParentPicker);
    updateParentPicker();
  </script>
<?php elseif ($action === 'import_csv'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/units">← Back to units</a>
    <a class="btn secondary sm" href="/admin/units?action=flags">🏷 Name Corrections</a>
    <a class="btn sm" href="/admin/units?action=sample_csv">⬇ Download sample CSV</a>
  </div>
  <div class="card" style="max-width:760px;">
    <h2>Import Churches from CSV</h2>
    <p class="sub">Columns: <code>Province, Zone, Area, Parish</code> — one church per row. Province is required; <strong>Parish is optional</strong> (leave blank if not known yet — it can be added later by the church's own admin). All names are stored in <strong>CAPS</strong>, and existing units are matched automatically, so there are no duplicates.</p>
    <p class="sub" style="margin-bottom:6px;">👉 <strong>Tip:</strong> click <strong>⬇ Download sample CSV</strong> above to get a ready-to-fill template — just replace the example rows with your own churches and upload it back.</p>
    <p class="sub" style="margin-bottom:18px;">Example:<br><code>Province,Zone,Area,Parish<br>LAGOS PROVINCE,LAGOS ZONE,SOMOLU AREA,<br>LAGOS PROVINCE,LAGOS ZONE,YABA AREA,ST JAMES PARISH</code></p>
    <form method="post" action="/admin/units?action=import_csv" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <label for="csv_file">Upload a .csv file (optional)</label>
      <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv">
      <label for="csv_text">…or paste CSV text here</label>
      <textarea id="csv_text" name="csv_text" rows="8" placeholder="Province,Zone,Area,Parish&#10;LAGOS PROVINCE,LAGOS ZONE,SOMOLU AREA,&#10;LAGOS PROVINCE,LAGOS ZONE,YABA AREA,ST JAMES PARISH"></textarea>
      <button class="btn" type="submit">Import Churches</button>
      <a class="btn secondary" href="/admin/units">Cancel</a>
    </form>
  </div>
<?php elseif ($action === 'flags'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/units">← Back to units</a>
    <a class="btn secondary sm" href="/admin/units?action=import_csv">⬆ Import Churches (CSV)</a>
  </div>
  <div class="card">
    <h2>Church Name Corrections</h2>
    <p class="sub">Churches can flag a misspelled name from the registration page. Approve to automatically change the name, or reject to keep it as is.</p>
    <?php if (!$nameFlags): ?>
      <div class="empty">No name corrections yet.</div>
    <?php else: ?>
      <table>
        <tr><th>Current Name</th><th>Suggested</th><th>Reported</th><th>Status</th><th></th></tr>
        <?php foreach ($nameFlags as $flag): ?>
        <tr>
          <td><strong><?= e($flag['current_name']) ?></strong></td>
          <td><span style="color:var(--gold-soft);font-weight:700;"><?= e($flag['suggested_name']) ?></span></td>
          <td><?= e($flag['reported_by'] ?: '—') ?><br><small style="color:var(--ink-faint);"><?= e(date('M j, Y', strtotime($flag['created_at']))) ?></small></td>
          <td>
            <?php if ($flag['status'] === 'pending'): ?><span class="badge warn">pending</span>
            <?php elseif ($flag['status'] === 'approved'): ?><span class="badge ok">approved</span>
            <?php else: ?><span class="badge fail">rejected</span><?php endif; ?>
          </td>
          <td>
            <?php if ($flag['status'] === 'pending'): ?>
              <form method="post" action="/admin/units?action=flag_approve" style="display:inline;">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $flag['id'] ?>">
                <button type="submit" class="btn sm" onclick="return confirm('Change &quot;<?= e($flag['current_name']) ?>&quot; to &quot;<?= e($flag['suggested_name']) ?>&quot;?');">Approve</button>
              </form>
              <form method="post" action="/admin/units?action=flag_reject" style="display:inline;">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $flag['id'] ?>">
                <button type="submit" class="btn sm danger">Reject</button>
              </form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn" href="/admin/units?action=create">+ Add Unit</a>
    <a class="btn secondary" href="/admin/units?action=import_csv">⬆ Import Churches (CSV)</a>
    <a class="btn secondary" href="/admin/units?action=flags">🏷 Name Corrections</a>
  </div>
  <?php if (!$tree): ?>
    <div class="card"><p style="color:var(--ink-faint);">No units yet — start by adding a Province, or <a href="/admin/units?action=import_csv" style="color:var(--gold-soft);">import churches from CSV</a>.</p></div>
  <?php else: ?>
  <div class="card">
    <table>
      <tr><th>Unit</th><th>Type</th><th></th></tr>
      <?php
      $renderNode = function (array $node, int $depth = 0) use (&$renderNode): void {
          $pad = str_repeat('&nbsp;&nbsp;', $depth);
          echo '<tr>';
          echo '<td>' . $pad . e($node['name']) . ' <small style="color:var(--ink-faint);">/' . e($node['slug']) . '</small></td>';
          echo '<td><span class="badge info">' . e($node['type']) . '</span></td>';
          echo '<td>';
          echo '<a class="btn sm" href="/admin/units?action=edit&id=' . (int) $node['id'] . '">Edit</a> ';
          echo '<form method="post" action="/admin/units?action=delete" onsubmit="return confirm(\'Delete this unit and everything under it? Posts/users will be unassigned.\');" style="display:inline;">';
          echo Csrf::field();
          echo '<input type="hidden" name="id" value="' . (int) $node['id'] . '">';
          echo '<button type="submit" class="btn danger sm">Delete</button>';
          echo '</form>';
          echo '</td></tr>';
          foreach ($node['children'] ?? [] as $child) {
              $renderNode($child, $depth + 1);
          }
      };
      foreach ($tree as $node) {
          $renderNode($node);
      }
      ?>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
