<?php
declare(strict_types=1);

/**
 * SMS → Contacts. The address book.
 *
 * Three things live here that matter more than the table itself:
 *   - the CSV import, which is a preview-then-commit flow rather than a silent upload,
 *     because a bad import into a paid channel is a real bill;
 *   - the export, which streams straight to the browser using the same pattern as the
 *     newsletter export;
 *   - the sync, which pulls phone numbers that already exist elsewhere in the church
 *     data — and which will only take newsletter numbers that explicitly consented.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$myUnitId = (int) $smsContext['my_unit_id'];
$defaultCountry = (string) setting('sms_default_country', '234');
$unitLabels = $smsContext['unit_labels'];
$assignableUnits = Unit::assignableScope($smsContext['user']);
$tags = SmsContacts::allTags();
$groups = SmsContacts::groups($scopeUnitIds);
$errors = [];
$notice = null;

$tabUrl = '/admin/sms?tab=contacts';

/* ============================================================== CSV export ==
 * Built from the same filter the table is showing, so "export" means "export this view".
 */
if (($_GET['action'] ?? '') === 'export') {
    $exportCriteria = [
        'scope_unit_ids' => $scopeUnitIds,
        'search' => trim((string) ($_GET['q'] ?? '')),
        'source' => (string) ($_GET['source'] ?? ''),
        'tag' => trim((string) ($_GET['tag'] ?? '')),
        'unit_id' => (int) ($_GET['unit'] ?? 0),
        'include_subtree' => ($_GET['subtree'] ?? '') === '1',
    ];
    $optoutFilter = (string) ($_GET['optout'] ?? '');
    if ($optoutFilter === 'yes') {
        $exportCriteria['opted_out'] = true;
    } elseif ($optoutFilter === 'no') {
        $exportCriteria['opted_out'] = false;
    }

    $rows = SmsContacts::filter($exportCriteria, 200000, 0);

    // Discard anything the tab echoed and stream exactly the CSV.
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sms-contacts-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Number', 'Country', 'Name', 'Email', 'Tags', 'Source', 'Church', 'Opted out', 'Added']);
    foreach ($rows as $row) {
        fputcsv($out, [
            Sms::prettyMsisdn((string) $row['msisdn']),
            (string) $row['country_code'],
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['tags'] ?? ''),
            SmsContacts::sourceLabel((string) $row['source']),
            !empty($row['org_unit_id']) ? ($unitLabels[(int) $row['org_unit_id']] ?? '') : '',
            (int) $row['is_opted_out'] === 1 ? 'yes' : 'no',
            (string) $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

/* ============================================================ POST handling */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = (string) ($_POST['do'] ?? '');

    /** Reads the ticked ids, ignoring anything that is not a positive integer. */
    $ticked = static function (): array {
        $raw = $_POST['ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    };

    // --- one contact, saved by hand ----------------------------------------------------------
    if ($do === 'save_contact') {
        $id = (int) ($_POST['id'] ?? 0);
        $unitId = $isSuper ? (int) ($_POST['org_unit_id'] ?? 0) : $myUnitId;
        $result = SmsContacts::save($id, (string) ($_POST['number'] ?? ''), [
            'name' => (string) ($_POST['name'] ?? ''),
            'email' => (string) ($_POST['email'] ?? ''),
            'tags' => (string) ($_POST['tags'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''),
            'country_code' => (string) ($_POST['country_code'] ?? $defaultCountry),
            'source' => $id > 0 ? (string) ($_POST['source'] ?? 'manual') : 'manual',
            'org_unit_id' => $unitId,
        ]);

        if (!$result['ok']) {
            $errors[] = (string) $result['error'];
        } else {
            flash('success', $result['action'] === 'created'
                ? 'Contact added.'
                : 'Contact saved. (The number already existed, so the existing record was updated rather than duplicated.)');
            redirect($tabUrl);
        }
    }

    // --- bulk: tag ----------------------------------------------------------------------------
    if ($do === 'bulk_tag') {
        $ids = $ticked();
        $added = SmsContacts::addTags($ids, (string) ($_POST['tags'] ?? ''));
        flash('success', $added . ' contact(s) tagged with “' . trim((string) ($_POST['tags'] ?? '')) . '”.');
        redirect($tabUrl);
    }

    // --- bulk: opt out / back in -----------------------------------------------------------------
    if ($do === 'bulk_optout' || $do === 'bulk_optin') {
        $ids = $ticked();
        $out = $do === 'bulk_optout';
        $changed = SmsContacts::setOptOut($ids, $out);
        flash('success', $changed . ' contact(s) marked as ' . ($out ? 'opted out. They will be skipped by every campaign.' : 'opted back in.'));
        redirect($tabUrl);
    }

    // --- bulk: add to a group ---------------------------------------------------------------------
    if ($do === 'bulk_group') {
        $ids = $ticked();
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $group = $groupId > 0 ? SmsContacts::findGroup($groupId) : null;
        if ($group === null) {
            flash('error', 'Choose a group.');
        } elseif ((string) $group['kind'] === 'dynamic') {
            flash('error', 'That group is dynamic, so its members come from its rule.');
        } else {
            $existing = SmsContacts::groupAudience($group, $scopeUnitIds);
            $total = SmsContacts::setGroupMembers($groupId, array_merge($existing, $ids));
            flash('success', count($ids) . ' contact(s) added to “' . $group['name'] . '”, which now has ' . $total . ' member(s).');
        }
        redirect($tabUrl);
    }

    // --- bulk: delete -----------------------------------------------------------------------------
    if ($do === 'bulk_delete') {
        $ids = $ticked();
        $deleted = SmsContacts::deleteMany($ids);
        flash('success', $deleted . ' contact(s) deleted. Campaign reports that already used them are unaffected.');
        redirect($tabUrl);
    }

    // --- sync from church data --------------------------------------------------------------------
    if ($do === 'sync') {
        $report = SmsContacts::syncFromChurchData($scopeUnitIds);
        $messages = [];
        $totalAdded = 0;
        $totalUpdated = 0;
        foreach ($report as $source => $counts) {
            $totalAdded += (int) $counts['added'];
            $totalUpdated += (int) $counts['updated'];
            if ((int) $counts['found'] > 0) {
                $messages[] = $counts['label'] . ': ' . $counts['found'] . ' found, '
                    . $counts['added'] . ' new, ' . $counts['updated'] . ' refreshed';
            }
        }
        $summary = 'Sync finished — ' . $totalAdded . ' new contact(s), ' . $totalUpdated . ' refreshed.';
        if ($messages !== []) {
            $summary .= ' ' . implode('. ', $messages) . '.';
        }
        flash('success', $summary);
        redirect($tabUrl);
    }

    // --- import, step 1: read the file and set up the mapping ---------------------------------------
    if ($do === 'import_upload') {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Choose a CSV file to upload.';
        } elseif ((int) $file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'That file is larger than 5 MB. Split it and import the parts.';
        } else {
            $content = (string) file_get_contents((string) $file['tmp_name']);
            $parsed = SmsContacts::parseCsv($content);

            if ($parsed['rows'] === []) {
                $errors[] = 'No data rows were found in that file.';
            } else {
                $token = bin2hex(random_bytes(8));
                $_SESSION['sms_import'][$token] = [
                    'header' => $parsed['header'],
                    'rows' => $parsed['rows'],
                    'delimiter' => $parsed['delimiter'],
                    'name' => (string) ($file['name'] ?? 'upload.csv'),
                    'at' => time(),
                ];
                // Keep at most three parked imports, so a session cannot grow without limit.
                if (count($_SESSION['sms_import']) > 3) {
                    array_shift($_SESSION['sms_import']);
                }
                redirect($tabUrl . '&import=' . $token);
            }
        }
    }

    // --- import, step 2: preview (dry run) -----------------------------------------------------------
    if ($do === 'import_preview' || $do === 'import_commit') {
        $token = (string) ($_POST['token'] ?? '');
        $parked = $_SESSION['sms_import'][$token] ?? null;
        if (!is_array($parked)) {
            flash('error', 'That import has expired. Please choose the file again.');
            redirect($tabUrl);
        }

        $mapping = [];
        foreach (['number', 'name', 'email', 'tags', 'notes'] as $field) {
            $column = (int) ($_POST['map_' . $field] ?? -1);
            if ($column >= 0) {
                $mapping[$field] = $column;
            }
        }
        if (!isset($mapping['number'])) {
            $errors[] = 'Tell the importer which column holds the phone number.';
        }

        if ($errors === []) {
            $dryRun = $do === 'import_preview';
            $report = SmsContacts::importRows($parked['rows'], $mapping, $defaultCountry, $dryRun);

            if ($dryRun) {
                $_SESSION['sms_import'][$token]['mapping'] = $mapping;
                $_SESSION['sms_import'][$token]['report'] = $report;
                redirect($tabUrl . '&import=' . $token . '&preview=1');
            }

            unset($_SESSION['sms_import'][$token]);
            $summary = $report['created'] . ' added, ' . $report['updated'] . ' updated, '
                . $report['invalid'] . ' rejected, ' . $report['duplicates'] . ' repeated within the file.';
            if ($report['invalid'] > 0 || $report['duplicates'] > 0) {
                flash('error', 'Import finished with problems — ' . $summary . ' Open the file and check the rejected rows below.');
                $_SESSION['sms_import_report'] = $report;
            } else {
                flash('success', 'Import finished — ' . $summary);
            }
            redirect($tabUrl);
        }
    }

    // --- import, cancel -------------------------------------------------------------------------------
    if ($do === 'import_cancel') {
        $token = (string) ($_POST['token'] ?? '');
        unset($_SESSION['sms_import'][$token]);
        flash('success', 'Import cancelled. Nothing was saved.');
        redirect($tabUrl);
    }
}

/* ============================================================== read / view */

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? SmsContacts::find($editId) : null;
if ($editId > 0 && $editing === null) {
    $errors[] = 'That contact could not be found.';
}

$importToken = (string) ($_GET['import'] ?? '');
$import = $importToken !== '' ? ($_SESSION['sms_import'][$importToken] ?? null) : null;
$showPreview = ($_GET['preview'] ?? '') === '1' && is_array($import);

// A finished import's rejected rows, shown once.
$lastReport = $_SESSION['sms_import_report'] ?? null;
unset($_SESSION['sms_import_report']);

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$optoutFilter = (string) ($_GET['optout'] ?? '');

$criteria = [
    'scope_unit_ids' => $scopeUnitIds,
    'search' => trim((string) ($_GET['q'] ?? '')),
    'source' => (string) ($_GET['source'] ?? ''),
    'tag' => trim((string) ($_GET['tag'] ?? '')),
    'unit_id' => (int) ($_GET['unit'] ?? 0),
    'include_subtree' => ($_GET['subtree'] ?? '') === '1',
];
if ($optoutFilter === 'yes') {
    $criteria['opted_out'] = true;
} elseif ($optoutFilter === 'no') {
    $criteria['opted_out'] = false;
}

$total = SmsContacts::count($criteria);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$contacts = SmsContacts::filter($criteria, $perPage, ($page - 1) * $perPage);

$totalContacts = SmsContacts::count(['scope_unit_ids' => $scopeUnitIds]);
$totalOptedOut = SmsContacts::count(['scope_unit_ids' => $scopeUnitIds, 'opted_out' => true]);
$sourceCounts = SmsContacts::countsBySource($scopeUnitIds);

/** Rebuilds the filter query string, so pagination and actions keep the current view. */
$queryFor = static function (array $overrides = []) use ($criteria, $page): string {
    $params = array_filter([
        'tab' => 'contacts',
        'q' => (string) $criteria['search'],
        'source' => (string) $criteria['source'],
        'tag' => (string) $criteria['tag'],
        'unit' => (int) $criteria['unit_id'] > 0 ? (string) $criteria['unit_id'] : '',
        'subtree' => !empty($criteria['include_subtree']) ? '1' : '',
        'optout' => isset($criteria['opted_out']) ? ($criteria['opted_out'] ? 'yes' : 'no') : '',
        'page' => $page > 1 ? (string) $page : '',
    ], static fn(string $v): bool => $v !== '');
    return '/admin/sms?' . http_build_query(array_merge($params, $overrides));
};
$staticGroups = array_values(array_filter($groups, static fn(array $g): bool => (string) $g['kind'] === 'static'));
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (is_array($lastReport) && ($lastReport['skipped'] ?? []) !== []): ?>
  <div class="card">
    <h2>Rows that were not imported</h2>
    <p class="sub">These are the first <?= count($lastReport['skipped']) ?> problems from the last import. Everything else went in.</p>
    <table>
      <tr><th>Line</th><th>Value</th><th>Why</th></tr>
      <?php foreach ($lastReport['skipped'] as $skip): ?>
        <tr>
          <td><?= (int) $skip['row'] ?></td>
          <td><code><?= e((string) $skip['value']) ?></code></td>
          <td><small style="color:var(--danger);"><?= e((string) $skip['reason']) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php if (is_array($import) && !$showPreview): ?>
  <div class="card">
    <h2>Import “<?= e((string) $import['name']) ?>” — match the columns</h2>
    <p class="sub">
      The file has <?= number_format(count($import['rows'])) ?> data row(s) and was read using the
      <code><?= $import['delimiter'] === "\t" ? 'tab' : e((string) $import['delimiter']) ?></code> as the separator.
      Tell the importer which column is which. Nothing has been saved yet.
    </p>

    <form method="post" action="<?= e($tabUrl) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="import_preview">
      <input type="hidden" name="token" value="<?= e($importToken) ?>">

      <div class="row two">
        <div>
          <label for="map_number">Phone number <span class="badge fail">required</span></label>
          <select id="map_number" name="map_number" required>
            <option value="">— choose a column —</option>
            <?php foreach ($import['header'] as $index => $heading): ?>
              <option value="<?= (int) $index ?>"><?= e($heading !== '' ? $heading : 'Column ' . ($index + 1)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="map_name">Name</label>
          <select id="map_name" name="map_name">
            <option value="">— not in this file —</option>
            <?php foreach ($import['header'] as $index => $heading): ?>
              <option value="<?= (int) $index ?>"><?= e($heading !== '' ? $heading : 'Column ' . ($index + 1)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row two">
        <div>
          <label for="map_email">Email</label>
          <select id="map_email" name="map_email">
            <option value="">— not in this file —</option>
            <?php foreach ($import['header'] as $index => $heading): ?>
              <option value="<?= (int) $index ?>"><?= e($heading !== '' ? $heading : 'Column ' . ($index + 1)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="map_tags">Tags</label>
          <select id="map_tags" name="map_tags">
            <option value="">— not in this file —</option>
            <?php foreach ($import['header'] as $index => $heading): ?>
              <option value="<?= (int) $index ?>"><?= e($heading !== '' ? $heading : 'Column ' . ($index + 1)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label for="map_notes">Notes</label>
      <select id="map_notes" name="map_notes">
        <option value="">— not in this file —</option>
        <?php foreach ($import['header'] as $index => $heading): ?>
          <option value="<?= (int) $index ?>"><?= e($heading !== '' ? $heading : 'Column ' . ($index + 1)) ?></option>
        <?php endforeach; ?>
      </select>

      <p class="sub" style="font-size:12.5px;">
        Numbers written locally (0803…) are read as <?= e(Sms::countryName($defaultCountry)) ?> numbers.
        A number already in the address book is updated rather than duplicated.
      </p>

      <div class="btn-row">
        <button class="btn" type="submit">Check the file — nothing saved yet</button>
        <button class="btn secondary" type="submit" formnovalidate
                onclick="this.form.querySelector('[name=do]').value='import_cancel';">Cancel</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if ($showPreview): ?>
  <?php
    $preview = $_SESSION['sms_import'][$importToken]['report'] ?? ['created' => 0, 'updated' => 0, 'invalid' => 0, 'duplicates' => 0, 'skipped' => []];
    $mapping = $_SESSION['sms_import'][$importToken]['mapping'] ?? [];
    $sampleRows = array_slice($import['rows'], 0, 8);
  ?>
  <div class="card">
    <h2>Check before importing</h2>
    <p class="sub">This is a dry run. <strong>Nothing has been written yet.</strong></p>

    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      <div class="stat"><div class="num"><?= number_format((int) $preview['created']) ?></div><div class="label">New contacts</div></div>
      <div class="stat"><div class="num"><?= number_format((int) $preview['updated']) ?></div><div class="label">Already known — will be refreshed</div></div>
      <div class="stat"><div class="num"><?= number_format((int) $preview['invalid']) ?></div><div class="label">Rejected — bad numbers</div></div>
      <div class="stat"><div class="num"><?= number_format((int) $preview['duplicates']) ?></div><div class="label">Repeated inside the file</div></div>
    </div>

    <?php if ((int) $preview['invalid'] > 0): ?>
      <div class="alert error">
        <?= number_format((int) $preview['invalid']) ?> number(s) could not be read and will be skipped —
        for example a landline, or a number with too few digits. They are listed below.
      </div>
    <?php endif; ?>

    <?php if (($preview['skipped'] ?? []) !== []): ?>
      <table style="margin-bottom:16px;">
        <tr><th>Line</th><th>Value</th><th>Why</th></tr>
        <?php foreach (array_slice($preview['skipped'], 0, 12) as $skip): ?>
          <tr>
            <td><?= (int) $skip['row'] ?></td>
            <td><code><?= e((string) $skip['value']) ?></code></td>
            <td><small><?= e((string) $skip['reason']) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:16px 0 8px;">First few rows, as they will be read</h3>
    <table class="resp-table">
      <tr>
        <th>Number</th><th>Name</th><th>Email</th><th>Tags</th>
      </tr>
      <?php foreach ($sampleRows as $row): ?>
        <tr>
          <td><code><?= e((string) ($row[$mapping['number'] ?? 0] ?? '')) ?></code></td>
          <td><?= e((string) ($row[$mapping['name'] ?? -1] ?? '')) ?></td>
          <td><small><?= e((string) ($row[$mapping['email'] ?? -1] ?? '')) ?></small></td>
          <td><small><?= e((string) ($row[$mapping['tags'] ?? -1] ?? '')) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" action="<?= e($tabUrl) ?>" style="margin-top:18px;">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="import_commit">
      <input type="hidden" name="token" value="<?= e($importToken) ?>">
      <?php foreach (['number', 'name', 'email', 'tags', 'notes'] as $field): ?>
        <?php if (isset($mapping[$field])): ?>
          <input type="hidden" name="map_<?= e($field) ?>" value="<?= (int) $mapping[$field] ?>">
        <?php endif; ?>
      <?php endforeach; ?>

      <div class="btn-row">
        <button class="btn" type="submit">
          Import <?= number_format((int) $preview['created'] + (int) $preview['updated']) ?> contact(s)
        </button>
        <button class="btn danger" type="submit"
                onclick="this.form.querySelector('[name=do]').value='import_cancel';">Cancel — don't import</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h2>Address book</h2>
      <p class="sub" style="max-width:640px;">
        <strong><?= number_format($totalContacts) ?></strong> contact(s)<?= $totalOptedOut > 0 ? ', of which ' . number_format($totalOptedOut) . ' opted out' : '' ?>.
        An opted-out number is never sent to, however it is selected.
      </p>
    </div>
    <form method="post" action="<?= e($tabUrl) ?>" style="margin:0;"
          onsubmit="return confirm('Search the church data for phone numbers and add any that are missing? Nothing is deleted.');">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="sync">
      <button class="btn secondary" type="submit">🔄 Sync from church data</button>
    </form>
  </div>

  <?php if ($sourceCounts): ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
      <?php foreach ($sourceCounts as $source => $count): ?>
        <a class="badge info" style="text-decoration:none;" href="<?= e($queryFor(['source' => (string) $source, 'page' => ''])) ?>">
          <?= e(SmsContacts::sourceLabel((string) $source)) ?>: <?= number_format((int) $count) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="get" action="/admin/sms" style="border-top:1px solid var(--border);padding-top:16px;">
    <input type="hidden" name="tab" value="contacts">
    <div class="row two">
      <div>
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= e((string) $criteria['search']) ?>" placeholder="Name, number or email">
      </div>
      <div>
        <label for="tag">Tag</label>
        <input type="text" id="tag" name="tag" list="tag-list" value="<?= e((string) $criteria['tag']) ?>" placeholder="Any tag">
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="source">Source</label>
        <select id="source" name="source">
          <option value="">Any source</option>
          <?php foreach (SmsContacts::SOURCES as $source): ?>
            <option value="<?= e($source) ?>" <?= $criteria['source'] === $source ? 'selected' : '' ?>><?= e(SmsContacts::sourceLabel($source)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="optout">Opt-out</label>
        <select id="optout" name="optout">
          <option value="">Everyone</option>
          <option value="no" <?= $optoutFilter === 'no' ? 'selected' : '' ?>>Only those who can be messaged</option>
          <option value="yes" <?= $optoutFilter === 'yes' ? 'selected' : '' ?>>Only those who opted out</option>
        </select>
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="unit">Church unit</label>
        <select id="unit" name="unit">
          <option value="">Any unit</option>
          <?php foreach ($assignableUnits as $unitId => $label): ?>
            <option value="<?= (int) $unitId ?>" <?= (int) $criteria['unit_id'] === (int) $unitId ? 'selected' : '' ?>><?= e((string) $label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="checkbox-row">
          <input type="checkbox" id="subtree" name="subtree" value="1" <?= !empty($criteria['include_subtree']) ? 'checked' : '' ?>>
          <label for="subtree" style="margin:0;">Include sub-units</label>
        </div>
      </div>
      <div>
        <label>&nbsp;</label>
        <div class="btn-row">
          <button class="btn" type="submit">Apply</button>
          <a class="btn secondary" href="<?= e($tabUrl) ?>">Clear</a>
          <a class="btn secondary" href="<?= e($queryFor(['action' => 'export', 'page' => ''])) ?>">⬇ Export this view</a>
        </div>
      </div>
    </div>
  </form>

  <?php if ($editing !== null): ?>
    <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
      <h3 style="font-size:15px;margin-bottom:10px;">Editing <?= e((string) ($editing['name'] ?: Sms::prettyMsisdn((string) $editing['msisdn']))) ?></h3>
      <form method="post" action="<?= e($tabUrl) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="do" value="save_contact">
        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <div class="row two">
          <div>
            <label for="edit_number">Phone number</label>
            <input type="text" id="edit_number" name="number" required value="<?= e(Sms::prettyMsisdn((string) $editing['msisdn'])) ?>">
          </div>
          <div>
            <label for="edit_name">Name</label>
            <input type="text" id="edit_name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>">
          </div>
        </div>
        <div class="row two">
          <div>
            <label for="edit_email">Email</label>
            <input type="email" id="edit_email" name="email" value="<?= e((string) ($editing['email'] ?? '')) ?>">
          </div>
          <div>
            <label for="edit_tags">Tags</label>
            <input type="text" id="edit_tags" name="tags" list="tag-list" value="<?= e((string) ($editing['tags'] ?? '')) ?>">
          </div>
        </div>
        <label for="edit_notes">Notes</label>
        <input type="text" id="edit_notes" name="notes" value="<?= e((string) ($editing['notes'] ?? '')) ?>">
        <div class="btn-row">
          <button class="btn" type="submit">Save changes</button>
          <a class="btn secondary" href="<?= e($tabUrl) ?>">Cancel</a>
        </div>
      </form>
    </div>
  <?php else: ?>
    <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
      <h3 style="font-size:15px;margin-bottom:10px;">Add a contact</h3>
      <form method="post" action="<?= e($tabUrl) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="do" value="save_contact">
        <div class="row two">
          <div>
            <label for="add_number">Phone number</label>
            <input type="text" id="add_number" name="number" required placeholder="0803 000 0000 or +2348030000000">
          </div>
          <div>
            <label for="add_name">Name</label>
            <input type="text" id="add_name" name="name" placeholder="Optional">
          </div>
        </div>
        <div class="row two">
          <div>
            <label for="add_email">Email</label>
            <input type="email" id="add_email" name="email" placeholder="Optional">
          </div>
          <div>
            <label for="add_tags">Tags</label>
            <input type="text" id="add_tags" name="tags" list="tag-list" placeholder="worker, choir">
          </div>
        </div>
        <?php if ($isSuper): ?>
          <label for="add_unit">Church</label>
          <select id="add_unit" name="org_unit_id">
            <option value="">— none / head office —</option>
            <?php foreach ($assignableUnits as $unitId => $label): ?>
              <option value="<?= (int) $unitId ?>"><?= e((string) $label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button class="btn" type="submit">Add contact</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Import or sync</h2>
  <p class="sub">
    Bring in a list from Excel, Google Contacts or a phone export. Save it as CSV, then upload it here.
    The importer will show you exactly what it plans to do before it does anything.
  </p>

  <form method="post" action="<?= e($tabUrl) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="import_upload">
    <label for="csv">CSV file</label>
    <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
    <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 14px;">
      Up to 5 MB. The first line is read as the column headings. Commas, semicolons and tabs are all understood.
    </small>
    <button class="btn" type="submit">Upload and check</button>
  </form>

  <p class="sub" style="font-size:12.5px;border-top:1px solid var(--border);padding-top:14px;margin-top:16px;">
    <strong>Sync from church data</strong> searches the records this site already holds — the team list,
    newcomers, event RSVPs, testimonies, registrations and app installs — and adds any phone number that
    is not in the address book yet. Newsletter subscribers are only included where the subscriber
    explicitly agreed to receive text messages.
  </p>
</div>

<div class="card">
  <h2><?= number_format($total) ?> contact(s) in this view</h2>
  <?php if (!$contacts): ?>
    <div class="empty">
      Nothing matches. <?= $totalContacts === 0 ? 'Add one above, sync from church data, or import a CSV.' : 'Try clearing the filters.' ?>
    </div>
  <?php else: ?>
    <form method="post" action="<?= e($tabUrl) ?>">
      <?= Csrf::field() ?>
      <div class="bulk-bar" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;margin-bottom:14px;background:#ffffff06;">
        <strong style="font-size:12.5px;">With the ticked rows:</strong>
        <input type="text" name="tags" placeholder="tags to add" style="width:150px;margin:0;">
        <button class="btn secondary sm" type="submit" name="do" value="bulk_tag">Add tags</button>
        <select name="group_id" style="width:180px;margin:0;">
          <option value="">— add to group —</option>
          <?php foreach ($staticGroups as $group): ?>
            <option value="<?= (int) $group['id'] ?>"><?= e((string) $group['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn secondary sm" type="submit" name="do" value="bulk_group">Add to group</button>
        <button class="btn secondary sm" type="submit" name="do" value="bulk_optout"
                onclick="return confirm('Mark the ticked contacts as opted out? They will be skipped by every campaign from now on.');">Opt out</button>
        <button class="btn secondary sm" type="submit" name="do" value="bulk_optin">Opt back in</button>
        <button class="btn danger sm" type="submit" name="do" value="bulk_delete"
                onclick="return confirm('Delete the ticked contacts? This cannot be undone.');">Delete</button>
      </div>

      <table class="resp-table">
        <tr>
          <th style="width:28px;"><input type="checkbox" id="tick-all" aria-label="Select all"
                                         onclick="var on = this.checked; document.querySelectorAll('.contact-tick').forEach(function (box) { box.checked = on; });"></th>
          <th>Name</th><th>Number</th><th>Tags</th><th>Source</th><th>Church</th><th></th>
        </tr>
        <?php foreach ($contacts as $contact): ?>
          <?php $optedOut = (int) $contact['is_opted_out'] === 1; ?>
          <tr<?= $optedOut ? ' style="opacity:.55;"' : '' ?>>
            <td><input type="checkbox" class="contact-tick" name="ids[]" value="<?= (int) $contact['id'] ?>" aria-label="Select contact"></td>
            <td>
              <?= e((string) ($contact['name'] ?: '—')) ?>
              <?php if ($optedOut): ?><span class="badge fail">opted out</span><?php endif; ?>
              <?php if (!empty($contact['email'])): ?><div><small style="color:var(--ink-faint);"><?= e((string) $contact['email']) ?></small></div><?php endif; ?>
            </td>
            <td><code><?= e(Sms::prettyMsisdn((string) $contact['msisdn'])) ?></code></td>
            <td><small style="color:var(--ink-dim);"><?= e((string) ($contact['tags'] ?: '')) ?></small></td>
            <td><small style="color:var(--ink-faint);"><?= e(SmsContacts::sourceLabel((string) $contact['source'])) ?></small></td>
            <td>
              <?php if (!empty($contact['org_unit_id'])): ?>
                <small style="color:var(--gold-soft);"><?= e($unitLabels[(int) $contact['org_unit_id']] ?? '') ?></small>
              <?php else: ?>
                <small style="color:var(--ink-faint);">—</small>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn secondary sm" href="<?= e($queryFor(['edit' => (int) $contact['id']])) ?>">Edit</a>
              <button class="btn secondary sm" type="submit" name="do" value="<?= $optedOut ? 'bulk_optin' : 'bulk_optout' ?>" formnovalidate
                      onclick="var form = this.form; form.querySelectorAll('.contact-tick').forEach(function (box) { box.checked = false; }); this.closest('tr').querySelector('.contact-tick').checked = true;">
                <?= $optedOut ? 'Opt in' : 'Opt out' ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </form>

    <?php if ($pages > 1): ?>
      <div class="btn-row" style="margin-top:14px;">
        <?php if ($page > 1): ?>
          <a class="btn secondary sm" href="<?= e($queryFor(['page' => (string) ($page - 1)])) ?>">← Previous</a>
        <?php endif; ?>
        <span style="align-self:center;font-size:12.5px;color:var(--ink-faint);">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?>
          <a class="btn secondary sm" href="<?= e($queryFor(['page' => (string) ($page + 1)])) ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<datalist id="tag-list">
  <?php foreach ($tags as $tag): ?><option value="<?= e((string) $tag) ?>"></option><?php endforeach; ?>
</datalist>
