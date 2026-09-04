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
    ['key' => 'events', 'href' => '/admin/events', 'label' => 'Events'],
    ['key' => 'sermons', 'href' => '/admin/sermons', 'label' => 'Sermons'],
    ['key' => 'team', 'href' => '/admin/team', 'label' => 'Team'],
    ['key' => 'prayer', 'href' => '/admin/prayer', 'label' => 'Prayer Wall'],
    ['key' => 'newsletter', 'href' => '/admin/newsletter', 'label' => 'Newsletter'],
    ['key' => 'forms', 'href' => '/admin/forms', 'label' => 'Forms'],
    ['key' => 'notifications', 'href' => '/admin/notifications', 'label' => 'Notifications'],
    ['key' => 'attendance', 'href' => '/admin/attendance', 'label' => 'Attendance'],
    ['key' => 'newcomers', 'href' => '/admin/newcomers', 'label' => 'Newcomers'],
    ['key' => 'pages', 'href' => '/admin/pages', 'label' => 'Pages', 'super' => true],
    ['key' => 'guide', 'href' => '/admin/guide', 'label' => 'Guide'],
];
$navItemsSystem = [
    ['key' => 'registrations', 'href' => '/admin/registrations', 'label' => 'Registrations', 'super' => true],
    ['key' => 'units', 'href' => '/admin/units', 'label' => 'Units', 'super' => true],
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
