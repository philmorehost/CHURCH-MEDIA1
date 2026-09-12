<?php
declare(strict_types=1);

/**
 * SMS → Groups.
 *
 * Two kinds, and the difference matters:
 *   - static  — a fixed list you curate.
 *   - dynamic — a rule, re-evaluated at the moment of sending, so a segment stays current
 *               without anyone remembering to top it up.
 *
 * A static group can be filled by pasting numbers, or by "add everyone matching a filter",
 * which is how an admin turns a one-off search into a reusable list.
 */

/** @var array<string, mixed> $smsContext */
$isSuper = (bool) $smsContext['is_super'];
$scopeUnitIds = $smsContext['scope_unit_ids'];
$myUnitId = (int) $smsContext['my_unit_id'];
$errors = [];

/** Loads a group, refusing anything outside the admin's reach. */
$loadGroup = static function (int $id) use ($pdo, $isSuper, $scopeUnitIds): ?array {
    $group = SmsContacts::findGroup($id);
    if ($group === null) {
        return null;
    }
    if ($isSuper || $scopeUnitIds === []) {
        return $group;
    }
    $unitId = (int) ($group['org_unit_id'] ?? 0);
    return $unitId === 0 || in_array($unitId, array_map('intval', $scopeUnitIds), true) ? $group : null;
};

/* ------------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = (string) ($_POST['do'] ?? '');

    // --- create / update a group ------------------------------------------------------------
    if ($do === 'save_group') {
        $id = (int) ($_POST['id'] ?? 0);
        $kind = (string) ($_POST['kind'] ?? 'static');
        $unitId = $isSuper ? (int) ($_POST['org_unit_id'] ?? 0) : $myUnitId;

        $rule = [
            'type' => (string) ($_POST['rule_type'] ?? 'all'),
            'source' => (string) ($_POST['rule_source'] ?? ''),
            'tag' => (string) ($_POST['rule_tag'] ?? ''),
            'unit_id' => (int) ($_POST['rule_unit_id'] ?? 0),
            'include_subtree' => isset($_POST['rule_include_subtree']) ? 1 : 0,
            'days' => (int) ($_POST['rule_days'] ?? 30),
        ];

        if ($id > 0 && $loadGroup($id) === null) {
            $errors[] = 'That group could not be found.';
        } else {
            $result = SmsContacts::saveGroup($id, (string) ($_POST['name'] ?? ''), [
                'kind' => $kind,
                'rule' => $rule,
                'org_unit_id' => $unitId,
                'created_by' => (int) ($smsContext['user']['id'] ?? 0),
            ]);
            if (!$result['ok']) {
                $errors[] = (string) $result['error'];
            } else {
                flash('success', $result['action'] === 'created' ? 'Group created.' : 'Group updated.');
                redirect('/admin/sms?tab=groups&id=' . (int) $result['id']);
            }
        }
    }

    // --- delete -----------------------------------------------------------------------------
    if ($do === 'delete_group') {
        $group = $loadGroup((int) ($_POST['id'] ?? 0));
        if ($group === null) {
            flash('error', 'That group could not be found.');
        } else {
            SmsContacts::deleteGroup((int) $group['id']);
            flash('success', 'Deleted the group “' . $group['name'] . '”. Campaigns that already used it are unaffected.');
        }
        redirect('/admin/sms?tab=groups');
    }

    // --- add numbers to a static group ------------------------------------------------------
    if ($do === 'add_numbers') {
        $group = $loadGroup((int) ($_POST['id'] ?? 0));
        if ($group === null) {
            flash('error', 'That group could not be found.');
            redirect('/admin/sms?tab=groups');
        }
        if ((string) $group['kind'] === 'dynamic') {
            flash('error', 'That is a dynamic group — its members come from a rule, not a list.');
            redirect('/admin/sms?tab=groups&id=' . (int) $group['id']);
        }

        $audience = SmsContacts::audienceFromNumbers((string) ($_POST['numbers'] ?? ''), (string) setting('sms_default_country', '234'));
        $ids = array_merge(
            array_map(static fn(array $row): int => (int) $row['id'], $audience['known']),
            // A number typed straight into a group is a deliberate act, so it is kept.
            array_map(static function (string $msisdn) use ($group): int {
                $saved = SmsContacts::save(0, $msisdn, ['source' => 'manual', 'org_unit_id' => $group['org_unit_id']]);
                return $saved['ok'] ? (int) $saved['id'] : 0;
            }, $audience['unknown'])
        );
        $ids = array_values(array_filter($ids));

        $existing = SmsContacts::groupAudience($group, $scopeUnitIds);
        SmsContacts::setGroupMembers((int) $group['id'], array_merge($existing, $ids));

        $message = count($ids) . ' contact(s) added.';
        if ($audience['invalid'] !== []) {
            $message .= ' ' . count($audience['invalid']) . ' number(s) were rejected, e.g. '
                . $audience['invalid'][0]['value'] . ' (' . $audience['invalid'][0]['reason'] . ').';
        }
        flash('success', $message);
        redirect('/admin/sms?tab=groups&id=' . (int) $group['id']);
    }

    // --- add everyone matching a filter ------------------------------------------------------
    if ($do === 'add_filtered') {
        $group = $loadGroup((int) ($_POST['id'] ?? 0));
        if ($group === null) {
            flash('error', 'That group could not be found.');
            redirect('/admin/sms?tab=groups');
        }
        if ((string) $group['kind'] === 'dynamic') {
            flash('error', 'That is a dynamic group — it fills itself.');
            redirect('/admin/sms?tab=groups&id=' . (int) $group['id']);
        }

        $criteria = [
            'scope_unit_ids' => $scopeUnitIds,
            'opted_out' => false,
            'source' => (string) ($_POST['filter_source'] ?? ''),
            'tag' => trim((string) ($_POST['filter_tag'] ?? '')),
            'unit_id' => (int) ($_POST['filter_unit_id'] ?? 0),
            'include_subtree' => isset($_POST['filter_include_subtree']) ? 1 : 0,
        ];
        $matches = array_map(static fn(array $row): int => (int) $row['id'], SmsContacts::filter($criteria, 5000));
        $existing = SmsContacts::groupAudience($group, $scopeUnitIds);
        $before = count($existing);
        $merged = SmsContacts::setGroupMembers((int) $group['id'], array_merge($existing, $matches));

        flash('success', count($matches) . ' matching contact(s) found; the group now has ' . $merged . ' member(s).'
            . ($merged === $before ? ' Nothing new to add.' : ''));
        redirect('/admin/sms?tab=groups&id=' . (int) $group['id']);
    }

    // --- remove one member --------------------------------------------------------------------
    if ($do === 'remove_member') {
        $group = $loadGroup((int) ($_POST['id'] ?? 0));
        if ($group === null) {
            flash('error', 'That group could not be found.');
            redirect('/admin/sms?tab=groups');
        }
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $remaining = array_values(array_diff(SmsContacts::groupAudience($group, $scopeUnitIds), [$contactId]));
        SmsContacts::setGroupMembers((int) $group['id'], $remaining);
        flash('success', 'Member removed.');
        redirect('/admin/sms?tab=groups&id=' . (int) $group['id']);
    }
}

/* --------------------------------------------------------------------- read */

$groupList = SmsContacts::groups($scopeUnitIds);
$unitLabels = $smsContext['unit_labels'];
$assignableUnits = Unit::assignableScope($smsContext['user']);
$tags = SmsContacts::allTags();

$openId = (int) ($_GET['id'] ?? 0);
$openGroup = $openId > 0 ? $loadGroup($openId) : null;
if ($openId > 0 && $openGroup === null) {
    $errors[] = 'That group could not be found.';
}

$members = [];
$memberCount = 0;
if ($openGroup !== null) {
    $ids = SmsContacts::groupAudience($openGroup, $scopeUnitIds);
    $memberCount = count($ids);
    $members = $ids !== [] ? SmsContacts::filter(['ids' => array_slice($ids, 0, 200), 'scope_unit_ids' => $scopeUnitIds], 200) : [];
}

$rule = [];
if ($openGroup !== null && (string) $openGroup['kind'] === 'dynamic') {
    $rule = is_string($openGroup['rule']) ? (json_decode((string) $openGroup['rule'], true) ?: []) : (array) ($openGroup['rule'] ?? []);
}
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2><?= $openGroup ? 'Edit group' : 'New group' ?></h2>
  <p class="sub">
    A <strong>static</strong> group is a list you keep — newcomers, the choir, this term's workers.
    A <strong>dynamic</strong> group is a rule that is re-checked every time you send, so it is never stale.
  </p>

  <form method="post" action="/admin/sms?tab=groups">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="save_group">
    <input type="hidden" name="id" value="<?= $openGroup ? (int) $openGroup['id'] : 0 ?>">

    <div class="row two">
      <div>
        <label for="name">Group name</label>
        <input type="text" id="name" name="name" maxlength="150" required
               value="<?= e((string) ($openGroup['name'] ?? formOld('name'))) ?>" placeholder="e.g. Workers — December">
      </div>
      <div>
        <label for="kind">How does it fill?</label>
        <select id="kind" name="kind" onchange="document.getElementById('rule-box').style.display = this.value === 'dynamic' ? 'block' : 'none';">
          <option value="static" <?= ($openGroup['kind'] ?? 'static') === 'static' ? 'selected' : '' ?>>Static — I choose the members</option>
          <option value="dynamic" <?= ($openGroup['kind'] ?? '') === 'dynamic' ? 'selected' : '' ?>>Dynamic — a rule chooses them</option>
        </select>
      </div>
    </div>

    <?php if ($isSuper): ?>
      <label for="org_unit_id">Church</label>
      <select id="org_unit_id" name="org_unit_id">
        <option value="">— shared with every church —</option>
        <?php foreach ($assignableUnits as $unitId => $label): ?>
          <option value="<?= (int) $unitId ?>" <?= (int) ($openGroup['org_unit_id'] ?? 0) === (int) $unitId ? 'selected' : '' ?>><?= e((string) $label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <div id="rule-box" style="display:<?= ($openGroup['kind'] ?? 'static') === 'dynamic' ? 'block' : 'none' ?>;border:1px solid var(--border);border-radius:10px;padding:14px;margin:6px 0 16px;">
      <label for="rule_type">Rule</label>
      <select id="rule_type" name="rule_type">
        <?php foreach (SmsContacts::RULE_TYPES as $type): ?>
          <option value="<?= e($type) ?>" <?= (string) ($rule['type'] ?? 'all') === $type ? 'selected' : '' ?>>
            <?= e(SmsContacts::RULE_LABELS[$type] ?? $type) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:12px;">
        Fill in the box below that matters for the rule you picked; the rest are ignored.
      </small>

      <div class="row two">
        <div>
          <label for="rule_source">Source</label>
          <select id="rule_source" name="rule_source">
            <option value="">— not used —</option>
            <?php foreach (SmsContacts::SOURCES as $source): ?>
              <option value="<?= e($source) ?>" <?= (string) ($rule['source'] ?? '') === $source ? 'selected' : '' ?>><?= e(SmsContacts::sourceLabel($source)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="rule_tag">Tag</label>
          <input type="text" id="rule_tag" name="rule_tag" list="tag-list" value="<?= e((string) ($rule['tag'] ?? '')) ?>" placeholder="e.g. worker">
          <datalist id="tag-list">
            <?php foreach ($tags as $tag): ?><option value="<?= e((string) $tag) ?>"></option><?php endforeach; ?>
          </datalist>
        </div>
      </div>

      <div class="row two">
        <div>
          <label for="rule_unit_id">Unit</label>
          <select id="rule_unit_id" name="rule_unit_id">
            <option value="">— not used —</option>
            <?php foreach ($assignableUnits as $unitId => $label): ?>
              <option value="<?= (int) $unitId ?>" <?= (int) ($rule['unit_id'] ?? 0) === (int) $unitId ? 'selected' : '' ?>><?= e((string) $label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="checkbox-row">
            <input type="checkbox" id="rule_include_subtree" name="rule_include_subtree" value="1" <?= !empty($rule['include_subtree']) ? 'checked' : '' ?>>
            <label for="rule_include_subtree" style="margin:0;">Include everything beneath it</label>
          </div>
        </div>
        <div>
          <label for="rule_days">Added in the last</label>
          <input type="number" id="rule_days" name="rule_days" min="1" max="3650" value="<?= (int) ($rule['days'] ?? 30) ?>">
          <small style="color:var(--ink-faint);font-size:12px;">Days — used by the “added recently” rule.</small>
        </div>
      </div>
    </div>

    <div class="btn-row">
      <button class="btn" type="submit"><?= $openGroup ? 'Save group' : 'Create group' ?></button>
      <?php if ($openGroup): ?><a class="btn secondary" href="/admin/sms?tab=groups">New group instead</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>Your groups</h2>
  <?php if (!$groupList): ?>
    <div class="empty">No groups yet. Create one above, or skip groups entirely and pick recipients each time in the composer.</div>
  <?php else: ?>
    <table class="resp-table">
      <tr><th>Name</th><th>Fills by</th><th>Members</th><th>Church</th><th></th></tr>
      <?php foreach ($groupList as $group): ?>
        <?php
          $dynamic = (string) $group['kind'] === 'dynamic';
          $groupRule = $dynamic ? (is_string($group['rule']) ? (json_decode((string) $group['rule'], true) ?: []) : (array) ($group['rule'] ?? [])) : [];
          $count = $dynamic ? count(SmsContacts::resolveRule($groupRule, $scopeUnitIds, (int) $group['id'])) : (int) $group['member_count'];
          $isOpen = $openGroup !== null && (int) $openGroup['id'] === (int) $group['id'];
        ?>
        <tr<?= $isOpen ? ' style="background:#ffffff08;"' : '' ?>>
          <td><strong><?= e((string) $group['name']) ?></strong></td>
          <td>
            <?php if ($dynamic): ?>
              <span class="badge info">Live rule</span>
              <div><small style="color:var(--ink-dim);"><?= e(SmsContacts::ruleSummary($groupRule)) ?></small></div>
            <?php else: ?>
              <span class="badge">Fixed list</span>
            <?php endif; ?>
          </td>
          <td><?= number_format($count) ?></td>
          <td>
            <?php if (!empty($group['org_unit_id'])): ?>
              <small style="color:var(--gold-soft);"><?= e($unitLabels[(int) $group['org_unit_id']] ?? '') ?></small>
            <?php else: ?>
              <small style="color:var(--ink-faint);">Shared</small>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <a class="btn secondary sm" href="/admin/sms?tab=groups&amp;id=<?= (int) $group['id'] ?>">Open</a>
            <a class="btn sm" href="/admin/sms?tab=compose&amp;group=<?= (int) $group['id'] ?>">Message</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete the group “<?= e((string) $group['name']) ?>”? The contacts themselves are kept.');">
              <?= Csrf::field() ?>
              <input type="hidden" name="do" value="delete_group"><input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
              <button class="btn danger sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php if ($openGroup !== null): ?>
  <div class="card">
    <h2><?= e((string) $openGroup['name']) ?> — members (<?= number_format($memberCount) ?>)</h2>

    <?php if ((string) $openGroup['kind'] === 'dynamic'): ?>
      <p class="sub">
        This group resolves live: <strong><?= e(SmsContacts::ruleSummary($rule)) ?></strong>
        Right now that is <?= number_format($memberCount) ?> contact(s). The list below is a preview
        and nothing is stored — the count is worked out again when you send.
      </p>
    <?php else: ?>
      <p class="sub">What you see here is what the group contains.</p>

      <form method="post" action="/admin/sms?tab=groups" style="margin-bottom:18px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="do" value="add_numbers">
        <input type="hidden" name="id" value="<?= (int) $openGroup['id'] ?>">
        <label for="numbers">Add numbers</label>
        <textarea id="numbers" name="numbers" rows="2" placeholder="08031234567, 0803 765 4321, +2348030000000"></textarea>
        <small style="color:var(--ink-faint);font-size:12px;display:block;margin:-10px 0 12px;">
          Separate them with commas, spaces or new lines. A number that is not in the address book yet
          is added to it — typing a number into a group is a deliberate act.
        </small>
        <button class="btn secondary sm" type="submit">Add these numbers</button>
      </form>

      <form method="post" action="/admin/sms?tab=groups" style="border-top:1px solid var(--border);padding-top:16px;margin-bottom:18px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="do" value="add_filtered">
        <input type="hidden" name="id" value="<?= (int) $openGroup['id'] ?>">
        <label>Add everyone matching a filter</label>
        <div class="row two">
          <div>
            <select name="filter_source">
              <option value="">Any source</option>
              <?php foreach (SmsContacts::SOURCES as $source): ?>
                <option value="<?= e($source) ?>"><?= e(SmsContacts::sourceLabel($source)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <input type="text" name="filter_tag" list="tag-list" placeholder="Any tag (leave blank for all)">
          </div>
        </div>
        <div class="row two">
          <div>
            <select name="filter_unit_id">
              <option value="">Any unit</option>
              <?php foreach ($assignableUnits as $unitId => $label): ?>
                <option value="<?= (int) $unitId ?>"><?= e((string) $label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <div class="checkbox-row">
              <input type="checkbox" id="filter_include_subtree" name="filter_include_subtree" value="1">
              <label for="filter_include_subtree" style="margin:0;">Include sub-units of that unit</label>
            </div>
          </div>
        </div>
        <small style="color:var(--ink-faint);font-size:12px;display:block;margin-bottom:12px;">
          Opted-out contacts are never added. This only ever adds — it does not clear the group.
        </small>
        <button class="btn secondary sm" type="submit">Find and add</button>
      </form>
    <?php endif; ?>

    <?php if (!$members): ?>
      <div class="empty"><?= (string) $openGroup['kind'] === 'dynamic' ? 'The rule matches nobody at the moment.' : 'This group is empty.' ?></div>
    <?php else: ?>
      <table class="resp-table">
        <tr><th>Name</th><th>Number</th><th>Source</th><th>Tags</th><?php if ((string) $openGroup['kind'] !== 'dynamic'): ?><th></th><?php endif; ?></tr>
        <?php foreach ($members as $member): ?>
          <tr>
            <td><?= e((string) ($member['name'] ?: '—')) ?></td>
            <td><code><?= e(Sms::prettyMsisdn((string) $member['msisdn'])) ?></code></td>
            <td><small style="color:var(--ink-faint);"><?= e(SmsContacts::sourceLabel((string) $member['source'])) ?></small></td>
            <td><small style="color:var(--ink-dim);"><?= e((string) ($member['tags'] ?: '')) ?></small></td>
            <?php if ((string) $openGroup['kind'] !== 'dynamic'): ?>
              <td>
                <form method="post" style="margin:0;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="do" value="remove_member">
                  <input type="hidden" name="id" value="<?= (int) $openGroup['id'] ?>">
                  <input type="hidden" name="contact_id" value="<?= (int) $member['id'] ?>">
                  <button class="btn danger sm" type="submit">Remove</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($memberCount > count($members)): ?>
        <p class="sub" style="font-size:12px;margin-top:10px;">
          Showing the first <?= number_format(count($members)) ?> of <?= number_format($memberCount) ?>.
          Use the composer if you need the whole list — it always uses everyone.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
