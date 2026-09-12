<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $activeNav */
$pageTitle ??= 'Dashboard';
$activeNav ??= '';
$adminUser = Auth::user();

/** Whether the signed-in user may see a nav entry. Shared by every group below. */
$canSeeNav = static function (array $item) use ($adminUser): bool {
    if (!empty($item['roles']) && (!$adminUser || !in_array($adminUser['role'], $item['roles'], true))) {
        return false;
    }
    if (!empty($item['super']) && (!$adminUser || empty($adminUser['is_super_admin']))) {
        return false;
    }
    return true;
};

/*
 * Sidebar navigation, grouped into collapsible sections.
 *
 * The flat list ran to 29 links, so reaching Settings meant scrolling past everything else and
 * the rail always carried a scrollbar. Groups cut it to one open section at a time (admin.js
 * closes the others) and make the area you are in obvious. Dashboard stays outside the groups:
 * it is the landing page, so it should never need a click to reveal.
 *
 * These are display groups, not permissions. The `roles` and `super` keys on an item are still
 * the single source of truth for who may see it, and an empty group is dropped entirely.
 */
$navHome = ['key' => 'dashboard', 'href' => '/admin', 'label' => 'Dashboard'];

$navGroups = [
    [
        'key' => 'content',
        'label' => 'Content',
        'items' => [
            ['key' => 'media', 'href' => '/admin/media', 'label' => 'Media & Reels'],
            ['key' => 'comments', 'href' => '/admin/comments', 'label' => 'Comments'],
            ['key' => 'ads', 'href' => '/admin/ads', 'label' => 'Ads Management'],
            ['key' => 'pages', 'href' => '/admin/pages', 'label' => 'Pages', 'super' => true],
        ],
    ],
    [
        'key' => 'word',
        'label' => 'The Word',
        'items' => [
            ['key' => 'sermons', 'href' => '/admin/sermons', 'label' => 'Sermons'],
            ['key' => 'series', 'href' => '/admin/series', 'label' => 'Series & Podcast'],
        ],
    ],
    [
        'key' => 'community',
        'label' => 'Events & Community',
        'items' => [
            ['key' => 'events', 'href' => '/admin/events', 'label' => 'Events'],
            ['key' => 'prayer', 'href' => '/admin/prayer', 'label' => 'Prayer Wall'],
            ['key' => 'testimonies', 'href' => '/admin/testimonies', 'label' => 'Testimonies'],
            ['key' => 'newcomers', 'href' => '/admin/newcomers', 'label' => 'Newcomers'],
            ['key' => 'team', 'href' => '/admin/team', 'label' => 'Team'],
        ],
    ],
    [
        'key' => 'people',
        'label' => 'Churches & People',
        'items' => [
            ['key' => 'units', 'href' => '/admin/units', 'label' => 'Units', 'roles' => ['admin']],
            ['key' => 'unit-levels', 'href' => '/admin/unit-levels', 'label' => 'Unit Levels', 'super' => true],
            ['key' => 'registrations', 'href' => '/admin/registrations', 'label' => 'Registrations', 'super' => true],
            ['key' => 'users', 'href' => '/admin/users', 'label' => 'Users', 'roles' => ['admin']],
        ],
    ],
    [
        'key' => 'engagement',
        'label' => 'Engagement',
        'items' => [
            ['key' => 'forms', 'href' => '/admin/forms', 'label' => 'Forms'],
            ['key' => 'newsletter', 'href' => '/admin/newsletter', 'label' => 'Newsletter'],
            ['key' => 'notifications', 'href' => '/admin/notifications', 'label' => 'Notifications'],
            ['key' => 'donations', 'href' => '/admin/donations', 'label' => 'Donations & Giving'],
        ],
    ],
    [
        'key' => 'messaging',
        'label' => 'Messaging',
        'items' => [
            ['key' => 'whatsapp', 'href' => '/admin/whatsapp', 'label' => 'WhatsApp'],
            ['key' => 'sms', 'href' => '/admin/sms', 'label' => 'SMS Messaging', 'roles' => ['admin', 'editor', 'media_team']],
        ],
    ],
    [
        'key' => 'reports',
        'label' => 'Reports',
        'items' => [
            ['key' => 'analytics', 'href' => '/admin/analytics', 'label' => 'Analytics', 'super' => true],
            ['key' => 'attendance', 'href' => '/admin/attendance', 'label' => 'Attendance'],
        ],
    ],
    [
        'key' => 'system',
        'label' => 'System',
        'items' => [
            ['key' => 'security', 'href' => '/admin/security', 'label' => 'Security'],
            ['key' => 'backup', 'href' => '/admin/backup', 'label' => 'Backups', 'super' => true],
            ['key' => 'settings', 'href' => '/admin/settings', 'label' => 'Settings', 'super' => true],
            ['key' => 'ads-settings', 'href' => '/admin/ads?action=settings', 'label' => 'Payment Gateway', 'super' => true],
            ['key' => 'firebase', 'href' => '/admin/firebase', 'label' => 'Firebase', 'super' => true],
            ['key' => 'guide', 'href' => '/admin/guide', 'label' => 'Guide'],
        ],
    ],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> · Admin · <?= e(setting('site_title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
</head>
<body>
<div class="admin-shell">
  <div class="sidebar-overlay" data-admin-overlay></div>
  <aside class="sidebar" data-admin-sidebar>
    <div class="brand">
      <div class="mark">C</div>
      <span><?= e(setting('site_title')) ?><br><small style="color:var(--ink-faint);font-weight:400;">Admin</small></span>
    </div>
    <nav aria-label="Admin sections">
      <?php $isHome = $activeNav === $navHome['key']; ?>
      <a href="<?= e($navHome['href']) ?>" class="<?= $isHome ? 'active' : '' ?>"<?= $isHome ? ' aria-current="page"' : '' ?>><?= e($navHome['label']) ?></a>

      <?php foreach ($navGroups as $group): ?>
        <?php
          // Drop a group the user cannot see any part of, rather than showing an empty shell.
          $items = array_values(array_filter($group['items'], $canSeeNav));
          if (!$items) {
              continue;
          }
          // Open the group holding the current page, so arriving somewhere never needs a click.
          $isOpen = false;
          foreach ($items as $item) {
              if ($activeNav === $item['key']) {
                  $isOpen = true;
                  break;
              }
          }
          $panelId = 'navgroup-' . $group['key'];
        ?>
        <div class="nav-group<?= $isOpen ? ' is-open' : '' ?>" data-nav-group>
          <button type="button" class="nav-group-toggle" data-nav-toggle
                  aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= e($panelId) ?>">
            <span><?= e($group['label']) ?></span>
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
          </button>
          <div class="nav-group-panel" id="<?= e($panelId) ?>">
            <?php foreach ($items as $item): ?>
              <?php $isActive = $activeNav === $item['key']; ?>
              <a href="<?= e($item['href']) ?>" class="<?= $isActive ? 'active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="foot">
      <?php
      // SaaS: the super admin can hop between churches. Rendered only when there
      // is somewhere to hop to, so a single-church install shows nothing extra.
      $tenants = ($adminUser && !empty($adminUser['is_super_admin']) && class_exists('Tenant')) ? Tenant::all() : [];
      $currentTenant = $tenants ? Tenant::current() : null;
      ?>
      <?php if (count($tenants) > 1): ?>
        <form method="post" action="/admin/tenant-switch" style="margin-bottom:12px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/admin') ?>">
          <label for="tenant_id" style="font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-faint);">Church</label>
          <select id="tenant_id" name="tenant_id" style="width:100%;margin-top:4px;font-size:12px;padding:6px 8px;">
            <?php foreach ($tenants as $t): ?>
              <option value="<?= (int) $t['id'] ?>" <?= $currentTenant && (int) $currentTenant['id'] === (int) $t['id'] ? 'selected' : '' ?>>
                <?= e($t['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn secondary sm" type="submit" style="width:100%;margin-top:6px;">Switch Church</button>
        </form>
      <?php endif; ?>
      <a href="/" target="_blank" style="color:var(--ink-dim);">↗ View Website</a><br><br>
      <a href="/admin/logout" style="color:var(--danger);">Log Out</a>
    </div>
  </aside>
  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <button class="menu-toggle" data-admin-toggle aria-label="Menu">☰</button>
        <h1><?= e($pageTitle) ?></h1>
      </div>
      <div class="actions">
        <a href="/admin/account" style="color:var(--ink-dim);font-size:13px;"><?= e($adminUser['name'] ?? '') ?> · <span style="color:var(--gold-soft);text-transform:capitalize;"><?= e($adminUser['role'] ?? '') ?></span></a>
      </div>
    </div>
    <div class="content">
      <?php if ($msg = flash('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
