<?php
declare(strict_types=1);

/**
 * The SMS Messaging Hub.
 *
 * Nine tabs, one per URL (`?tab=compose`), rather than a JavaScript tab widget: the flat
 * file CMS has no tab component, and URL-addressed tabs mean the guide can link straight
 * to the screen it is describing, a reload keeps you where you were, and nothing breaks
 * when JavaScript is off.
 *
 * Each tab lives in `admin/partials/sms/<tab>.php` and owns both its POST handling and its
 * rendering. The POST handling must come first inside the file, because a redirect has to
 * happen before any output. The content is captured in a buffer so the handler can still
 * redirect (headers are not sent while a buffer is open).
 *
 * Permissions: a super admin and any unit admin, editor or media-team member may use the
 * SMS screens. Two things are narrower — only the super admin may change gateway settings
 * or force a sender-ID status, and a scoped admin only ever sees and sends to their own
 * subtree.
 */

Auth::requireRole('admin', 'editor', 'media_team');

$user = Auth::user();
$isSuper = Auth::isSuperAdmin();
$myUnitId = !empty($user['org_unit_id']) ? (int) $user['org_unit_id'] : 0;
$pdo = Database::getInstance()->getConnection();

/**
 * The units this admin may act within. An empty list means "no restriction" — that is
 * what a super admin gets, and it is deliberately the same value the contact queries use
 * so there is only one way to express "everything".
 */
$scopeUnitIds = [];
if ($isSuper) {
    $scopeUnitIds = [];
} elseif ($myUnitId > 0) {
    $scopeUnitIds = Unit::subtreeIds($myUnitId);
}

// Whether this church is allowed to send at all, per the settings tab.
$sendingAllowed = $isSuper || (int) setting('sms_allow_unit_sending', 1) === 1;
$sendingAllowed = $sendingAllowed && ($isSuper || $myUnitId > 0);

$unitLabels = Unit::labelsById();

$tabs = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => '📊'],
    'compose' => ['label' => 'Compose', 'icon' => '✉️'],
    'contacts' => ['label' => 'Contacts', 'icon' => '👥'],
    'groups' => ['label' => 'Groups & Segments', 'icon' => '🎯'],
    'sender-ids' => ['label' => 'Sender IDs', 'icon' => '🏷'],
    'templates' => ['label' => 'Templates', 'icon' => '📝'],
    'campaigns' => ['label' => 'Campaigns', 'icon' => '📤'],
    'settings' => ['label' => 'Settings', 'icon' => '⚙️', 'super' => true],
    'guide' => ['label' => 'Guide', 'icon' => '📖'],
];

$requested = (string) ($_GET['tab'] ?? 'dashboard');
if (!isset($tabs[$requested]) || (!empty($tabs[$requested]['super']) && !$isSuper)) {
    $requested = 'dashboard';
}
$tab = $requested;

// Values the tab partials share. Kept in one place so a tab cannot invent its own idea of
// who the admin is or what they may touch.
$smsContext = [
    'is_super' => $isSuper,
    'user' => $user,
    'my_unit_id' => $myUnitId,
    'scope_unit_ids' => $scopeUnitIds,
    'sending_allowed' => $sendingAllowed,
    'unit_labels' => $unitLabels,
    'tab' => $tab,
];

$tabFile = __DIR__ . '/partials/sms/' . $tab . '.php';
if (!is_file($tabFile)) {
    http_response_code(500);
    exit('That SMS screen is missing from this installation.');
}

// Capture the tab. Its POST handler runs here, before any page output, so a redirect
// after a successful action still works.
ob_start();
require $tabFile;
$tabContent = (string) ob_get_clean();

$pageTitle = 'SMS Messaging';
$activeNav = 'sms';
require __DIR__ . '/partials/layout-open.php';
?>

<?php if (!$sendingAllowed): ?>
  <div class="alert error">
    Sending is currently switched off for your church. A super admin can turn it back on under
    <strong>SMS → Settings → Permissions</strong>.
  </div>
<?php endif; ?>

<?php if (!Sms::configured() && $isSuper): ?>
  <div class="alert error">
    No SMS gateway is connected yet. Add your provider API token under
    <strong>SMS → Settings</strong> before anything can be sent.
  </div>
<?php elseif (!Sms::configured()): ?>
  <div class="alert error">
    The SMS gateway has not been connected yet. A super admin needs to add the API token under
    <strong>SMS → Settings</strong>.
  </div>
<?php endif; ?>

<nav class="sms-tabs" aria-label="SMS sections">
  <?php foreach ($tabs as $key => $meta): ?>
    <?php if (!empty($meta['super']) && !$isSuper) { continue; } ?>
    <a href="/admin/sms?tab=<?= e($key) ?>"
       class="sms-tab<?= $key === $tab ? ' active' : '' ?>"
       <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= $meta['icon'] ?> <?= e($meta['label']) ?></a>
  <?php endforeach; ?>
</nav>

<style>
  .sms-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px;padding:0;}
  .sms-tab{
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 15px;border-radius:999px;border:1px solid var(--border);
    background:var(--panel);color:var(--ink-dim);font-size:13px;font-weight:600;
    text-decoration:none;white-space:nowrap;
  }
  .sms-tab:hover{border-color:var(--gold);color:var(--ink);}
  .sms-tab.active{background:var(--gold-dim,#e8b95f22);border-color:#e8b95f66;color:var(--gold-soft);}
  @media (max-width:640px){.sms-tabs{gap:6px;}.sms-tab{padding:8px 12px;font-size:12.5px;}}
</style>

<?= $tabContent ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
