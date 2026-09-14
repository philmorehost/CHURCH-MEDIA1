<?php
declare(strict_types=1);

/**
 * Duty roster — services, the roles that need filling, and who is filling them.
 *
 * Shape: a list of services, a form for one service, and a roster page per service where roles and
 * people are managed. The roster page is where the real work happens, so it is the one that shows
 * what is still missing rather than only what is arranged.
 *
 * Two things this screen deliberately does that a simpler one would not:
 *
 *  - **An invitation is not counted as covered.** "Still to find" counts confirmed people only, and
 *    invited people are shown separately, because a planner who reads *invited* as *filled* stops
 *    looking before anyone has answered.
 *  - **A decline is visible, not removed.** The row stays on the page in red so the planner can see
 *    who said no and go and ask somebody else, which is the actual next action.
 *
 * Access is admin/editor, matching Attendance: this is operational coordination. Scope is enforced on
 * every write through `ServiceRoster::canEdit()`, so a guessed id cannot reach another church's
 * roster even though the forms post ids.
 */

Auth::requireRole('admin', 'editor');
$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$action = (string) ($_GET['action'] ?? 'list');
$id = (int) ($_GET['id'] ?? 0);
$errors = array();

$unitOptions = array();
foreach (Unit::assignableScope($user) as $unit) {
    $unitOptions[(int) $unit['id']] = Unit::optionLabel($unit);
}
$defaultUnit = (int) ($user['org_unit_id'] ?? 0);
$today = date('Y-m-d');

/** Refuses a plan the user cannot touch, then redirects. Returns the plan when allowed. */
$guardPlan = static function (int $planId) use ($user): array {
    $plan = ServiceRoster::find($planId);
    if ($plan === null || !ServiceRoster::inScope($user, $plan)) {
        flash('error', 'That service is not one you can change.');
        redirect('/admin/roster');
    }
    return $plan;
};

/* ============================================================== service writes == */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    if ($id > 0) {
        $guardPlan($id);
    } elseif (!empty($_POST['org_unit_id']) && !isset($unitOptions[(int) $_POST['org_unit_id']])) {
        // A unit outside the user's assignable scope would create a roster they cannot then see.
        $errors = array('Choose one of your own churches for this service.');
    }

    if (!$errors) {
        $result = ServiceRoster::savePlan($id, array(
            'title' => (string) ($_POST['title'] ?? ''),
            'service_date' => (string) ($_POST['service_date'] ?? ''),
            'service_time' => (string) ($_POST['service_time'] ?? ''),
            'location' => (string) ($_POST['location'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''),
            'is_cancelled' => !empty($_POST['is_cancelled']),
            // A scoped admin's form has no unit picker, so their own church fills the gap rather
            // than the service landing with no church at all — which is invisible to them.
            'org_unit_id' => (int) ($_POST['org_unit_id'] ?? 0) ?: $defaultUnit,
            'created_by' => (int) ($user['id'] ?? 0),
        ));

        if (empty($result['ok'])) {
            $errors = $result['errors'] ?? array('Could not save that service.');
            $id = (int) ($_POST['id'] ?? $id);
        } else {
            flash('success', $id > 0 ? 'Service updated.' : 'Service added — now add the roles that need filling.');
            redirect('/admin/roster?action=roster&id=' . (int) $result['id']);
        }
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $guardPlan($id);
    ServiceRoster::deletePlan($id);
    flash('success', 'Service deleted, along with its roster.');
    redirect('/admin/roster');
}

/* ================================================================= role writes == */
if ($action === 'save-role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $guardPlan($planId);

    $result = ServiceRoster::saveRole($planId, $roleId, array(
        'name' => (string) ($_POST['name'] ?? ''),
        'slots_needed' => (string) ($_POST['slots_needed'] ?? '1'),
        'notes' => (string) ($_POST['notes'] ?? ''),
    ));

    if (empty($result['ok'])) {
        flash('error', implode(' ', $result['errors'] ?? array('Could not save that role.')));
    } else {
        flash('success', $roleId > 0 ? 'Role updated.' : 'Role added.');
    }
    redirect('/admin/roster?action=roster&id=' . $planId);
}

if ($action === 'delete-role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $role = ServiceRoster::findRole($roleId);
    if ($role === null) {
        flash('error', 'That role no longer exists.');
        redirect('/admin/roster');
    }
    $planId = (int) $role['plan_id'];
    $guardPlan($planId);

    if (ServiceRoster::assignments($roleId)) {
        // Removing a role with people on it would silently drop them, so it is refused rather than
        // confirmed: the planner should move them somewhere first, which is a decision, not a click.
        flash('error', 'Take the people off "' . $role['name'] . '" first — removing the role would drop them silently.');
    } else {
        ServiceRoster::deleteRole($roleId);
        flash('success', 'Role removed.');
    }
    redirect('/admin/roster?action=roster&id=' . $planId);
}

/* =========================================================== assignment writes == */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $role = ServiceRoster::findRole($roleId);
    if ($role === null) {
        flash('error', 'That role no longer exists.');
        redirect('/admin/roster');
    }
    $planId = (int) $role['plan_id'];
    $guardPlan($planId);

    $result = ServiceRoster::assign($roleId, array(
        'member_id' => (string) ($_POST['member_id'] ?? ''),
        'person_name' => (string) ($_POST['person_name'] ?? ''),
        'person_phone' => (string) ($_POST['person_phone'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
    ));

    if (empty($result['ok'])) {
        flash('error', implode(' ', $result['errors'] ?? array('Could not add that person.')));
    } else {
        flash('success', 'Added to ' . $role['name'] . '.');
    }
    redirect('/admin/roster?action=roster&id=' . $planId);
}

if (($action === 'unassign' || $action === 'respond') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $assignment = ServiceRoster::findAssignment($assignmentId);
    if ($assignment === null) {
        flash('error', 'That slot no longer exists.');
        redirect('/admin/roster');
    }
    $planId = (int) $assignment['plan_id'];
    $guardPlan($planId);

    if ($action === 'unassign') {
        ServiceRoster::unassign($assignmentId);
        flash('success', $assignment['person_name'] . ' removed from ' . $assignment['role_name'] . '.');
    } else {
        $status = (string) ($_POST['status'] ?? '');
        $result = ServiceRoster::respond($assignmentId, $status);
        flash(
            empty($result['ok']) ? 'error' : 'success',
            empty($result['ok'])
                ? implode(' ', $result['errors'] ?? array('Could not record that answer.'))
                : $assignment['person_name'] . ' marked as ' . $status . '.'
        );
    }
    redirect('/admin/roster?action=roster&id=' . $planId);
}

/* ==================================================================== load view == */
$editing = null;
if ($action === 'edit' && $id > 0) {
    $editing = $guardPlan($id);
} elseif ($action === 'edit') {
    // A brand-new service, pre-filled with the next Sunday — the common case by a wide margin.
    $editing = array(
        'id' => 0,
        'title' => 'Sunday Service',
        'service_date' => date('Y-m-d', strtotime('next sunday')),
        'service_time' => '8:00 AM',
        'location' => '',
        'notes' => '',
        'is_cancelled' => 0,
        'org_unit_id' => $defaultUnit,
    );
}

$plan = null;
$roster = array();
$shortfall = array();
$totals = array('needed' => 0, 'filled' => 0, 'open' => 0);
if ($action === 'roster' && $id > 0) {
    $plan = $guardPlan($id);
    $roster = ServiceRoster::roster($id);
    $shortfall = ServiceRoster::shortfall($roster);
    $totals = ServiceRoster::totals($roster);
}

$scope = (string) ($_GET['scope'] ?? 'upcoming');
$plans = array();
if ($action === 'list') {
    $plans = ServiceRoster::listPlans($user, in_array($scope, array('upcoming', 'past', 'all'), true) ? $scope : 'upcoming');
    foreach ($plans as &$row) {
        $row['totals'] = ServiceRoster::totals(ServiceRoster::roster((int) $row['id']));
    }
    unset($row);
}

$members = ServiceRoster::assignableMembers($user);

$pageTitle = 'Duty Roster';
$activeNav = 'roster';
require __DIR__ . '/partials/layout-open.php';
?>

<?php if ($errors): ?>
  <div class="alert error">
    <?php foreach ($errors as $error): ?>
      <div><?= e($error) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($action === 'edit'): ?>
  <?php $field = static fn (string $key, string $fallback = '') => (string) ($_POST[$key] ?? ($editing[$key] ?? $fallback)); ?>
  <h2><?= $editing['id'] > 0 ? 'Edit Service' : 'Add a Service' ?></h2>
  <p class="sub">The occasion somebody has to be on the door for — not an event you are publicising.</p>

  <form method="post" action="/admin/roster?action=save<?= $editing['id'] > 0 ? '&id=' . (int) $editing['id'] : '' ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

    <div class="row">
      <div>
        <label for="title">Service name</label>
        <input type="text" id="title" name="title" maxlength="<?= ServiceRoster::MAX_TITLE ?>"
               value="<?= e($field('title')) ?>" placeholder="Sunday 1st Service" required>
      </div>
      <div>
        <label for="service_date">Date</label>
        <input type="date" id="service_date" name="service_date" value="<?= e($field('service_date')) ?>" required>
      </div>
    </div>

    <div class="row">
      <div>
        <label for="service_time">Time</label>
        <input type="text" id="service_time" name="service_time" maxlength="<?= ServiceRoster::MAX_TIME ?>"
               value="<?= e($field('service_time')) ?>" placeholder="8:00 AM">
      </div>
      <div>
        <label for="location">Where</label>
        <input type="text" id="location" name="location" maxlength="<?= ServiceRoster::MAX_LOCATION ?>"
               value="<?= e($field('location')) ?>">
      </div>
    </div>

    <?php if (count($unitOptions) > 1): ?>
      <label for="org_unit_id">Church</label>
      <select id="org_unit_id" name="org_unit_id">
        <option value="">— church-wide —</option>
        <?php foreach ($unitOptions as $unitId => $label): ?>
          <option value="<?= (int) $unitId ?>" <?= (int) $field('org_unit_id') === $unitId ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <label for="notes">Notes for the team</label>
    <textarea id="notes" name="notes" rows="3"><?= e($field('notes')) ?></textarea>

    <?php if ($editing['id'] > 0): ?>
      <label style="display:flex;align-items:flex-start;gap:10px;margin-top:10px;">
        <input type="checkbox" name="is_cancelled" value="1" <?= !empty($field('is_cancelled')) ? 'checked' : '' ?> style="margin-top:3px;">
        <span>
          <strong>This service is cancelled</strong><br>
          <span style="font-size:12.5px;color:var(--muted,#8b87a8);">
            Keeps the record but takes it off “who is serving” and out of the reminder worker.
          </span>
        </span>
      </label>
    <?php endif; ?>

    <div style="margin-top:20px;display:flex;gap:10px;">
      <button class="btn" type="submit"><?= $editing['id'] > 0 ? 'Save Changes' : 'Add Service' ?></button>
      <a class="btn secondary" href="/admin/roster">Cancel</a>
    </div>
  </form>

<?php elseif ($action === 'roster' && $plan !== null): ?>
  <h2><?= e((string) $plan['title']) ?></h2>
  <p class="sub">
    <?= e(ServiceRoster::dateLabel((string) $plan['service_date'])) ?><?php
      if (trim((string) $plan['service_time']) !== '') echo ' · ' . e((string) $plan['service_time']);
      if (trim((string) $plan['location'] ?? '') !== '') echo ' · ' . e((string) $plan['location']);
    ?>
    <?php if (!empty($plan['is_cancelled'])): ?><strong style="color:var(--danger,#ff6b6b);"> · CANCELLED</strong><?php endif; ?>
  </p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;">
    <a class="btn secondary" href="/admin/roster?action=edit&id=<?= (int) $plan['id'] ?>">Edit service details</a>
    <a class="btn secondary" href="/admin/roster">All services</a>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <strong>
      <?= (int) $totals['filled'] ?> of <?= (int) $totals['needed'] ?> slots confirmed
    </strong>
    <?php if ($totals['open'] > 0): ?>
      <span style="color:var(--warn,#d9a441);"> · <?= (int) $totals['open'] ?> still to find</span>
    <?php else: ?>
      <span style="color:var(--ok,#3fbf7f);"> · fully covered</span>
    <?php endif; ?>

    <?php if ($shortfall): ?>
      <div style="margin-top:12px;font-size:13.5px;color:var(--muted,#8b87a8);">
        Still short:
        <?= e(implode(', ', array_map(static fn (array $r): string => $r['name'] . ' (' . $r['open'] . ')', $shortfall))) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php foreach ($roster as $role): ?>
    <div class="card" style="margin-bottom:16px;">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
        <strong style="font-size:16px;">
          <?= e((string) $role['name']) ?>
          <span style="color:var(--muted,#8b87a8);font-weight:400;">
            · <?= (int) $role['accepted'] ?>/<?= (int) $role['slots_needed'] ?> confirmed
          </span>
          <?php if ($role['open'] > 0): ?>
            <span style="color:var(--warn,#d9a441);font-weight:400;"> · <?= (int) $role['open'] ?> to find</span>
          <?php endif; ?>
        </strong>
        <form method="post" action="/admin/roster?action=delete-role" style="margin:0;">
          <?= Csrf::field() ?>
          <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
          <button class="btn secondary" type="submit">Remove role</button>
        </form>
      </div>

      <?php if (trim((string) $role['notes']) !== ''): ?>
        <p class="sub" style="margin:6px 0 0;font-size:13px;"><?= e((string) $role['notes']) ?></p>
      <?php endif; ?>

      <?php if ($role['people']): ?>
        <table class="table" style="margin-top:12px;">
          <thead><tr><th>Person</th><th>Contact</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($role['people'] as $person): ?>
              <tr>
                <td>
                  <?= e((string) $person['person_name']) ?>
                  <?php if (!empty($person['member_id'])): ?>
                    <span style="font-size:12px;color:var(--muted,#8b87a8);"> · member</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= trim((string) $person['phone_display']) !== '' ? e((string) $person['phone_display']) : '—' ?>
                </td>
                <td>
                  <?php if ($person['status'] === 'accepted'): ?>
                    <span style="color:var(--ok,#3fbf7f);">Accepted</span>
                  <?php elseif ($person['status'] === 'declined'): ?>
                    <span style="color:var(--danger,#ff6b6b);">Declined</span>
                  <?php else: ?>
                    <span style="color:var(--warn,#d9a441);">Invited — no reply yet</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <?php foreach (array('accepted' => 'Accepted', 'declined' => 'Declined', 'invited' => 'Awaiting') as $status => $label): ?>
                    <?php if ($person['status'] !== $status): ?>
                      <form method="post" action="/admin/roster?action=respond" style="display:inline;margin:0;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="assignment_id" value="<?= (int) $person['id'] ?>">
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <button class="btn secondary" type="submit" style="padding:2px 8px;font-size:12.5px;"><?= e($label) ?></button>
                      </form>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <form method="post" action="/admin/roster?action=unassign" style="display:inline;margin:0;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="assignment_id" value="<?= (int) $person['id'] ?>">
                    <button class="btn secondary" type="submit" style="padding:2px 8px;font-size:12.5px;">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="sub" style="margin:10px 0 0;font-size:13px;">Nobody on this role yet.</p>
      <?php endif; ?>

      <form method="post" action="/admin/roster?action=assign" style="margin-top:14px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
        <div class="row">
          <div>
            <label for="member_<?= (int) $role['id'] ?>">Add a member</label>
            <select id="member_<?= (int) $role['id'] ?>" name="member_id">
              <option value="">— or type a name below —</option>
              <?php foreach ($members as $member): ?>
                <option value="<?= (int) $member['id'] ?>"><?= e((string) $member['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="name_<?= (int) $role['id'] ?>">Or somebody not registered</label>
            <input type="text" id="name_<?= (int) $role['id'] ?>" name="person_name" maxlength="<?= ServiceRoster::MAX_NAME ?>"
                   placeholder="Ushers and choir members are often not members yet">
          </div>
        </div>
        <div class="row">
          <div>
            <label for="phone_<?= (int) $role['id'] ?>">Their contact number</label>
            <input type="text" id="phone_<?= (int) $role['id'] ?>" name="person_phone" maxlength="<?= Phone::MAX_LENGTH ?>"
                   placeholder="0803 123 4567">
          </div>
        </div>
        <button class="btn" type="submit" style="margin-top:10px;">Add to <?= e((string) $role['name']) ?></button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <h3 style="margin-top:0;">Add a role</h3>
    <form method="post" action="/admin/roster?action=save-role">
      <?= Csrf::field() ?>
      <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
      <input type="hidden" name="role_id" value="0">
      <div class="row">
        <div>
          <label for="new_role_name">Role</label>
          <input type="text" id="new_role_name" name="name" maxlength="<?= ServiceRoster::MAX_ROLE ?>"
                 list="role-presets" placeholder="Ushering" required>
          <datalist id="role-presets">
            <?php foreach (ServiceRoster::ROLE_PRESETS as $preset): ?>
              <option value="<?= e($preset) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label for="new_role_slots">How many people</label>
          <input type="number" id="new_role_slots" name="slots_needed" min="1" max="<?= ServiceRoster::MAX_SLOTS ?>" value="1">
        </div>
      </div>
      <button class="btn" type="submit" style="margin-top:10px;">Add role</button>
    </form>
  </div>

<?php else: ?>
  <h2>Duty Roster</h2>
  <p class="sub">Who is serving, and what still needs filling. Add a service, then its roles.</p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;">
    <a class="btn" href="/admin/roster?action=edit">Add a Service</a>
    <?php foreach (array('upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All') as $key => $label): ?>
      <a class="btn secondary" href="/admin/roster?scope=<?= e($key) ?>"
         style="<?= $scope === $key ? 'border-color:var(--gold);' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$plans): ?>
    <p class="sub">
      <?= $scope === 'past' ? 'No services in the past yet.' : 'No services yet — add the next Sunday to get started.' ?>
    </p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Service</th><th>When</th><th>Coverage</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($plans as $row): ?>
          <tr>
            <td>
              <strong><?= e((string) $row['title']) ?></strong>
              <?php if (!empty($row['is_cancelled'])): ?>
                <span style="color:var(--danger,#ff6b6b);font-size:12.5px;"> · cancelled</span>
              <?php endif; ?>
              <?php if (trim((string) $row['location'] ?? '') !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $row['location']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= e(ServiceRoster::dateLabel((string) $row['service_date'])) ?>
              <?php if (trim((string) $row['service_time']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $row['service_time']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['totals']['needed'] === 0): ?>
                <span style="color:var(--muted,#8b87a8);">no roles yet</span>
              <?php elseif ($row['totals']['open'] === 0): ?>
                <span style="color:var(--ok,#3fbf7f);">fully covered</span>
                <div style="font-size:12px;color:var(--muted,#8b87a8);">
                  <?= (int) $row['totals']['filled'] ?>/<?= (int) $row['totals']['needed'] ?>
                </div>
              <?php else: ?>
                <span style="color:var(--warn,#d9a441);"><?= (int) $row['totals']['open'] ?> to find</span>
                <div style="font-size:12px;color:var(--muted,#8b87a8);">
                  <?= (int) $row['totals']['filled'] ?>/<?= (int) $row['totals']['needed'] ?> confirmed
                </div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn secondary" href="/admin/roster?action=roster&id=<?= (int) $row['id'] ?>">Open roster</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p class="sub" style="margin-top:20px;">
      <?php if ($scope === 'upcoming'): ?>
        Showing services from today onward. <a href="/admin/roster?scope=past">See past services.</a>
      <?php elseif ($scope === 'past'): ?>
        Showing services that have already happened. <a href="/admin/roster">Back to upcoming.</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
