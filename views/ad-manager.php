<?php
declare(strict_types=1);

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(403);
    exit('Access denied — invalid or missing access token.');
}

$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare('SELECT * FROM ad_publishers WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$publisher = $stmt->fetch();

if (!$publisher) {
    http_response_code(403);
    exit('Access denied — publisher account not found.');
}

// Fetch publisher's ads with live stats
$stmt = $pdo->prepare('SELECT * FROM ads WHERE publisher_id = ? ORDER BY created_at DESC');
$stmt->execute([(int) $publisher['id']]);
$ads = $stmt->fetchAll();

// Calculate total summary stats
$totalViews = 0;
$totalClicks = 0;
$activeCount = 0;
foreach ($ads as $ad) {
    $totalViews += (int) $ad['views_count'];
    $totalClicks += (int) $ad['clicks_count'];
    if ($ad['status'] === 'approved' && $ad['expires_at'] && strtotime($ad['expires_at']) > time()) {
        $activeCount++;
    }
}
$overallCtr = $totalViews > 0 ? round(($totalClicks / $totalViews) * 100, 2) : 0.0;

$durationsStmt = $pdo->query('SELECT * FROM ad_durations WHERE is_active = 1 ORDER BY sort_order ASC, days ASC');
$durations = $durationsStmt->fetchAll();

$metaTitle = 'Publisher Ad Manager · ' . $publisher['name'];
$metaDescription = 'Manage your advertisements and monitor campaign performance.';
?>
<div class="container section" style="max-width:960px; padding-top:40px; padding-bottom:60px;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:32px; border-bottom:1px solid var(--border); padding-bottom:20px;">
    <div>
      <span class="eyebrow" style="color:var(--gold-soft); font-weight:700; text-transform:uppercase; letter-spacing:1px; font-size:12px;">Ad Publisher Portal</span>
      <h1 style="margin:4px 0 0; font-size:28px;"><?= e($publisher['name']) ?></h1>
      <p style="color:var(--ink-faint); margin:4px 0 0; font-size:14px;"><?= e($publisher['email']) ?> <?= $publisher['phone'] ? '· ' . e($publisher['phone']) : '' ?></p>
    </div>
    <div>
      <a class="btn btn-gold" href="#create-ad" onclick="document.getElementById('newAdModal').style.display='block';">+ Create New Advert</a>
    </div>
  </div>

  <?php if ($msg = flash('pub_error')): ?>
    <div class="alert error" style="margin-bottom:20px; padding:12px 16px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:8px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('pub_success')): ?>
    <div class="alert success" style="margin-bottom:20px; padding:12px 16px; background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); color:#34d399; border-radius:8px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>

  <!-- Summary Stats Overview -->
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:36px;">
    <div class="card glass-card" style="padding:20px; border-radius:10px;">
      <span style="font-size:12px; color:var(--ink-faint); text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Active Campaigns</span>
      <div style="font-size:32px; font-weight:800; color:#34d399; margin-top:6px;"><?= $activeCount ?></div>
    </div>
    <div class="card glass-card" style="padding:20px; border-radius:10px;">
      <span style="font-size:12px; color:var(--ink-faint); text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Total Impressions (Views)</span>
      <div style="font-size:32px; font-weight:800; color:var(--ink-base); margin-top:6px;"><?= number_format($totalViews) ?></div>
    </div>
    <div class="card glass-card" style="padding:20px; border-radius:10px;">
      <span style="font-size:12px; color:var(--ink-faint); text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Total Clicks</span>
      <div style="font-size:32px; font-weight:800; color:var(--gold-soft); margin-top:6px;"><?= number_format($totalClicks) ?></div>
    </div>
    <div class="card glass-card" style="padding:20px; border-radius:10px;">
      <span style="font-size:12px; color:var(--ink-faint); text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Avg Click-Through Rate</span>
      <div style="font-size:32px; font-weight:800; color:#60a5fa; margin-top:6px;"><?= $overallCtr ?>%</div>
    </div>
  </div>

  <!-- Ad Campaigns Table -->
  <div class="card glass-card" style="padding:24px; border-radius:12px;">
    <h2 style="font-size:20px; margin-bottom:20px;">Your Advertisements</h2>

    <?php if (!$ads): ?>
      <div style="text-align:center; padding:40px 20px; color:var(--ink-faint);">
        <p>You have not created any advertisements yet.</p>
        <button class="btn btn-gold" onclick="document.getElementById('newAdModal').style.display='block';">Create Your First Advert</button>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; text-align:left;">
          <thead>
            <tr style="border-bottom:1px solid var(--border); font-size:13px; color:var(--ink-faint); text-transform:uppercase; letter-spacing:0.5px;">
              <th style="padding:12px 8px;">Ad / Media</th>
              <th style="padding:12px 8px;">Target</th>
              <th style="padding:12px 8px;">Duration &amp; Expiry</th>
              <th style="padding:12px 8px;">Views</th>
              <th style="padding:12px 8px;">Clicks</th>
              <th style="padding:12px 8px;">CTR %</th>
              <th style="padding:12px 8px;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ads as $ad): ?>
              <?php
                $v = (int) $ad['views_count'];
                $c = (int) $ad['clicks_count'];
                $ctr = $v > 0 ? round(($c / $v) * 100, 2) : 0.0;
                $isExpired = $ad['expires_at'] && strtotime($ad['expires_at']) <= time();
              ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:12px 8px;">
                  <div style="display:flex; gap:12px; align-items:center;">
                    <div style="width:40px; height:70px; background:#110e1b; border-radius:4px; overflow:hidden; flex-shrink:0; border:1px solid var(--border);">
                      <?php if ($ad['media_type'] === 'image'): ?>
                        <img src="<?= e(uploadUrl($ad['file_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                      <?php else: ?>
                        <?php if (!empty($ad['thumbnail_path'])): ?>
                          <img src="<?= e(uploadUrl($ad['thumbnail_path'])) ?>" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                          <video src="<?= e(uploadUrl($ad['file_path'])) ?>" style="width:100%; height:100%; object-fit:cover;" muted></video>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                    <div>
                      <strong style="font-size:14px;"><?= e($ad['title']) ?></strong><br>
                      <span class="badge info" style="font-size:10px;"><?= e(strtoupper($ad['media_type'])) ?></span>
                      <?php if ($ad['destination_url']): ?>
                        <br><a href="<?= e($ad['destination_url']) ?>" target="_blank" style="font-size:11px; color:var(--gold-soft);">↗ Link</a>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td style="padding:12px 8px; font-size:13px; text-transform:uppercase; font-weight:600;">
                  <?= e($ad['target_platform']) ?>
                </td>
                <td style="padding:12px 8px; font-size:13px;">
                  <strong><?= (int) $ad['duration_days'] ?> Days</strong>
                  <?php if ($ad['status'] === 'approved' && $ad['expires_at']): ?>
                    <br><span style="font-size:11px; color:var(--ink-faint);"><?= $isExpired ? 'Expired' : 'Expires ' . date('M j, Y', strtotime($ad['expires_at'])) ?></span>
                  <?php endif; ?>
                </td>
                <td style="padding:12px 8px; font-weight:700; font-size:15px;"><?= number_format($v) ?></td>
                <td style="padding:12px 8px; font-weight:700; font-size:15px; color:var(--gold-soft);"><?= number_format($c) ?></td>
                <td style="padding:12px 8px; font-weight:700; font-size:14px; color:#60a5fa;"><?= $ctr ?>%</td>
                <td style="padding:12px 8px;">
                  <?php if ($ad['status'] === 'pending'): ?>
                    <span class="badge warn" style="padding:4px 8px; border-radius:4px; font-size:11px;">Pending Approval</span>
                  <?php elseif ($ad['status'] === 'approved'): ?>
                    <?php if ($isExpired): ?>
                      <span class="badge fail" style="padding:4px 8px; border-radius:4px; font-size:11px;">Expired</span>
                    <?php else: ?>
                      <span class="badge ok" style="padding:4px 8px; border-radius:4px; font-size:11px;">Active / Live</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge fail" style="padding:4px 8px; border-radius:4px; font-size:11px;">Rejected</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal for Creating New Advert from Publisher Portal -->
<div id="newAdModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; overflow-y:auto; padding:30px 15px;">
  <div class="card glass-card" style="max-width:600px; margin:20px auto; padding:32px; border-radius:12px; position:relative;">
    <button type="button" onclick="document.getElementById('newAdModal').style.display='none';" style="position:absolute; top:16px; right:16px; background:none; border:none; color:inherit; font-size:24px; cursor:pointer;">&times;</button>
    <h2 style="margin-top:0; margin-bottom:20px;">Create New Advert</h2>

    <form method="post" action="/ad-manager?token=<?= rawurlencode($publisher['token']) ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>

      <div style="margin-bottom:16px;">
        <label for="modal_title" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Ad Title / Campaign Name *</label>
        <input type="text" id="modal_title" name="title" required placeholder="e.g. Special Easter Promotion" style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
      </div>

      <div style="margin-bottom:16px;">
        <label for="modal_destination_url" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Destination URL</label>
        <input type="url" id="modal_destination_url" name="destination_url" placeholder="https://yourwebsite.com/offer" style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
      </div>

      <div class="row two" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label for="modal_target_platform" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Target Platform</label>
          <select id="modal_target_platform" name="target_platform" style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, #1e1b2e); color:inherit;">
            <option value="both">Both Web &amp; App</option>
            <option value="web">Website Only</option>
            <option value="app">Mobile App Only</option>
          </select>
        </div>

        <div>
          <label for="modal_duration_days" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Duration *</label>
          <select id="modal_duration_days" name="duration_days" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, #1e1b2e); color:inherit;">
            <?php foreach ($durations as $d): ?>
              <option value="<?= (int) $d['days'] ?>"><?= e($d['title']) ?> (<?= (int) $d['days'] ?> Days)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <label style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Media Type *</label>
        <div style="display:flex; gap:20px; align-items:center;">
          <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
            <input type="radio" name="media_type" value="image" checked onclick="toggleModalMediaType('image')"> Image Ad
          </label>
          <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
            <input type="radio" name="media_type" value="video" onclick="toggleModalMediaType('video')"> Video Ad
          </label>
        </div>
      </div>

      <div style="margin-bottom:24px;">
        <label for="modal_media_file" style="display:block; margin-bottom:6px; font-weight:600; font-size:14px;">Upload Media * (Auto 9:16 Vertical)</label>
        <input type="file" id="modal_media_file" name="media_file" accept="image/*" required style="width:100%; padding:10px 12px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input, rgba(255,255,255,0.05)); color:inherit;">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:12px;">
        <button type="button" class="btn secondary" onclick="document.getElementById('newAdModal').style.display='none';">Cancel</button>
        <button type="submit" class="btn btn-gold">Submit Advert</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleModalMediaType(type) {
  var fileInput = document.getElementById('modal_media_file');
  if (type === 'video') {
    fileInput.accept = 'video/mp4,video/quicktime,video/webm';
  } else {
    fileInput.accept = 'image/*';
  }
}
</script>
