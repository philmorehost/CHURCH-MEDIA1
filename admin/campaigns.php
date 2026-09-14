<?php
declare(strict_types=1);

/**
 * Giving campaigns — a named project with a target, and what has actually been given to it.
 *
 * Three screens: the list with progress, the editor, and one campaign with its gifts, donors and
 * pledges.
 *
 * Two things this screen is careful about, because both are ways money gets misreported:
 *
 *  - **Raised and pledged are never added together.** The progress bar moves on completed gifts only.
 *    A pledge is shown beside it, labelled as a promise, and a pledge becomes money only when somebody
 *    records the gift — one click, at most once, guarded in the database.
 *  - **A pending gift is not counted.** A bank transfer with a receipt uploaded but not yet verified
 *    is money that has been *claimed*, so it is reported separately and the campaign page says so
 *    rather than the bar moving on an unconfirmed number.
 *
 * Access is admin-only, matching Donations: this is the finance screen, not content.
 */

Auth::requireRole('admin');
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

/** Refuses a campaign outside the user's scope, then redirects. Returns it when allowed. */
$guard = static function (int $campaignId) use ($user): array {
    $campaign = GivingCampaign::find($campaignId);
    if ($campaign === null || !GivingCampaign::inScope($user, $campaign)) {
        flash('error', 'That campaign is not one you can change.');
        redirect('/admin/campaigns');
    }
    return $campaign;
};

/* ================================================================ campaign writes == */

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($id > 0) {
        $guard($id);
    }

    $data = array(
        'title' => (string) ($_POST['title'] ?? ''),
        'summary' => (string) ($_POST['summary'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'goal_amount' => (string) ($_POST['goal_amount'] ?? '0'),
        'currency' => (string) ($_POST['currency'] ?? 'NGN'),
        'starts_on' => (string) ($_POST['starts_on'] ?? ''),
        'ends_on' => (string) ($_POST['ends_on'] ?? ''),
        'is_active' => !empty($_POST['is_active']),
        'sort_order' => (string) ($_POST['sort_order'] ?? '0'),
        'org_unit_id' => (int) ($_POST['org_unit_id'] ?? 0) ?: $defaultUnit,
    );

    // An image is optional and only processed when one was actually chosen, so re-saving the form
    // without touching the file input does not clear the picture that is already there.
    $uploadedImage = null;
    if (!empty($_FILES['image']['name'])) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'The image upload failed — the file may be too large for the server.';
        } elseif (!is_uploaded_file($_FILES['image']['tmp_name'] ?? '') || !($filename = MediaProcessor::processImage($_FILES['image']['tmp_name'], UPLOADS_WEBP_PATH, 82))) {
            $errors[] = 'That image could not be processed — use JPG, PNG, GIF, WebP, BMP or AVIF.';
        } else {
            $uploadedImage = 'webp/' . $filename;
        }
    }

    if (!$errors) {
        $result = GivingCampaign::save($user, $id, $data);
        if (empty($result['ok'])) {
            $errors = $result['errors'] ?? array('Could not save that campaign.');
        } else {
            $campaignId = (int) $result['id'];
            if ($uploadedImage !== null) {
                $pdo->prepare('UPDATE giving_campaigns SET image_path = ? WHERE id = ?')->execute(array($uploadedImage, $campaignId));
            } elseif (!empty($_POST['remove_image'])) {
                $pdo->prepare('UPDATE giving_campaigns SET image_path = NULL WHERE id = ?')->execute(array($campaignId));
            }
            flash('success', $id > 0 ? 'Campaign updated.' : 'Campaign created.');
            redirect('/admin/campaigns?action=view&id=' . $campaignId);
        }
    }
    $action = 'edit';
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $guard($id);
    $result = GivingCampaign::delete($id);
    flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
        ? implode(' ', $result['errors'] ?? array('Could not delete that campaign.'))
        : 'Campaign deleted.');
    redirect('/admin/campaigns');
}

/* ================================================================== pledge writes == */

if ($action === 'pledge-add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $campaignId = (int) ($_POST['campaign_id'] ?? 0);
    $guard($campaignId);

    // Recorded on somebody's behalf when they promise it in person or over the phone — which is how
    // most pledges actually arrive.
    $result = GivingCampaign::pledge($campaignId, array(
        'donor_name' => (string) ($_POST['donor_name'] ?? ''),
        'donor_email' => (string) ($_POST['donor_email'] ?? ''),
        'donor_phone' => (string) ($_POST['donor_phone'] ?? ''),
        'amount' => (string) ($_POST['amount'] ?? ''),
        'promised_on' => (string) ($_POST['promised_on'] ?? ''),
        'note' => (string) ($_POST['note'] ?? ''),
    ), (int) ($user['id'] ?? 0));

    flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
        ? implode(' ', $result['errors'] ?? array('Could not record that pledge.'))
        : 'Pledge recorded. It is not counted as money until it is received.');
    redirect('/admin/campaigns?action=view&id=' . $campaignId);
}

if (in_array($action, array('pledge-record', 'pledge-cancel', 'pledge-delete'), true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $pledgeId = (int) ($_POST['pledge_id'] ?? 0);
    $pledge = GivingCampaign::findPledge($pledgeId);
    if ($pledge === null) {
        flash('error', 'That pledge no longer exists.');
        redirect('/admin/campaigns');
    }
    $campaignId = (int) $pledge['campaign_id'];
    $guard($campaignId);

    if ($action === 'pledge-record') {
        $result = GivingCampaign::recordPledge($pledgeId, (int) ($user['id'] ?? 0));
        flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
            ? implode(' ', $result['errors'] ?? array('Could not record that pledge.'))
            : $pledge['donor_name'] . '\'s pledge of ' . number_format((float) $pledge['amount'], 2) . ' has been recorded as a gift and now counts towards the total.');
    } elseif ($action === 'pledge-cancel') {
        $result = GivingCampaign::cancelPledge($pledgeId);
        flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
            ? implode(' ', $result['errors'] ?? array('Could not cancel that pledge.'))
            : 'Pledge cancelled. It no longer counts towards what has been promised.');
    } else {
        $result = GivingCampaign::deletePledge($pledgeId);
        flash(empty($result['ok']) ? 'error' : 'success', empty($result['ok'])
            ? implode(' ', $result['errors'] ?? array('Could not remove that pledge.'))
            : 'Pledge removed.');
    }
    redirect('/admin/campaigns?action=view&id=' . $campaignId);
}

/* ==================================================================== load view == */

$editing = null;
$campaign = null;
if ($action === 'edit' && $id > 0) {
    $editing = $guard($id);
} elseif ($action === 'edit') {
    $editing = array(
        'id' => 0,
        'title' => '',
        'summary' => '',
        'description' => '',
        'goal_amount' => 0,
        'currency' => 'NGN',
        'starts_on' => date('Y-m-d'),
        'ends_on' => '',
        'is_active' => 1,
        'sort_order' => 0,
        'org_unit_id' => $defaultUnit,
        'image_path' => null,
    );
}

$campaignProgress = null;
$gifts = array();
$donors = array();
$pledges = array();
if ($action === 'view' && $id > 0) {
    $campaign = $guard($id);
    $campaignProgress = GivingCampaign::progressFor($campaign);
    $gifts = GivingCampaign::gifts($id);
    $donors = GivingCampaign::donors($id, (string) $campaign['currency'], 30);
    $pledges = GivingCampaign::pledges($id);
}

$campaigns = array();
$totals = array('raised' => 0.0, 'pledged' => 0.0, 'goal' => 0.0, 'open' => 0);
if ($action === 'list') {
    $campaigns = GivingCampaign::all($user, true);
    foreach ($campaigns as $row) {
        $totals['raised'] += (float) $row['progress']['raised'];
        $totals['pledged'] += (float) $row['progress']['pledged'];
        $totals['goal'] += (float) $row['progress']['goal'];
        if (GivingCampaign::status($row) === 'open') {
            $totals['open']++;
        }
    }
}

$pageTitle = 'Giving Campaigns';
$activeNav = 'campaigns';
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

  <h2><?= $editing['id'] > 0 ? 'Edit Campaign' : 'New Campaign' ?></h2>
  <p class="sub">A project with a target. Gifts given on the website are counted against it automatically.</p>

  <form method="post" action="/admin/campaigns?action=save<?= $editing['id'] > 0 ? '&id=' . (int) $editing['id'] : '' ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="row">
      <div>
        <label for="title">Campaign name</label>
        <input type="text" id="title" name="title" maxlength="<?= GivingCampaign::MAX_TITLE ?>" value="<?= e($field('title')) ?>" placeholder="New Auditorium" required>
      </div>
      <div>
        <label for="goal_amount">Target amount</label>
        <input type="number" id="goal_amount" name="goal_amount" min="0" step="0.01" value="<?= e($field('goal_amount', '0')) ?>">
      </div>
    </div>

    <label for="summary">One line about it</label>
    <input type="text" id="summary" name="summary" maxlength="<?= GivingCampaign::MAX_SUMMARY ?>" value="<?= e($field('summary')) ?>" placeholder="A roof over the whole congregation">

    <label for="description">The full appeal</label>
    <textarea id="description" name="description" rows="6" placeholder="Shown on the campaign page. Plain text — blank lines become paragraphs."><?= e($field('description')) ?></textarea>

    <div class="row">
      <div>
        <label for="currency">Currency</label>
        <input type="text" id="currency" name="currency" maxlength="10" value="<?= e($field('currency', 'NGN')) ?>">
      </div>
      <div>
        <label for="starts_on">Opens on</label>
        <input type="date" id="starts_on" name="starts_on" value="<?= e($field('starts_on')) ?>">
      </div>
      <div>
        <label for="ends_on">Closes on</label>
        <input type="date" id="ends_on" name="ends_on" value="<?= e($field('ends_on')) ?>">
        <p class="sub" style="margin-top:4px;">After this date the campaign stops taking gifts on its own.</p>
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

    <label for="image">Picture (optional)</label>
    <?php if (!empty($editing['image_path'])): ?>
      <div style="margin-bottom:8px;">
        <img src="<?= e(uploadUrl((string) $editing['image_path'])) ?>" alt="" style="max-width:220px; border-radius:10px; display:block;">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-top:8px;">
          <input type="checkbox" name="remove_image" value="1"> Remove the picture
        </label>
      </div>
    <?php endif; ?>
    <input type="file" id="image" name="image" accept="image/*">

    <div class="row">
      <div>
        <label for="sort_order">Order in the list</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= e($field('sort_order', '0')) ?>">
        <p class="sub" style="margin-top:4px;">Lower numbers appear first.</p>
      </div>
      <div>
        <label style="display:flex;align-items:center;gap:10px;margin-top:26px;">
          <input type="checkbox" name="is_active" value="1" <?= !empty($field('is_active', '1')) ? 'checked' : '' ?>>
          <span>Open — it accepts gifts and appears on the giving page</span>
        </label>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button class="btn" type="submit"><?= $editing['id'] > 0 ? 'Save campaign' : 'Create campaign' ?></button>
      <a class="btn secondary" href="/admin/campaigns">Cancel</a>
      <?php if ($editing['id'] > 0): ?>
        <a class="btn secondary" href="/give/c/<?= e((string) $editing['slug']) ?>" target="_blank" rel="noopener">View public page ↗</a>
      <?php endif; ?>
    </div>
  </form>

<?php elseif ($action === 'view' && $campaign !== null): ?>
  <?php $status = GivingCampaign::status($campaign); ?>
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
    <div>
      <h2 style="margin-bottom:4px;"><?= e((string) $campaign['title']) ?></h2>
      <p class="sub">
        <?= e(GivingCampaign::statusLabel($status)) ?>
        <?php if ($status === 'closed' && !empty($campaign['is_active'])): ?>
          <span style="color:var(--warn,#d9a441);">— it is still switched on, but the closing date has passed</span>
        <?php endif; ?>
        <?php $daysLeft = GivingCampaign::daysLeft($campaign); ?>
        <?php if ($daysLeft !== null && $daysLeft >= 0 && $status === 'open'): ?>
          · <?= (int) $daysLeft ?> day<?= (int) $daysLeft === 1 ? '' : 's' ?> left
        <?php endif; ?>
        · <a href="/give/c/<?= e((string) $campaign['slug']) ?>" target="_blank" rel="noopener">Public page ↗</a>
      </p>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="btn secondary" href="/admin/campaigns?action=edit&id=<?= (int) $campaign['id'] ?>">Edit</a>
      <a class="btn secondary" href="/admin/donations?campaign=<?= (int) $campaign['id'] ?>">Gifts in Donations</a>
    </div>
  </div>

  <?php
  $raised = (float) $campaignProgress['raised'];
  $goal = (float) $campaignProgress['goal'];
  $percent = (float) $campaignProgress['percent'];
  ?>
  <div class="card" style="margin-top:16px;">
    <?php if ($goal > 0): ?>
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
        <div style="font-size:24px;font-weight:800;color:var(--gold-soft);">₦<?= number_format($raised, 2) ?></div>
        <div style="color:var(--ink-dim);font-size:14px;">of ₦<?= number_format($goal, 2) ?> (<?= rtrim(rtrim(number_format($percent, 1), '0'), '.') ?>%)</div>
      </div>
      <div style="height:12px;border-radius:999px;background:rgba(255,255,255,0.08);overflow:hidden;">
        <div style="height:100%;width:<?= $percent ?>%;background:linear-gradient(90deg,var(--gold),var(--gold-soft));"></div>
      </div>
    <?php else: ?>
      <div style="font-size:24px;font-weight:800;color:var(--gold-soft);">₦<?= number_format($raised, 2) ?> given</div>
      <p class="sub" style="margin-top:6px;">No target was set, so there is no progress bar.</p>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-top:18px;">
      <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--ink-dim);">Received</div>
        <div style="font-weight:700;"><?= (int) $campaignProgress['gifts'] ?> gift<?= (int) $campaignProgress['gifts'] === 1 ? '' : 's' ?> from <?= (int) $campaignProgress['donors'] ?> giver<?= (int) $campaignProgress['donors'] === 1 ? '' : 's' ?></div>
      </div>
      <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--ink-dim);">Awaiting verification</div>
        <div style="font-weight:700;color:<?= (float) $campaignProgress['pending'] > 0 ? 'var(--warn,#d9a441)' : 'inherit' ?>;">₦<?= number_format((float) $campaignProgress['pending'], 2) ?></div>
        <div class="sub" style="font-size:12px;">not counted above</div>
      </div>
      <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--ink-dim);">Pledged, not yet given</div>
        <div style="font-weight:700;color:<?= (float) $campaignProgress['pledged'] > 0 ? 'var(--brand,#8b7bd8)' : 'inherit' ?>;">₦<?= number_format((float) $campaignProgress['pledged'], 2) ?></div>
        <div class="sub" style="font-size:12px;">not counted above</div>
      </div>
    </div>

    <?php if ($campaignProgress['other_currencies']): ?>
      <p class="sub" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
        Also received in other currencies, kept separate rather than added to the total:
        <?php foreach ($campaignProgress['other_currencies'] as $code => $figures): ?>
          <strong><?= e((string) $code) ?> <?= number_format((float) $figures['total'], 2) ?></strong><?= ' ' ?>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
  </div>

  <?php if ($pledges): ?>
    <h3 style="font-size:16px;margin:26px 0 8px 0;">Pledges</h3>
    <p class="sub">Promises. They count towards the total only when they are recorded as received.</p>
    <table class="table">
      <thead><tr><th>Who</th><th>Amount</th><th>Promised for</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pledges as $pledge): ?>
          <tr>
            <td>
              <strong><?= e((string) $pledge['donor_name']) ?></strong>
              <?php if (trim((string) $pledge['donor_email']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $pledge['donor_email']) ?></div>
              <?php endif; ?>
              <?php if (trim((string) $pledge['note']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $pledge['note']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-weight:700;">₦<?= number_format((float) $pledge['amount'], 2) ?></td>
            <td><?= $pledge['promised_on'] ? e(date('j M Y', strtotime((string) $pledge['promised_on']))) : '—' ?></td>
            <td>
              <?php if ($pledge['status'] === 'received'): ?>
                <span style="color:var(--ok,#3fbf7f);">Received</span>
                <?php if (!empty($pledge['recorded_donation_id'])): ?>
                  <div style="font-size:12px;color:var(--muted,#8b87a8);">recorded as a gift</div>
                <?php endif; ?>
              <?php elseif ($pledge['status'] === 'cancelled'): ?>
                <span style="color:var(--muted,#8b87a8);">Cancelled</span>
              <?php else: ?>
                <span style="color:var(--brand,#8b7bd8);">Promised</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <?php if ($pledge['status'] === 'pledged'): ?>
                <form method="post" action="/admin/campaigns?action=pledge-record" style="display:inline;" onsubmit="return confirm('Record this pledge as money received? It will be added to the campaign total.');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="pledge_id" value="<?= (int) $pledge['id'] ?>">
                  <button class="btn sm ok" type="submit">Mark received</button>
                </form>
                <form method="post" action="/admin/campaigns?action=pledge-cancel" style="display:inline;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="pledge_id" value="<?= (int) $pledge['id'] ?>">
                  <button class="btn sm secondary" type="submit">Withdrawn</button>
                </form>
              <?php endif; ?>
              <?php if (empty($pledge['recorded_donation_id'])): ?>
                <form method="post" action="/admin/campaigns?action=pledge-delete" style="display:inline;" onsubmit="return confirm('Remove this pledge?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="pledge_id" value="<?= (int) $pledge['id'] ?>">
                  <button class="btn sm secondary" type="submit">Remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3 style="font-size:16px;margin:26px 0 8px 0;">Record a pledge</h3>
  <p class="sub">For a promise made in person or on the phone. It is not counted as money until received.</p>
  <form method="post" action="/admin/campaigns?action=pledge-add">
    <?= Csrf::field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
    <div class="row">
      <div>
        <label for="donor_name">Name</label>
        <input type="text" id="donor_name" name="donor_name" maxlength="<?= GivingCampaign::MAX_NAME ?>" required>
      </div>
      <div>
        <label for="amount">Amount</label>
        <input type="number" id="amount" name="amount" min="1" step="0.01" required>
      </div>
      <div>
        <label for="promised_on">Promised for</label>
        <input type="date" id="promised_on" name="promised_on">
      </div>
    </div>
    <div class="row">
      <div>
        <label for="donor_email">Email</label>
        <input type="email" id="donor_email" name="donor_email" maxlength="<?= GivingCampaign::MAX_EMAIL ?>">
      </div>
      <div>
        <label for="donor_phone">Phone</label>
        <input type="tel" id="donor_phone" name="donor_phone" maxlength="32">
      </div>
    </div>
    <label for="note">Note</label>
    <input type="text" id="note" name="note" maxlength="<?= GivingCampaign::MAX_NOTE ?>" placeholder="Optional">
    <button class="btn" type="submit" style="margin-top:12px;">Record pledge</button>
  </form>

  <?php if ($donors): ?>
    <h3 style="font-size:16px;margin:26px 0 8px 0;">Who has given</h3>
    <table class="table">
      <thead><tr><th>Giver</th><th>Given</th><th>Gifts</th><th>Last</th></tr></thead>
      <tbody>
        <?php foreach ($donors as $donorRow): ?>
          <tr>
            <td>
              <strong><?= e((string) ($donorRow['name'] ?: 'Anonymous Giver')) ?></strong>
              <?php if (trim((string) $donorRow['email']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $donorRow['email']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-weight:700;">₦<?= number_format((float) $donorRow['total'], 2) ?></td>
            <td><?= (int) $donorRow['gifts'] ?></td>
            <td style="font-size:12.5px;"><?= e(date('j M Y', strtotime((string) $donorRow['last_gift']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3 style="font-size:16px;margin:26px 0 8px 0;">Every gift</h3>
  <?php if (!$gifts): ?>
    <div class="card empty">Nothing has been given to this campaign yet.</div>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>When</th><th>Giver</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($gifts as $gift): ?>
          <tr>
            <td style="font-size:12.5px;white-space:nowrap;"><?= e(date('j M Y', strtotime((string) $gift['created_at']))) ?></td>
            <td>
              <?= e((string) ($gift['donor_name'] ?: 'Anonymous Giver')) ?>
              <?php if (trim((string) $gift['donor_email']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $gift['donor_email']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-weight:700;"><?= e((string) $gift['currency']) ?> <?= number_format((float) $gift['amount'], 2) ?></td>
            <td style="font-size:12.5px;"><?= $gift['payment_method'] === 'online' ? 'Online' : 'Bank transfer' ?></td>
            <td>
              <?php if ($gift['payment_status'] === 'completed'): ?>
                <span style="color:var(--ok,#3fbf7f);">Counted</span>
              <?php elseif ($gift['payment_status'] === 'pending'): ?>
                <span style="color:var(--warn,#d9a441);">Awaiting verification</span>
              <?php else: ?>
                <span style="color:var(--danger,#ff6b6b);">Failed</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php else: ?>
  <h2>Giving Campaigns</h2>
  <p class="sub">A named project with a target — a building, a vehicle, a mission fund — and what has actually been given to it.</p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;">
    <a class="btn" href="/admin/campaigns?action=edit">New Campaign</a>
    <a class="btn secondary" href="/admin/donations">All Donations</a>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:22px;">
    <div class="card" style="padding:18px;margin:0;">
      <span class="sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Received across campaigns</span>
      <div style="font-size:24px;font-weight:800;color:var(--gold-soft);margin-top:4px;">₦<?= number_format($totals['raised'], 2) ?></div>
    </div>
    <div class="card" style="padding:18px;margin:0;">
      <span class="sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Pledged, not yet given</span>
      <div style="font-size:24px;font-weight:800;color:var(--brand,#8b7bd8);margin-top:4px;">₦<?= number_format($totals['pledged'], 2) ?></div>
      <div class="sub" style="font-size:12px;">not part of the figure on the left</div>
    </div>
    <div class="card" style="padding:18px;margin:0;">
      <span class="sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Open campaigns</span>
      <div style="font-size:24px;font-weight:800;margin-top:4px;"><?= (int) $totals['open'] ?></div>
    </div>
    <div class="card" style="padding:18px;margin:0;">
      <span class="sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Total targets</span>
      <div style="font-size:24px;font-weight:800;margin-top:4px;">₦<?= number_format($totals['goal'], 2) ?></div>
    </div>
  </div>

  <?php if (!$campaigns): ?>
    <div class="card empty">
      <p>No campaigns yet. Gifts still work — they simply are not counted against a project.</p>
      <a class="btn" href="/admin/campaigns?action=edit">Create the first campaign</a>
    </div>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Campaign</th><th>Progress</th><th>Received</th><th>Pledged</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($campaigns as $row): ?>
          <?php
          $rowStatus = GivingCampaign::status($row);
          $rowRaised = (float) $row['progress']['raised'];
          $rowGoal = (float) $row['progress']['goal'];
          $rowPercent = (float) $row['progress']['percent'];
          ?>
          <tr>
            <td>
              <strong><?= e((string) $row['title']) ?></strong>
              <?php if (trim((string) $row['summary']) !== ''): ?>
                <div style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= e((string) $row['summary']) ?></div>
              <?php endif; ?>
            </td>
            <td style="min-width:150px;">
              <?php if ($rowGoal > 0): ?>
                <div style="height:8px;border-radius:999px;background:rgba(255,255,255,0.08);overflow:hidden;margin-bottom:6px;">
                  <div style="height:100%;width:<?= $rowPercent ?>%;background:linear-gradient(90deg,var(--gold),var(--gold-soft));"></div>
                </div>
                <span style="font-size:12.5px;color:var(--muted,#8b87a8);"><?= rtrim(rtrim(number_format($rowPercent, 1), '0'), '.') ?>% of ₦<?= number_format($rowGoal, 0) ?></span>
              <?php else: ?>
                <span style="font-size:12.5px;color:var(--muted,#8b87a8);">no target set</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:700;">₦<?= number_format($rowRaised, 2) ?></td>
            <td>
              <?php if ((float) $row['progress']['pledged'] > 0): ?>
                <span style="color:var(--brand,#8b7bd8);">₦<?= number_format((float) $row['progress']['pledged'], 2) ?></span>
              <?php else: ?>
                <span style="color:var(--muted,#8b87a8);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($rowStatus === 'open'): ?>
                <span style="color:var(--ok,#3fbf7f);">Open</span>
              <?php elseif ($rowStatus === 'upcoming'): ?>
                <span style="color:var(--muted,#8b87a8);">Opens <?= e(date('j M', strtotime((string) $row['starts_on']))) ?></span>
              <?php elseif ($rowStatus === 'closed'): ?>
                <span style="color:var(--muted,#8b87a8);">Closed</span>
                <?php if (!empty($row['is_active'])): ?>
                  <div style="font-size:12px;color:var(--warn,#d9a441);">still switched on</div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--danger,#ff6b6b);">Switched off</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn sm secondary" href="/admin/campaigns?action=view&id=<?= (int) $row['id'] ?>">Open</a>
              <a class="btn sm secondary" href="/admin/campaigns?action=edit&id=<?= (int) $row['id'] ?>">Edit</a>
              <form method="post" action="/admin/campaigns?action=delete" style="display:inline;" onsubmit="return confirm('Delete this campaign?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button class="btn sm secondary" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
