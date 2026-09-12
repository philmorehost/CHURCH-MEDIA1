<?php
declare(strict_types=1);

/**
 * The WhatsApp channel.
 *
 * Same shape as the SMS hub: URL-addressed tabs (`?tab=inbox`), each tab owning both its POST
 * handling and its rendering, its output captured in a buffer so a redirect after a successful
 * action still works. The flat file CMS has no tab component and URL tabs mean the guide can
 * link straight to a screen.
 *
 * Permissions follow SMS. Anyone who may use the messaging screens may read the inbox and reply,
 * because a message from a member that nobody answers is worse than no inbox at all. Only a super
 * admin may change the credentials or delete a template.
 *
 * The channel being off is not hidden: it is stated at the top of every tab, with what to do
 * about it. An admin who cannot see why nothing sends will assume the feature is broken.
 */

Auth::requireRole('admin', 'editor', 'media_team');

$user = Auth::user();
$isSuper = Auth::isSuperAdmin();
$myUnitId = !empty($user['org_unit_id']) ? (int) $user['org_unit_id'] : 0;
$pdo = Database::getInstance()->getConnection();

/** Empty means "no restriction" — what a super admin gets, and the same value the queries use. */
$scopeUnitIds = [];
if (!$isSuper && $myUnitId > 0) {
    $scopeUnitIds = Unit::subtreeIds($myUnitId);
}

$unitLabels = Unit::labelsById();

$tabs = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => '📊'],
    'inbox'     => ['label' => 'Inbox', 'icon' => '💬'],
    'templates' => ['label' => 'Templates', 'icon' => '📝'],
    'settings'  => ['label' => 'Settings', 'icon' => '⚙️', 'super' => true],
    'guide'     => ['label' => 'Guide', 'icon' => '📖'],
];

$requested = (string) ($_GET['tab'] ?? 'dashboard');
if (!isset($tabs[$requested]) || (!empty($tabs[$requested]['super']) && !$isSuper)) {
    $requested = 'dashboard';
}
$tab = $requested;

$waContext = [
    'is_super' => $isSuper,
    'user' => $user,
    'my_unit_id' => $myUnitId,
    'scope_unit_ids' => $scopeUnitIds,
    'unit_labels' => $unitLabels,
    'tab' => $tab,
];

/*
 * The conversation scope, built once.
 *
 * A conversation with no church belongs to whoever the number is: an inbound message from a
 * stranger has no unit yet, and hiding it from every scoped admin would mean nobody ever answers
 * it. So a scoped admin sees their own subtree plus the unassigned, and the unassigned is what
 * makes the inbox useful rather than tidy.
 */
$convWhere = '';
$convParams = [];
if ($scopeUnitIds !== []) {
    $placeholders = implode(',', array_fill(0, count($scopeUnitIds), '?'));
    $convWhere = ' AND (c.org_unit_id IS NULL OR c.org_unit_id IN (' . $placeholders . '))';
    $convParams = array_map('intval', $scopeUnitIds);
}
$waContext['conv_where'] = $convWhere;
$waContext['conv_params'] = $convParams;

$tabFile = __DIR__ . '/partials/whatsapp/' . $tab . '.php';
if (!is_file($tabFile)) {
    http_response_code(500);
    exit('That WhatsApp screen is missing from this installation.');
}

ob_start();
require $tabFile;
$tabContent = (string) ob_get_clean();

$pageTitle = 'WhatsApp';
$activeNav = 'whatsapp';
require __DIR__ . '/partials/layout-open.php';
?>

<?php if (!WhatsApp::enabled()): ?>
  <div class="alert error">
    WhatsApp is switched off, so nothing can be sent and no messages will be received.
    <?= $isSuper
        ? 'Turn it on under <strong>Settings</strong> once the number is live.'
        : 'A super admin needs to turn it on under <strong>Settings</strong>.' ?>
  </div>
<?php else: ?>
  <?php $waProblem = WhatsApp::problem(); ?>
  <?php if ($waProblem !== null): ?>
    <div class="alert error">
      <?= e($waProblem) ?>
      <?php if ($isSuper): ?>Finish the setup under <strong>Settings</strong>.<?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<nav class="wa-tabs" aria-label="WhatsApp sections">
  <?php foreach ($tabs as $key => $meta): ?>
    <?php if (!empty($meta['super']) && !$isSuper) { continue; } ?>
    <a href="/admin/whatsapp?tab=<?= e($key) ?>"
       class="wa-tab<?= $key === $tab ? ' active' : '' ?>"
       <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= $meta['icon'] ?> <?= e($meta['label']) ?></a>
  <?php endforeach; ?>
</nav>

<style>
  .wa-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px;padding:0;}
  .wa-tab{
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 15px;border-radius:999px;border:1px solid var(--border);
    background:var(--panel);color:var(--ink-dim);font-size:13px;font-weight:600;
    text-decoration:none;white-space:nowrap;
  }
  .wa-tab:hover{border-color:var(--gold);color:var(--ink);}
  .wa-tab.active{background:var(--gold-dim,#e8b95f22);border-color:#e8b95f66;color:var(--gold-soft);}
  /* The thread reads like a conversation: ours right, theirs left. */
  .wa-thread{display:flex;flex-direction:column;gap:10px;max-height:56vh;overflow-y:auto;padding:4px 2px 12px;}
  .wa-bubble{max-width:78%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.55;white-space:pre-wrap;word-break:break-word;}
  .wa-bubble.in{align-self:flex-start;background:var(--panel);border:1px solid var(--border);}
  .wa-bubble.out{align-self:flex-end;background:#1d3a2a;border:1px solid #2f6b46;}
  .wa-bubble.failed{align-self:flex-end;background:#3a1d1d;border:1px solid #6b2f2f;}
  .wa-meta{font-size:11px;color:var(--ink-faint,#9b96bc);margin-top:5px;}
  .wa-window-open{color:#7ec97e;font-size:12px;font-weight:600;}
  .wa-window-closed{color:var(--gold-soft);font-size:12px;font-weight:600;}
  .wa-unread{display:inline-block;min-width:20px;text-align:center;padding:1px 6px;border-radius:999px;background:var(--gold-soft);color:#1a1426;font-size:11px;font-weight:800;}
  @media (max-width:640px){.wa-tabs{gap:6px;}.wa-tab{padding:8px 12px;font-size:12.5px;}.wa-bubble{max-width:88%;}}
</style>

<?= $tabContent ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
