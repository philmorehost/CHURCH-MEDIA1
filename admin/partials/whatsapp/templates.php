<?php
declare(strict_types=1);

/**
 * WhatsApp → Templates.
 *
 * A message template has to be approved by Meta before it can be sent, and it is sent by *name
 * and language* — the wording lives at Meta, not here. So this screen is a local register of what
 * has been approved, kept so the composer can offer a sensible list and so the conversation view
 * can show what a template said.
 *
 * It deliberately does not submit anything to Meta. Submission happens in the Meta dashboard, and
 * pretending otherwise from here would mean a button that sometimes works and cannot explain why
 * it did not. The status is recorded by hand, and the screen says so.
 */

$user = $waContext['user'];
$isSuper = (bool) $waContext['is_super'];
$userId = (int) $user['id'];

$STATUSES = ['draft', 'pending', 'approved', 'rejected', 'paused', 'disabled'];
$CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

/** Meta's rules: lowercase letters, digits and underscores, starting with a letter. */
function waTemplateNameProblem(string $name): ?string
{
    if ($name === '') {
        return 'Give the template a name.';
    }
    if (strlen($name) > 120) {
        return 'That name is too long — Meta allows 120 characters.';
    }
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        return 'Meta only accepts lowercase letters, digits and underscores, and the name must start with a letter.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        // Deliberately NOT lowercased. The name is an identifier that has to match Meta's exactly,
        // so quietly rewriting what was typed would hide the mismatch until a send failed with
        // "template does not exist". The validator below rejects it and says why instead.
        $name = trim((string) ($_POST['name'] ?? ''));
        $language = trim((string) ($_POST['language'] ?? '')) ?: WhatsApp::defaultLanguage();
        $category = strtoupper(trim((string) ($_POST['category'] ?? '')));
        $body = trim((string) ($_POST['body_text'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'draft');

        $errors = [];
        if (($problem = waTemplateNameProblem($name)) !== null) {
            $errors[] = $problem;
        }
        if (!in_array($category, $CATEGORIES, true)) {
            $errors[] = 'Choose a category.';
        }
        if ($status !== '' && !in_array($status, $STATUSES, true)) {
            $errors[] = 'That status is not recognised.';
        }
        if ($id > 0 && !$isSuper) {
            $errors[] = 'Only a super admin can change a template.';
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/admin/whatsapp?tab=templates');
        }

        try {
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE wa_templates SET name = ?, language = ?, category = ?, body_text = ?, status = ? WHERE id = ?'
                )->execute([$name, $language, $category, $body !== '' ? $body : null, $status, $id]);
                flash('success', 'Template updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO wa_templates (tenant_id, name, language, category, body_text, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    class_exists('Tenant') ? Tenant::id() : null,
                    $name,
                    $language,
                    $category,
                    $body !== '' ? $body : null,
                    $status,
                    $userId,
                ]);
                flash('success', 'Template recorded. Submit it to Meta for approval before it can be sent.');
            }
        } catch (Throwable $e) {
            // The unique key is (tenant, name, language).
            flash('error', 'A template with that name and language is already recorded.');
        }
        redirect('/admin/whatsapp?tab=templates');
    }

    if ($action === 'delete') {
        if (!$isSuper) {
            flash('error', 'Only a super admin can delete a template.');
            redirect('/admin/whatsapp?tab=templates');
        }
        $id = (int) ($_POST['id'] ?? 0);
        // Only a local record is removed. The approved template still exists at Meta, which is
        // why the wording says "forget" rather than "delete" on the button.
        $pdo->prepare('DELETE FROM wa_templates WHERE id = ?')->execute([$id]);
        flash('success', 'Removed from this list. The template at Meta is unchanged.');
        redirect('/admin/whatsapp?tab=templates');
    }
}

$editing = null;
if (($editId = (int) ($_GET['edit'] ?? 0)) > 0) {
    $stmt = $pdo->prepare('SELECT * FROM wa_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$templates = $pdo->query(
    'SELECT * FROM wa_templates ORDER BY FIELD(status, "approved", "pending", "draft", "paused", "rejected", "disabled"), name ASC'
)->fetchAll();
?>

<div class="card" style="max-width:760px;">
  <h2 style="margin-top:0;"><?= $editing ? 'Edit template' : 'Add a template' ?></h2>

  <?php if (!$isSuper): ?>
    <div class="alert error">
      Only a super admin can add or change templates. You can see them here because the composer
      uses them.
    </div>
  <?php else: ?>
    <p style="color:var(--ink-dim);font-size:13.5px;margin-top:-4px;">
      Record a template here so it can be offered when a reply window has closed. Creating it in
      Meta's dashboard is what actually gets it approved — this screen keeps the two in step.
    </p>

    <form method="post" action="/admin/whatsapp?tab=templates">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <?php endif; ?>

      <div class="row two">
        <div>
          <label for="name">Template name</label>
          <input type="text" id="name" name="name" required maxlength="120"
                 value="<?= e((string) ($editing['name'] ?? '')) ?>" placeholder="service_invitation">
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">
            Lowercase, underscores, starting with a letter. It must match Meta's name exactly.
          </p>
        </div>
        <div>
          <label for="language">Language</label>
          <input type="text" id="language" name="language" maxlength="12"
                 value="<?= e((string) ($editing['language'] ?? WhatsApp::defaultLanguage())) ?>" placeholder="en">
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">e.g. <code>en</code></p>
        </div>
      </div>

      <div class="row two">
        <div>
          <label for="category">Category</label>
          <select id="category" name="category">
            <?php foreach ($CATEGORIES as $cat): ?>
              <option value="<?= e($cat) ?>" <?= (string) ($editing['category'] ?? '') === $cat ? 'selected' : '' ?>>
                <?= e($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">
            UTILITY for reminders and confirmations, MARKETING for invitations and announcements.
          </p>
        </div>
        <div>
          <label for="status">Approval status</label>
          <select id="status" name="status">
            <?php foreach ($STATUSES as $st): ?>
              <option value="<?= e($st) ?>" <?= (string) ($editing['status'] ?? 'draft') === $st ? 'selected' : '' ?>>
                <?= e(ucfirst($st)) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">
            Only an approved template can be sent.
          </p>
        </div>
      </div>

      <label for="body_text">Body</label>
      <textarea id="body_text" name="body_text" rows="4"
                placeholder="Hello {{1}}, our service this Sunday starts at {{2}}."><?= e((string) ($editing['body_text'] ?? '')) ?></textarea>
      <p class="hint" style="margin-top:-6px;font-size:12px;color:var(--ink-dim);">
        Copy the wording exactly as approved at Meta. Use <code>{{1}}</code>, <code>{{2}}</code> for
        the values filled in at send time. This copy is used for previews and for what the
        conversation shows afterwards — it is never sent.
      </p>

      <div class="btn-row">
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add template' ?></button>
        <?php if ($editing): ?>
          <a class="btn secondary" href="/admin/whatsapp?tab=templates">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;">Recorded templates</h2>
  <?php if (!$templates): ?>
    <div class="empty-state">No templates recorded yet.</div>
  <?php else: ?>
    <table>
      <tr><th>Name</th><th>Language</th><th>Category</th><th>Status</th><th>Body</th><th></th></tr>
      <?php foreach ($templates as $t): ?>
        <?php
          // An array rather than a `match`: the codebase supports PHP before 8, where `match` is
          // not a keyword and the file will not parse.
          $badges = ['approved' => 'ok', 'rejected' => 'fail', 'disabled' => 'fail'];
          $badge = $badges[(string) $t['status']] ?? 'warn';
        ?>
        <tr>
          <td><code><?= e((string) $t['name']) ?></code></td>
          <td><?= e((string) $t['language']) ?></td>
          <td style="font-size:12.5px;color:var(--ink-dim);"><?= e((string) ($t['category'] ?? '—')) ?></td>
          <td><span class="badge <?= $badge ?>"><?= e((string) $t['status']) ?></span></td>
          <td style="max-width:320px;">
            <span style="font-size:12.5px;color:var(--ink-dim);">
              <?= e(mb_strimwidth((string) ($t['body_text'] ?? ''), 0, 90, '…')) ?>
            </span>
          </td>
          <td style="white-space:nowrap;">
            <?php if ($isSuper): ?>
              <a class="btn secondary sm" href="/admin/whatsapp?tab=templates&edit=<?= (int) $t['id'] ?>">Edit</a>
              <form method="post" action="/admin/whatsapp?tab=templates" style="display:inline;"
                    onsubmit="return confirm('Remove this template from the list? The approved template at Meta is not affected.');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn danger sm" type="submit">Forget</button>
              </form>
            <?php else: ?>
              <span style="font-size:12px;color:var(--ink-dim);">super admin only</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
