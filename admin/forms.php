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
$assignableUnits = Unit::assignableScope($user);
$unitLabels = Unit::labelsById();

// Super admin / scoped admin can assign a form to a church.
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $reassignId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'forms', $reassignId, $user) && $unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE forms SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $reassignId]);
        flash('success', 'Assigned to ' . Unit::label($unitId) . '.');
    } else {
        flash('error', 'Could not reassign that form.');
    }
    redirect('/admin/forms');
}

const FORM_FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'date', 'url', 'select', 'radio', 'checkbox', 'image', 'cascade', 'church', 'time', 'datetime'];

function formSlug(PDO $pdo, string $title, int $ignoreId = 0): string
{
    $base = slugify($title);
    $slug = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE slug = ? AND id != ?');
    while (true) {
        $stmt->execute([$slug, $ignoreId]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . (++$i);
    }
}

function formFieldsFor(PDO $pdo, int $formId): array
{
    $stmt = $pdo->prepare('SELECT * FROM form_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$formId]);
    return $stmt->fetchAll();
}

/** Builds [headers, rows] for a form's submissions — shared by download + shareable export. */
function formExportData(array $form, array $subFields, array $rows): array
{
    $headers = ['Submitted At', 'IP Address'];
    foreach ($subFields as $f) {
        $headers[] = $f['label'];
    }
    $out = [];
    foreach ($rows as $row) {
        $data = json_decode((string) $row['data'], true) ?: [];
        $line = [$row['created_at'], $row['ip_address'] ?? ''];
        foreach ($subFields as $f) {
            $value = $data[(string) $f['id']] ?? '';
            if (is_array($value)) {
                $value = $f['field_type'] === 'image'
                    ? implode('; ', array_map(fn ($v) => uploadUrl($v), $value))
                    : implode('; ', $value);
            } elseif ($f['field_type'] === 'image' && $value !== '') {
                $value = uploadUrl($value);
            }
            $line[] = (string) $value;
        }
        $out[] = $line;
    }
    return [$headers, $out];
}

function decodeFieldsPayload(string $raw): ?array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** Normalizes + validates the field array posted by the builder. Returns [cleanFields, errors]. */
function validateFieldPayload(array $fields): array
{
    $clean = [];
    $errors = [];
    $i = 0;
    foreach ($fields as $field) {
        $i++;
        if (!is_array($field)) {
            $errors[] = 'Field #' . $i . ' is malformed.';
            continue;
        }
        $label = trim((string) ($field['label'] ?? ''));
        $type = (string) ($field['type'] ?? '');
        if ($label === '') {
            $errors[] = 'Field #' . $i . ' is missing a label.';
            continue;
        }
        if (!in_array($type, FORM_FIELD_TYPES, true)) {
            $errors[] = 'Field #' . $i . ' has an invalid type.';
            continue;
        }
        $options = trim((string) ($field['options'] ?? ''));
        if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
            $optionLines = array_filter(array_map('trim', explode("\n", $options)));
            if (!$optionLines) {
                $errors[] = '"' . $label . '" needs at least one option (one per line).';
                continue;
            }
        }
        if ($type === 'cascade' && !formCascadeOptions(['options' => $options])) {
            $errors[] = '"' . $label . '" needs at least one path — one full path per line, levels separated by > (e.g. Lagos > Somolu > LP63 YAYA).';
            continue;
        }
        $clean[] = [
            'label' => mb_substr($label, 0, 255),
            'field_type' => $type,
            'placeholder' => mb_substr(trim((string) ($field['placeholder'] ?? '')), 0, 255),
            'options' => mb_substr($options, 0, 5000),
            'required' => !empty($field['required']) ? 1 : 0,
            'sort_order' => $i,
        ];
    }
    return [$clean, $errors];
}

if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($action === 'edit' && !Unit::recordInScope($pdo, 'forms', $id, $user)) {
        http_response_code(403);
        exit('This form belongs to another church.');
    }
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $submitLabel = trim($_POST['submit_label'] ?? '');
    $endAt = trim($_POST['end_at'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
    $newPassword = trim((string) ($_POST['access_password'] ?? ''));
    $fieldsJson = trim((string) ($_POST['fields_json'] ?? ''));

    // Every church account must belong to a church so the form is auto-assigned
    // to them on creation (never silently unassigned).
    if ($action === 'create' && empty($user['is_super_admin']) && empty($user['org_unit_id'])) {
        $errors[] = 'Your account has no Home Church assigned — ask the super admin to set it (Users → Edit → Home Unit) before creating forms.';
    }

    if ($title === '') {
        $errors[] = 'Form title is required.';
    }

    // Private forms need a password: a new one, or keep the existing one when editing.
    $existingHash = null;
    if ($action === 'edit') {
        $stmt = $pdo->prepare('SELECT password_hash FROM forms WHERE id = ?');
        $stmt->execute([$id]);
        $existingHash = $stmt->fetchColumn() ?: null;
    }
    $passHash = null;
    if ($visibility === 'private') {
        $passHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $existingHash;
        if ($passHash === null) {
            $errors[] = 'Private forms need an access password — set one below.';
        }
    }

    [$fields, $fieldErrors] = decodeFieldsPayload($fieldsJson) !== null
        ? validateFieldPayload(decodeFieldsPayload($fieldsJson))
        : [[], ['The fields payload is missing or invalid — please re-open the form and try again.']];
    $errors = array_merge($errors, $fieldErrors);

    if (!$errors) {
        $slug = $slug === '' ? formSlug($pdo, $title, $action === 'edit' ? $id : 0) : $slug;
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors[] = 'Slug may only contain lowercase letters, numbers, and dashes.';
        } else {
            $check = $pdo->prepare('SELECT id FROM forms WHERE slug = ? AND id != ? LIMIT 1');
            $check->execute([$slug, $action === 'edit' ? $id : 0]);
            if ($check->fetchColumn()) {
                $errors[] = 'That slug is already in use — try another one or leave it blank to auto-generate.';
            }
        }
    }

    if (!$errors) {
        $endAtValue = $endAt === '' ? null : str_replace('T', ' ', $endAt);
        $submitLabelValue = $submitLabel === '' ? 'Submit' : $submitLabel;

        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO forms (title, slug, description, submit_label, end_at, is_active, org_unit_id, visibility, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $slug, $description, $submitLabelValue, $endAtValue, $isActive, $user['org_unit_id'] ?? null, $visibility, $passHash]);
            $formId = (int) $pdo->lastInsertId();
            flash('success', 'Form created — copy the link shown above to share it.');
        } else {
            $pdo->prepare('UPDATE forms SET title = ?, slug = ?, description = ?, submit_label = ?, end_at = ?, is_active = ?, visibility = ?, password_hash = ? WHERE id = ?')
                ->execute([$title, $slug, $description, $submitLabelValue, $endAtValue, $isActive, $visibility, $passHash, $id]);
            $formId = $id;
            $pdo->prepare('DELETE FROM form_fields WHERE form_id = ?')->execute([$formId]);
            flash('success', 'Form updated.');
        }

        $insert = $pdo->prepare('INSERT INTO form_fields (form_id, label, field_type, placeholder, options, required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($fields as $f) {
            $insert->execute([$formId, $f['label'], $f['field_type'], $f['placeholder'] ?: null, $f['options'] ?: null, $f['required'], $f['sort_order']]);
        }
        redirect('/admin/forms?action=edit&id=' . $formId . ($action === 'create' ? '&created=1' : ''));
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $formId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'forms', $formId, $user)) {
        http_response_code(403);
        exit('This form belongs to another church.');
    }
    $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$formId]);
    flash('success', 'Form and its submissions deleted.');
    redirect('/admin/forms');
}

if ($action === 'delete_submission' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $formId = (int) ($_POST['form_id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'forms', $formId, $user)) {
        http_response_code(403);
        exit('This form belongs to another church.');
    }
    $pdo->prepare('DELETE FROM form_submissions WHERE id = ? AND form_id = ?')->execute([(int) ($_POST['sid'] ?? 0), $formId]);
    flash('success', 'Submission deleted.');
    redirect('/admin/forms?action=submissions&id=' . $formId);
}

if ($action === 'clear_submissions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $formId = (int) ($_POST['form_id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'forms', $formId, $user)) {
        http_response_code(403);
        exit('This form belongs to another church.');
    }
    $pdo->prepare('DELETE FROM form_submissions WHERE form_id = ?')->execute([$formId]);
    flash('success', 'All submissions cleared.');
    redirect('/admin/forms?action=submissions&id=' . $formId);
}

// Generate a server-hosted shareable CSV (Google-Forms style) for a form.
if ($action === 'generate_export' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $formId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'forms', $formId, $user)) {
        http_response_code(403);
        exit('This form belongs to another church.');
    }
    $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ?');
    $stmt->execute([$formId]);
    $form = $stmt->fetch();
    if (!$form) {
        redirect('/admin/forms');
    }
    $subFields = formFieldsFor($pdo, $formId);
    $stmt = $pdo->prepare('SELECT * FROM form_submissions WHERE form_id = ? ORDER BY created_at ASC');
    $stmt->execute([$formId]);
    [$headers, $rows] = formExportData($form, $subFields, $stmt->fetchAll());
    $saved = saveExportFile($pdo, 'form', 'Form - ' . $form['title'], $headers, $rows, $formId, (int) ($user['id'] ?? 0));
    flash('success', 'Shareable CSV created: ' . $saved['url']);
    redirect('/admin/forms?action=submissions&id=' . $formId . '#exports');
}

// Remove a shareable export (file + record).
if ($action === 'delete_export' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $formId = (int) ($_POST['form_id'] ?? 0);
    $exportId = (int) ($_POST['export_id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'forms', $formId, $user)) {
        http_response_code(403);
        exit('This form belongs to another church.');
    }
    $stmt = $pdo->prepare('SELECT * FROM export_files WHERE id = ? AND form_id = ?');
    $stmt->execute([$exportId, $formId]);
    $ef = $stmt->fetch();
    if ($ef) {
        @unlink(STORAGE_PATH . '/exports/' . basename((string) $ef['path']));
        $pdo->prepare('DELETE FROM export_files WHERE id = ?')->execute([$exportId]);
    }
    flash('success', 'Export link removed.');
    redirect('/admin/forms?action=submissions&id=' . $formId);
}

$editing = null;
$editFields = [];
$submissionCount = 0;
if ($action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing) {
        redirect('/admin/forms');
    }
    if (!Unit::inScope($user, (int) ($editing['org_unit_id'] ?? 0))) {
        redirect('/admin/forms');
    }
    $editFields = formFieldsFor($pdo, $id);
    $submissionCount = (int) $pdo->query('SELECT COUNT(*) FROM form_submissions WHERE form_id = ' . (int) $id)->fetchColumn();
}

$activeForm = null;
$submissions = [];
$exports = [];
if ($action === 'submissions') {
    $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ?');
    $stmt->execute([$id]);
    $activeForm = $stmt->fetch();
    if (!$activeForm) {
        redirect('/admin/forms');
    }
    if (!Unit::inScope($user, (int) ($activeForm['org_unit_id'] ?? 0))) {
        redirect('/admin/forms');
    }
    $stmt = $pdo->prepare('SELECT * FROM form_submissions WHERE form_id = ? ORDER BY created_at DESC LIMIT 500');
    $stmt->execute([$id]);
    $submissions = $stmt->fetchAll();
    $subFields = formFieldsFor($pdo, $id);
    $stmt = $pdo->prepare('SELECT * FROM export_files WHERE form_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$id]);
    $exports = $stmt->fetchAll();
}

if ($action === 'export' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ?');
    $stmt->execute([$id]);
    $form = $stmt->fetch();
    if (!$form) {
        redirect('/admin/forms');
    }
    if (!Unit::inScope($user, (int) ($form['org_unit_id'] ?? 0))) {
        redirect('/admin/forms');
    }
    $subFields = formFieldsFor($pdo, $id);
    $stmt = $pdo->prepare('SELECT * FROM form_submissions WHERE form_id = ? ORDER BY created_at ASC');
    $stmt->execute([$id]);
    [$headers, $rows] = formExportData($form, $subFields, $stmt->fetchAll());
    csvDownload('form-' . $form['slug'] . '-' . date('Y-m-d') . '.csv', $headers, $rows);
}

$forms = $action === 'list'
    ? $pdo->query('SELECT f.*, (SELECT COUNT(*) FROM form_submissions fs WHERE fs.form_id = f.id) AS submission_count FROM forms f WHERE 1=1' . $scopeSql . ' ORDER BY f.created_at DESC LIMIT 100')->fetchAll()
    : [];

$pageTitle = 'Forms';
$activeNav = 'forms';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <style>
    .form-field-row{border:1px solid var(--border); border-radius:12px; padding:14px; margin-bottom:12px; background:#ffffff04; position:relative; cursor:default;}
    .form-field-row.dragging{opacity:.55; border-color:var(--gold); border-style:dashed;}
    .form-field-row.drag-over-top{border-top:3px solid var(--gold);}
    .drag-grip{
      position:absolute; top:6px; right:10px; color:var(--ink-faint); font-size:16px; letter-spacing:2px;
      cursor:grab; user-select:none; padding:2px 6px; border-radius:6px; transition:color .2s;
    }
    .drag-grip:hover{color:var(--gold-soft);}
    .form-field-row .grid-field{display:grid; grid-template-columns:1fr 200px; gap:10px; margin-top:6px;}
    .form-field-row .mini{font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--ink-faint); font-weight:700; margin-bottom:4px;}
    .field-req{display:flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-dim); margin-top:8px;}
    .field-req input{width:16px; height:16px;}
    .row-actions{display:flex; justify-content:space-between; align-items:center; margin-top:10px;}
    .row-actions .btns{display:flex; gap:8px;}
    .copy-link{display:flex; gap:8px; align-items:center; margin-top:6px;}
    .copy-link input{flex:1;}
  </style>
  <?php if ($action === 'edit' && ($_GET['created'] ?? '') === '1' && $editing): ?>
  <div class="card" style="margin-bottom:18px;border-color:#5fe0a455;background:linear-gradient(135deg,#5fe0a40f,#5fe0a403);">
    <h2 style="margin:0 0 4px;">✅ Form created — share this link!</h2>
    <p class="sub" style="margin:0;">Copy your form link and share it. You can still edit the form below before sharing.</p>
    <div class="copy-link" style="margin-top:12px;">
      <span style="color:var(--ink-faint);font-weight:700;font-size:12px;flex-shrink:0;">FORM LINK</span>
      <input type="text" readonly value="<?= e(baseUrl('forms/' . $editing['slug'])) ?>">
      <button type="button" class="btn" data-copy="<?= e(baseUrl('forms/' . $editing['slug'])) ?>">Copy</button>
    </div>
    <?php if (($editing['visibility'] ?? 'public') === 'private'): ?>
      <p class="sub" style="margin:10px 0 0;color:var(--gold-soft);">🔒 This form is <strong>private</strong> — share the link <u>and</u> the password separately.</p>
    <?php else: ?>
      <p class="sub" style="margin:10px 0 0;">🌍 This form is <strong>public</strong> — anyone with the link can open it.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/forms">← Back to forms</a>
    <?php if ($action === 'edit'): ?>
      <a class="btn sm" href="<?= e('/forms/' . rawurlencode((string) $editing['slug'])) ?>" target="_blank">Open form ↗</a>
      <a class="btn secondary sm" href="/admin/forms?action=submissions&id=<?= (int) $editing['id'] ?>">View <?= $submissionCount ?> response<?= $submissionCount === 1 ? '' : 's' ?></a>
    <?php endif; ?>
  </div>

  <div class="card" style="max-width:760px;">
    <h2><?= $action === 'create' ? 'New Form' : 'Edit Form' ?></h2>
    <p class="sub">Build it like Google Forms — add fields, set a closing date, and share the public link.</p>

    <form method="post" action="/admin/forms?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>" id="formBuilder">
      <?= Csrf::field() ?>

      <label for="title">Form title</label>
      <input type="text" id="title" name="title" value="<?= e($editing['title'] ?? '') ?>" required>

      <label for="slug">Public link slug <span style="color:var(--ink-faint);font-weight:400;">(optional — auto-generated from title)</span></label>
      <div class="copy-link">
        <span style="color:var(--ink-faint);">/forms/</span>
        <input type="text" id="slug" name="slug" value="<?= e($editing['slug'] ?? '') ?>" placeholder="e.g. choir-registration">
      </div>

      <label for="description">Description / welcome message</label>
      <textarea id="description" name="description" rows="3" placeholder="Briefly explain what this form is for…"><?= e($editing['description'] ?? '') ?></textarea>

      <div class="row two">
        <div>
          <label for="submit_label">Submit button label</label>
          <input type="text" id="submit_label" name="submit_label" value="<?= e($editing['submit_label'] ?? 'Submit') ?>">
        </div>
        <div>
          <label for="end_at">Expiry date &amp; time <span style="color:var(--ink-faint);font-weight:400;">(blank = never expires)</span></label>
          <input type="datetime-local" id="end_at" name="end_at" value="<?= e($editing && $editing['end_at'] ? str_replace(' ', 'T', substr((string) $editing['end_at'], 0, 16)) : '') ?>">
        </div>
      </div>

      <div class="checkbox-row">
        <input type="checkbox" id="is_active" name="is_active" <?= $editing === null || !empty($editing['is_active']) ? 'checked' : '' ?>>
        <label for="is_active" style="margin:0;">Accepting responses</label>
      </div>

      <h2 style="margin-top:34px;">Access control</h2>
      <p class="sub">Public forms open for anyone with the link. Private forms also need a password, so only people with the link <strong>and</strong> the password can open them.</p>

      <div class="row two">
        <div>
          <label for="visibility">Who can access this form?</label>
          <select id="visibility" name="visibility">
            <option value="public" <?= ($editing['visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Public — anyone with the link</option>
            <option value="private" <?= ($editing['visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Private — link + password</option>
          </select>
        </div>
        <div id="passwordWrap" style="<?= ($editing['visibility'] ?? 'public') === 'private' ? '' : 'display:none;' ?>">
          <label for="access_password">Access password</label>
          <input type="text" id="access_password" name="access_password" value="" autocomplete="off" placeholder="<?= !empty($editing['password_hash']) ? 'Leave blank to keep the current password' : 'Set the access password' ?>">
          <div style="font-size:12px;color:var(--ink-faint);margin-top:-8px;"><?= !empty($editing['password_hash']) ? '✔ A password is currently set.' : 'No password set yet — required for private forms.' ?></div>
        </div>
      </div>

      <div id="accessNote" style="display:none;background:#e8b95f14;border:1px solid #e8b95f44;border-radius:12px;padding:12px 14px;font-size:13px;color:var(--gold-soft);margin-bottom:14px;line-height:1.5;">
        🔒 <strong>Private form.</strong> Share the link and the password <strong>separately</strong> (e.g. link in the group, password in a private message). Only people with both can open it.
      </div>

      <div style="background:#ffffff06;border:1px dashed var(--border);border-radius:12px;padding:12px 14px;font-size:12.5px;color:var(--ink-dim);line-height:1.7;margin-bottom:6px;">
        <strong style="color:var(--ink);">Quick guide — don't mix these up:</strong><br>
        • <strong>Public</strong> = anyone with the link can open &amp; fill it (registrations, contact forms, open polls).<br>
        • <strong>Private</strong> = link <u>+</u> password required — only people with both can open it. Share the two separately.<br>
        • <strong>Expiry</strong> = set a date &amp; time above to stop responses automatically; leave it blank to <em>never expire</em>.
      </div>

      <h2 style="margin-top:34px;">Form fields</h2>
      <p class="sub">Add the questions people will answer.</p>

      <div id="fieldsContainer"></div>

      <button type="button" class="btn secondary sm" id="addFieldBtn" style="margin-bottom:24px;">+ Add Field</button>

      <input type="hidden" name="fields_json" id="fieldsJson">

      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'create' ? 'Create Form' : 'Save Changes' ?></button>
        <a class="btn secondary" href="/admin/forms">Cancel</a>
      </div>
    </form>
  </div>

  <script>
    window.__FORM_FIELDS__ = <?= $editFields ? json_encode($editFields, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : '[]' ?>;
  </script>
  <script src="<?= asset('js/admin-forms.js') ?>"></script>

<?php elseif ($action === 'submissions'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/forms">← Back to forms</a>
    <a class="btn sm" href="<?= e('/forms/' . rawurlencode((string) $activeForm['slug'])) ?>" target="_blank">Open form ↗</a>
    <a class="btn secondary sm" href="/admin/forms?action=export&id=<?= (int) $activeForm['id'] ?>">Export CSV</a>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
      <div>
        <h2><?= e($activeForm['title']) ?> — Responses</h2>
        <p class="sub"><?= count($submissions) ?> submission<?= count($submissions) === 1 ? '' : 's' ?></p>
      </div>
      <?php if ($submissions): ?>
        <form method="post" action="/admin/forms?action=clear_submissions" onsubmit="return confirm('Delete ALL submissions for this form?');">
          <?= Csrf::field() ?><input type="hidden" name="form_id" value="<?= (int) $activeForm['id'] ?>">
          <button type="submit" class="btn danger sm">Clear all</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$submissions): ?>
      <div class="empty">No responses yet — share the form link to start collecting them.</div>
    <?php else: ?>
      <table>
        <tr><th>#</th><th>Submitted</th><th>IP</th><th>Answers</th><th></th></tr>
        <?php foreach ($submissions as $i => $sub): $data = json_decode((string) $sub['data'], true) ?: []; ?>
        <tr>
          <td><?= count($submissions) - $i ?></td>
          <td><?= e(date('M j, Y g:i A', strtotime($sub['created_at']))) ?></td>
          <td><?= e($sub['ip_address'] ?: '—') ?></td>
          <td style="max-width:420px;">
            <?php $preview = []; foreach ($subFields as $f) { $v = $data[(string) $f['id']] ?? ''; if ($f['field_type'] === 'image') { $v = is_array($v) ? $v : ($v === '' ? [] : [$v]); if ($v) { $thumbs = ''; foreach (array_slice($v, 0, 4) as $img) { $thumbs .= '<a href="' . e(uploadUrl($img)) . '" target="_blank" style="display:inline-block;"><img src="' . e(uploadUrl($img)) . '" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid var(--border);margin:2px;" loading="lazy"></a>'; } $preview[] = '<b>' . e($f['label']) . ':</b><br>' . $thumbs; } } else { if (is_array($v)) { $v = implode(', ', $v); } if ($v !== '' && $v !== null) { $preview[] = '<b>' . e($f['label']) . ':</b> ' . e(mb_strimwidth((string) $v, 0, 60, '…')); } } } ?>
            <?= $preview ? implode('<br>', array_slice($preview, 0, 3)) : '<span style="color:var(--ink-faint);">(empty)</span>' ?>
          </td>
          <td>
            <form method="post" action="/admin/forms?action=delete_submission" onsubmit="return confirm('Delete this submission?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="form_id" value="<?= (int) $activeForm['id'] ?>"><input type="hidden" name="sid" value="<?= (int) $sub['id'] ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" id="exports" style="margin-top:18px;">
    <h2>Shareable CSV</h2>
    <p class="sub">The responses CSV is saved on the server and exposed as a link. Anyone with the link can view or download it — share it the way you'd share a Google Forms response sheet.</p>
    <form method="post" action="/admin/forms?action=generate_export" style="margin-bottom:16px;">
      <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $activeForm['id'] ?>">
      <button type="submit" class="btn">+ Generate shareable CSV</button>
    </form>
    <?php if (!$exports): ?>
      <div class="empty">No shareable exports yet — click the button above to create one.</div>
    <?php else: ?>
      <table>
        <tr><th>Created</th><th>Link</th><th>Downloads</th><th></th></tr>
        <?php foreach ($exports as $ef): ?>
        <tr>
          <td><?= e(date('M j, Y g:i A', strtotime((string) $ef['created_at']))) ?></td>
          <td>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
              <a href="<?= e(exportUrl((string) $ef['token'])) ?>" target="_blank" rel="noopener" style="color:var(--gold-soft);font-size:12.5px;word-break:break-all;"><?= e(exportUrl((string) $ef['token'])) ?></a>
              <button type="button" class="btn secondary sm" data-copy="<?= e(exportUrl((string) $ef['token'])) ?>">Copy</button>
            </div>
          </td>
          <td><?= (int) $ef['downloads'] ?></td>
          <td>
            <form method="post" action="/admin/forms?action=delete_export" onsubmit="return confirm('Remove this export link and its file?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="form_id" value="<?= (int) $activeForm['id'] ?>"><input type="hidden" name="export_id" value="<?= (int) $ef['id'] ?>">
              <button type="submit" class="btn danger sm">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <script src="<?= asset('js/admin-forms.js') ?>"></script>

<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn" href="/admin/forms?action=create">+ New Form</a>
  </div>

  <div class="card">
    <?php if (!$forms): ?>
      <div class="empty">No forms yet — create one to get a shareable public link.</div>
    <?php else: ?>
      <table>
        <tr><th>Form</th><th>Church</th><th>Public Link</th><th>Responses</th><th>Expires</th><th>Status</th><th></th></tr>
        <?php foreach ($forms as $f): $expired = formsExpired($f); $fieldCount = count(formFieldsFor($pdo, (int) $f['id'])); ?>
        <tr>
          <td>
            <strong><?= e($f['title']) ?></strong>
            <?php if (($f['visibility'] ?? 'public') === 'private'): ?><span class="badge" title="Private — password required">🔒 Private</span><?php else: ?><span class="badge ok">Public</span><?php endif; ?>
            <div style="color:var(--ink-faint);font-size:12px;"><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?> · created <?= e(date('M j, Y', strtotime($f['created_at']))) ?></div>
          </td>
          <td>
            <?php if (!empty($f['org_unit_id'])): ?>
              <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $f['org_unit_id']] ?? '') ?></span>
            <?php else: ?>
              <span class="badge warn">Unassigned</span>
            <?php endif; ?>
            <div style="margin-top:6px;">
              <?php $reassignId = (int) $f['id']; $reassignUnitId = !empty($f['org_unit_id']) ? (int) $f['org_unit_id'] : null; $showUnassignedOnly = false; $assignAction = '/admin/forms?action=reassign'; require __DIR__ . '/partials/unit-assign.php'; ?>
            </div>
          </td>
          <td>
            <div style="display:flex;gap:6px;align-items:center;">
              <a href="<?= e('/forms/' . rawurlencode((string) $f['slug'])) ?>" target="_blank" style="color:var(--gold-soft);font-size:13px;">/forms/<?= e($f['slug']) ?></a>
              <button type="button" class="btn secondary sm" data-copy="<?= e(baseUrl('forms/' . $f['slug'])) ?>">Copy</button>
            </div>
          </td>
          <td><?= (int) $f['submission_count'] ?></td>
          <td><?= $f['end_at'] ? e(date('M j, Y', strtotime((string) $f['end_at']))) : '<span style="color:var(--ink-faint);">Never</span>' ?></td>
          <td>
            <?php if (!$f['is_active']): ?><span class="badge">closed</span>
            <?php elseif ($expired): ?><span class="badge fail">expired</span>
            <?php else: ?><span class="badge ok">open</span><?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <a class="btn secondary sm" href="/admin/forms?action=submissions&id=<?= (int) $f['id'] ?>">Responses</a>
            <a class="btn secondary sm" href="/admin/forms?action=submissions&id=<?= (int) $f['id'] ?>#exports">CSV link</a>
            <a class="btn secondary sm" href="/admin/forms?action=edit&id=<?= (int) $f['id'] ?>">Edit</a>
            <form method="post" action="/admin/forms?action=delete" onsubmit="return confirm('Delete this form and ALL its submissions?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <script src="<?= asset('js/admin-forms.js') ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
