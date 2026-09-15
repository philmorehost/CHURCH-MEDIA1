<?php
declare(strict_types=1);

/**
 * Admin → Branding: what a church may change about its own site, and nothing else.
 *
 * `admin/settings.php` is super-admin only, and most of what it holds is not a church's to change:
 * SMTP credentials, the payment keys, the SMS and WhatsApp tokens, the cPanel API token, the licence
 * key, the backup path and retention, the analytics retention. Letting a church admin into that screen
 * would let them send the church's mail through a mail server of their choosing, change where money is
 * collected, and set the retention that governs everybody's backups.
 *
 * So this screen exists instead of loosening that one, and the boundary is a **whitelist**, not a
 * blacklist: the handler reads only the keys named in `$branding` below, so a POST carrying
 * `smtp_password` or `license_key` changes nothing at all. Adding a field here is the only way to make
 * it editable, which is the point — "not editable" is the default that a mistake cannot break.
 *
 * **One array drives both the handler and the form**, so what is saved and what is shown cannot drift
 * apart. That drift is how a field ends up editable without appearing on the screen, or shown without
 * ever being saved.
 *
 * **The guard that matters most is the one for "no church".** `settingSave()` writes the current
 * church's own row — but when no church resolves it falls back to the *shared* row, the defaults every
 * church on the installation inherits. On a host with no church resolved this screen would therefore
 * rewrite every church's branding at once, so it refuses instead: saving is refused outright, and the
 * form is not offered.
 */

Auth::requireRole('admin');

$pdo = Database::getInstance()->getConnection();
$errors = [];

$tenantId = class_exists('Tenant') ? Tenant::id() : null;
$church = $tenantId !== null ? Tenant::find((int) $tenantId) : null;

/**
 * The whitelist. Key => kind, label and an optional hint. `image` entries are handled by the upload
 * block; `enum` entries must also appear in `$enums`.
 *
 * Anything not named here is not read from the request, whatever the form pretends to send.
 */
$branding = [
    // The church's own identity.
    'site_title' => ['kind' => 'text', 'label' => 'Site name', 'hint' => 'The church name, shown in the browser tab and across the site.'],
    'site_tagline' => ['kind' => 'text', 'label' => 'Tagline'],
    'logo_path' => ['kind' => 'image', 'label' => 'Logo'],
    'favicon_path' => ['kind' => 'image', 'label' => 'Favicon', 'hint' => 'The little icon in the browser tab.'],
    'meta_description' => ['kind' => 'text', 'label' => 'Search description', 'hint' => 'One or two sentences for search results.'],

    // The front page hero.
    'hero_type' => ['kind' => 'enum', 'label' => 'Hero style'],
    'hero_image_path' => ['kind' => 'image', 'label' => 'Hero background image'],
    'hero_eyebrow' => ['kind' => 'text', 'label' => 'Hero eyebrow', 'hint' => 'The small line above the headline.'],
    'hero_tagline' => ['kind' => 'text', 'label' => 'Hero headline'],
    'hero_scripture' => ['kind' => 'text', 'label' => 'Hero scripture'],
    'hero_youtube_url' => ['kind' => 'url', 'label' => 'Hero YouTube video'],
    'hero_cta_primary_label' => ['kind' => 'text', 'label' => 'Main button label'],
    'hero_cta_primary_url' => ['kind' => 'url', 'label' => 'Main button link'],
    'hero_cta_secondary_label' => ['kind' => 'text', 'label' => 'Second button label'],
    'hero_cta_secondary_url' => ['kind' => 'url', 'label' => 'Second button link'],

    // How to reach the church.
    'contact_email' => ['kind' => 'email', 'label' => 'Contact email', 'hint' => 'Also where this church\'s sign-in and security notices are sent.'],
    'contact_phone' => ['kind' => 'text', 'label' => 'Contact phone'],
    'address' => ['kind' => 'text', 'label' => 'Address'],
    'facebook_url' => ['kind' => 'url', 'label' => 'Facebook'],
    'instagram_url' => ['kind' => 'url', 'label' => 'Instagram'],
    'youtube_url' => ['kind' => 'url', 'label' => 'YouTube'],
    'tiktok_url' => ['kind' => 'url', 'label' => 'TikTok'],
    'twitter_url' => ['kind' => 'url', 'label' => 'X (Twitter)'],
    'giving_url' => ['kind' => 'url', 'label' => 'Giving page', 'hint' => 'A link to your giving page. The payment gateway itself is not set here.'],

    // Live and the reading/announcement strip.
    'livestream_embed_url' => ['kind' => 'url', 'label' => 'Livestream embed URL'],
    'livestream_is_live' => ['kind' => 'bool', 'label' => 'Show the "live now" banner'],
    'go_declaration_enabled' => ['kind' => 'bool', 'label' => 'Show the declaration strip'],
    'go_declaration_title' => ['kind' => 'text', 'label' => 'Declaration title'],
    'go_declaration_text' => ['kind' => 'text', 'label' => 'Declaration text'],
    'go_declaration_mode' => ['kind' => 'enum', 'label' => 'Declaration style'],

    'footer_about_text' => ['kind' => 'text', 'label' => 'Footer text'],
];

$enums = [
    // `video_upload` is deliberately absent: uploading a video is the one path on the old screen that
    // trusts a file extension, and this screen is offered to more people. A church whose hero is
    // currently a video keeps that value — see the note where the options are built.
    'hero_type' => ['gradient', 'image', 'youtube'],
    'go_declaration_mode' => ['marquee', 'static'],
];

/** The fields on this screen that hold a link, so they can be checked as links. */
$urlFields = ['hero_youtube_url', 'hero_cta_primary_url', 'hero_cta_secondary_url', 'facebook_url',
    'instagram_url', 'youtube_url', 'tiktok_url', 'twitter_url', 'giving_url', 'livestream_embed_url'];

$current = settings();
$readable = static function (array $row, string $key): string {
    $value = $row[$key] ?? '';
    return $value === null ? '' : (string) $value;
};

/* ------------------------------------------------------------------ saving */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    if ($church === null) {
        // Refused, not ignored. Saving here would write the shared row that every church inherits.
        $errors[] = 'No church is resolved for this address, so there is nothing to save to. A change '
            . 'made here would go to the shared defaults and affect every church on this installation. '
            . 'Sign in on the church\'s own address instead.';
    } else {
        $fields = [];

        foreach ($branding as $key => $spec) {
            $kind = $spec['kind'];

            if ($kind === 'bool') {
                $fields[$key] = isset($_POST[$key]) ? 1 : 0;
                continue;
            }

            if ($kind === 'image') {
                // Never taken from the request as a path: the only way to set one is to upload a file
                // that `MediaProcessor` accepts, so a posted `logo_path` cannot point anywhere.
                continue;
            }

            $posted = trim((string) ($_POST[$key] ?? ''));

            if ($kind === 'enum') {
                $options = $enums[$key] ?? [];
                // A value already stored stays selectable even when it is not one this screen offers,
                // so opening the form and saving cannot quietly downgrade a hero that was a video.
                $stored = $readable($current, $key);
                if ($stored !== '' && !in_array($stored, $options, true)) {
                    $options[] = $stored;
                }
                $fields[$key] = in_array($posted, $options, true) ? $posted : ($options[0] ?? '');
                continue;
            }

            $fields[$key] = $posted;
        }

        if ($fields['site_title'] === '') {
            $errors[] = 'The site name cannot be empty — it is the church\'s name on every page.';
        }

        if ($fields['contact_email'] !== '' && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The contact email does not look like an email address.';
        }

        // Only the fields that hold a link, and only rejecting what is neither empty nor http(s):
        // `javascript:` in an href is the reason this exists, and it matters more now that the people
        // writing these fields are not the platform owner.
        foreach ($urlFields as $key) {
            $value = (string) ($fields[$key] ?? '');
            if ($value !== '' && !preg_match('#^https?://#i', $value)) {
                $errors[] = $branding[$key]['label'] . ' must start with http:// or https://.';
            }
        }

        if (!$errors) {
            $imageErrors = [];

            foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path', 'hero_image' => 'hero_image_path'] as $file => $column) {
                if (empty($_FILES[$file]['name'])) {
                    continue;
                }

                $label = $branding[$column]['label'];

                if (($_FILES[$file]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $imageErrors[] = $label . ': the upload failed — the file may be larger than the server allows.';
                } elseif (!is_uploaded_file($_FILES[$file]['tmp_name'] ?? '')
                    || !($filename = MediaProcessor::processImage($_FILES[$file]['tmp_name'], UPLOADS_WEBP_PATH))
                ) {
                    $imageErrors[] = $label . ': the file could not be processed — use JPG, PNG, GIF, WebP, BMP or AVIF.';
                } else {
                    $fields[$column] = 'webp/' . $filename;
                }
            }

            // Through settingSave(), which writes this church's own row and leaves the shared defaults
            // and every other church untouched. Column names come from `$branding`, never from input.
            settingSave($fields);

            flash($imageErrors ? 'error' : 'success', $imageErrors
                ? implode(' ', $imageErrors) . ' The rest of your branding was saved.'
                : 'Your branding has been saved.');
            redirect('/admin/branding');
        }
    }
}

$pageTitle = 'Branding';
$activeNav = 'branding';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card" style="margin-bottom:18px;">
  <p style="margin:0;font-size:13.5px;">
    <?php if ($church !== null): ?>
      These are the words and pictures for <strong><?= e((string) $church['name']) ?></strong>.
      Saving changes this church only — other churches on this installation keep their own.
    <?php else: ?>
      <strong>No church is resolved for this address.</strong> This page is still shown so you can see
      what it holds, but nothing will be saved from here: without a church, a save would change the
      shared defaults that every church inherits.
    <?php endif; ?>
  </p>
</div>

<?php if ($church === null): ?>
  <div class="card">
    <p style="margin:0;">Open this page on the church's own address to edit its branding.</p>
  </div>
<?php else: ?>
  <form method="post" action="/admin/branding" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Identity</h2>
      <?php foreach (['site_title', 'site_tagline', 'meta_description'] as $key): $spec = $branding[$key]; ?>
        <div style="margin-bottom:12px;">
          <label for="<?= e($key) ?>"><?= e($spec['label']) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($readable($current, $key)) ?>">
          <?php if (!empty($spec['hint'])): ?><small style="color:var(--ink-faint);"><?= e($spec['hint']) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach (['logo_path' => 'logo', 'favicon_path' => 'favicon'] as $key => $file): $spec = $branding[$key]; ?>
        <div style="margin-bottom:12px;">
          <label for="<?= e($file) ?>"><?= e($spec['label']) ?><?= $readable($current, $key) !== '' ? ' (currently set)' : '' ?></label>
          <input type="file" id="<?= e($file) ?>" name="<?= e($file) ?>" accept="image/*">
          <?php if ($readable($current, $key) !== ''): ?>
            <img src="<?= e(uploadUrl($readable($current, $key))) ?>" class="thumb" alt="">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Front page</h2>
      <div style="margin-bottom:12px;">
        <label for="hero_type">Hero style</label>
        <select id="hero_type" name="hero_type">
          <?php
          $heroOptions = $enums['hero_type'];
          $storedHero = $readable($current, 'hero_type');
          if ($storedHero !== '' && !in_array($storedHero, $heroOptions, true)) {
              $heroOptions[] = $storedHero;
          }
          foreach ($heroOptions as $option): ?>
            <option value="<?= e($option) ?>"<?= $readable($current, 'hero_type') === $option ? ' selected' : '' ?>><?= e($option) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:12px;">
        <label for="hero_image">Hero background image<?= $readable($current, 'hero_image_path') !== '' ? ' (currently set)' : '' ?></label>
        <input type="file" id="hero_image" name="hero_image" accept="image/*">
      </div>

      <?php foreach (['hero_eyebrow', 'hero_tagline', 'hero_scripture', 'hero_youtube_url'] as $key): $spec = $branding[$key]; ?>
        <div style="margin-bottom:12px;">
          <label for="<?= e($key) ?>"><?= e($spec['label']) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($readable($current, $key)) ?>">
          <?php if (!empty($spec['hint'])): ?><small style="color:var(--ink-faint);"><?= e($spec['hint']) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach (['hero_cta_primary_label', 'hero_cta_primary_url', 'hero_cta_secondary_label', 'hero_cta_secondary_url'] as $key): $spec = $branding[$key]; ?>
        <div style="margin-bottom:12px;">
          <label for="<?= e($key) ?>"><?= e($spec['label']) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($readable($current, $key)) ?>">
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Contact and links</h2>
      <?php foreach (['contact_email', 'contact_phone', 'address', 'giving_url', 'facebook_url', 'instagram_url', 'youtube_url', 'tiktok_url', 'twitter_url'] as $key): $spec = $branding[$key]; ?>
        <div style="margin-bottom:12px;">
          <label for="<?= e($key) ?>"><?= e($spec['label']) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($readable($current, $key)) ?>">
          <?php if (!empty($spec['hint'])): ?><small style="color:var(--ink-faint);"><?= e($spec['hint']) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Live and announcements</h2>
      <?php foreach (['livestream_is_live' => 'bool', 'livestream_embed_url' => 'url', 'go_declaration_enabled' => 'bool', 'go_declaration_title' => 'text', 'go_declaration_text' => 'text', 'footer_about_text' => 'text'] as $key => $kind): $spec = $branding[$key]; ?>
        <div style="margin-bottom:12px;">
          <?php if ($kind === 'bool'): ?>
            <label style="display:inline-flex; gap:8px; align-items:center;" for="<?= e($key) ?>">
              <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"<?= $readable($current, $key) === '1' ? ' checked' : '' ?>>
              <?= e($spec['label']) ?>
            </label>
          <?php else: ?>
            <label for="<?= e($key) ?>"><?= e($spec['label']) ?></label>
            <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($readable($current, $key)) ?>">
            <?php if (!empty($spec['hint'])): ?><small style="color:var(--ink-faint);"><?= e($spec['hint']) ?></small><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div style="margin-bottom:12px;">
        <label for="go_declaration_mode">Declaration style</label>
        <select id="go_declaration_mode" name="go_declaration_mode">
          <?php foreach ($enums['go_declaration_mode'] as $option): ?>
            <option value="<?= e($option) ?>"<?= $readable($current, 'go_declaration_mode') === $option ? ' selected' : '' ?>><?= e($option) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <button type="submit" class="btn">Save branding</button>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
