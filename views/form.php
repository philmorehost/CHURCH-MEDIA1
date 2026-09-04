<?php
declare(strict_types=1);

$stmt = Database::getInstance()->getConnection()->prepare('SELECT * FROM forms WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$form = $stmt->fetch();

if (!$form) {
    http_response_code(404);
    render('404', [], true);
    return;
}

$stmt = Database::getInstance()->getConnection()->prepare('SELECT * FROM form_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$form['id']]);
$fields = $stmt->fetchAll();

$sent = flash('form_sent') !== null || ($_GET['sent'] ?? '') === '1';
$error = flash('form_error');
$accepting = formsAccepting($form);
$unlocked = formUnlocked($form);
$initial = mb_substr((string) ($form['title']), 0, 1);
$fieldIndex = 0;

$metaTitle = $form['title'];
$metaDescription = $form['description'] ? mb_strimwidth((string) $form['description'], 0, 160, '…') : 'Please fill out this form.';
$metaRobots = 'noindex, nofollow';
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card">
    <?php if ($sent): ?>
      <div class="form-success">
        <div class="form-check">
          <svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none"/><path fill="none" d="M14 27l8 8 16-16"/></svg>
        </div>
        <h1 class="form-title center">Thanks for submitting!</h1>
        <p class="form-desc center">Your response has been recorded. God bless you.</p>
        <a class="form-submit ghost" href="/forms/<?= e(rawurlencode((string) $form['slug'])) ?>">Submit another response</a>
      </div>
    <?php elseif (!$accepting): ?>
      <div class="form-closed">
        <div class="form-check closed">
          <svg viewBox="0 0 52 52">
            <circle cx="26" cy="26" r="24" fill="none"/>
            <path class="x1" fill="none" d="M17 17l18 18"/>
            <path class="x2" fill="none" d="M35 17L17 35"/>
          </svg>
        </div>
        <span class="eyebrow">Response Window Closed</span>
        <h1 class="form-title center">This form has closed</h1>
        <?php if (formsExpired($form)): ?>
          <p class="form-desc center">It stopped accepting responses on <strong><?= e(date('F j, Y \a\t g:i A', strtotime((string) $form['end_at']))) ?></strong>.</p>
        <?php else: ?>
          <p class="form-desc center">This form is no longer accepting responses.</p>
        <?php endif; ?>
        <p class="form-desc center" style="margin-top:2px;">Thank you for your interest — if you'd like to reach us, we'd love to hear from you.</p>
        <div class="form-closed-actions">
          <a class="form-submit" href="/contact">Contact Us</a>
          <a class="form-submit ghost" href="/">Back to Homepage</a>
        </div>
      </div>
    <?php elseif (!$unlocked): ?>
      <div class="form-banner">
        <div class="form-mark"><?= e($initial) ?></div>
        <div>
          <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
          <h1 class="form-title"><?= e($form['title']) ?></h1>
        </div>
      </div>
      <div class="form-lock">
        <div class="form-lock-icon">
          <svg viewBox="0 0 24 24" width="30" height="30"><path fill="currentColor" d="M6 8h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2Zm1-2a5 5 0 0 1 10 0v2h-2V6a3 3 0 0 0-6 0v2H7V6Zm4 6v3h2v-3h-2Z"/></svg>
        </div>
        <h1 class="form-title center">This form is private</h1>
        <p class="form-desc center">Only people with the link <strong>and</strong> the password can open it. Ask the church admin for the password.</p>
        <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e('/forms/' . rawurlencode((string) $form['slug']) . '/unlock') ?>" class="form-lock-form">
          <label class="form-label" for="form_password">Enter the form password</label>
          <input type="password" id="form_password" name="password" autocomplete="off" required placeholder="Form password">
          <button type="submit" class="form-submit"><span>Unlock form</span></button>
        </form>
      </div>
    <?php else: ?>
      <div class="form-banner">
        <div class="form-mark"><?= e($initial) ?></div>
        <div>
          <div class="form-eyebrow"><?= e(setting('site_title')) ?></div>
          <h1 class="form-title"><?= e($form['title']) ?></h1>
        </div>
      </div>

      <?php if (!empty($form['description'])): ?>
        <div class="form-desc"><?= nl2br(e((string) $form['description'])) ?></div>
      <?php endif; ?>

      <div class="form-meta">
        <?php if (!empty($form['end_at'])): ?>
          <span class="form-deadline"><span class="dl-icon">⏱</span> Accepts responses until <?= e(date('F j, Y, g:i A', strtotime((string) $form['end_at']))) ?></span>
        <?php endif; ?>
        <span class="form-hint">* Required</span>
      </div>

      <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

      <form method="post" action="<?= e('/forms/' . rawurlencode((string) $form['slug'])) ?>" enctype="multipart/form-data" novalidate>
        <input type="text" name="website" value="" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

        <?php foreach ($fields as $field):
            $key = 'field_' . $field['id'];
            $required = !empty($field['required']);
            $fieldIndex++;
        ?>
        <div class="form-field" data-type="<?= e($field['field_type']) ?>">
          <label class="form-label" for="<?= e($key) ?>">
            <span class="field-num"><?= $fieldIndex ?></span>
            <span><?= e($field['label']) ?><?= $required ? ' <span class="req">*</span>' : '' ?></span>
          </label>

          <?php if ($field['field_type'] === 'textarea'): ?>
            <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" rows="4" placeholder="<?= e($field['placeholder']) ?>" <?= $required ? 'required' : '' ?>><?= e((string) formOld($key)) ?></textarea>

          <?php elseif ($field['field_type'] === 'image'): ?>
            <label class="form-upload" for="<?= e($key) ?>">
              <input type="file" id="<?= e($key) ?>" name="<?= e($key) ?>[]" accept="image/*" <?= $required ? 'required' : '' ?> multiple>
              <span class="upload-icon">
                <svg viewBox="0 0 24 24" width="26" height="26"><path fill="currentColor" d="M12 16a1 1 0 0 1-1-1V7.4L8.7 9.7a1 1 0 0 1-1.4-1.4l4-4a1 1 0 0 1 1.4 0l4 4a1 1 0 0 1-1.4 1.4L13 7.4V15a1 1 0 0 1-1 1Zm-6 4h12a1 1 0 0 0 0-2H6a1 1 0 0 0 0 2Z"/></svg>
              </span>
              <span class="upload-title">Upload image<?= $field['required'] ? '' : 's' ?></span>
              <span class="upload-sub">JPG · PNG · GIF · WebP · BMP · AVIF — auto-compressed</span>
              <span class="upload-files" data-upload-count>No files chosen</span>
            </label>

          <?php elseif ($field['field_type'] === 'select'): ?>
            <select id="<?= e($key) ?>" name="<?= e($key) ?>" <?= $required ? 'required' : '' ?>>
              <option value="" <?= formOld($key) === '' ? 'selected' : '' ?>><?= e($field['placeholder'] ?: 'Choose an option…') ?></option>
              <?php foreach (formFieldOptions($field) as $opt): ?>
                <option value="<?= e($opt) ?>" <?= (string) formOld($key) === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
              <?php endforeach; ?>
            </select>

          <?php elseif ($field['field_type'] === 'radio' || $field['field_type'] === 'checkbox'):
              $old = formOld($key);
              $oldArr = is_array($old) ? $old : ($old === '' ? [] : [$old]);
          ?>
            <div class="form-options">
              <?php foreach (formFieldOptions($field) as $opt): ?>
                <label class="form-option">
                  <input type="<?= $field['field_type'] === 'radio' ? 'radio' : 'checkbox' ?>"
                         name="<?= e($key) ?><?= $field['field_type'] === 'checkbox' ? '[]' : '' ?>"
                         value="<?= e($opt) ?>"
                         <?= in_array($opt, $oldArr, true) ? 'checked' : '' ?>>
                  <span class="option-box"><svg viewBox="0 0 12 12"><path fill="none" stroke="currentColor" stroke-width="2" d="M2 6l3 3 5-6"/></svg></span>
                  <span><?= e($opt) ?></span>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($field['field_type'] === 'cascade' || $field['field_type'] === 'church'):
              // Cascading dropdowns (Province > Zone > Area > Parish). The
              // 'church' type builds the same chained selects live from the
              // org_units hierarchy so it always matches the current church list.
              $cascadePaths = $field['field_type'] === 'church'
                  ? array_map(fn (string $p): array => array_map('trim', explode(' > ', $p)), churchCascadePaths())
                  : formCascadeOptions($field);
              $oldPath = (string) formOld($key);
          ?>
            <div class="form-cascade"
                 data-cascade='<?= e(json_encode(array_values($cascadePaths), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                 data-old="<?= e($oldPath) ?>">
              <div class="cascade-selects"></div>
              <div class="cascade-note"><?= $field['field_type'] === 'church' ? 'Auto-filled from the church list — select your parish (Province → Zone → Area → Parish).' : 'Choose from the dropdowns — each one is filtered by the one before it.' ?></div>
              <input type="hidden" name="<?= e($key) ?>" value="<?= e($oldPath) ?>">
            </div>

          <?php else:
              $typeAttr = match ($field['field_type']) {
                  'email', 'number', 'date', 'url' => $field['field_type'],
                  'time' => 'time',
                  'datetime' => 'datetime-local',
                  default => 'text',
              };
          ?>
            <input type="<?= e($typeAttr) ?>"
                   id="<?= e($key) ?>"
                   name="<?= e($key) ?>"
                   value="<?= e((string) formOld($key)) ?>"
                   placeholder="<?= e($field['placeholder']) ?>"
                   <?= $required ? 'required' : '' ?>>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="form-submit">
          <span><?= e($form['submit_label'] ?: 'Submit') ?></span>
          <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M5 12h13m-5-5 5 5-5 5"/></svg>
        </button>
      </form>
    <?php endif; ?>
  </div>
</section>

<script src="<?= asset('js/form.js') ?>"></script>
<?php clearFormOld(); ?>
