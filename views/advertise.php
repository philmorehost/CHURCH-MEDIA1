<?php
declare(strict_types=1);

$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->query('SELECT * FROM ad_durations WHERE is_active = 1 ORDER BY sort_order ASC, days ASC');
$durations = $stmt->fetchAll();

$metaTitle = 'Place an Advert';
$metaDescription = 'Advertise on our website and Mobile App. Place video or image display ads with targeted visibility and performance tracking.';
?>
<div class="container section" style="max-width:760px; padding-top:40px; padding-bottom:60px;">
  <div style="text-align:center; margin-bottom:32px;">
    <span class="eyebrow" style="color:var(--gold-soft); font-weight:700; text-transform:uppercase; letter-spacing:1px; font-size:13px;">Reach Thousands</span>
    <h1 style="margin:8px 0 12px; font-size:32px;">Advertise With Us</h1>
    <p style="color:var(--ink-dim); max-width:580px; margin:0 auto; font-size:15px; line-height:1.6;">
      Promote your brand, ministry, or business across our Website and Mobile App. Submit your vertical video or image ad below. Once approved by our team, your ad will go live immediately!
    </p>
  </div>

  <?php if ($msg = flash('advertise_error')): ?>
    <div class="alert error" style="margin-bottom:20px; padding:12px 16px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:8px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if (isset($_GET['sent']) || flash('advertise_sent')): ?>
    <div class="card" style="text-align:center; padding:40px 24px;">
      <div style="font-size:48px; margin-bottom:12px;">🎉</div>
      <h2 style="margin-bottom:12px;">Advert Submitted Successfully!</h2>
      <p style="color:var(--ink-dim); max-width:500px; margin:0 auto 20px; line-height:1.6;">
        Thank you for submitting your advertisement. Our admin team will review and approve your submission shortly.
      </p>
      <p style="color:var(--gold-soft); font-size:14px; font-weight:600;">
        ✉ Once approved, an email with your secure Ad Manager access link will be sent to your email address!
      </p>
      <div style="margin-top:24px;">
        <a class="btn" href="/">Return to Home</a>
      </div>
    </div>
  <?php else: ?>
    <div class="card glass-card" style="padding:32px; border-radius:12px;">
      <form method="post" action="/advertise" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <!-- Honeypot -->
        <div style="display:none;">
          <input type="text" name="website" value="">
        </div>

        <h3 style="margin-top:0; margin-bottom:18px; font-size:18px; border-bottom:1px solid var(--border); padding-bottom:10px;">1. Advertiser Information</h3>

        <div class="row two" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
          <div>
            <label for="publisher_name" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Your Name / Business Name *</label>
            <input type="text" id="publisher_name" name="publisher_name" value="<?= formOld('publisher_name') ?>" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
          </div>
          <div>
            <label for="publisher_email" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Email Address *</label>
            <input type="email" id="publisher_email" name="publisher_email" value="<?= formOld('publisher_email') ?>" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
          </div>
        </div>

        <div style="margin-bottom:24px;">
          <label for="publisher_phone" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">WhatsApp / Phone Number</label>
          <input type="tel" id="publisher_phone" name="publisher_phone" value="<?= formOld('publisher_phone') ?>" style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
        </div>

        <h3 style="margin-top:28px; margin-bottom:18px; font-size:18px; border-bottom:1px solid var(--border); padding-bottom:10px;">2. Advert Details</h3>

        <div style="margin-bottom:16px;">
          <label for="title" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Ad Title / Campaign Name *</label>
          <input type="text" id="title" name="title" value="<?= formOld('title') ?>" placeholder="e.g. Easter Youth Conference 2026" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
        </div>

        <div style="margin-bottom:16px;">
          <label for="destination_url" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Destination Website / Action Link</label>
          <input type="url" id="destination_url" name="destination_url" value="<?= formOld('destination_url') ?>" placeholder="https://yourwebsite.com/offer" style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
        </div>

        <div class="row two" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
          <div>
            <label for="target_platform" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Target Placement Platform</label>
            <select id="target_platform" name="target_platform" style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, #1e1b2e); color:inherit;">
              <option value="both" <?= formOld('target_platform') === 'both' ? 'selected' : '' ?>>Both Website &amp; Mobile App</option>
              <option value="web" <?= formOld('target_platform') === 'web' ? 'selected' : '' ?>>Website Only</option>
              <option value="app" <?= formOld('target_platform') === 'app' ? 'selected' : '' ?>>Mobile App Only</option>
            </select>
          </div>

          <div>
            <label for="duration_days" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Ad Display Duration *</label>
            <select id="duration_days" name="duration_days" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, #1e1b2e); color:inherit;">
              <?php foreach ($durations as $d): ?>
                <option value="<?= (int) $d['days'] ?>" <?= (int) formOld('duration_days') === (int) $d['days'] ? 'selected' : '' ?>><?= e($d['title']) ?> (<?= (int) $d['days'] ?> Days)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <h3 style="margin-top:28px; margin-bottom:18px; font-size:18px; border-bottom:1px solid var(--border); padding-bottom:10px;">3. Media Upload (Vertical 9:16 Format)</h3>

        <div style="margin-bottom:16px;">
          <label style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Media Type *</label>
          <div style="display:flex; gap:20px; align-items:center;">
            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
              <input type="radio" name="media_type" value="image" checked onclick="toggleMediaType('image')"> Image Ad
            </label>
            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
              <input type="radio" name="media_type" value="video" onclick="toggleMediaType('video')"> Video Ad
            </label>
          </div>
          <p style="font-size:12px; color:var(--ink-faint); margin-top:6px;">
            ✨ Note: Images and videos are automatically formatted into vertical 9:16 aspect ratio (Instagram/Facebook Reels style).
          </p>
        </div>

        <div id="media_file_wrap" style="margin-bottom:24px;">
          <label for="media_file" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Select File *</label>
          <input type="file" id="media_file" name="media_file" accept="image/*,video/mp4,video/quicktime,video/webm" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
        </div>

        <div style="margin-top:32px; text-align:right;">
          <button type="submit" class="btn btn-gold" style="padding:12px 28px; font-size:16px; font-weight:700;">🚀 Submit Advert for Approval</button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>

<script>
function toggleMediaType(type) {
  var fileInput = document.getElementById('media_file');
  if (type === 'video') {
    fileInput.accept = 'video/mp4,video/quicktime,video/webm';
  } else {
    fileInput.accept = 'image/*';
  }
}
</script>
