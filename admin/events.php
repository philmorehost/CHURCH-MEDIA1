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

// Super admin / scoped admin can assign an event to a church.
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $reassignId = (int) ($_POST['id'] ?? 0);
    $unitId = (int) ($_POST['org_unit_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'events', $reassignId, $user) && $unitId > 0 && Unit::inAssignableScope($user, $unitId)) {
        $pdo->prepare('UPDATE events SET org_unit_id = ? WHERE id = ?')->execute([$unitId, $reassignId]);
        flash('success', 'Assigned to ' . Unit::label($unitId) . '.');
    } else {
        flash('error', 'Could not reassign that event.');
    }
    redirect('/admin/events');
}

function eventSlug(PDO $pdo, string $title, int $ignoreId = 0): string
{
    $base = slugify($title);
    $slug = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE slug = ? AND id != ?');
    while (true) {
        $stmt->execute([$slug, $ignoreId]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . (++$i);
    }
}

if (in_array($action, ['create', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($action === 'edit' && !Unit::recordInScope($pdo, 'events', $id, $user)) {
        flash('error', 'You can only manage events for your own church.');
        redirect('/admin/events');
    }
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $startAt = $_POST['start_at'] ?? '';
    $endAt = trim($_POST['end_at'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $rsvpEnabled = isset($_POST['rsvp_enabled']) ? 1 : 0;
    $rsvpUrl = trim($_POST['rsvp_url'] ?? '');
    $rsvpMode = in_array($_POST['rsvp_mode'] ?? 'legacy', ['legacy', 'off', 'external', 'internal'], true) ? (string) $_POST['rsvp_mode'] : 'legacy';
    $maxCapacity = max(0, (int) ($_POST['max_capacity'] ?? 0));
    $allowGuests = isset($_POST['allow_guests']) ? 1 : 0;
    $waitlistEnabled = isset($_POST['waitlist_enabled']) ? 1 : 0;
    $rsvpClosesAt = trim($_POST['rsvp_closes_at'] ?? '');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Auto-assign events to the creator's church (block creation if they have none).
    if ($action === 'create' && empty($user['is_super_admin']) && empty($user['org_unit_id'])) {
        $errors[] = 'Your account has no Home Church assigned — ask the super admin to set it (Users → Edit → Home Unit) before creating events.';
    }

    if ($title === '' || $startAt === '') {
        $errors[] = 'Title and start date/time are required.';
    } else {
        $coverPath = null;
        if (!empty($_FILES['cover_image']['tmp_name']) && is_uploaded_file($_FILES['cover_image']['tmp_name'])) {
            $filename = MediaProcessor::processImage($_FILES['cover_image']['tmp_name'], UPLOADS_WEBP_PATH);
            $coverPath = $filename ? 'webp/' . $filename : null;
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO events (title, slug, description, cover_image, start_at, end_at, location, rsvp_enabled, rsvp_url, rsvp_mode, max_capacity, allow_guests, waitlist_enabled, rsvp_closes_at, is_published, org_unit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, eventSlug($pdo, $title), $description, $coverPath, $startAt, $endAt ?: null, $location, $rsvpEnabled, $rsvpUrl ?: null, $rsvpMode, $maxCapacity, $allowGuests, $waitlistEnabled, $rsvpClosesAt ?: null, $isPublished, $user['org_unit_id'] ?? null]);
            if ($isPublished) {
                try {
                    Pusher::notifyNewEvent($pdo, (int) $pdo->lastInsertId(), $user['org_unit_id'] ?? null, $title, $location);
                } catch (Throwable $e) {
                    error_log('Push notify failed: ' . $e->getMessage());
                }
            }
            flash('success', 'Event created.');
        } else {
            $sql = 'UPDATE events SET title=?, slug=?, description=?, start_at=?, end_at=?, location=?, rsvp_enabled=?, rsvp_url=?, rsvp_mode=?, max_capacity=?, allow_guests=?, waitlist_enabled=?, rsvp_closes_at=?, is_published=?';
            $params = [$title, eventSlug($pdo, $title, $id), $description, $startAt, $endAt ?: null, $location, $rsvpEnabled, $rsvpUrl ?: null, $rsvpMode, $maxCapacity, $allowGuests, $waitlistEnabled, $rsvpClosesAt ?: null, $isPublished];
            if ($coverPath) {
                $sql .= ', cover_image=?';
                $params[] = $coverPath;
            }
            $sql .= ' WHERE id=?';
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            flash('success', 'Event updated.');
        }
        redirect('/admin/events');
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $targetId = (int) ($_POST['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'events', $targetId, $user)) {
        flash('error', 'You can only manage events for your own church.');
        redirect('/admin/events');
    }
    $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$targetId]);
    flash('success', 'Event deleted.');
    redirect('/admin/events');
}

// Check a guest in (or undo it) at the door.
if ($action === 'rsvp_checkin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $evId = (int) ($_POST['event_id'] ?? 0);
    if (Unit::recordInScope($pdo, 'events', $evId, $user)) {
        Rsvp::setCheckedIn((int) ($_POST['rsvp_id'] ?? 0), (string) ($_POST['checked'] ?? '1') === '1');
        flash('success', 'Check-in updated.');
    } else {
        flash('error', 'That event belongs to a church outside your scope.');
    }
    redirect('/admin/events?action=attendees&id=' . $evId);
}

// Export the guest list, including anyone waiting.
if ($action === 'rsvp_export') {
    $evId = (int) ($_GET['id'] ?? 0);
    if (!Unit::recordInScope($pdo, 'events', $evId, $user)) {
        flash('error', 'That event belongs to a church outside your scope.');
        redirect('/admin/events');
    }
    $titleStmt = $pdo->prepare('SELECT title FROM events WHERE id = ?');
    $titleStmt->execute([$evId]);
    $evTitle = (string) ($titleStmt->fetchColumn() ?: 'event');

    $listStmt = $pdo->prepare("SELECT * FROM event_rsvps WHERE event_id = ? AND status <> 'cancelled' ORDER BY FIELD(status,'going','maybe','waitlist','declined'), name ASC");
    $listStmt->execute([$evId]);

    $rows = [];
    foreach ($listStmt->fetchAll() as $r) {
        $rows[] = [
            'Name' => $r['name'],
            'Email' => (string) ($r['email'] ?? ''),
            'Phone' => (string) ($r['phone'] ?? ''),
            'Guests' => (int) $r['guests'],
            'Seats' => (int) $r['guests'] + 1,
            'Status' => (string) $r['status'],
            'Checked In' => $r['checked_in'] ? 'yes' : 'no',
            'Note' => (string) ($r['note'] ?? ''),
            'RSVPed At' => (string) $r['created_at'],
        ];
    }
    csvDownload(slugify($evTitle) . '-guests-' . date('Y-m-d') . '.csv', ['Name', 'Email', 'Phone', 'Guests', 'Seats', 'Status', 'Checked In', 'Note', 'RSVPed At'], $rows);
}

$editing = null;
if ($action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing) {
        redirect('/admin/events');
    }
    if (!Unit::recordInScope($pdo, 'events', $id, $user)) {
        flash('error', 'You can only manage events for your own church.');
        redirect('/admin/events');
    }
}

$events = $action === 'list' ? $pdo->query('SELECT * FROM events WHERE 1=1' . $scopeSql . ' ORDER BY start_at DESC LIMIT 100')->fetchAll() : [];

// Guest list for the door.
$attendeeEvent = null;
$attendees = [];
$attendeeCounts = ['going' => 0, 'maybe' => 0, 'declined' => 0, 'waitlist' => 0, 'cancelled' => 0, 'seats_taken' => 0, 'checked_in' => 0, 'rows' => 0];
if ($action === 'attendees' && Unit::recordInScope($pdo, 'events', $id, $user)) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $attendeeEvent = $stmt->fetch() ?: null;
    if ($attendeeEvent) {
        $attendees = Rsvp::attendees($id);
        $attendeeCounts = Rsvp::counts($id);
    }
}

$pageTitle = 'Events';
$activeNav = 'events';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
  <div class="card" style="max-width:640px;">
    <h2><?= $action === 'create' ? 'New Event' : 'Edit Event' ?></h2>
    <form method="post" action="/admin/events?action=<?= $action ?><?= $editing ? '&id=' . (int) $editing['id'] : '' ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <label for="title">Title</label>
      <input type="text" id="title" name="title" value="<?= e($editing['title'] ?? '') ?>" required>
      <label for="description">Description</label>
      <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
      <div class="row two">
        <div>
          <label for="start_at">Starts</label>
          <input type="datetime-local" id="start_at" name="start_at" value="<?= e($editing ? str_replace(' ', 'T', substr((string) $editing['start_at'], 0, 16)) : '') ?>" required>
        </div>
        <div>
          <label for="end_at">Ends (optional)</label>
          <input type="datetime-local" id="end_at" name="end_at" value="<?= e($editing && $editing['end_at'] ? str_replace(' ', 'T', substr((string) $editing['end_at'], 0, 16)) : '') ?>">
        </div>
      </div>
      <label for="location">Location</label>
      <input type="text" id="location" name="location" value="<?= e($editing['location'] ?? '') ?>" placeholder="Main Auditorium">
      <label for="cover_image">Cover Image</label>
      <input type="file" id="cover_image" name="cover_image" accept="image/*">
      <h2 style="margin-top:24px;font-size:15px;">RSVP</h2>
      <label for="rsvp_mode">How should RSVPs work?</label>
      <select id="rsvp_mode" name="rsvp_mode">
        <option value="internal" <?= (($editing['rsvp_mode'] ?? 'legacy') === 'internal') ? 'selected' : '' ?>>Take RSVPs on this site</option>
        <option value="external" <?= (($editing['rsvp_mode'] ?? 'legacy') === 'external') ? 'selected' : '' ?>>Link to another page</option>
        <option value="off" <?= (($editing['rsvp_mode'] ?? 'legacy') === 'off') ? 'selected' : '' ?>>No RSVP</option>
        <option value="legacy" <?= (($editing['rsvp_mode'] ?? 'legacy') === 'legacy') ? 'selected' : '' ?>>Keep the old settings below</option>
      </select>
      <p class="sub" style="margin-top:-6px;">
        Choose <strong>Take RSVPs on this site</strong> to use places, guest counts, a waiting list and door check-in.
        The two “old settings” fields are only used by the last two options, so events you set up before keep working exactly as they did.
      </p>

      <div class="row two">
        <div>
          <label for="max_capacity">Maximum places <small style="color:var(--ink-faint);">(0 = unlimited)</small></label>
          <input type="number" id="max_capacity" name="max_capacity" min="0" max="100000" value="<?= (int) ($editing['max_capacity'] ?? 0) ?>">
        </div>
        <div>
          <label for="rsvp_closes_at">RSVPs close (optional)</label>
          <input type="datetime-local" id="rsvp_closes_at" name="rsvp_closes_at" value="<?= e($editing && !empty($editing['rsvp_closes_at']) ? str_replace(' ', 'T', substr((string) $editing['rsvp_closes_at'], 0, 16)) : '') ?>">
        </div>
      </div>
      <div class="checkbox-row">
        <input type="checkbox" id="allow_guests" name="allow_guests" <?= $editing === null || !empty($editing['allow_guests']) ? 'checked' : '' ?>>
        <label for="allow_guests" style="margin:0;">Let people bring guests</label>
      </div>
      <div class="checkbox-row">
        <input type="checkbox" id="waitlist_enabled" name="waitlist_enabled" <?= $editing === null || !empty($editing['waitlist_enabled']) ? 'checked' : '' ?>>
        <label for="waitlist_enabled" style="margin:0;">Start a waiting list once the event is full</label>
      </div>

      <div class="checkbox-row">
        <input type="checkbox" id="rsvp_enabled" name="rsvp_enabled" <?= !empty($editing['rsvp_enabled']) ? 'checked' : '' ?>>
        <label for="rsvp_enabled" style="margin:0;">Old settings: enable RSVP link</label>
      </div>
      <label for="rsvp_url">Old settings: RSVP URL</label>
      <input type="url" id="rsvp_url" name="rsvp_url" value="<?= e($editing['rsvp_url'] ?? '') ?>" placeholder="https://...">
      <div class="checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" <?= $editing === null || !empty($editing['is_published']) ? 'checked' : '' ?>>
        <label for="is_published" style="margin:0;">Published</label>
      </div>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $action === 'create' ? 'Create Event' : 'Save Changes' ?></button>
        <a class="btn secondary" href="/admin/events">Cancel</a>
      </div>
    </form>
  </div>
<?php elseif ($action === 'attendees'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/events">← Back to events</a>
    <?php if ($attendeeEvent): ?>
      <a class="btn sm" href="/admin/events?action=rsvp_export&id=<?= (int) $attendeeEvent['id'] ?>">⬇ Export guest list (CSV)</a>
      <a class="btn secondary sm" href="/events/<?= e($attendeeEvent['slug']) ?>" target="_blank" rel="noopener">↗ View page</a>
    <?php endif; ?>
  </div>

  <?php if (!$attendeeEvent): ?>
    <div class="card"><div class="empty">That event could not be found in your churches.</div></div>
  <?php else: ?>
    <div class="card">
      <h2><?= e($attendeeEvent['title']) ?></h2>
      <p class="sub">
        <?= e(date('l, F j, Y g:i A', strtotime($attendeeEvent['start_at']))) ?><?= $attendeeEvent['location'] ? ' · ' . e($attendeeEvent['location']) : '' ?>.
        <?php if (Rsvp::capacity($attendeeEvent) > 0): ?>
          <?= (int) $attendeeCounts['seats_taken'] ?> of <?= number_format(Rsvp::capacity($attendeeEvent)) ?> places taken.
        <?php else: ?>
          <?= (int) $attendeeCounts['seats_taken'] ?> place(s) booked (no limit set).
        <?php endif; ?>
      </p>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:18px;">
        <?php
        $cards = [
            ['Coming', $attendeeCounts['going'], 'parties'],
            ['Seats', $attendeeCounts['seats_taken'], 'including guests'],
            ['Waiting', $attendeeCounts['waitlist'], 'waiting list'],
            ['Maybe', $attendeeCounts['maybe'], 'undecided'],
            ['Checked in', $attendeeCounts['checked_in'], 'at the door'],
            ['Declined', $attendeeCounts['declined'], 'cannot make it'],
        ];
        foreach ($cards as [$label, $value, $hint]):
        ?>
          <div style="background:var(--panel-2,#1c1a33);border:1px solid var(--border);border-radius:12px;padding:12px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-faint);"><?= e($label) ?></div>
            <div style="font-size:22px;font-weight:700;color:var(--gold-soft);"><?= number_format((int) $value) ?></div>
            <div style="font-size:11px;color:var(--ink-faint);"><?= e($hint) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!$attendees): ?>
        <div class="empty">Nobody has RSVPed yet.</div>
      <?php else: ?>
        <table>
          <tr><th>Guest</th><th>Contact</th><th>Seats</th><th>Status</th><th>Door</th></tr>
          <?php foreach ($attendees as $a): ?>
            <tr>
              <td>
                <strong><?= e($a['name']) ?></strong>
                <?php if (!empty($a['note'])): ?><br><small style="color:var(--ink-faint);"><?= e((string) $a['note']) ?></small><?php endif; ?>
                <br><small style="color:var(--ink-faint);">RSVPed <?= e(date('M j, g:i A', strtotime((string) $a['created_at']))) ?></small>
              </td>
              <td style="font-size:12px;color:var(--ink-dim);">
                <?= e((string) ($a['email'] ?? '—')) ?><br><?= e((string) ($a['phone'] ?? '')) ?: '' ?>
              </td>
              <td><?= (int) $a['guests'] + 1 ?></td>
              <td>
                <span class="badge <?= $a['status'] === 'going' ? 'ok' : 'warn' ?>"><?= e((string) $a['status']) ?></span>
              </td>
              <td>
                <form method="post" action="/admin/events?action=rsvp_checkin" style="margin:0;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="rsvp_id" value="<?= (int) $a['id'] ?>">
                  <input type="hidden" name="event_id" value="<?= (int) $attendeeEvent['id'] ?>">
                  <input type="hidden" name="checked" value="<?= $a['checked_in'] ? '0' : '1' ?>">
                  <?php if ($a['checked_in']): ?>
                    <button class="btn secondary sm" type="submit">✓ In — undo</button>
                  <?php else: ?>
                    <button class="btn sm" type="submit">Check in</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="btn-row" style="margin-bottom:20px;"><a class="btn" href="/admin/events?action=create">+ New Event</a></div>
  <div class="card">
    <?php if (!$events): ?>
      <div class="empty">No events yet.</div>
    <?php else: ?>
      <table>
        <tr><th>Title</th><th>Church</th><th>Starts</th><th>Location</th><th>RSVP</th><th>Status</th><th></th></tr>
        <?php foreach ($events as $ev): ?>
        <tr>
          <td><?= e($ev['title']) ?></td>
          <td>
            <?php if (!empty($ev['org_unit_id'])): ?>
              <span style="color:var(--gold-soft);font-size:12px;"><?= e($unitLabels[(int) $ev['org_unit_id']] ?? '') ?></span>
            <?php else: ?>
              <span class="badge warn">Unassigned</span>
            <?php endif; ?>
            <div style="margin-top:6px;">
              <?php $reassignId = (int) $ev['id']; $reassignUnitId = !empty($ev['org_unit_id']) ? (int) $ev['org_unit_id'] : null; $showUnassignedOnly = false; $assignAction = '/admin/events?action=reassign'; require __DIR__ . '/partials/unit-assign.php'; ?>
            </div>
          </td>
          <td><?= e(date('M j, Y g:i A', strtotime($ev['start_at']))) ?></td>
          <td><?= e($ev['location'] ?: '—') ?></td>
          <td>
            <?php
            $evMode = Rsvp::modeFor($ev);
            $evCounts = $evMode === 'internal' ? Rsvp::counts((int) $ev['id']) : null;
            ?>
            <?php if ($evMode === 'internal'): ?>
              <span class="badge ok"><?= (int) $evCounts['seats_taken'] ?> going</span>
              <?php if (Rsvp::capacity($ev) > 0): ?><br><small style="color:var(--ink-faint);">of <?= number_format(Rsvp::capacity($ev)) ?> places</small><?php endif; ?>
              <?php if ($evCounts['waitlist'] > 0): ?><br><span class="badge warn"><?= (int) $evCounts['waitlist'] ?> waiting</span><?php endif; ?>
              <div style="margin-top:6px;"><a class="btn secondary sm" href="/admin/events?action=attendees&id=<?= (int) $ev['id'] ?>">Guest list</a></div>
            <?php elseif ($evMode === 'external'): ?>
              <span class="badge info">external link</span>
            <?php else: ?>
              <span class="badge">no</span>
            <?php endif; ?>
          </td>
          <td><?= $ev['is_published'] ? '<span class="badge ok">published</span>' : '<span class="badge warn">draft</span>' ?></td>
          <td>
            <a class="btn secondary sm" href="/admin/events?action=edit&id=<?= (int) $ev['id'] ?>">Edit</a>
            <form method="post" action="/admin/events?action=delete" onsubmit="return confirm('Delete this event?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
