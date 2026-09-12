<?php
declare(strict_types=1);

/**
 * SMS → Templates. Reusable message bodies, with the placeholders the composer supports.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$tenantId = Tenant::id();
$errors = [];

/** Loads a template, refusing anything outside the admin's reach. */
$loadTemplate = static function (int $id) use ($pdo, $isSuper, $scopeUnitIds): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM sms_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($isSuper || $scopeUnitIds === []) {
        return $row;
    }
    $unitId = (int) ($row['org_unit_id'] ?? 0);
    return $unitId === 0 || in_array($unitId, array_map('intval', $scopeUnitIds), true) ? $row : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'delete') {
        $row = $loadTemplate((int) ($_POST['id'] ?? 0));
        if ($row === null) {
            flash('error', 'That template could not be found.');
        } else {
            $pdo->prepare('DELETE FROM sms_templates WHERE id = ?')->execute([(int) $row['id']]);
            flash('success', 'Deleted the template “' . $row['name'] . '”.');
        }
        redirect('/admin/sms?tab=templates');
    }

    if ($do === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $unitId = $isSuper ? (int) ($_POST['org_unit_id'] ?? 0) : (int) $smsContext['my_unit_id'];

        if ($name === '') {
            $errors[] = 'Give the template a name.';
        }
        if ($body === '') {
            $errors[] = 'The template needs a message body.';
        }
        if (mb_strlen($body) > 640) {
            $errors[] = 'That template is ' . mb_strlen($body) . ' characters. Keep it under 640 so it stays a sensible number of segments.';
        }

        if ($errors === []) {
            $existing = $id > 0 ? $loadTemplate($id) : null;
            if ($id > 0 && $existing === null) {
                $errors[] = 'That template could not be found.';
            } else {
                if ($existing !== null) {
                    $pdo->prepare('UPDATE sms_templates SET name = ?, body = ?, org_unit_id = ? WHERE id = ?')
                        ->execute([mb_substr($name, 0, 150), $body, $unitId > 0 ? $unitId : null, $id]);
                    flash('success', 'Template updated.');
                } else {
                    $pdo->prepare('INSERT INTO sms_templates (tenant_id, name, body, org_unit_id, created_by) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$tenantId, mb_substr($name, 0, 150), $body, $unitId > 0 ? $unitId : null, (int) ($smsContext['user']['id'] ?? 0)]);
                    flash('success', 'Template saved.');
                }
                redirect('/admin/sms?tab=templates');
            }
        }
    }
}

$editing = null;
$editId = (int) ($_GET['id'] ?? 0);
if ($editId > 0) {
    $editing = $loadTemplate($editId);
    if ($editing === null) {
        $errors[] = 'That template could not be found.';
    }
}

$templates = [];
try {
    $clauses = [$tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?'];
    $params = $tenantId === null ? [] : [$tenantId];
    if (!$isSuper && $scopeUnitIds !== []) {
        $ids = array_map('intval', $scopeUnitIds);
        $clauses[] = '(org_unit_id IS NULL OR org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
        foreach ($ids as $id) {
            $params[] = $id;
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM sms_templates WHERE ' . implode(' AND ', $clauses) . ' ORDER BY name ASC');
    $stmt->execute($params);
    $templates = $stmt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'The templates table is not available yet.';
}

$assignableUnits = Unit::assignableScope($smsContext['user']);
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2><?= $editing ? 'Edit template' : 'New template' ?></h2>
  <p class="sub">
    Templates are just saved wording — you choose the recipients in the composer. These placeholders
    are filled in per person:
    <?php foreach (Sms::placeholders() as $token => $meaning): ?>
      <code><?= e($token) ?></code> <small style="color:var(--ink-faint);">(<?= e($meaning) ?>)</small><?= array_key_last(Sms::placeholders()) === $token ? '' : ' · ' ?>
    <?php endforeach; ?>
  </p>
  <p class="sub" style="font-size:12px;">
    A placeholder that cannot be filled is left exactly as typed, so a broken template is obvious
    rather than silently sending “Hi , welcome”.
  </p>

  <form method="post" action="/admin/sms?tab=templates">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : 0 ?>">

    <div class="row two">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" maxlength="150" required
               value="<?= e((string) ($editing['name'] ?? formOld('name'))) ?>" placeholder="e.g. Sunday service reminder">
      </div>
      <div>
        <label for="org_unit_id">Church</label>
        <?php if ($isSuper): ?>
          <select id="org_unit_id" name="org_unit_id">
            <option value="">— shared with every church —</option>
            <?php foreach ($assignableUnits as $unitId => $label): ?>
              <option value="<?= (int) $unitId ?>" <?= (int) ($editing['org_unit_id'] ?? 0) === (int) $unitId ? 'selected' : '' ?>><?= e((string) $label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" value="<?= e($smsContext['unit_labels'][$smsContext['my_unit_id']] ?? 'Your church') ?>" disabled>
        <?php endif; ?>
      </div>
    </div>

    <label for="body">Message</label>
    <textarea id="body" name="body" rows="4" maxlength="640" required
              placeholder="Hi {first_name}, don't forget Sunday service at 9am."><?= e((string) ($editing['body'] ?? formOld('body'))) ?></textarea>

    <div class="btn-row">
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Save template' ?></button>
      <?php if ($editing): ?>
        <a class="btn secondary" href="/admin/sms?tab=templates">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>Saved templates</h2>
  <?php if (!$templates): ?>
    <div class="empty">No templates yet. They are optional — you can always type a message from scratch.</div>
  <?php else: ?>
    <table class="resp-table">
      <tr><th>Name</th><th>Message</th><th>Segments</th><th>Church</th><th></th></tr>
      <?php foreach ($templates as $template): ?>
        <?php $segments = Sms::segmentsFor((string) $template['body']); ?>
        <tr>
          <td><strong><?= e((string) $template['name']) ?></strong></td>
          <td style="max-width:380px;"><small style="color:var(--ink-dim);"><?= e(mb_strimwidth((string) $template['body'], 0, 110, '…')) ?></small></td>
          <td>
            <span class="badge <?= $segments > 1 ? 'warn' : 'info' ?>"><?= $segments ?> unit<?= $segments === 1 ? '' : 's' ?></span>
          </td>
          <td>
            <?php if (!empty($template['org_unit_id'])): ?>
              <small style="color:var(--gold-soft);"><?= e($smsContext['unit_labels'][(int) $template['org_unit_id']] ?? '') ?></small>
            <?php else: ?>
              <small style="color:var(--ink-faint);">Shared</small>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <a class="btn secondary sm" href="/admin/sms?tab=compose&amp;template=<?= (int) $template['id'] ?>">Use</a>
            <a class="btn secondary sm" href="/admin/sms?tab=templates&amp;id=<?= (int) $template['id'] ?>">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this template?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $template['id'] ?>">
              <button class="btn danger sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
