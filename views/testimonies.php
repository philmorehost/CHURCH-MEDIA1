<?php
declare(strict_types=1);

$metaTitle = 'Testimonies & Praise Reports — ' . ($s['site_title'] ?? 'Church');
$metaDescription = 'Read inspiring testimonies of God\'s goodness and share your own praise report with our church community.';
$path = '/testimonies';

$pdo = Database::getInstance()->getConnection();

// Fetch church units for the dropdown
$units = [];
try {
    $units = $pdo->query('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {}

// Query approved testimonies
$testimonies = [];
try {
    $testimonies = $pdo->query("SELECT t.*, u.name AS unit_name FROM testimonies t LEFT JOIN org_units u ON u.id = t.unit_id WHERE t.status = 'approved' ORDER BY COALESCE(t.approved_at, t.submitted_at) DESC, t.id DESC")->fetchAll();
} catch (Throwable $e) {}

require __DIR__ . '/partials/layout-open.php';
?>

<div class="page-hero">
  <div class="page-hero-inner">
    <span class="eyebrow">Praise Reports</span>
    <h1>Testimonies of Faith & Victory</h1>
    <p class="page-hero-sub">“They overcame him by the blood of the Lamb and by the word of their testimony.” — Revelation 12:11</p>
    <div style="margin-top:20px;">
      <a href="#submit-testimony" class="btn">Share Your Testimony</a>
    </div>
  </div>
</div>

<div class="container section">

  <?php if ($success = flash('testimony_success')): ?>
    <div class="alert success" style="margin-bottom:28px; background:#5fe0a418; border:1px solid #5fe0a444; color:#b6f5d8; padding:16px 20px; border-radius:12px;">
      <strong>Praise God!</strong> <?= e($success) ?>
    </div>
  <?php endif; ?>

  <?php if ($error = flash('testimony_error')): ?>
    <div class="alert error" style="margin-bottom:28px; background:#ff6b6b18; border:1px solid #ff6b6b44; color:#ffb3b3; padding:16px 20px; border-radius:12px;">
      <?= e($error) ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom:48px;">
    <h2 style="font-size:26px; font-weight:700; margin-bottom:24px; text-align:center;">Recent Praise Reports</h2>

    <?php if (!$testimonies): ?>
      <div class="glass-card" style="text-align:center; padding:48px 24px; max-width:600px; margin:0 auto;">
        <div style="font-size:40px; margin-bottom:12px;">🙌</div>
        <h3 style="margin-bottom:8px;">No testimonies published yet</h3>
        <p style="color:var(--ink-dim); margin-bottom:20px;">Be the first to share what God has done for you!</p>
        <a href="#submit-testimony" class="btn sm">Submit A Testimony</a>
      </div>
    <?php else: ?>
      <div class="grid grid-3" style="gap:24px;">
        <?php foreach ($testimonies as $t): ?>
          <div class="glass-card" style="display:flex; flex-direction:column; padding:28px; border-radius:16px; position:relative; overflow:hidden;">
            <?php if (!empty($t['media_url'])): ?>
              <div style="margin:-28px -28px 20px -28px; max-height:220px; overflow:hidden;">
                <img src="<?= e(uploadUrl($t['media_url'])) ?>" alt="<?= e($t['title']) ?>" style="width:100%; height:220px; object-fit:cover;">
              </div>
            <?php endif; ?>
            <div style="margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
              <span class="badge" style="background:var(--gold-dim); color:var(--gold-soft); font-size:11px; padding:4px 10px; border-radius:20px;">
                <?= e($t['unit_name'] ?: 'Grace Community') ?>
              </span>
              <span style="font-size:12px; color:var(--ink-dim);"><?= e(date('M j, Y', strtotime($t['approved_at'] ?: $t['submitted_at']))) ?></span>
            </div>
            <h3 style="font-size:19px; font-weight:700; margin:0 0 12px; color:var(--ink); line-height:1.3;"><?= e($t['title']) ?></h3>
            <div style="font-size:14px; color:var(--ink-dim); line-height:1.6; margin-bottom:20px; flex-grow:1;">
              <?= nl2br(e($t['content'])) ?>
            </div>
            <div style="border-top:1px solid var(--border-soft); padding-top:14px; display:flex; align-items:center; gap:10px;">
              <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, var(--gold), #c98a3d); color:#1a1530; font-weight:700; display:flex; align-items:center; justify-content:center; font-size:15px;">
                <?= e(mb_substr($t['name'], 0, 1)) ?>
              </div>
              <div>
                <strong style="display:block; font-size:13px; color:var(--ink);"><?= e($t['name']) ?></strong>
                <span style="font-size:11px; color:var(--ink-dim);">Verified Testimony</span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Testimony Submission Form -->
  <div id="submit-testimony" class="glass-card" style="max-width:720px; margin:0 auto; padding:36px 32px; border-radius:20px;">
    <div style="text-align:center; margin-bottom:28px;">
      <span class="eyebrow" style="color:var(--gold-soft);">Give Glory To God</span>
      <h2 style="font-size:24px; font-weight:700; margin:6px 0;">Share Your Praise Report</h2>
      <p style="color:var(--ink-dim); font-size:14px;">Your story can encourage and strengthen someone else's faith. Submissions are reviewed by our team before publishing.</p>
    </div>

    <form method="post" action="/testimonies" enctype="multipart/form-data">
      <?= Csrf::field() ?>

      <div class="row two">
        <div>
          <label for="name">Your Full Name <span style="color:var(--danger);">*</span></label>
          <input type="text" id="name" name="name" required placeholder="e.g. Sister Mercy Okon">
        </div>
        <div>
          <label for="email">Email Address <small>(optional)</small></label>
          <input type="email" id="email" name="email" placeholder="you@example.com">
        </div>
      </div>

      <div class="row two">
        <div>
          <label for="phone">Phone Number <small>(optional)</small></label>
          <input type="text" id="phone" name="phone" placeholder="+234 800 000 0000">
        </div>
        <div>
          <label for="unit_id">Parish / Church Unit <small>(optional)</small></label>
          <select id="unit_id" name="unit_id">
            <option value="">— Select Parish/Unit —</option>
            <?php foreach ($units as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div>
        <label for="title">Testimony Title <span style="color:var(--danger);">*</span></label>
        <input type="text" id="title" name="title" required placeholder="e.g. Divine Healing and Miraculous Job Breakthrough">
      </div>

      <div>
        <label for="content">Your Testimony / Praise Report <span style="color:var(--danger);">*</span></label>
        <textarea id="content" name="content" rows="6" required placeholder="Describe what God did for you in detail..."></textarea>
      </div>

      <div>
        <label for="media">Upload Photo or Image Proof <small>(optional, max 8MB)</small></label>
        <input type="file" id="media" name="media" accept="image/*">
      </div>

      <div style="margin-top:20px;">
        <button type="submit" class="btn" style="width:100%;">Submit Praise Report</button>
      </div>
    </form>
  </div>

</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
