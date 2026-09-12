<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $activeNav */
$pageTitle ??= 'Dashboard';
$activeNav ??= '';
$adminUser = Auth::user();

$navItems = [
    ['key' => 'dashboard', 'href' => '/admin', 'label' => 'Dashboard'],
    ['key' => 'media', 'href' => '/admin/media', 'label' => 'Media & Reels'],
    ['key' => 'comments', 'href' => '/admin/comments', 'label' => 'Comments'],
    ['key' => 'ads', 'href' => '/admin/ads', 'label' => 'Ads Management'],
    ['key' => 'events', 'href' => '/admin/events', 'label' => 'Events'],
    ['key' => 'sermons', 'href' => '/admin/sermons', 'label' => 'Sermons'],
    ['key' => 'team', 'href' => '/admin/team', 'label' => 'Team'],
    ['key' => 'prayer', 'href' => '/admin/prayer', 'label' => 'Prayer Wall'],
    ['key' => 'newsletter', 'href' => '/admin/newsletter', 'label' => 'Newsletter'],
    ['key' => 'forms', 'href' => '/admin/forms', 'label' => 'Forms'],
    ['key' => 'notifications', 'href' => '/admin/notifications', 'label' => 'Notifications'],
    ['key' => 'attendance', 'href' => '/admin/attendance', 'label' => 'Attendance'],
    ['key' => 'donations', 'href' => '/admin/donations', 'label' => 'Donations & Giving'],
    ['key' => 'testimonies', 'href' => '/admin/testimonies', 'label' => 'Testimonies'],
    ['key' => 'newcomers', 'href' => '/admin/newcomers', 'label' => 'Newcomers'],
    ['key' => 'pages', 'href' => '/admin/pages', 'label' => 'Pages', 'super' => true],
    ['key' => 'guide', 'href' => '/admin/guide', 'label' => 'Guide'],
];
$navItemsSystem = [
    ['key' => 'registrations', 'href' => '/admin/registrations', 'label' => 'Registrations', 'super' => true],
    ['key' => 'units', 'href' => '/admin/units', 'label' => 'Units', 'roles' => ['admin']],
    ['key' => 'unit-levels', 'href' => '/admin/unit-levels', 'label' => 'Unit Levels', 'super' => true],
    ['key' => 'security', 'href' => '/admin/security', 'label' => 'Security'],
    ['key' => 'settings', 'href' => '/admin/settings', 'label' => 'Settings', 'super' => true],
    ['key' => 'firebase', 'href' => '/admin/firebase', 'label' => 'Firebase', 'super' => true],
    ['key' => 'users', 'href' => '/admin/users', 'label' => 'Users', 'roles' => ['admin']],
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
    <nav>
      <?php foreach ($navItems as $item): ?>
        <?php
          if (!empty($item['roles']) && (!$adminUser || !in_array($adminUser['role'], $item['roles'], true))) continue;
          if (!empty($item['super']) && (!$adminUser || empty($adminUser['is_super_admin']))) continue;
        ?>
        <a href="<?= e($item['href']) ?>" class="<?= $activeNav === $item['key'] ? 'active' : '' ?>"><?= e($item['label']) ?></a>
      <?php endforeach; ?>
      <div class="group">System</div>
      <?php foreach ($navItemsSystem as $item): ?>
        <?php
          if (!empty($item['roles']) && (!$adminUser || !in_array($adminUser['role'], $item['roles'], true))) continue;
          if (!empty($item['super']) && (!$adminUser || empty($adminUser['is_super_admin']))) continue;
        ?>
        <a href="<?= e($item['href']) ?>" class="<?= $activeNav === $item['key'] ? 'active' : '' ?>"><?= e($item['label']) ?></a>
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
