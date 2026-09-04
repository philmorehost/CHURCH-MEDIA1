<?php
declare(strict_types=1);

Auth::requireRole('admin');
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can manage settings.');
}
$pdo = Database::getInstance()->getConnection();
$errors = [];

$row = $pdo->query('SELECT * FROM settings ORDER BY id ASC LIMIT 1')->fetch();
$serviceTimes = $row && $row['service_times'] ? (json_decode($row['service_times'], true) ?: []) : [];

// Test the cPanel connection using the values currently in the form.
if (($_GET['action'] ?? '') === 'test_cpanel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $api = new CpanelApi([
        'host' => trim($_POST['email_cpanel_host'] ?? ''),
        'user' => trim($_POST['email_cpanel_user'] ?? ''),
        'token' => trim((string) ($_POST['email_cpanel_token'] ?? '')),
    ]);
    if (!$api->configured()) {
        flash('error', 'Fill in the cPanel host, username, and API token first, then test.');
    } else {
        $res = $api->testConnection();
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'cPanel connection OK — your API token works.' : 'cPanel connection failed: ' . ($res['error'] ?? 'unknown error'));
    }
    redirect('/admin/settings');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $fields = [
        'site_title' => trim($_POST['site_title'] ?? ''),
        'site_tagline' => trim($_POST['site_tagline'] ?? ''),
        'hero_tagline' => trim($_POST['hero_tagline'] ?? ''),
        'hero_scripture' => trim($_POST['hero_scripture'] ?? ''),
        'hero_eyebrow' => trim($_POST['hero_eyebrow'] ?? ''),
        'hero_cta_primary_label' => trim($_POST['hero_cta_primary_label'] ?? ''),
        'hero_cta_primary_url' => trim($_POST['hero_cta_primary_url'] ?? ''),
        'hero_cta_secondary_label' => trim($_POST['hero_cta_secondary_label'] ?? ''),
        'hero_cta_secondary_url' => trim($_POST['hero_cta_secondary_url'] ?? ''),
        'contact_email' => trim($_POST['contact_email'] ?? ''),
        'contact_phone' => trim($_POST['contact_phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'facebook_url' => trim($_POST['facebook_url'] ?? ''),
        'instagram_url' => trim($_POST['instagram_url'] ?? ''),
        'youtube_url' => trim($_POST['youtube_url'] ?? ''),
        'tiktok_url' => trim($_POST['tiktok_url'] ?? ''),
        'livestream_embed_url' => trim($_POST['livestream_embed_url'] ?? ''),
        'livestream_is_live' => isset($_POST['livestream_is_live']) ? 1 : 0,
        'giving_url' => trim($_POST['giving_url'] ?? ''),
        'app_download_enabled' => isset($_POST['app_download_enabled']) ? 1 : 0,
        'app_download_url' => trim($_POST['app_download_url'] ?? ''),
        'app_download_pages' => trim($_POST['app_download_pages'] ?? ''),
        'app_redirect_mode' => in_array($_POST['app_redirect_mode'] ?? 'off', ['off', 'banner', 'interstitial', 'force'], true) ? $_POST['app_redirect_mode'] : 'off',
        'footer_about_text' => trim($_POST['footer_about_text'] ?? ''),
        'meta_description' => trim($_POST['meta_description'] ?? ''),
        'bible_source' => trim($_POST['bible_source'] ?? 'keyless'),
        'bible_api_key' => trim($_POST['bible_api_key'] ?? ''),
        'smtp_host' => trim($_POST['smtp_host'] ?? ''),
        'smtp_port' => (int) ($_POST['smtp_port'] ?? 587),
        'smtp_secure' => in_array($_POST['smtp_secure'] ?? 'tls', ['ssl', 'tls', ''], true) ? $_POST['smtp_secure'] : 'tls',
        'smtp_username' => trim($_POST['smtp_username'] ?? ''),
        'smtp_password' => (string) ($_POST['smtp_password'] ?? ''),
        'smtp_from' => trim($_POST['smtp_from'] ?? ''),
        'email_cpanel_enabled' => isset($_POST['email_cpanel_enabled']) ? 1 : 0,
        'email_cpanel_host' => trim($_POST['email_cpanel_host'] ?? ''),
        'email_cpanel_user' => trim($_POST['email_cpanel_user'] ?? ''),
        'email_cpanel_token' => trim((string) ($_POST['email_cpanel_token'] ?? '')),
        'email_domain' => trim($_POST['email_domain'] ?? ''),
        'email_default_quota' => (int) ($_POST['email_default_quota'] ?? 500),
    ];

    $labels = $_POST['service_label'] ?? [];
    $times = $_POST['service_time'] ?? [];
    $newServiceTimes = [];
    foreach ($labels as $i => $label) {
        $label = trim($label);
        $time = trim($times[$i] ?? '');
        if ($label !== '' && $time !== '') {
            $newServiceTimes[] = ['label' => $label, 'time' => $time];
        }
    }
    $fields['service_times'] = json_encode($newServiceTimes);

    if ($fields['site_title'] === '') {
        $errors[] = 'Site title is required.';
    } else {
        $imageErrors = [];
        if (!empty($_FILES['logo']['name'])) {
            if (($_FILES['logo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $imageErrors[] = 'Logo upload failed — the file may be too large for the server.';
            } elseif (!is_uploaded_file($_FILES['logo']['tmp_name'] ?? '') || !($filename = MediaProcessor::processImage($_FILES['logo']['tmp_name'], UPLOADS_WEBP_PATH))) {
                $imageErrors[] = 'Logo could not be processed — use JPG, PNG, GIF, WebP, BMP, or AVIF.';
            } else {
                $fields['logo_path'] = 'webp/' . $filename;
            }
        }
        if (!empty($_FILES['favicon']['name'])) {
            if (($_FILES['favicon']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $imageErrors[] = 'Favicon upload failed — the file may be too large for the server.';
            } elseif (!is_uploaded_file($_FILES['favicon']['tmp_name'] ?? '') || !($filename = MediaProcessor::processImage($_FILES['favicon']['tmp_name'], UPLOADS_WEBP_PATH))) {
                $imageErrors[] = 'Favicon could not be processed — use JPG, PNG, GIF, WebP, BMP, or AVIF.';
            } else {
                $fields['favicon_path'] = 'webp/' . $filename;
            }
        }
        if (isset($_POST['remove_hero_image'])) {
            $fields['hero_image_path'] = null;
        } elseif (!empty($_FILES['hero_image']['name'])) {
            if (($_FILES['hero_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $imageErrors[] = 'Hero image upload failed — the file may be too large for the server (increase upload_max_filesize/post_max_size in php.ini).';
            } elseif (!is_uploaded_file($_FILES['hero_image']['tmp_name'] ?? '') || !($filename = MediaProcessor::processImage($_FILES['hero_image']['tmp_name'], UPLOADS_WEBP_PATH, 80))) {
                $imageErrors[] = 'Hero image could not be processed — use JPG, PNG, GIF, WebP, BMP, or AVIF (iPhone HEIC files are not supported).';
            } else {
                $fields['hero_image_path'] = 'webp/' . $filename;
            }
        }

        $setSql = implode(', ', array_map(fn ($k) => "$k = :$k", array_keys($fields)));
        $pdo->prepare("UPDATE settings SET $setSql WHERE id = :id")->execute([...$fields, 'id' => $row['id']]);
        if ($imageErrors) {
            flash('error', implode(' ', $imageErrors) . ' Other settings were still saved.');
        } else {
            flash('success', 'Settings saved.');
        }
        redirect('/admin/settings');
    }
}

$row = $pdo->query('SELECT * FROM settings ORDER BY id ASC LIMIT 1')->fetch();
$serviceTimes = $row['service_times'] ? (json_decode($row['service_times'], true) ?: []) : [];
while (count($serviceTimes) < 4) {
    $serviceTimes[] = ['label' => '', 'time' => ''];
}

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <?= Csrf::field() ?>

  <div class="card">
    <h2>Branding</h2>
    <div class="row two">
      <div>
        <label for="site_title">Site / Church Name</label>
        <input type="text" id="site_title" name="site_title" value="<?= e($row['site_title']) ?>" required>
      </div>
      <div>
        <label for="site_tagline">Tagline</label>
        <input type="text" id="site_tagline" name="site_tagline" value="<?= e((string) $row['site_tagline']) ?>">
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="logo">Logo <?= $row['logo_path'] ? '(currently set)' : '' ?></label>
        <input type="file" id="logo" name="logo" accept="image/*">
        <?php if ($row['logo_path']): ?><img src="<?= e(uploadUrl($row['logo_path'])) ?>" class="thumb" alt=""><?php endif; ?>
      </div>
      <div>
        <label for="favicon">Favicon <?= $row['favicon_path'] ? '(currently set)' : '' ?></label>
        <input type="file" id="favicon" name="favicon" accept="image/*">
        <?php if ($row['favicon_path']): ?><img src="<?= e(uploadUrl($row['favicon_path'])) ?>" class="thumb" alt=""><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Homepage Hero</h2>
    <p class="sub">The large banner at the top of the homepage. Upload a background image (compressed to WebP automatically) and edit the text that sits on top of it.</p>
    <label for="hero_image">Hero Background Image <?= $row['hero_image_path'] ? '(currently set)' : '' ?></label>
    <input type="file" id="hero_image" name="hero_image" accept="image/*">
    <?php if ($row['hero_image_path']): ?>
      <div style="display:flex; align-items:center; gap:14px; margin:10px 0;">
        <img src="<?= e(uploadUrl($row['hero_image_path'])) ?>" class="thumb" alt="" style="width:120px; height:68px; object-fit:cover; border-radius:10px;">
        <label class="checkbox-row" style="margin:0;">
          <input type="checkbox" id="remove_hero_image" name="remove_hero_image">
          <label for="remove_hero_image" style="margin:0;">Remove current image (back to the animated gradient)</label>
        </label>
      </div>
    <?php endif; ?>
    <label for="hero_eyebrow">Eyebrow Text <small>(small label above the headline)</small></label>
    <input type="text" id="hero_eyebrow" name="hero_eyebrow" value="<?= e((string) $row['hero_eyebrow']) ?>" placeholder="Welcome Home">
    <label for="hero_tagline2">Headline (Hero Tagline)</label>
    <input type="text" id="hero_tagline2" name="hero_tagline" value="<?= e((string) $row['hero_tagline']) ?>" placeholder="Where Faith Comes Alive">
    <label for="hero_scripture2">Scripture Line</label>
    <input type="text" id="hero_scripture2" name="hero_scripture" value="<?= e((string) $row['hero_scripture']) ?>">
    <div class="row two">
      <div>
        <label for="hero_cta_primary_label">Primary Button Label</label>
        <input type="text" id="hero_cta_primary_label" name="hero_cta_primary_label" value="<?= e((string) $row['hero_cta_primary_label']) ?>" placeholder="Plan Your Visit">
      </div>
      <div>
        <label for="hero_cta_primary_url">Primary Button Link</label>
        <input type="text" id="hero_cta_primary_url" name="hero_cta_primary_url" value="<?= e((string) $row['hero_cta_primary_url']) ?>" placeholder="/about or https://…">
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="hero_cta_secondary_label">Secondary Button Label</label>
        <input type="text" id="hero_cta_secondary_label" name="hero_cta_secondary_label" value="<?= e((string) $row['hero_cta_secondary_label']) ?>" placeholder="Watch the Feed">
      </div>
      <div>
        <label for="hero_cta_secondary_url">Secondary Button Link</label>
        <input type="text" id="hero_cta_secondary_url" name="hero_cta_secondary_url" value="<?= e((string) $row['hero_cta_secondary_url']) ?>" placeholder="/feed or https://…">
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Contact &amp; Service Times</h2>
    <div class="row two">
      <div><label for="contact_email">Contact Email</label><input type="email" id="contact_email" name="contact_email" value="<?= e((string) $row['contact_email']) ?>"></div>
      <div><label for="contact_phone">Contact Phone</label><input type="text" id="contact_phone" name="contact_phone" value="<?= e((string) $row['contact_phone']) ?>"></div>
    </div>
    <label for="address">Address</label>
    <input type="text" id="address" name="address" value="<?= e((string) $row['address']) ?>">
    <label>Service Times</label>
    <?php foreach ($serviceTimes as $st): ?>
      <div class="row two">
        <input type="text" name="service_label[]" value="<?= e($st['label']) ?>" placeholder="Sunday Worship">
        <input type="text" name="service_time[]" value="<?= e($st['time']) ?>" placeholder="9:00 AM & 11:00 AM">
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>Social &amp; Live</h2>
    <div class="row two">
      <div><label for="facebook_url">Facebook URL</label><input type="url" id="facebook_url" name="facebook_url" value="<?= e((string) $row['facebook_url']) ?>"></div>
      <div><label for="instagram_url">Instagram URL</label><input type="url" id="instagram_url" name="instagram_url" value="<?= e((string) $row['instagram_url']) ?>"></div>
    </div>
    <div class="row two">
      <div><label for="youtube_url">YouTube URL</label><input type="url" id="youtube_url" name="youtube_url" value="<?= e((string) $row['youtube_url']) ?>"></div>
      <div><label for="tiktok_url">TikTok URL</label><input type="url" id="tiktok_url" name="tiktok_url" value="<?= e((string) $row['tiktok_url']) ?>"></div>
    </div>
    <label for="livestream_embed_url">Livestream YouTube Link (paste your channel's live video URL)</label>
    <input type="url" id="livestream_embed_url" name="livestream_embed_url" value="<?= e((string) $row['livestream_embed_url']) ?>" placeholder="https://www.youtube.com/watch?v=... or https://www.youtube.com/live/...">
    <div class="checkbox-row">
      <input type="checkbox" id="livestream_is_live" name="livestream_is_live" <?= !empty($row['livestream_is_live']) ? 'checked' : '' ?>>
      <label for="livestream_is_live" style="margin:0;">We are live right now (shows "LIVE" badge site-wide)</label>
    </div>
    <label for="giving_url">Giving / Donation URL</label>
    <input type="url" id="giving_url" name="giving_url" value="<?= e((string) $row['giving_url']) ?>" placeholder="https://giving-platform.com/your-church">
  </div>

  <div class="card">
    <h2>Mobile App Download Button</h2>
    <p class="sub">Shows a floating "Get it on Google Play" button on the left edge of the website so visitors can download your app. Paste the Google Play listing link for the app below.</p>
    <div class="checkbox-row">
      <input type="checkbox" id="app_download_enabled" name="app_download_enabled" <?= !empty($row['app_download_enabled']) ? 'checked' : '' ?>>
      <label for="app_download_enabled" style="margin:0;">Enable the app download button on the website</label>
    </div>
    <label for="app_download_url">Google Play App Link</label>
    <input type="url" id="app_download_url" name="app_download_url" value="<?= e((string) ($row['app_download_url'] ?? '')) ?>" placeholder="https://play.google.com/store/apps/details?id=com.churchmedia.app">
    <label for="app_download_pages">Show on pages</label>
    <input type="text" id="app_download_pages" name="app_download_pages" value="<?= e((string) ($row['app_download_pages'] ?? '')) ?>" placeholder="all">
    <p class="hint" style="margin-top:6px;">Type <code>all</code> to show it on every page, or a comma-separated list of page paths, e.g. <code>/, /feed, /media, /events, /sermons</code>.</p>

    <hr style="border:0;border-top:1px solid var(--border);margin:18px 0;">
    <h3 style="margin:0 0 6px;">Admin &amp; App Only Mode</h3>
    <p class="sub">Push Android phone visitors toward the app. A landing page is shown with the Google Play button and a “Continue to website” link; <em>force</em> sends them straight to the Play link. iPhone and desktop visitors always keep the normal site, and search engines are never redirected.</p>
    <label for="app_redirect_mode">Redirect Android mobile visitors</label>
    <select id="app_redirect_mode" name="app_redirect_mode">
      <option value="off" <?= ($row['app_redirect_mode'] ?? 'off') === 'off' ? 'selected' : '' ?>>Off — keep the website as-is</option>
      <option value="banner" <?= ($row['app_redirect_mode'] ?? '') === 'banner' ? 'selected' : '' ?>>Show a small dismissible banner (light touch)</option>
      <option value="interstitial" <?= ($row['app_redirect_mode'] ?? '') === 'interstitial' ? 'selected' : '' ?>>Show a “Get the App” landing page (recommended)</option>
      <option value="force" <?= ($row['app_redirect_mode'] ?? '') === 'force' ? 'selected' : '' ?>>Force — send straight to the Play link</option>
    </select>
  </div>

  <div class="card">
    <h2>Footer &amp; SEO</h2>
    <label for="footer_about_text">Footer About Text</label>
    <textarea id="footer_about_text" name="footer_about_text"><?= e((string) $row['footer_about_text']) ?></textarea>
    <label for="meta_description">Meta Description (SEO)</label>
    <textarea id="meta_description" name="meta_description"><?= e((string) $row['meta_description']) ?></textarea>
  </div>

  <div class="card">
    <h2>Bible</h2>
    <p class="sub">Choose the source that powers the Bible page (/bible) and the mobile app's Bible screen. The key-less option works with no signup but only provides public-domain translations (KJV/WEB). API.Bible (scripture.api.bible) adds modern translations like NIV, NLT, and NKJV — you'll need to register a free API key at <a href="https://scripture.api.bible/" target="_blank" rel="noopener">scripture.api.bible</a>.</p>
    <label for="bible_source">Bible Source</label>
    <select id="bible_source" name="bible_source">
      <option value="keyless" <?= ($row['bible_source'] ?? 'keyless') === 'keyless' ? 'selected' : '' ?>>Key-less (free — public domain translations)</option>
      <option value="api_bible" <?= ($row['bible_source'] ?? '') === 'api_bible' ? 'selected' : '' ?>>API.Bible (NIV, NLT, NKJV — requires API key)</option>
    </select>
    <div id="bible-api-key-wrap" style="<?= ($row['bible_source'] ?? 'keyless') === 'api_bible' ? '' : 'display:none;' ?>">
      <label for="bible_api_key">API.Bible API Key</label>
      <input type="text" id="bible_api_key" name="bible_api_key" value="<?= e((string) ($row['bible_api_key'] ?? '')) ?>" placeholder="Paste your api.bible access token" autocomplete="off">
    </div>
  </div>

  <div class="card">
    <h2>Email (SMTP)</h2>
    <p class="sub">Used for all outgoing mail — newsletters, church notifications, and security alerts. Leave Host empty to fall back to PHP mail().</p>
    <div class="row two">
      <div><label for="smtp_host">SMTP Host</label><input type="text" id="smtp_host" name="smtp_host" value="<?= e((string) ($row['smtp_host'] ?? '')) ?>" placeholder="smtp.gmail.com"></div>
      <div><label for="smtp_port">SMTP Port</label><input type="number" id="smtp_port" name="smtp_port" value="<?= e((string) ($row['smtp_port'] ?? 587)) ?>" min="1" max="65535"></div>
    </div>
    <div class="row two">
      <div><label for="smtp_secure">Encryption</label>
        <select id="smtp_secure" name="smtp_secure">
          <option value="tls" <?= ($row['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS — recommended)</option>
          <option value="ssl" <?= ($row['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
          <option value="" <?= ($row['smtp_secure'] ?? '') === '' ? 'selected' : '' ?>>None</option>
        </select>
      </div>
      <div><label for="smtp_from">From Address</label><input type="email" id="smtp_from" name="smtp_from" value="<?= e((string) ($row['smtp_from'] ?? '')) ?>" placeholder="no-reply@yourchurch.org"></div>
    </div>
    <div class="row two">
      <div><label for="smtp_username">Username</label><input type="text" id="smtp_username" name="smtp_username" value="<?= e((string) ($row['smtp_username'] ?? '')) ?>" autocomplete="off"></div>
      <div><label for="smtp_password">Password</label><input type="password" id="smtp_password" name="smtp_password" value="<?= e((string) ($row['smtp_password'] ?? '')) ?>" autocomplete="new-password"></div>
    </div>
  </div>

  <div class="card">
    <h2>Corporate Email (cPanel)</h2>
    <p class="sub">When you approve a church registration, the app can automatically create a corporate email for that admin — e.g. <code>sopadmin@<?= e((string) ($row['email_domain'] ?? 'yourdomain.com')) ?></code> — using the password they registered with. Requires a cPanel <strong>API token</strong> (cPanel → Security → Manage API Tokens). The <strong>host is usually the cPanel hostname</strong> (e.g. <code>cpanel.yourhost.com</code> or <code>server.yourhost.com</code>) on port <code>2083</code> — <strong>not</strong> your website domain. Use <strong>🔌 Test cPanel connection</strong> to verify; if it fails it now explains exactly why. Leave <em>Enable</em> off if you don't want auto-created emails.</p>
    <div class="checkbox-row">
      <input type="checkbox" id="email_cpanel_enabled" name="email_cpanel_enabled" <?= !empty($row['email_cpanel_enabled']) ? 'checked' : '' ?>>
      <label for="email_cpanel_enabled" style="margin:0;">Enable automatic cPanel email creation on approval</label>
    </div>
    <div class="row two">
      <div><label for="email_cpanel_host">cPanel Host</label><input type="text" id="email_cpanel_host" name="email_cpanel_host" value="<?= e((string) ($row['email_cpanel_host'] ?? '')) ?>" placeholder="cpanel.example.com or your domain"></div>
      <div><label for="email_cpanel_user">cPanel Username</label><input type="text" id="email_cpanel_user" name="email_cpanel_user" value="<?= e((string) ($row['email_cpanel_user'] ?? '')) ?>" autocomplete="off"></div>
    </div>
    <div class="row two">
      <div><label for="email_cpanel_token">cPanel API Token</label><input type="password" id="email_cpanel_token" name="email_cpanel_token" value="<?= e((string) ($row['email_cpanel_token'] ?? '')) ?>" autocomplete="new-password" placeholder="Paste the API token"></div>
      <div><label for="email_domain">Email Domain</label><input type="text" id="email_domain" name="email_domain" value="<?= e((string) ($row['email_domain'] ?? '')) ?>" placeholder="yourchurch.org"></div>
    </div>
    <div style="max-width:280px;"><label for="email_default_quota">Default Mailbox Quota (MB)</label><input type="number" id="email_default_quota" name="email_default_quota" value="<?= e((string) ($row['email_default_quota'] ?? 500)) ?>" min="0"></div>
    <button type="submit" class="btn secondary sm" formaction="/admin/settings?action=test_cpanel" style="margin-top:12px;">🔌 Test cPanel connection</button>
  </div>

  <button class="btn" type="submit">Save Settings</button>
</form>

<script>
(function () {
  const source = document.getElementById('bible_source');
  const keyWrap = document.getElementById('bible-api-key-wrap');
  if (!source || !keyWrap) return;
  const toggle = () => { keyWrap.style.display = source.value === 'api_bible' ? '' : 'none'; };
  source.addEventListener('change', toggle);
})();
</script>

<div class="card">
  <h2>Video Conversion (Cron Job)</h2>
  <p class="sub">Uploaded videos play instantly in the feed. If FFmpeg is installed, a background job crops them into the vertical 9:16 reel format — this runs automatically right after you publish, and the cron below is the safety net that guarantees every video is processed even if a browser closes mid-upload.</p>

  <h3>Set it up in cPanel</h3>
  <ol style="margin:0 0 14px 1.2em;line-height:1.7;">
    <li>Log in to cPanel and open <strong>Advanced &rarr; Cron Jobs</strong>.</li>
    <li>Under "Add New Cron Job", set the interval to <strong>every 5 minutes</strong>:
      <code>Minute: */5 &nbsp;Hour: * &nbsp;Day: * &nbsp;Month: * &nbsp;Weekday: *</code>
    </li>
    <li>Paste this as the command (path shown is for this server):</li>
  </ol>
  <pre style="background:#1a1530;color:#e8e4f0;padding:12px;border-radius:8px;overflow-x:auto;line-height:1.6;"><code>/usr/bin/php <?= e((string) realpath(__DIR__ . '/../cli/media_worker.php')) ?> &gt;&gt; <?= e(STORAGE_PATH . '/logs/media_worker.log') ?> 2&gt;&amp;1</code></pre>
  <p class="hint">
    If <code>/usr/bin/php</code> isn't found on your host, run <code>which php</code> in cPanel's Terminal to find it (often <code>/usr/local/bin/php</code>). Each run converts whatever originals are still waiting and stops after ~4 minutes; it is safe to run more often. Progress is logged to <code><?= e(STORAGE_PATH . '/logs/media_worker.log') ?></code>.
  </p>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
