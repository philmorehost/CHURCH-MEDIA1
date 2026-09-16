<?php
declare(strict_types=1);

/**
 * Home cells — the midweek gatherings behind the public finder.
 *
 * The list is every leaf unit with what is known about its meeting. Editing one at a time keeps the
 * form honest: a grid of inputs that saves on every keystroke is how a half-typed address ends up
 * published, and a leader's phone number is the kind of thing that must not be published by
 * accident.
 *
 * Access is admin-only, matching the sidebar entry, because these fields carry a person's name and
 * number. A scoped admin only ever sees and edits cells inside their own part of the hierarchy —
 * `Unit::inScope` is checked on both the list and the save, so a guessed id cannot reach past it.
 */

Auth::requireRole('admin');
$user = Auth::user();
$action = (string) ($_GET['action'] ?? 'list');
$id = (int) ($_GET['id'] ?? 0);
$errors = array();

$leafLabel = Unit::labelFor(Unit::leafType());
$leafPlural = Unit::pluralFor(Unit::leafType());

/* ======================================================================== save == */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $target = HomeCell::find($id);
    if ($target === null || !Unit::inScope($user, $id)) {
        flash('error', 'That ' . strtolower($leafLabel) . ' is not one you can edit.');
        redirect('/admin/home-cells');
    }

    $result = HomeCell::save($id, $_POST);
    if (empty($result['ok'])) {
        $errors = $result['errors'] ?? array('Could not save those details.');
    } else {
        $listed = HomeCell::isListed(HomeCell::find($id) ?? array());
        flash('success', $target['name'] . ' saved. ' . ($listed
            ? 'It is showing in the public finder.'
            : 'It is not in the public finder yet — add a meeting day to list it.'));
        redirect('/admin/home-cells');
    }
}

/* =================================================================== load view == */
$editing = null;
if ($action === 'edit') {
    $editing = HomeCell::find($id);
    if ($editing === null || !Unit::inScope($user, $id)) {
        flash('error', 'That ' . strtolower($leafLabel) . ' is not one you can edit.');
        redirect('/admin/home-cells');
    }
}

$query = trim((string) ($_GET['q'] ?? ''));
$cells = array_values(array_filter(
    HomeCell::all(),
    static fn (array $c): bool => Unit::inScope($user, (int) $c['id'])
));

if ($query !== '') {
    $needle = mb_strtolower($query);
    $cells = array_values(array_filter($cells, static function (array $c) use ($needle): bool {
        $haystack = mb_strtolower(implode(' ', [
            (string) $c['name'],
            (string) $c['path_label'],
            (string) ($c['leader_name'] ?? ''),
        ]));
        return mb_strpos($haystack, $needle) !== false;
    }));
}

$listedCount = count(array_filter($cells, static fn (array $c): bool => HomeCell::isListed($c)));

$pageTitle = 'Home Cells';
$activeNav = 'home-cells';
require __DIR__ . '/partials/layout-open.php';
?>

<?php if ($errors): ?>
  <div class="alert error">
    <?php foreach ($errors as $error): ?>
      <div><?= e($error) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($editing !== null): ?>
  <?php
  $posted = static fn (string $key, $fallback = '') => (string) ($_POST[$key] ?? ($editing[$key] ?? $fallback));
  $ticked = static function (string $key) use ($editing): bool {
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          return !empty($_POST[$key]);
      }
      return !empty($editing[$key]);
  };
  $missing = HomeCell::missing($editing);
  ?>
  <h2>Home cell details — <?= e($editing['name']) ?></h2>
  <p class="sub"><?= e($editing['path_label']) ?></p>

  <?php if ($missing): ?>
    <div class="alert info">
      Still needed before this appears in the public finder: <strong><?= e(implode(', ', $missing)) ?></strong>.
      Nothing is lost by saving without them.
    </div>
  <?php endif; ?>

  <form method="post" action="/admin/home-cells?action=edit&id=<?= (int) $id ?>">
    <?= Csrf::field() ?>
    <div class="row">
      <div>
        <label for="meeting_day">Meeting day</label>
        <select id="meeting_day" name="meeting_day">
          <option value="">— does not meet as a home cell —</option>
          <?php foreach (HomeCell::DAYS as $day): ?>
            <option value="<?= e($day) ?>" <?= $posted('meeting_day') === $day ? 'selected' : '' ?>><?= e($day) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="meeting_time">Meeting time</label>
        <input type="text" id="meeting_time" name="meeting_time" maxlength="<?= HomeCell::MAX_TIME ?>"
               value="<?= e($posted('meeting_time')) ?>" placeholder="6:30 PM">
        <p class="hint" style="margin:6px 0 0;font-size:12.5px;color:var(--muted,#8b87a8);">
          Typed as people say it. There is no need to convert to 24-hour time.
        </p>
      </div>
    </div>

    <label for="meeting_address">Meeting address</label>
    <input type="text" id="meeting_address" name="meeting_address" maxlength="<?= HomeCell::MAX_ADDRESS ?>"
           value="<?= e($posted('meeting_address')) ?>" placeholder="12 Example Close, Off Example Road">

    <div class="row">
      <div>
        <label for="leader_name">Cell leader</label>
        <input type="text" id="leader_name" name="leader_name" maxlength="<?= HomeCell::MAX_LEADER ?>"
               value="<?= e($posted('leader_name')) ?>">
      </div>
      <div>
        <label for="leader_phone">Leader's contact number</label>
        <input type="text" id="leader_phone" name="leader_phone" maxlength="<?= HomeCell::MAX_PHONE ?>"
               value="<?= e($posted('leader_phone')) ?>" placeholder="0803 123 4567">
      </div>
    </div>

    <?php // Hidden pair: the checkbox is always posted, so a form that failed to render it can never quietly unpublish the number. ?>
    <input type="hidden" name="leader_phone_public" value="0">
    <label style="display:flex;align-items:flex-start;gap:10px;margin-top:10px;">
      <input type="checkbox" name="leader_phone_public" value="1" <?= $ticked('leader_phone_public') ? 'checked' : '' ?>
             style="margin-top:3px;">
      <span>
        <strong>Show this number on the public finder</strong><br>
        <span style="font-size:12.5px;color:var(--muted,#8b87a8);">
          Off by default. A leader's number on a public page gets copied and called by people they never
          agreed to hear from, so publishing it is a decision, not a side effect of saving.
        </span>
      </span>
    </label>

    <div class="row" style="margin-top:14px;">
      <div>
        <label for="capacity">Space for how many people</label>
        <input type="text" id="capacity" name="capacity" inputmode="numeric"
               value="<?= e($posted('capacity')) ?>" placeholder="Leave empty if not recorded">
      </div>
    </div>

    <input type="hidden" name="cell_is_public" value="0">
    <label style="display:flex;align-items:flex-start;gap:10px;margin-top:10px;">
      <input type="checkbox" name="cell_is_public" value="1" <?= $ticked('cell_is_public') ? 'checked' : '' ?>
             style="margin-top:3px;">
      <span>
        <strong>List this cell</strong><br>
        <span style="font-size:12.5px;color:var(--muted,#8b87a8);">
          Untick while a cell is between leaders — everything stays, it just stops being shown.
        </span>
      </span>
    </label>

    <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
      <button class="btn" type="submit">Save details</button>
      <a class="btn secondary" href="/admin/home-cells">Cancel</a>
      <a class="btn secondary" href="/find-a-cell" target="_blank" rel="noopener">Open the public finder</a>
    </div>
  </form>

<?php else: ?>
  <h2>Home Cells</h2>
  <p class="sub">
    The <?= e(strtolower($leafPlural)) ?> where your people meet midweek. Fill in a meeting day and the
    cell appears in <a href="/find-a-cell" target="_blank" rel="noopener">the public finder</a>.
    <?= (int) $listedCount ?> of <?= count($cells) ?> are listed.
  </p>

  <form method="get" action="/admin/home-cells" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;">
    <input type="text" name="q" value="<?= e($query) ?>" placeholder="Search by name, leader or area" style="max-width:340px;">
    <button class="btn" type="submit">Search</button>
    <?php if ($query !== ''): ?>
      <a class="btn secondary" href="/admin/home-cells">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!$cells): ?>
    <p class="sub">
      <?= $query !== ''
          ? 'No cells match that search.'
          : 'No ' . e(strtolower($leafPlural)) . ' exist yet — add them under Units first.' ?>
    </p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e($leafLabel) ?></th>
          <th>Meets</th>
          <th>Leader</th>
          <th>Space</th>
          <th>Finder</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cells as $cell): ?>
          <?php $missing = HomeCell::missing($cell); ?>
          <tr>
            <td>
              <strong><?= e($cell['name']) ?></strong>
              <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e($cell['path_label']) ?></div>
              <?php if (trim((string) ($cell['meeting_address'] ?? '')) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $cell['meeting_address']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (trim((string) ($cell['meeting_day'] ?? '')) === ''): ?>
                <span style="color:var(--muted,#8b87a8);">—</span>
              <?php else: ?>
                <?= e((string) $cell['meeting_day']) ?>
                <?php if (trim((string) ($cell['meeting_time'] ?? '')) !== ''): ?>
                  <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $cell['meeting_time']) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if (trim((string) ($cell['leader_name'] ?? '')) === ''): ?>
                <span style="color:var(--muted,#8b87a8);">—</span>
              <?php else: ?>
                <?= e((string) $cell['leader_name']) ?>
                <?php if (trim((string) ($cell['leader_phone'] ?? '')) !== ''): ?>
                  <div style="font-size:12.5px;color:var(--muted,#8b87a8);">
                    <?= e(Phone::display((string) $cell['leader_phone'])) ?>
                    <?php if (empty($cell['leader_phone_public'])): ?>
                      <em>· not public</em>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= $cell['capacity'] !== null ? (int) $cell['capacity'] : '—' ?></td>
            <td>
              <?php if (HomeCell::isListed($cell)): ?>
                <span style="color:var(--ok,#3fbf7f);">Listed</span>
              <?php elseif (empty($cell['cell_is_public'])): ?>
                <span style="color:var(--muted,#8b87a8);">Hidden</span>
              <?php else: ?>
                <span style="color:var(--warn,#d9a441);">Not yet</span>
                <div style="font-size:12px;color:var(--muted,#8b87a8);">needs <?= e(implode(', ', $missing)) ?></div>
              <?php endif; ?>
            </td>
            <td><a class="btn secondary" href="/admin/home-cells?action=edit&id=<?= (int) $cell['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
