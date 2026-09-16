<?php
declare(strict_types=1);

/**
 * Follow-up — the plan for a first-time visitor, and whether it is actually happening.
 *
 * Three screens:
 *
 *  - **the hub** at /admin/follow-up — the pipeline, who has stalled, the tasks nobody has done yet,
 *    and the sequences themselves;
 *  - **the sequence editor** — the steps, in the order they happen;
 *  - **one visitor** — everything planned for them, what has gone out, and how to stop it.
 *
 * The hub leads with the two things that go wrong quietly rather than loudly: a visitor nobody has
 * moved for a fortnight, and a send that was refused. Both are invisible on a screen that only shows
 * "sequences", so neither is hidden behind a tab.
 *
 * Access is admin/editor, matching Newcomers and Attendance: this is operational coordination, not
 * site configuration. Every write goes through a scope guard, so a guessed id cannot reach another
 * church's people even though the forms post plain ids.
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

/** Refuses a sequence outside the user's scope, then redirects. Returns it when allowed. */
$guardSequence = static function (int $sequenceId) use ($user): array {
    $sequence = FollowUp::findSequence($sequenceId);
    if ($sequence === null || !FollowUp::inScope($user, $sequence)) {
        flash('error', 'That follow-up sequence is not one you can change.');
        redirect('/admin/follow-up');
    }
    return $sequence;
};

/** Refuses a newcomer outside the user's scope. `newcomers` is already per-church, so this is strict. */
$guardNewcomer = static function (int $newcomerId) use ($pdo, $user): array {
    $stmt = $pdo->prepare('SELECT * FROM newcomers WHERE id = ?');
    $stmt->execute([$newcomerId]);
    $newcomer = $stmt->fetch();
    if ($newcomer === false || !Unit::inScope($user, $newcomer['org_unit_id'] === null ? null : (int) $newcomer['org_unit_id'])) {
        flash('error', 'That newcomer is not one you can see.');
        redirect('/admin/newcomers');
    }
    return $newcomer;
};

/* ============================================================== sequence writes == */

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($id > 0) {
        $guardSequence($id);
    }

    $result = FollowUp::saveSequence($user, $id, array(
        'name' => (string) ($_POST['name'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'is_active' => !empty($_POST['is_active']),
        'org_unit_id' => (int) ($_POST['org_unit_id'] ?? 0) ?: $defaultUnit,
    ));

    if (empty($result['ok'])) {
        $errors = $result['errors'] ?? array('Could not save that sequence.');
        $action = 'edit';
    } else {
        flash('success', $id > 0 ? 'Sequence updated.' : 'Sequence added — now add the steps.');
        redirect('/admin/follow-up?action=edit&id=' . (int) $result['id']);
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $guardSequence($id);
    $result = FollowUp::deleteSequence($id);
    flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
        ? implode(' ', $result['errors'] ?? array('Could not delete that sequence.'))
        : 'Sequence deleted.');
    redirect('/admin/follow-up');
}

/* ================================================================== step writes == */

if ($action === 'save-step' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $sequenceId = (int) ($_POST['sequence_id'] ?? 0);
    $stepId = (int) ($_POST['step_id'] ?? 0);
    $guardSequence($sequenceId);

    // Editing the day or the wording of a step people have already received is allowed — the record of
    // what was sent is on the action row, so changing the template does not rewrite history.
    $result = FollowUp::saveStep($sequenceId, $stepId, array(
        'day_offset' => (int) ($_POST['day_offset'] ?? 0),
        'channel' => (string) ($_POST['channel'] ?? 'email'),
        'subject' => (string) ($_POST['subject'] ?? ''),
        'body' => (string) ($_POST['body'] ?? ''),
        'task_label' => (string) ($_POST['task_label'] ?? ''),
        'is_active' => !empty($_POST['is_active']),
    ));

    if (empty($result['ok'])) {
        flash('error', implode(' ', $result['errors'] ?? array('Could not save that step.')));
    } else {
        flash('success', $stepId > 0 ? 'Step updated.' : 'Step added.');
    }
    redirect('/admin/follow-up?action=edit&id=' . $sequenceId);
}

if ($action === 'delete-step' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $stepId = (int) ($_POST['step_id'] ?? 0);
    $step = FollowUp::findStep($stepId);
    if ($step === null) {
        flash('error', 'That step no longer exists.');
        redirect('/admin/follow-up');
    }
    $guardSequence((int) $step['sequence_id']);

    $result = FollowUp::deleteStep($stepId);
    flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
        ? implode(' ', $result['errors'] ?? array('Could not remove that step.'))
        : 'Step removed.');
    redirect('/admin/follow-up?action=edit&id=' . (int) $step['sequence_id']);
}

/** Fills an empty sequence with a sensible starting plan rather than leaving a blank form. */
if ($action === 'starter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $sequenceId = (int) ($_POST['sequence_id'] ?? 0);
    $guardSequence($sequenceId);

    if (FollowUp::steps($sequenceId)) {
        // Refused rather than appended: the button reads as "start me off", and quietly adding four
        // more steps to a sequence somebody has already written is not that.
        flash('error', 'That sequence already has steps, so nothing was added.');
    } else {
        foreach (FollowUp::starterSteps() as $step) {
            FollowUp::saveStep($sequenceId, 0, $step);
        }
        flash('success', 'A four-step starting plan was added — edit the wording to sound like you.');
    }
    redirect('/admin/follow-up?action=edit&id=' . $sequenceId);
}

/* ============================================================ enrolment writes == */

if ($action === 'enrol' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $newcomerId = (int) ($_POST['newcomer_id'] ?? 0);
    $sequenceId = (int) ($_POST['sequence_id'] ?? 0);
    $newcomer = $guardNewcomer($newcomerId);
    $guardSequence($sequenceId);

    $result = FollowUp::enrol($newcomerId, $sequenceId, array(
        'enrolled_on' => (string) ($_POST['enrolled_on'] ?? ''),
        'enrolled_by' => (int) ($user['id'] ?? 0),
    ));

    if (empty($result['ok'])) {
        flash('error', implode(' ', $result['errors'] ?? array('Could not enrol them.')));
    } elseif (!empty($result['existing'])) {
        flash('success', $newcomer['name'] . ' was already in that sequence — nothing changed.');
    } else {
        flash('success', $newcomer['name'] . ' is now being followed up. The worker will send the first step.');
    }
    redirect('/admin/follow-up?action=person&id=' . $newcomerId);
}

if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $enrolmentId = (int) ($_POST['enrolment_id'] ?? 0);
    $enrolment = FollowUp::findEnrolment($enrolmentId);
    if ($enrolment === null) {
        flash('error', 'That enrolment no longer exists.');
        redirect('/admin/follow-up');
    }
    $guardSequence((int) $enrolment['sequence_id']);
    $guardNewcomer((int) $enrolment['newcomer_id']);

    FollowUp::stop($enrolmentId, (string) ($_POST['reason'] ?? ''), (int) ($user['id'] ?? 0));
    flash('success', 'Stopped. Nothing further will be sent, and the tasks come off the list.');
    redirect('/admin/follow-up?action=person&id=' . (int) $enrolment['newcomer_id']);
}

/* ================================================================ task writes == */

if ($action === 'done' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $actionId = (int) ($_POST['action_id'] ?? 0);

    // Scope is checked through the newcomer, because a task id is not a thing anyone owns.
    $stmt = $pdo->prepare(
        'SELECT a.id, e.newcomer_id FROM follow_up_actions a'
        . ' JOIN follow_up_enrolments e ON e.id = a.enrolment_id'
        . ' WHERE a.id = ?'
    );
    $stmt->execute([$actionId]);
    $task = $stmt->fetch();
    if ($task === false) {
        flash('error', 'That task no longer exists.');
        redirect('/admin/follow-up');
    }
    $guardNewcomer((int) $task['newcomer_id']);

    $result = FollowUp::completeTask($actionId, (int) ($user['id'] ?? 0), (string) ($_POST['note'] ?? ''));
    flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
        ? implode(' ', $result['errors'] ?? array('Could not tick that off.'))
        : 'Ticked off.');
    redirect('/admin/follow-up' . (!empty($_POST['back']) ? '?action=person&id=' . (int) $_POST['back'] : ''));
}

/* ==================================================================== load view == */

$editing = null;
$steps = array();
if ($action === 'edit' && $id > 0) {
    $editing = $guardSequence($id);
    $steps = FollowUp::steps($id);
} elseif ($action === 'edit') {
    $editing = array(
        'id' => 0,
        'name' => '',
        'description' => '',
        'is_active' => 1,
        'org_unit_id' => $defaultUnit,
    );
}

$person = null;
$personEnrolments = array();
$personTimeline = array();
if ($action === 'person' && $id > 0) {
    $person = $guardNewcomer($id);
    $personEnrolments = FollowUp::enrolmentsFor($id);
    foreach ($personEnrolments as &$enrolmentRow) {
        $enrolmentRow['timeline'] = FollowUp::timeline((int) $enrolmentRow['id']);
    }
    unset($enrolmentRow);
}

$sequences = array();
$pipeline = array();
$stalled = array();
$tasks = array();
$unreachable = array();
$failures = array();
$sequenceOptions = array();
if ($action === 'list') {
    $sequences = FollowUp::sequences($user, true);
    foreach ($sequences as &$sequenceRow) {
        $sequenceRow['first_step'] = FollowUp::steps((int) $sequenceRow['id']);
    }
    unset($sequenceRow);
    $pipeline = FollowUp::pipeline($user);
    $stalled = FollowUp::stalled($user, 20);
    $tasks = FollowUp::openTasks($user, 50);
    $unreachable = FollowUp::unreachable($user, 20);
    $failures = FollowUpRunner::failures($user, 20);
}
if ($action === 'person') {
    foreach (FollowUp::sequences($user) as $choice) {
        $sequenceOptions[(int) $choice['id']] = (string) $choice['name'];
    }
}

$pageTitle = 'Follow-up';
$activeNav = 'follow-up';
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
  <?php $field = static function (string $key, string $fallback = '') use ($editing): string {
      return (string) ($_POST[$key] ?? ($editing[$key] ?? $fallback));
  }; ?>

  <h2><?= $editing['id'] > 0 ? 'Edit Sequence' : 'New Sequence' ?></h2>
  <p class="sub">The plan for a first-time visitor. Add steps in the order they should happen.</p>

  <form method="post" action="/admin/follow-up?action=save<?= $editing['id'] > 0 ? '&id=' . (int) $editing['id'] : '' ?>">
    <?= Csrf::field() ?>
    <div class="row">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" maxlength="<?= FollowUp::MAX_NAME ?>" value="<?= e($field('name')) ?>" placeholder="First visit follow-up" required>
      </div>
      <div>
        <label for="description">What it is for (optional)</label>
        <input type="text" id="description" name="description" maxlength="<?= FollowUp::MAX_DESCRIPTION ?>" value="<?= e($field('description')) ?>">
      </div>
    </div>

    <?php if (count($unitOptions) > 1): ?>
      <label for="org_unit_id">Church</label>
      <select id="org_unit_id" name="org_unit_id">
        <?php foreach ($unitOptions as $unitId => $label): ?>
          <option value="<?= (int) $unitId ?>" <?= (int) $field('org_unit_id') === $unitId ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <label style="display:flex;align-items:center;gap:10px;margin-top:10px;">
      <input type="checkbox" name="is_active" value="1" <?= !empty($field('is_active')) ? 'checked' : '' ?>>
      <span>Running — people in this sequence are emailed and tasks are created</span>
    </label>

    <div style="margin-top:14px;display:flex;gap:10px;">
      <button class="btn" type="submit">Save</button>
      <a class="btn secondary" href="/admin/follow-up">Cancel</a>
    </div>
  </form>

  <?php if ($editing['id'] > 0): ?>
    <h2 style="font-size:16px;margin:28px 0 6px 0;">Steps</h2>
    <p class="sub">
      An <strong>email</strong> goes out on its own. A <strong>task</strong> appears on this page for
      somebody to do. Use <code>{{first_name}}</code>, <code>{{name}}</code> and
      <code>{{church}}</code> to personalise the wording.
    </p>

    <?php if (!$steps): ?>
      <div class="card empty" style="margin-bottom:16px;">
        <p>No steps yet, so nobody would ever hear anything.</p>
        <form method="post" action="/admin/follow-up?action=starter">
          <?= Csrf::field() ?>
          <input type="hidden" name="sequence_id" value="<?= (int) $editing['id'] ?>">
          <button class="btn" type="submit">Add a four-step starting plan</button>
        </form>
      </div>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>When</th><th>What</th><th>Wording</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($steps as $step): ?>
            <tr>
              <td style="white-space:nowrap;">
                <?= e(FollowUp::offsetLabel((int) $step['day_offset'])) ?>
                <?php if (empty($step['is_active'])): ?>
                  <div style="font-size:12px;color:var(--muted,#8b87a8);">switched off</div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($step['channel'] === 'email'): ?>
                  <span style="color:var(--gold-soft);">Email</span>
                <?php else: ?>
                  <span style="color:var(--info,#6fb3ff);">Task</span>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= e((string) ($step['channel'] === 'email' ? $step['subject'] : $step['task_label'])) ?></strong>
                <?php if ($step['channel'] === 'email' && trim((string) $step['body']) !== ''): ?>
                  <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e(mb_substr(trim((string) $step['body']), 0, 110)) ?>…</div>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap;">
                <a class="btn sm secondary" href="/admin/follow-up?action=edit&id=<?= (int) $editing['id'] ?>&step=<?= (int) $step['id'] ?>#step-form">Edit</a>
                <form method="post" action="/admin/follow-up?action=delete-step" style="display:inline;" onsubmit="return confirm('Remove this step?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
                  <button class="btn sm secondary" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php
    $stepId = (int) ($_GET['step'] ?? 0);
    $editingStep = $stepId > 0 ? FollowUp::findStep($stepId) : null;
    if ($editingStep !== null && (int) $editingStep['sequence_id'] !== (int) $editing['id']) {
        // A step id from another sequence must not prefill this form.
        $editingStep = null;
    }
    $stepField = static function (string $key, string $fallback = '') use ($editingStep): string {
        return (string) ($_POST[$key] ?? ($editingStep[$key] ?? $fallback));
    };
    $stepChannel = (string) ($_POST['channel'] ?? ($editingStep['channel'] ?? 'email'));
    ?>
    <h3 id="step-form" style="font-size:15px;margin:22px 0 6px 0;"><?= $editingStep ? 'Edit step' : 'Add a step' ?></h3>
    <form method="post" action="/admin/follow-up?action=save-step">
      <?= Csrf::field() ?>
      <input type="hidden" name="sequence_id" value="<?= (int) $editing['id'] ?>">
      <input type="hidden" name="step_id" value="<?= $editingStep ? (int) $editingStep['id'] : 0 ?>">

      <div class="row">
        <div>
          <label for="day_offset">Days after the first visit</label>
          <input type="number" id="day_offset" name="day_offset" min="0" max="<?= FollowUp::MAX_DAY_OFFSET ?>" value="<?= e($stepField('day_offset', '0')) ?>" required>
        </div>
        <div>
          <label for="channel">What kind of step</label>
          <select id="channel" name="channel">
            <option value="email" <?= $stepChannel === 'email' ? 'selected' : '' ?>>Send an email</option>
            <option value="task" <?= $stepChannel === 'task' ? 'selected' : '' ?>>Set a task for somebody</option>
          </select>
        </div>
      </div>

      <label for="subject">Email subject</label>
      <input type="text" id="subject" name="subject" maxlength="<?= FollowUp::MAX_SUBJECT ?>" value="<?= e($stepField('subject')) ?>" placeholder="Thank you for worshipping with us">

      <label for="body">Email wording</label>
      <textarea id="body" name="body" rows="6" placeholder="Dear {{first_name}}, ..."><?= e($stepField('body')) ?></textarea>

      <label for="task_label">Task</label>
      <input type="text" id="task_label" name="task_label" maxlength="<?= FollowUp::MAX_TASK ?>" value="<?= e($stepField('task_label')) ?>" placeholder="Ring {{first_name}} and see how they are settling in">
      <p class="sub" style="margin-top:4px;">Fill in the subject and wording for an email, or the task for a task. Only the one that matches the kind is used.</p>

      <label style="display:flex;align-items:center;gap:10px;margin-top:10px;">
        <input type="checkbox" name="is_active" value="1" <?= (string) $stepField('is_active', '1') !== '0' ? 'checked' : '' ?>>
        <span>This step is switched on</span>
      </label>

      <div style="margin-top:14px;">
        <button class="btn" type="submit"><?= $editingStep ? 'Save step' : 'Add step' ?></button>
        <?php if ($editingStep): ?>
          <a class="btn secondary" href="/admin/follow-up?action=edit&id=<?= (int) $editing['id'] ?>">Cancel edit</a>
        <?php endif; ?>
      </div>
    </form>
  <?php endif; ?>

<?php elseif ($action === 'person' && $person !== null): ?>
  <h2><?= e((string) $person['name']) ?></h2>
  <p class="sub">
    <?= e(FollowUp::VISIT_LABELS[(string) $person['follow_up_status']] ?? (string) $person['follow_up_status']) ?>
    · added <?= e(date('j M Y', strtotime((string) $person['created_at']))) ?>
    <?php if (trim((string) $person['email']) !== ''): ?>
      · <?= e((string) $person['email']) ?>
    <?php else: ?>
      · <strong style="color:var(--warn,#d9a441);">no email address, so email steps cannot reach them</strong>
    <?php endif; ?>
    · <a href="/admin/newcomers?action=edit&id=<?= (int) $person['id'] ?>">Edit their details</a>
  </p>

  <?php if (!$personEnrolments): ?>
    <div class="card empty" style="margin-bottom:18px;">
      <p>Nobody is following this visitor up yet.</p>
    </div>
  <?php else: ?>
    <?php foreach ($personEnrolments as $enrolmentRow): ?>
      <div class="card" style="margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div>
            <strong><?= e((string) $enrolmentRow['sequence_name']) ?></strong>
            <div style="font-size:12.5px;color:var(--muted,#8b87a8);">
              started <?= e(date('j M Y', strtotime((string) $enrolmentRow['enrolled_on']))) ?>
              ·
              <?php if ($enrolmentRow['status'] === 'active'): ?>
                <span style="color:var(--ok,#3fbf7f);">running</span>
              <?php elseif ($enrolmentRow['status'] === 'finished'): ?>
                finished
              <?php else: ?>
                <span style="color:var(--danger,#ff6b6b);">stopped</span>
                <?php if (trim((string) $enrolmentRow['stopped_reason']) !== ''): ?>
                  — <?= e((string) $enrolmentRow['stopped_reason']) ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($enrolmentRow['status'] === 'active'): ?>
            <form method="post" action="/admin/follow-up?action=stop" onsubmit="return confirm('Stop following this person up? Nothing further will be sent.');">
              <?= Csrf::field() ?>
              <input type="hidden" name="enrolment_id" value="<?= (int) $enrolmentRow['id'] ?>">
              <input type="text" name="reason" maxlength="<?= FollowUp::MAX_DESCRIPTION ?>" placeholder="Why (optional)" style="margin:0 0 6px 0;">
              <button class="btn sm secondary" type="submit">Stop</button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($enrolmentRow['timeline']): ?>
          <table class="table" style="margin-top:12px;">
            <thead><tr><th>Planned</th><th>Step</th><th>What happened</th></tr></thead>
            <tbody>
              <?php foreach ($enrolmentRow['timeline'] as $row): ?>
                <tr>
                  <td style="white-space:nowrap;"><?= e(date('j M', strtotime((string) $row['planned_on']))) ?></td>
                  <td>
                    <?php if ($row['channel'] === 'email'): ?>
                      Email: <?= e((string) $row['subject']) ?>
                    <?php else: ?>
                      Task: <?= e((string) $row['task_label']) ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($row['sent_at'] !== null): ?>
                      <span style="color:var(--ok,#3fbf7f);">sent <?= e(date('j M, g:ia', strtotime((string) $row['sent_at']))) ?></span>
                    <?php elseif ($row['done_at'] !== null): ?>
                      <span style="color:var(--ok,#3fbf7f);">done <?= e(date('j M', strtotime((string) $row['done_at']))) ?></span>
                      <?php if (trim((string) $row['note']) !== ''): ?>
                        <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $row['note']) ?></div>
                      <?php endif; ?>
                    <?php elseif ($row['error'] !== null): ?>
                      <span style="color:var(--danger,#ff6b6b);">refused — will be retried</span>
                    <?php elseif ($row['channel'] === 'task' && $row['action_id'] !== null): ?>
                      <span style="color:var(--warn,#d9a441);">waiting to be done</span>
                    <?php elseif ($row['is_overdue']): ?>
                      <span style="color:var(--warn,#d9a441);">should have gone out</span>
                    <?php else: ?>
                      <span style="color:var(--muted,#8b87a8);">not due yet</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="sub" style="margin-top:10px;">This sequence has no steps, so nothing will happen.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h3 style="font-size:15px;margin:22px 0 6px 0;">Put them into a sequence</h3>
  <?php if (!$sequenceOptions): ?>
    <p class="sub">There are no running sequences yet. <a href="/admin/follow-up?action=edit">Create one</a> first.</p>
  <?php else: ?>
    <form method="post" action="/admin/follow-up?action=enrol">
      <?= Csrf::field() ?>
      <input type="hidden" name="newcomer_id" value="<?= (int) $person['id'] ?>">
      <div class="row">
        <div>
          <label for="sequence_id">Sequence</label>
          <select id="sequence_id" name="sequence_id">
            <?php foreach ($sequenceOptions as $sequenceId => $label): ?>
              <option value="<?= (int) $sequenceId ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="enrolled_on">Starting from</label>
          <input type="date" id="enrolled_on" name="enrolled_on" value="<?= e(date('Y-m-d')) ?>">
          <p class="sub" style="margin-top:4px;">Leave it as today unless you are catching up on last month's visitors — the days are counted from here.</p>
        </div>
      </div>
      <button class="btn" type="submit">Start following them up</button>
    </form>
  <?php endif; ?>

<?php else: ?>
  <h2>Follow-up</h2>
  <p class="sub">What happens after somebody visits for the first time — and whether it is happening.</p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;">
    <a class="btn" href="/admin/follow-up?action=edit">New Sequence</a>
    <a class="btn secondary" href="/admin/newcomers">Newcomers</a>
  </div>

  <h3 style="font-size:15px;margin:22px 0 8px 0;">Where people are</h3>
  <div style="display:flex;gap:12px;flex-wrap:wrap;">
    <?php foreach (FollowUp::VISIT_STATUSES as $statusKey): ?>
      <a class="stat" href="/admin/newcomers?status=<?= e($statusKey) ?>" style="text-decoration:none;min-width:110px;">
        <div class="num"><?= (int) ($pipeline[$statusKey] ?? 0) ?></div>
        <div class="label"><?= e(FollowUp::VISIT_LABELS[$statusKey]) ?></div>
      </a>
    <?php endforeach; ?>
    <div class="stat" style="min-width:110px;<?= (int) ($pipeline['stalled'] ?? 0) > 0 ? 'border-color:var(--warn,#d9a441);' : '' ?>">
      <div class="num" style="color:<?= (int) ($pipeline['stalled'] ?? 0) > 0 ? 'var(--warn,#d9a441)' : 'inherit' ?>;"><?= (int) ($pipeline['stalled'] ?? 0) ?></div>
      <div class="label">Needing attention</div>
    </div>
  </div>

  <?php if ($stalled): ?>
    <h3 style="font-size:15px;margin:26px 0 8px 0;">Nobody has moved these people forward</h3>
    <p class="sub">
      Never contacted within <?= FollowUp::STALL_NEW_DAYS ?> days of arriving, or nothing done for
      <?= FollowUp::STALL_CONTACTED_DAYS ?> days after first contact.
    </p>
    <table class="table">
      <thead><tr><th>Visitor</th><th>Status</th><th>Quiet for</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($stalled as $row): ?>
          <tr>
            <td>
              <strong><?= e((string) $row['name']) ?></strong>
              <?php if (trim((string) $row['whatsapp_phone']) !== ''): ?>
                <div style="font-size:12.5px;"><a href="https://wa.me/<?= e(Sms::normaliseMsisdn((string) $row['whatsapp_phone']) ?: '') ?>" target="_blank" rel="noopener">Message on WhatsApp</a></div>
              <?php endif; ?>
            </td>
            <td><?= e(FollowUp::VISIT_LABELS[(string) $row['follow_up_status']] ?? (string) $row['follow_up_status']) ?></td>
            <td><?= (int) $row['days_idle'] ?> day<?= (int) $row['days_idle'] === 1 ? '' : 's' ?></td>
            <td><a class="btn sm secondary" href="/admin/follow-up?action=person&id=<?= (int) $row['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($tasks): ?>
    <h3 style="font-size:15px;margin:26px 0 8px 0;">Tasks waiting to be done</h3>
    <p class="sub">These are the parts of a follow-up that need a person, not a worker.</p>
    <table class="table">
      <thead><tr><th>Visitor</th><th>What</th><th>Was due</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tasks as $task): ?>
          <tr>
            <td>
              <a href="/admin/follow-up?action=person&id=<?= (int) $task['newcomer_id'] ?>"><?= e((string) $task['newcomer_name']) ?></a>
              <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $task['sequence_name']) ?></div>
            </td>
            <td><?= e((string) $task['task_label']) ?></td>
            <td style="white-space:nowrap;<?= (string) $task['due_on'] < date('Y-m-d') ? 'color:var(--warn,#d9a441);' : '' ?>">
              <?= e(date('j M', strtotime((string) $task['due_on']))) ?>
            </td>
            <td>
              <form method="post" action="/admin/follow-up?action=done">
                <?= Csrf::field() ?>
                <input type="hidden" name="action_id" value="<?= (int) $task['id'] ?>">
                <input type="text" name="note" maxlength="<?= FollowUp::MAX_NOTE ?>" placeholder="What happened (optional)" style="margin:0 0 6px 0;">
                <button class="btn sm" type="submit">Done</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($failures): ?>
    <h3 style="font-size:15px;margin:26px 0 8px 0;color:var(--danger,#ff6b6b);">Emails the mail server refused</h3>
    <p class="sub">
      These are retried, most recently after an hour, up to <?= FollowUpRunner::MAX_ATTEMPTS ?> times.
      If one sits here it is usually a wrong address.
    </p>
    <table class="table">
      <thead><tr><th>Visitor</th><th>Address</th><th>Step</th><th>Tries</th></tr></thead>
      <tbody>
        <?php foreach ($failures as $failure): ?>
          <tr>
            <td><a href="/admin/follow-up?action=person&id=<?= (int) $failure['newcomer_id'] ?>"><?= e((string) $failure['newcomer_name']) ?></a></td>
            <td><?= e((string) $failure['email']) ?></td>
            <td><?= e((string) ($failure['subject'] ?? '')) ?></td>
            <td><?= (int) $failure['attempts'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($unreachable): ?>
    <h3 style="font-size:15px;margin:26px 0 8px 0;">Being followed up, but with no email address</h3>
    <p class="sub">
      The email steps of their sequence cannot arrive. The tasks still will — and if you get an address
      from them, the next email step will reach them from then on.
    </p>
    <table class="table">
      <thead><tr><th>Visitor</th><th>Sequence</th><th>Phone</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($unreachable as $row): ?>
          <tr>
            <td><a href="/admin/follow-up?action=person&id=<?= (int) $row['id'] ?>"><?= e((string) $row['name']) ?></a></td>
            <td><?= e((string) $row['sequence_name']) ?></td>
            <td><?= e((string) $row['whatsapp_phone']) ?></td>
            <td><a class="btn sm secondary" href="/admin/newcomers?action=edit&id=<?= (int) $row['id'] ?>">Add their email</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3 style="font-size:15px;margin:26px 0 8px 0;">Sequences</h3>
  <?php if (!$sequences): ?>
    <div class="card empty">
      <p>No sequences yet — nothing happens to a visitor after their first Sunday.</p>
      <a class="btn" href="/admin/follow-up?action=edit">Create the first one</a>
    </div>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Sequence</th><th>Steps</th><th>Being followed up</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($sequences as $sequenceRow): ?>
          <tr>
            <td>
              <strong><?= e((string) $sequenceRow['name']) ?></strong>
              <?php if (empty($sequenceRow['is_active'])): ?>
                <span style="color:var(--danger,#ff6b6b);font-size:12.5px;"> · switched off</span>
              <?php endif; ?>
              <?php if (trim((string) $sequenceRow['description']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $sequenceRow['description']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int) $sequenceRow['step_count'] === 0): ?>
                <span style="color:var(--danger,#ff6b6b);">none — nothing would be sent</span>
              <?php else: ?>
                <?= (int) $sequenceRow['step_count'] ?>
                <span style="font-size:12.5px;color:var(--muted,#8b87a8);">(<?= (int) $sequenceRow['email_step_count'] ?> email)</span>
              <?php endif; ?>
            </td>
            <td>
              <?= (int) $sequenceRow['active_count'] ?>
              <?php if ((int) $sequenceRow['enrolled_count'] > (int) $sequenceRow['active_count']): ?>
                <span style="font-size:12.5px;color:var(--muted,#8b87a8);">of <?= (int) $sequenceRow['enrolled_count'] ?> ever</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn sm secondary" href="/admin/follow-up?action=edit&id=<?= (int) $sequenceRow['id'] ?>">Open</a>
              <form method="post" action="/admin/follow-up?action=delete" style="display:inline;" onsubmit="return confirm('Delete this sequence?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $sequenceRow['id'] ?>">
                <button class="btn sm secondary" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h3 style="font-size:15px;margin:26px 0 8px 0;">The worker</h3>
    <p class="sub">
      Nothing is sent until this runs. Add it to cron — hourly is plenty, and safe at any hour, because
      an email does not ring in somebody's bedroom the way a text message does.
    </p>
    <pre style="background:#0f0d1f;border:1px solid var(--border);border-radius:10px;padding:12px;overflow:auto;font-size:12.5px;">20 * * * * php <?= e(ROOT_PATH) ?>/cli/followup_worker.php --quiet</pre>
    <p class="sub">
      At most one email goes to a person per run, so somebody enrolled against last month's visits
      catches up over a few runs instead of receiving the whole sequence at once.
    </p>
    <?php if (!Mailer::configured()): ?>
      <p style="padding:10px 12px;border-radius:8px;background:rgba(217,164,65,0.14);font-size:12.5px;">
        <strong>No SMTP host is set</strong>, so these emails fall back to the server's own mail
        function — which many hosts quietly drop, and which often lands in spam even when it works.
        Set up email under <a href="/admin/settings">Settings</a> before trusting this.
      </p>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
